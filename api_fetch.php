<?php
// =============================================
// api_fetch.php - 각 API / 크롤링 수집 클래스
// =============================================
// config.php에 없으면 여기서 기본값 사용 (구버전 config 호환)
if (!defined('G2B_DAYS'))                    define('G2B_DAYS', 90);
if (!defined('MAX_PAGES_PER_SOURCE'))         define('MAX_PAGES_PER_SOURCE', 50);
if (!defined('G2B_INCREMENTAL_DAYS'))          define('G2B_INCREMENTAL_DAYS', 3);
if (!defined('G2B_INCREMENTAL_MAX_PAGES'))    define('G2B_INCREMENTAL_MAX_PAGES', 10);
if (!defined('KSTARTUP_INCREMENTAL_PAGES'))  define('KSTARTUP_INCREMENTAL_PAGES', 10);
if (!defined('SMES24_DAYS'))                 define('SMES24_DAYS', 30);

class ApiFetch {
    private Database $db;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    // ── 전체 소스 수집 실행 ──
    public function fetchAll(): array {
        $results = [];
        $results['나라장터']   = $this->fetchG2B();
        $results['K-스타트업'] = $this->fetchKStartup();
        $results['중소벤처24'] = $this->fetchSmes24();
        $results['기업마당']   = $this->fetchBizinfo();
        return $results;
    }

    // ── 나라장터 (G2B) ──
    public function fetchG2B(): int {
        $count    = 0;
        $keywords = $this->db->getKeywords();

        // 증분: DB에 한 번이라도 있으면 최근 N일만 조회(구간이 비어 0건 나오는 것 방지). 없으면 전체 기간
        $lastFetched = $this->db->getLastFetchedAt('나라장터');
        if ($lastFetched) {
            $bgnDt   = date('YmdHi', strtotime('-' . G2B_INCREMENTAL_DAYS . ' day'));
            $endDt   = date('YmdHi');
            $maxPage = G2B_INCREMENTAL_MAX_PAGES;
        } else {
            $bgnDt   = date('YmdHi', strtotime('-' . G2B_DAYS . ' day'));
            $endDt   = date('YmdHi');
            $maxPage = MAX_PAGES_PER_SOURCE;
        }

        for ($page = 1; $page <= $maxPage; $page++) {
            $params = http_build_query([
                'serviceKey'     => G2B_API_KEY,
                'numOfRows'      => 100,
                'pageNo'         => $page,
                'type'           => 'json',
                'inqryDiv'       => 1,
                'inqryBgnDt'     => $bgnDt,
                'inqryEndDt'     => $endDt,
            ]);

            $response = @file_get_contents(G2B_API_URL . '?' . $params);
            if ($response === false) {
                if ($page === 1) trigger_error('나라장터 API 연결 실패: ' . G2B_API_URL, E_USER_WARNING);
                break;
            }

            $data  = json_decode($response, true);
            $body  = $data['response']['body'] ?? [];
            $resultCode = $data['response']['header']['resultCode'] ?? $body['resultCode'] ?? '';
            if ($resultCode && $resultCode !== '00' && $resultCode !== '0') {
                if ($page === 1) trigger_error('나라장터 API 오류: ' . ($data['response']['header']['resultMsg'] ?? $body['resultMsg'] ?? $resultCode), E_USER_WARNING);
                break;
            }
            $items = $body['items']['item'] ?? $body['items'] ?? [];
            if (empty($items)) break;

            // 단일 결과도 배열로 처리
            if (isset($items['bidNtceNo'])) $items = [$items];

            foreach ($items as $item) {
                $title    = $item['bidNtceNm']    ?? '';
                $url      = $item['bidNtceUrl']   ?? 'https://www.g2b.go.kr';
                $org      = $item['ntceInsttNm']  ?? '';
                $budget   = $item['presmptPrce']  ?? '';
                $deadline = $item['bidClseDt']    ?? '';

                $bidId = $this->db->saveBid([
                    ':title'         => $title,
                    ':url'           => $url,
                    ':source'        => '나라장터',
                    ':org_name'      => $org,
                    ':budget'        => number_format((float)$budget) . '원',
                    ':budget_raw'    => (float)$budget,
                    ':deadline_date' => date('Y-m-d', strtotime($deadline)),
                ]);

                $matched = $this->matchKeywords($title, $keywords);
                if ($matched) {
                    $this->db->saveBidKeywords($bidId, $matched);
                    $count++;
                }
            }
        }

        return $count;
    }

