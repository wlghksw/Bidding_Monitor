<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;

/**
 * 중소벤처24 공고정보 API 크롤러
 */
class Smes24Crawler implements NoticeCrawler
{
    public function __construct(
        private HttpClient $http,
    ) {}

    public function getSourceName(): string
    {
        return '중소벤처24';
    }

    public function crawl(): array
    {
        $days = defined('SMES24_DAYS') ? SMES24_DAYS : 30;
        $endDt = date('Ymd');
        $strDt = date('Ymd', strtotime("-{$days} day"));
        $url = SMES24_API_URL . '?token=' . SMES24_API_KEY . '&strDt=' . $strDt . '&endDt=' . $endDt;
        $response = $this->http->get($url);
        if ($response === null) {
            trigger_error('중소벤처24 API 연결 실패', E_USER_WARNING);
            return [];
        }
        $data = json_decode($response, true);
        if (!is_array($data) || ($data['resultCd'] ?? '') !== '0') {
            return [];
        }
        $items = $data['data'] ?? [];
        if (isset($items['pblancNm'])) $items = [$items];
        $notices = [];
        foreach ($items as $item) {
            $title = $item['pblancNm'] ?? '';
            if ($title === '') continue;
            $url = $item['pblancDtlUrl'] ?? $item['reqstLinkInfo'] ?? 'https://www.smes.go.kr';
            $org = $item['sportInsttNm'] ?? '';
            $endDate = $item['pblancEndDt'] ?? '';
            $deadline = strlen($endDate) >= 10 ? substr($endDate, 0, 10) : null;
            $notices[] = new Notice(
                title: $title,
                url: $url,
                source: $this->getSourceName(),
                orgName: $org,
                deadlineDate: $deadline,
                budget: '-',
                budgetRaw: 0,
            );
        }
        return $notices;
    }
}
