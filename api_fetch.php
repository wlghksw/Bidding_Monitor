<?php
// =============================================
// api_fetch.php - 각 API / 크롤링 수집 클래스
// =============================================

class ApiFetch {
    private Database $db;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    // ── 전체 소스 수집 실행 ──
    public function fetchAll(): array {
        $results = [];
        $results['나라장터']           = $this->fetchG2B();
        $results['K-스타트업']         = $this->fetchKStartup();
        $results['중소기업기술정보진흥원'] = $this->fetchSmtech();
        $results['IITP']              = $this->fetchIITP();
        return $results;
    }

    // ── 나라장터 (G2B) ──
    public function fetchG2B(): int {
        $count    = 0;
        $keywords = $this->db->getKeywords();

        for ($page = 1; $page <= MAX_PAGES_PER_SOURCE; $page++) {
            $params = http_build_query([
                'serviceKey'     => G2B_API_KEY,
                'numOfRows'      => 100,
                'pageNo'         => $page,
                'type'           => 'json',
                'inqryDiv'       => 1,
                'inqryBgnDt'     => date('YmdHis', strtotime('-1 day')),
                'inqryEndDt'     => date('YmdHis'),
            ]);

            $response = @file_get_contents(G2B_API_URL . '?' . $params);
            if (!$response) break;

            $data  = json_decode($response, true);
            $items = $data['response']['body']['items']['item'] ?? [];
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

    // ── K-스타트업 ──
    public function fetchKStartup(): int {
        $count    = 0;
        $keywords = $this->db->getKeywords();

        for ($page = 1; $page <= MAX_PAGES_PER_SOURCE; $page++) {
            $params = http_build_query([
                'serviceKey' => KSTARTUP_API_KEY,
                'pageNo'     => $page,
                'numOfRows'  => 100,
                'type'       => 'json',
            ]);

            $response = @file_get_contents(KSTARTUP_API_URL . '?' . $params);
            if (!$response) break;

            $data  = json_decode($response, true);
            $items = $data['response']['body']['items'] ?? [];
            if (empty($items)) break;

            foreach ($items as $item) {
                $title    = $item['pbanNm']         ?? '';
                $url      = $item['detlPgUrl']       ?? 'https://www.k-startup.go.kr';
                $org      = $item['suprtInsttNm']    ?? '';
                $deadline = $item['rcptEdDt']        ?? '';

                $bidId = $this->db->saveBid([
                    ':title'         => $title,
                    ':url'           => $url,
                    ':source'        => 'K-스타트업',
                    ':org_name'      => $org,
                    ':budget'        => '-',
                    ':budget_raw'    => 0,
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

    // ── 중소기업기술정보진흥원 (SMTECH) ──
    public function fetchSmtech(): int {
        $count    = 0;
        $keywords = $this->db->getKeywords();

        for ($page = 1; $page <= MAX_PAGES_PER_SOURCE; $page++) {
            $params = http_build_query([
                'serviceKey' => SMTECH_API_KEY,
                'pageNo'     => $page,
                'numOfRows'  => 100,
                'type'       => 'json',
            ]);

            $response = @file_get_contents(SMTECH_API_URL . '?' . $params);
            if (!$response) break;

            $data  = json_decode($response, true);
            $items = $data['response']['body']['items']['item'] ?? [];
            if (empty($items)) break;
            if (isset($items['pbanNm'])) $items = [$items];

            foreach ($items as $item) {
                $title    = $item['pbanNm']      ?? '';
                $url      = $item['pbanUrl']     ?? 'https://www.smtech.go.kr';
                $org      = $item['suprtInsttNm'] ?? '';
                $deadline = $item['rcptEdDt']    ?? '';

                $bidId = $this->db->saveBid([
                    ':title'         => $title,
                    ':url'           => $url,
                    ':source'        => '중소기업기술정보진흥원',
                    ':org_name'      => $org,
                    ':budget'        => '-',
                    ':budget_raw'    => 0,
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

    // ── IITP (HTML 크롤링) ──
    public function fetchIITP(): int {
        $count    = 0;
        $keywords = $this->db->getKeywords();

        $html = @file_get_contents(IITP_URL);
        if (!$html) return 0;

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // 공고 목록 행 파싱 (실제 사이트 구조에 맞게 셀렉터 수정 필요)
        $rows = $xpath->query('//table[contains(@class,"list")]//tbody//tr');

        foreach ($rows as $row) {
            $cols = $xpath->query('td', $row);
            if ($cols->length < 4) continue;

            $title    = trim($cols->item(1)->textContent);
            $deadline = trim($cols->item(3)->textContent);
            $anchor   = $xpath->query('.//a', $cols->item(1))->item(0);
            $href     = $anchor ? 'https://www.iitp.kr' . $anchor->getAttribute('href') : IITP_URL;

            if (!$title) continue;

            $bidId = $this->db->saveBid([
                ':title'         => $title,
                ':url'           => $href,
                ':source'        => 'IITP',
                ':org_name'      => '정보통신기획평가원',
                ':budget'        => '-',
                ':budget_raw'    => 0,
                ':deadline_date' => date('Y-m-d', strtotime($deadline)) ?: null,
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