    // ── K-스타트업 (getAnnouncementInformation01, XML 응답) ──
    public function fetchKStartup(): int {
        $count    = 0;
        $keywords = $this->db->getKeywords();

        // 증분: 최신 순이므로 앞쪽 페이지만 조회 (새 공고만 빠르게 반영)
        for ($page = 1; $page <= KSTARTUP_INCREMENTAL_PAGES; $page++) {
            $params = http_build_query([
                'serviceKey' => KSTARTUP_API_KEY,
                'page'       => $page,
                'perPage'    => 100,
            ]);

            $response = @file_get_contents(KSTARTUP_API_URL . '?' . $params);
            if (!$response) break;

            $xml = @simplexml_load_string($response);
            if ($xml === false) break;

            $items = $xml->data->item ?? [];
            if (count($items) === 0) break;

            foreach ($items as $item) {
                $row = [];
                foreach ($item->col as $col) {
                    $name = (string) $col['name'];
                    $row[$name] = trim((string) $col);
                }
                $title    = $row['biz_pbanc_nm'] ?? '';
                $url      = $row['detl_pg_url']  ?? 'https://www.k-startup.go.kr';
                $org      = $row['pbanc_ntrp_nm'] ?? '';
                $endDt    = $row['pbanc_rcpt_end_dt'] ?? '';
                $deadline = strlen($endDt) >= 8 ? substr($endDt, 0, 4) . '-' . substr($endDt, 4, 2) . '-' . substr($endDt, 6, 2) : null;

                if (!$title) continue;

                $bidId = $this->db->saveBid([
                    ':title'         => $title,
                    ':url'           => $url,
                    ':source'        => 'K-스타트업',
                    ':org_name'      => $org,
                    ':budget'        => '-',
                    ':budget_raw'    => 0,
                    ':deadline_date' => $deadline,
                ]);

                $matched = $this->matchKeywords($title, $keywords);
                if ($matched) {
                    $this->db->saveBidKeywords($bidId, $matched);
                    $count++;
                }
            }
        }

        return $count;
    }

    // ── 중소벤처24 공고정보 API (공고정보 연계 API 가이드 V2 기준) ──
    // 파라미터: token(필수), strDt/endDt(yyyyMMdd 선택), html(yes/no 선택). 페이지 없음, 기간으로 조회.
    public function fetchSmes24(): int {
        $count    = 0;
        $keywords = $this->db->getKeywords();

        $endDt  = date('Ymd');
        $strDt  = date('Ymd', strtotime('-' . SMES24_DAYS . ' day'));
        // token은 가이드대로 url encoding하여 전달 (config에 인코딩된 키 저장 시 그대로 사용)
        $url = SMES24_API_URL . '?token=' . SMES24_API_KEY . '&strDt=' . $strDt . '&endDt=' . $endDt;
        $response = @file_get_contents($url);
        if ($response === false) {
            trigger_error('중소벤처24 API 연결 실패: ' . SMES24_API_URL, E_USER_WARNING);
            return 0;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            trigger_error('중소벤처24 API 응답 파싱 실패', E_USER_WARNING);
            return 0;
        }

        $resultCd = $data['resultCd'] ?? '';
        if ($resultCd !== '0') {
            trigger_error('중소벤처24 API 오류: ' . ($data['resultMsg'] ?? $resultCd), E_USER_WARNING);
            return 0;
        }

        $items = $data['data'] ?? [];
        if (isset($items['pblancNm'])) {
            $items = [$items];
        }
        if (empty($items)) return 0;

        foreach ($items as $item) {
            $title    = $item['pblancNm'] ?? '';
            $url      = $item['pblancDtlUrl'] ?? $item['reqstLinkInfo'] ?? 'https://www.smes.go.kr';
            $org      = $item['sportInsttNm'] ?? '';
            $endDate  = $item['pblancEndDt'] ?? ''; // yyyy-MM-dd
            $deadline = (strlen($endDate) >= 10) ? substr($endDate, 0, 10) : null;

            if (!$title) continue;

            $bidId = $this->db->saveBid([
                ':title'         => $title,
                ':url'           => $url,
                ':source'        => '중소벤처24',
                ':org_name'      => $org,
                ':budget'        => '-',
                ':budget_raw'    => 0,
                ':deadline_date' => $deadline,
            ]);

            $matched = $this->matchKeywords($title, $keywords);
            if ($matched) {
                $this->db->saveBidKeywords($bidId, $matched);
                $count++;
            }
        }

        return $count;
    }

