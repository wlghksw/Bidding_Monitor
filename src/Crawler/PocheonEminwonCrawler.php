<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;
use \Database;

/**
 * 포천시 고시공고(이민원) 크롤러
 * 목록: https://www.pocheon.go.kr/www/selectEminwonList.do
 * 상세: https://www.pocheon.go.kr/www/selectEminwonView.do?...&notAncmtMgtNo=...
 *
 * 상세 본문에 "고시기간: 2026. 3. 9.~3. 23." 같은 텍스트가 있어 끝 날짜를 마감일로 저장.
 */
class PocheonEminwonCrawler implements NoticeCrawler
{
    private const BASE_URL  = 'https://www.pocheon.go.kr';
    private const LIST_PATH = '/www/selectEminwonList.do';
    private const SOURCE    = '포천시 고시공고';

    // 목록 기본 파라미터 (포천시: key=12563, notAncmtSeCode=01)
    private const KEY = '12563';
    private const NOT_ANCMT_SE_CODE = '01';

    private const TARGET_YEAR = 2026;
    private const PAGE_UNIT = 50;
    private const MAX_PAGES = 300;
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
                'key'           => self::KEY,
                'notAncmtSeCode' => self::NOT_ANCMT_SE_CODE,
                'searchCnd'      => 'notAncmtSj',
                'searchKrwd'     => '',
                'pageUnit'       => self::PAGE_UNIT,
                'pageIndex'      => $page,
            ]);

            $html = $this->http->get($listUrl);
            if ($html === null) break;
            if (!$this->parser->loadHtml($html)) break;
            $xpath = $this->parser->getXPath();
            if ($xpath === null) break;

            $rows = $xpath->query("//tr[.//a[contains(@href,'selectEminwonView.do')]]");
            if ($rows === false || $rows->length === 0) break;

            $hasTargetYearOnPage = false;
            $hasOlderThanTargetOnPage = false;
            $pageMap = [];
            $pageTitles = [];

            foreach ($rows as $row) {
                $tds = $xpath->query('./td', $row);
                if ($tds === false || $tds->length < 4) continue;

                $regDateStr = trim(HtmlParser::getTextContent($tds->item(3)));
                if ($regDateStr === '') continue;

                $year = (int)substr($regDateStr, 0, 4);
                if ($year > self::TARGET_YEAR) continue;
                if ($year < self::TARGET_YEAR) {
                    $hasOlderThanTargetOnPage = true;
                    continue;
                }

                $hasTargetYearOnPage = true;

                $a = $xpath->query('.//a[contains(@href,"selectEminwonView.do")]', $tds->item(1))->item(0);
                if ($a === null) continue;

                $title = trim(preg_replace('/\s+/u', ' ', HtmlParser::getTextContent($a)) ?? '');
                if ($title === '') continue;

                $href = HtmlParser::getHref($a, self::BASE_URL . '/www');
                $href = str_replace('/./', '/', $href);
                if ($href === '') continue;

                $dept = trim(HtmlParser::getTextContent($tds->item(2)));
                if ($dept === '') $dept = '포천시';

                $meta = [
                    'title'   => $title,
                    'dept'    => $dept,
                    'regDate' => $regDateStr,
                ];
                $pageMap[$href] = $meta;
                $pageTitles[] = $title;
            }

            if (!$hasTargetYearOnPage) break;

            // DB가 있으면 "이 페이지 전부 이미 처리됨"이면 여기서 종료 (목록이 최신순 정렬)
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

            if ($hasOlderThanTargetOnPage) break;
        }

        return $linkMap;
    }

    private function extractDeadline(string $html): ?string
    {
        // 본문에서 기간 문자열을 뽑기 위해 텍스트화
        $text = preg_replace('/<[^>]+>/', ' ', $html);
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim((string)$text));

        // 1) "2026. 3. 9.(월) ~ 2026. 3. 23.(월)" / "2026.3.9.~2026.3.23."
        if (preg_match(
            '/(\d{4})[.\s\x{00A0}]+(\d{1,2})[.\s\x{00A0}]+(\d{1,2})\s*\.?\s*(?:\([^)]*\))?\s*[~～\-]\s*(\d{4})[.\s\x{00A0}]+(\d{1,2})[.\s\x{00A0}]+(\d{1,2})/u',
            $text,
            $m
        )) {
            return $this->normalizeDate(sprintf('%04d-%02d-%02d', (int)$m[4], (int)$m[5], (int)$m[6]));
        }

        // 2) "2026. 3. 9.~3. 23." (끝 연도 생략)
        if (preg_match(
            '/(\d{4})[.\s\x{00A0}]+(\d{1,2})[.\s\x{00A0}]+(\d{1,2})\s*\.?\s*(?:\([^)]*\))?\s*[~～\-]\s*(\d{1,2})[.\s\x{00A0}]+(\d{1,2})/u',
            $text,
            $m
        )) {
            return $this->normalizeDate(sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[4], (int)$m[5]));
        }

        // 3) "YYYY-MM-DD ~ YYYY-MM-DD"
        if (preg_match('/(\d{4}-\d{1,2}-\d{1,2})\s*[~～\-]\s*(\d{4}-\d{1,2}-\d{1,2})/u', $text, $m)) {
            return $this->normalizeDate($m[2]);
        }

        // 4) 한글 날짜 "2026년 3월 31일"
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

