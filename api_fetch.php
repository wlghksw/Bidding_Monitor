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
        $results['나라장터']   = $this->fetchG2B();
        $results['K-스타트업'] = $this->fetchKStartup();
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
                'inqryBgnDt'     => date('YmdHi', strtotime('-7 day')),
                'inqryEndDt'     => date('YmdHi'),
            ]);

            $response = @file_get_contents(G2B_API_URL . '?' . $params);
            if (!$response) break;

            $data  = json_decode($response, true);
            $body  = $data['response']['body'] ?? [];
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

        for ($page = 1; $page <= MAX_PAGES_PER_SOURCE; $page++) {
            $params = http_build_query([
                'serviceKey' => KSTARTUP_API_KEY,
                'pageNo'     => $page,
                'numOfRows'  => 100,
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
