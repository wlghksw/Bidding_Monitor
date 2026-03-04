<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;

/**
 * 강화군 고시공고 HTML 크롤러
 * 목록 URL은 강화군청 사이트 구조에 맞게 설정 필요
 * 고시공고 목록 URL 사용
 */
class GanghwaCityCrawler implements NoticeCrawler
{
    private const LIST_URL = 'https://www.ganghwa.go.kr/open_content/main/cms/?mCode=MN051&mode=list';

    public function __construct(
        private HttpClient $http,
        private HtmlParser $parser,
    ) {}

    public function getSourceName(): string
    {
        return '강화군 고시공고';
    }

    public function crawl(): array
    {
        $html = $this->http->get(self::LIST_URL);
        if ($html === null) return [];
        if (!$this->parser->loadHtml($html)) return [];

        $notices = [];
        $xpath = $this->parser->getXPath();
        if ($xpath === null) return [];

        $rows = $xpath->query("//a[contains(@href,'view') or contains(@href,'detail') or contains(@href,'bbs')]");
        if ($rows === false) return [];

        $baseUrl = 'https://www.ganghwa.go.kr';
        foreach ($rows as $row) {
            $title = HtmlParser::getTextContent($row);
            if ($title === '' || mb_strlen($title) < 5) continue;
            $href = HtmlParser::getHref($row, $baseUrl);
            if ($href === '') continue;
            $notices[] = new Notice(
                title: $title,
                url: $href,
                source: $this->getSourceName(),
                orgName: '강화군',
                deadlineDate: null,
                budget: '-',
                budgetRaw: 0,
            );
        }
        return $notices;
    }
}
