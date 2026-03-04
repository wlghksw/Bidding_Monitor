<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;

/**
 * K-스타트업 API 기반 크롤러
 * 응답이 XML(getAnnouncementInformation01) 또는 JSON일 수 있음 → 형식에 따라 분기
 */
class KStartupCrawler implements NoticeCrawler
{
    public function __construct(
        private HttpClient $http,
    ) {}

    public function getSourceName(): string
    {
        return 'K-스타트업';
    }

    public function crawl(): array
    {
        $notices = [];
        $maxPage = defined('KSTARTUP_INCREMENTAL_PAGES') ? KSTARTUP_INCREMENTAL_PAGES : 10;
        for ($page = 1; $page <= $maxPage; $page++) {
            $params = http_build_query([
                'serviceKey' => KSTARTUP_API_KEY,
                'page'       => $page,
                'perPage'    => 100,
            ]);
            $url = KSTARTUP_API_URL . '?' . $params;
            $response = $this->http->get($url);
            if ($response === null) break;
            $response = trim($response);

            // JSON 응답인 경우 (API에 type=json 또는 별도 JSON 엔드포인트 사용 시)
            if (str_starts_with($response, '{')) {
                $data = json_decode($response, true);
                $items = $data['data']['item'] ?? $data['item'] ?? $data['data'] ?? [];
                if (isset($items['biz_pbanc_nm'])) {
                    $items = [$items];
                }
                foreach (is_array($items) ? $items : [] as $item) {
                    $row = $item;
                    $title = trim((string)($row['biz_pbanc_nm'] ?? ''));
                    if ($title === '') continue;
                    $linkUrl = trim((string)($row['detl_pg_url'] ?? 'https://www.k-startup.go.kr'));
                    $org = trim((string)($row['pbanc_ntrp_nm'] ?? ''));
                    $endDt = trim((string)($row['pbanc_rcpt_end_dt'] ?? ''));
                    $deadline = strlen($endDt) >= 8
                        ? substr($endDt, 0, 4) . '-' . substr($endDt, 4, 2) . '-' . substr($endDt, 6, 2)
                        : null;
                    $notices[] = new Notice(
                        title: $title,
                        url: $linkUrl,
                        source: $this->getSourceName(),
                        orgName: $org,
                        deadlineDate: $deadline,
                        budget: '-',
                        budgetRaw: 0,
                    );
                }
                continue;
            }

            // XML 응답 (기본)
            $xml = @simplexml_load_string($response);
            if ($xml === false) break;
            $items = $xml->data->item ?? [];
            if (count($items) === 0) break;
            foreach ($items as $item) {
                $row = [];
                foreach ($item->col as $col) {
                    $row[(string)$col['name']] = trim((string)$col);
                }
                $title = $row['biz_pbanc_nm'] ?? '';
                if ($title === '') continue;
                $linkUrl = $row['detl_pg_url'] ?? 'https://www.k-startup.go.kr';
                $org = $row['pbanc_ntrp_nm'] ?? '';
                $endDt = $row['pbanc_rcpt_end_dt'] ?? '';
                $deadline = strlen($endDt) >= 8
                    ? substr($endDt, 0, 4) . '-' . substr($endDt, 4, 2) . '-' . substr($endDt, 6, 2)
                    : null;
                $notices[] = new Notice(
                    title: $title,
                    url: $linkUrl,
                    source: $this->getSourceName(),
                    orgName: $org,
                    deadlineDate: $deadline,
                    budget: '-',
                    budgetRaw: 0,
                );
            }
        }
        return $notices;
    }
}