    // ── 기업마당(비즈인포) 지원사업 API ──
    // URL: https://www.bizinfo.go.kr/uss/rss/bizinfoApi.do
    // 필수 파라미터: crtfcKey (서비스키, 대소문자 구분)
    // 선택: dataType(rss/json), searchCnt, searchLclasId, hashtags, pageUnit, pageIndex
    public function fetchBizinfo(): int {
        $count    = 0;
        $keywords = $this->db->getKeywords();

        $params = http_build_query([
            'crtfcKey' => BIZINFO_API_KEY,  // 명세: crtfcKey (대문자 K)
            'dataType' => 'json',
        ]);

        $url      = BIZINFO_API_URL . '?' . $params;
        $response = @file_get_contents($url);
        if ($response === false) {
            trigger_error('기업마당 API 연결 실패: ' . BIZINFO_API_URL, E_USER_WARNING);
            return 0;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            trigger_error('기업마당 API 응답 파싱 실패 (JSON 아님)', E_USER_WARNING);
            return 0;
        }

        // 응답 형태 2가지:
        // 1) {"jsonArray": { title, link, ..., item: [ {...}, {...} ] }}
        // 2) {"jsonArray": [ {...}, {...} ]}  // jsonArray가 곧 item 배열
        $root = $data['jsonArray'] ?? $data;

        if (isset($root['item'])) {
            $items = $root['item'];
        } elseif (isset($root[0]) && is_array($root[0])) {
            $items = $root; // jsonArray가 곧 item 리스트
        } else {
            $items = [];
        }

        if (isset($items['title']) || isset($items['pblancNm'])) {
            $items = [$items];
        }

        if (empty($items) && !empty($root['reqErr'])) {
            trigger_error('기업마당 API: ' . (is_string($root['reqErr']) ? $root['reqErr'] : '인증/요청 오류'), E_USER_WARNING);
            return 0;
        }
        if (empty($items)) {
            return 0;
        }

        foreach ($items as $item) {
            $title = trim((string)($item['pblancNm'] ?? $item['title'] ?? ''));
            $link  = trim((string)($item['pblancUrl'] ?? $item['link'] ?? ''));
            if (!$title) continue;
            if (!$link) {
                $link = 'https://www.bizinfo.go.kr/web/lay1/bbs/S1T122C128/AS/74/list.do';
            } elseif (strpos($link, 'http') !== 0) {
                $link = 'https://www.bizinfo.go.kr' . (strpos($link, '/') === 0 ? $link : '/' . $link);
            }

            $pubDate = trim((string)($item['reqstBeginEndDe'] ?? $item['pubDate'] ?? ''));
            $deadline = null;
            // reqstBeginEndDe 예: 20220727 ~ 20220930 → 끝 날짜를 마감일로 사용
            if ($pubDate !== '') {
                if (strpos($pubDate, '~') !== false) {
                    [, $end] = array_map('trim', explode('~', $pubDate, 2));
                    if (preg_match('/^\d{8}$/', $end)) {
                        $deadline = substr($end, 0, 4) . '-' . substr($end, 4, 2) . '-' . substr($end, 6, 2);
                    }
                } else {
                    $ts = strtotime($pubDate);
                    if ($ts) $deadline = date('Y-m-d', $ts);
                }
            }

            $org    = trim((string)($item['jrsdInsttNm'] ?? $item['author'] ?? '기업마당'));
            $field  = trim((string)($item['lcategory'] ?? $item['pldirSportRealmLclasCodeNm'] ?? ''));
            $region = trim((string)($item['hashTags'] ?? ''));
            $period = trim((string)($item['reqstBeginEndDe'] ?? $item['reqstDt'] ?? ''));

            $bidId = $this->db->saveBid([
                ':title'          => $title,
                ':url'            => $link,
                ':source'         => '기업마당',
                ':org_name'       => $org,
                ':budget'         => '-',
                ':budget_raw'     => 0,
                ':deadline_date'  => $deadline,
                ':region'         => $region ?: null,
                ':support_field'  => $field ?: null,
                ':receipt_period' => $period ?: null,
            ]);

            $matched = $this->matchKeywords($title, $keywords);
            if ($matched) {
                $this->db->saveBidKeywords($bidId, $matched);
                $count++;
            }
        }

        return $count;
    }

    // ── 키워드 매칭 ──
    private function matchKeywords(string $title, array $keywords): array {
        $matched = [];
        foreach ($keywords as $kw) {
            if (mb_strpos($title, $kw['keyword']) !== false) {
                $matched[] = $kw['id'];
            }
        }
        return $matched;
    }
}
