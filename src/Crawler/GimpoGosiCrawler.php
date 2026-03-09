<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;
use \Database;

/**
 * 김포시 공고(고시/공고) 크롤러
 * 목록: https://www.gimpo.go.kr/portal/ntfcPblancList.do?key=1004&cate_cd=1&searchCnd=40900000000
 * 상세: https://www.gimpo.go.kr/portal/ntfcPblancView.do?key=1004&not_ancmt_mgt_no=73137&pageIndex=1&searchCnd=40900000000&cate_cd=1
 *
 * 마감일은 상세 본문에서 "접수기간: 2026.3.9.(월) ~ 3.27.(금)" 등 기간의 끝 날짜로 추출.
 */
class GimpoGosiCrawler implements NoticeCrawler
{
    private const BASE_URL  = 'https://www.gimpo.go.kr';
    private const LIST_PATH = '/portal/ntfcPblancList.do';
    private const SOURCE    = '김포시 고시공고';

    private const KEY = '1004';
    private const CATE_CD = '1';
    private const SEARCH_CND = '40900000000';

    private const TARGET_YEAR = 2026;
    private const MAX_PAGES = 120;
    private const CHUNK_SIZE = 10;
    private const RECENT_SKIP_DAYS = 7;
    private const CHUNK_DELAY_US = 100_000;

    private ?Database $db = null;

    public function __construct(
        private HttpClient $http,
        private HtmlParser $parser,
    ) {}

    public function setDatabase(Database $db): void
    {
        $this->db = $db;
    }

    public function getSourceName(): string
    {
        return self::SOURCE;
    }

    public function crawl(): array
    {
        $linkMap = $this->collectLinks();
        if ($linkMap === []) {
            return [];
        }

        $notices = [];
        $chunks = array_chunk($linkMap, self::CHUNK_SIZE, true);
        foreach ($chunks as $chunkMap) {
            $responses = $this->http->getMulti(array_keys($chunkMap));
            foreach ($responses as $url => $html) {
                if ($html === null) continue;
                $deadline = $this->extractDeadline($html);
                $meta = $chunkMap[$url];
                $notices[] = new Notice(
                    title:        $meta['title'],
                    url:          $url,
                    source:       self::SOURCE,
                    orgName:      $meta['dept'],
                    deadlineDate: $deadline,
                    budget:       '-',
                    budgetRaw:    0,
                );
            }
            usleep(self::CHUNK_DELAY_US);
        }

        return $notices;
    }

