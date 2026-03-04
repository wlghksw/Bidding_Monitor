<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;

/**
 * 나라장터(G2B) API 기반 크롤러
 */
class NaraJangteoCrawler implements NoticeCrawler
{
    public function __construct(
        private HttpClient $http,
        private ?string $lastFetchedAt = null,
    ) {}

    public function getSourceName(): string
    {
        return '나라장터';
    }

    public function crawl(): array
    {
        $notices = [];
        $lastFetched = $this->lastFetchedAt;
        $bgnDt = $lastFetched
            ? date('YmdHi', strtotime('-' . (defined('G2B_INCREMENTAL_DAYS') ? G2B_INCREMENTAL_DAYS : 3) . ' day'))
            : date('YmdHi', strtotime('-' . (defined('G2B_DAYS') ? G2B_DAYS : 90) . ' day'));
        $endDt = date('YmdHi');
        $maxPage = $lastFetched
            ? (defined('G2B_INCREMENTAL_MAX_PAGES') ? G2B_INCREMENTAL_MAX_PAGES : 10)
            : (defined('MAX_PAGES_PER_SOURCE') ? MAX_PAGES_PER_SOURCE : 50);

        for ($page = 1; $page <= $maxPage; $page++) {
            $params = http_build_query([
                'serviceKey' => G2B_API_KEY,
                'numOfRows'  => 100,
                'pageNo'     => $page,
                'type'       => 'json',
                'inqryDiv'   => 1,
                'inqryBgnDt' => $bgnDt,
                'inqryEndDt' => $endDt,
            ]);
            $url = G2B_API_URL . '?' . $params;
            $response = $this->http->get($url);
            if ($response === null) {
                if ($page === 1) trigger_error('나라장터 API 연결 실패', E_USER_WARNING);
                break;
            }
            $data = json_decode($response, true);
            $body = $data['response']['body'] ?? [];
            $resultCode = $data['response']['header']['resultCode'] ?? $body['resultCode'] ?? '';
            if ($resultCode && $resultCode !== '00' && $resultCode !== '0') {
                break;
            }
            $items = $body['items']['item'] ?? $body['items'] ?? [];
            if (empty($items)) break;
            if (isset($items['bidNtceNo'])) $items = [$items];
            foreach ($items as $item) {
                $title = $item['bidNtceNm'] ?? '';
                $url = $item['bidNtceUrl'] ?? 'https://www.g2b.go.kr';
                $org = $item['ntceInsttNm'] ?? '';
                $budget = (float)($item['presmptPrce'] ?? 0);
                $deadline = $item['bidClseDt'] ?? '';
                $notices[] = new Notice(
                    title: $title,
                    url: $url,
                    source: $this->getSourceName(),
                    orgName: $org,
                    deadlineDate: $deadline ? date('Y-m-d', strtotime($deadline)) : null,
                    budget: $budget > 0 ? number_format($budget) . '원' : '-',
                    budgetRaw: $budget,
                );
            }
        }
        return $notices;
    }
}
