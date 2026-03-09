<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;
use \Database;

/**
 * 기업마당(비즈인포) 지원사업 API 크롤러
 */
class BizInfoCrawler implements NoticeCrawler
{
    private const PAGE_UNIT = 100;
    private const MAX_PAGES = 10;
    private const RECENT_SKIP_DAYS = 7;

    private ?Database $db = null;

    public function __construct(
        private HttpClient $http,
    ) {}

    public function setDatabase(Database $db): void
    {
        $this->db = $db;
    }

    public function getSourceName(): string
    {
        return '기업마당';
    }

    public function crawl(): array
    {
        $notices = [];

        $cutoff = $this->db !== null ? new \DateTime('-' . self::RECENT_SKIP_DAYS . ' days') : null;

        for ($pageIndex = 1; $pageIndex <= self::MAX_PAGES; $pageIndex++) {
            $params = http_build_query([
                'crtfcKey'  => BIZINFO_API_KEY,
                'dataType'  => 'json',
                'pageUnit'  => self::PAGE_UNIT,
                'pageIndex' => $pageIndex,
            ]);
            $url = BIZINFO_API_URL . '?' . $params;
            $response = $this->http->get($url);
            if ($response === null) {
                if ($pageIndex === 1) {
                    trigger_error('기업마당 API 연결 실패', E_USER_WARNING);
                }
                break;
            }
            $data = json_decode($response, true);
            if (!is_array($data)) break;

            $root = $data['jsonArray'] ?? $data;
            if (isset($root['item'])) {
                $items = $root['item'];
            } elseif (isset($root[0]) && is_array($root[0])) {
                $items = $root;
            } else {
                $items = [];
            }
            if (!$items) {
                break;
            }
            if (isset($items['title']) || isset($items['pblancNm'])) $items = [$items];

            $pageMap = []; // url => Notice
            foreach ($items as $item) {
                $title = trim((string)($item['pblancNm'] ?? $item['title'] ?? ''));
                if ($title === '') continue;

                $link = trim((string)($item['pblancUrl'] ?? $item['link'] ?? ''));
                if ($link === '') {
                    $link = 'https://www.bizinfo.go.kr/web/lay1/bbs/S1T122C128/AS/74/list.do';
                } elseif (strpos($link, 'http') !== 0) {
                    $link = 'https://www.bizinfo.go.kr' . (strpos($link, '/') === 0 ? $link : '/' . $link);
                }

                $pubDate = trim((string)($item['reqstBeginEndDe'] ?? $item['pubDate'] ?? ''));
                $deadline = null;
                if ($pubDate !== '') {
                    if (strpos($pubDate, '~') !== false) {
                        $parts = array_map('trim', explode('~', $pubDate, 2));
                        $end = $parts[1] ?? '';
                        if (preg_match('/^\d{8}$/', $end)) {
                            $deadline = substr($end, 0, 4) . '-' . substr($end, 4, 2) . '-' . substr($end, 6, 2);
                        }
                    } else {
                        $ts = strtotime($pubDate);
                        if ($ts) $deadline = date('Y-m-d', $ts);
                    }
                }

                $org = trim((string)($item['jrsdInsttNm'] ?? $item['author'] ?? '기업마당'));

                $pageMap[$link] = new Notice(
                    title: $title,
                    url: $link,
                    source: $this->getSourceName(),
                    orgName: $org,
                    deadlineDate: $deadline,
                    budget: '-',
                    budgetRaw: 0,
                );
            }

            if ($this->db !== null && $pageMap !== []) {
                $info = $this->db->getExistingBidInfoByUrls($this->getSourceName(), array_keys($pageMap));
                $pageFullyKnown = true;
                foreach ($pageMap as $link => $notice) {
                    $row = $info[$link] ?? null;
                    if (!is_array($row)) {
                        $pageFullyKnown = false;
                        $notices[] = $notice;
                        continue;
                    }
                }

                if ($pageFullyKnown) {
                    break;
                }
                continue;
            }

            foreach ($pageMap as $notice) {
                $notices[] = $notice;
            }
        }
        return $notices;
    }
}