    /**
     * @return array<string, array{title:string,dept:string,regDate:string}>
     */
    private function collectLinks(): array
    {
        $linkMap = [];
        $cutoff = $this->db !== null ? new \DateTime('-' . self::RECENT_SKIP_DAYS . ' days') : null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $listUrl = self::BASE_URL . self::LIST_PATH . '?' . http_build_query([
                'key'       => self::KEY,
                'cate_cd'   => self::CATE_CD,
                'searchCnd' => self::SEARCH_CND,
                'pageIndex' => $page,
            ]);

            $html = $this->http->get($listUrl);
            if ($html === null) break;
            if (!$this->parser->loadHtml($html)) break;
            $xpath = $this->parser->getXPath();
            if ($xpath === null) break;

            $rows = $xpath->query("//tbody/tr[.//a[contains(@href,'ntfcPblancView.do')]]");
            if ($rows === false || $rows->length === 0) break;

            $hasTargetYearOnPage = false;
            $pageMap = [];
            $pageTitles = [];

            foreach ($rows as $row) {
                $tds = $xpath->query('./td', $row);
                if ($tds === false || $tds->length < 5) continue;

                $regDateStr = trim(HtmlParser::getTextContent($tds->item(4)));
                if ($regDateStr === '') continue;
                $year = (int)substr($regDateStr, 0, 4);
                if ($year !== self::TARGET_YEAR) continue;
                $hasTargetYearOnPage = true;

                $a = $xpath->query('.//a[contains(@href,"ntfcPblancView.do")]', $tds->item(2))->item(0);
                if ($a === null) continue;

                $title = trim(preg_replace('/\s+/u', ' ', HtmlParser::getTextContent($a)) ?? '');
                if ($title === '') continue;

                // href는 ./ntfcPblancView.do?... 형태
                $href = HtmlParser::getHref($a, self::BASE_URL . '/portal');
                $href = str_replace('/./', '/', $href);
                if ($href === '') continue;

                $dept = trim(HtmlParser::getTextContent($tds->item(3)));
                if ($dept === '') $dept = '김포시';

                $meta = ['title' => $title, 'dept' => $dept, 'regDate' => $regDateStr];
                $pageMap[$href] = $meta;
                $pageTitles[] = $title;
            }

            if (!$hasTargetYearOnPage) break;

            // 페이지 단위 조기 종료 (해당 페이지가 전부 이미 처리된 상태면 stop)
            if ($this->db !== null && $pageTitles !== []) {
                $info = $this->db->getExistingBidInfoByTitles($this->getSourceName(), $pageTitles);
                $pageFullyKnown = true;
                foreach ($pageMap as $url => $meta) {
                    $t = (string)$meta['title'];
                    $row = $info[$t] ?? null;
                    if (!is_array($row)) {
                        $pageFullyKnown = false;
                        $linkMap[$url] = $meta;
                        continue;
                    }

                    $dl = $row['deadline_date'] ?? null;
                    if ($dl !== null && $dl !== '' && $dl !== '1970-01-01') {
                        continue;
                    }
                    $fetchedAt = $row['fetched_at'] ?? null;
                    if ($cutoff !== null && is_string($fetchedAt) && $fetchedAt !== '') {
                        try {
                            if (new \DateTime($fetchedAt) >= $cutoff) {
                                continue;
                            }
                        } catch (\Exception) {}
                    }

                    $pageFullyKnown = false;
                    $linkMap[$url] = $meta;
                }

                if ($pageFullyKnown) {
                    break;
                }
            } else {
                foreach ($pageMap as $url => $meta) {
                    $linkMap[$url] = $meta;
                }
            }
        }

        return $linkMap;
    }

    private function extractDeadline(string $html): ?string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $html);
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim((string)$text));

        // 접수기간: 2026.3.9.(월) ~ 3.27.(금)
        if (preg_match(
            '/(?:접수기간|공고기간|모집기간|신청기간|기간)\s*[:：]?\s*(\d{4})\.\s*(\d{1,2})\.\s*(\d{1,2})\.\s*(?:\([^)]*\))?\s*[~～\-]\s*(\d{1,2})\.\s*(\d{1,2})/u',
            $text,
            $m
        )) {
            return $this->normalizeDate(sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[4], (int)$m[5]));
        }

        // 2026. 3. 9. ~ 2026. 3. 27.
        if (preg_match(
            '/(\d{4})\.\s*(\d{1,2})\.\s*(\d{1,2})\.\s*[~～\-]\s*(\d{4})\.\s*(\d{1,2})\.\s*(\d{1,2})\.?/u',
            $text,
            $m
        )) {
            return $this->normalizeDate(sprintf('%04d-%02d-%02d', (int)$m[4], (int)$m[5], (int)$m[6]));
        }

        // YYYY-MM-DD ~ YYYY-MM-DD
        if (preg_match('/(\d{4}-\d{1,2}-\d{1,2})\s*[~～\-]\s*(\d{4}-\d{1,2}-\d{1,2})/u', $text, $m)) {
            return $this->normalizeDate($m[2]);
        }

        // 2026년 3월 27일
        if (preg_match('/(\d{4})년\s*(\d{1,2})월\s*(\d{1,2})일/u', $text, $m)) {
            return $this->normalizeDate(sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]));
        }

        return null;
    }

    private function normalizeDate(string $dateStr): ?string
    {
        try {
            return (new \DateTime($dateStr))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}

