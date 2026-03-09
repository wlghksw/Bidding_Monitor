<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;
use \Database;

/**
 * ui4u(새올) 고시공고 크롤러 - 의정부시
 * 목록: https://www.ui4u.go.kr/portal/saeol/gosiList.do?seCode=01&mId=0301040000
 * 상세: https://www.ui4u.go.kr/portal/saeol/gosiView.do?notAncmtMgtNo=66894&mId=0301040000
 *
 * 목록의 제목 링크는 href="#" 이고 onclick="boardView('1','66894')" 형태이므로
 * notAncmtMgtNo(66894)를 추출해 상세 URL로 조합한다.
 *
 * 마감일은 상세 본문(view_cont)에서 "기간: 2026. 3. 9. ~ 2026. 3. 25." 같은 텍스트로 추출한다.
 */
class Ui4uGosiCrawler implements NoticeCrawler
{
    private const BASE_URL  = 'https://www.ui4u.go.kr';
    private const LIST_PATH = '/portal/saeol/gosiList.do';
    private const VIEW_PATH = '/portal/saeol/gosiView.do';
    private const SOURCE    = '의정부시 고시공고';

    private const M_ID = '0301040000';
    private const SE_CODE = '01';

    private const TARGET_YEAR = 2026;
    private const MAX_PAGES = 50;
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
        $chunkCount = count($chunks);
        foreach ($chunks as $chunkMap) {
            $responses = $this->http->getMulti(array_keys($chunkMap));
            foreach ($responses as $url => $html) {
                if ($html === null) {
                    continue;
                }
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
            if ($chunkCount > 1) {
                usleep(self::CHUNK_DELAY_US);
            }
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
                'seCode' => self::SE_CODE,
                'mId'    => self::M_ID,
                'page'   => $page,
            ]);

            $html = $this->http->get($listUrl);
            if ($html === null) {
                break;
            }
            if (!$this->parser->loadHtml($html)) {
                break;
            }
            $xpath = $this->parser->getXPath();
            if ($xpath === null) {
                break;
            }

            $rows = $xpath->query('//tbody/tr');
            if ($rows === false || $rows->length === 0) {
                break;
            }

            $hasTargetYearOnPage = false;
            $pageMap = [];

            foreach ($rows as $row) {
                $tds = $xpath->query('./td', $row);
                if ($tds === false || $tds->length < 5) {
                    continue;
                }

                $regDateStr = trim(HtmlParser::getTextContent($tds->item(4)));
                if ($regDateStr === '') {
                    continue;
                }
                $year = (int)substr($regDateStr, 0, 4);
                if ($year !== self::TARGET_YEAR) {
                    continue;
                }
                $hasTargetYearOnPage = true;

                $a = $xpath->query('.//a', $tds->item(2))->item(0);
                if ($a === null) {
                    continue;
                }

                $title = HtmlParser::getTextContent($a);
                $title = preg_replace('/\s*새글\s*/u', ' ', $title) ?? $title;
                $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
                if ($title === '') {
                    continue;
                }

                $onClick = (string)$a->getAttribute('onclick');
                $mgtNo = null;
                if ($onClick !== '' && preg_match("/boardView\\([^,]+,\\s*'?(\\d+)'?\\)/", $onClick, $m)) {
                    $mgtNo = $m[1];
                }
                if ($mgtNo === null) {
                    continue;
                }

                $detailUrl = self::BASE_URL . self::VIEW_PATH . '?' . http_build_query([
                    'notAncmtMgtNo' => $mgtNo,
                    'mId'           => self::M_ID,
                ]);

                $dept = trim(HtmlParser::getTextContent($tds->item(3)));
                if ($dept === '') {
                    $dept = '의정부시';
                }

                $pageMap[$detailUrl] = [
                    'title'   => $title,
                    'dept'    => $dept,
                    'regDate' => $regDateStr,
                ];
            }

            if (!$hasTargetYearOnPage) {
                break;
            }

            // DB가 있으면 "이 페이지 전부 이미 처리됨"이면 여기서 종료 (URL 기반)
            if ($this->db !== null && $pageMap !== []) {
                $info = $this->db->getExistingBidInfoByUrls($this->getSourceName(), array_keys($pageMap));
                $pageFullyKnown = true;
                foreach ($pageMap as $detailUrl => $meta) {
                    $row = $info[$detailUrl] ?? null;
                    if (!is_array($row)) {
                        $pageFullyKnown = false;
                        $linkMap[$detailUrl] = $meta;
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
                    $linkMap[$detailUrl] = $meta;
                }

                if ($pageFullyKnown) {
                    break;
                }
            } else {
                foreach ($pageMap as $detailUrl => $meta) {
                    $linkMap[$detailUrl] = $meta;
                }
            }
        }

        return $linkMap;
    }

    private function extractDeadline(string $html): ?string
    {
        // 본문(기간 문구 포함 영역) 추출
        $body = $html;
        foreach ([
            '/<div[^>]+class=["\'][^"\']*view_cont[^"\']*["\'][^>]*>(.*?)<\/div>/si',
            '/<div[^>]+class=["\'][^"\']*view[^"\']*["\'][^>]*>(.*?)<\/div>/si',
        ] as $pattern) {
            if (preg_match($pattern, $html, $bm)) {
                $body = $bm[1];
                break;
            }
        }

        $body = preg_replace('/<[^>]+>/', ' ', $body);
        $text = html_entity_decode((string)$body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim((string)$text));

        // 1) "2026. 3. 9. ~ 2026. 3. 25."
        if (preg_match(
            '/(\d{4})\.\s*(\d{1,2})\.\s*(\d{1,2})\.\s*[~～\-]\s*(\d{4})\.\s*(\d{1,2})\.\s*(\d{1,2})\.?/u',
            $text,
            $m
        )) {
            return $this->normalizeDate(sprintf('%04d-%02d-%02d', (int)$m[4], (int)$m[5], (int)$m[6]));
        }

        // 2) "2026. 3. 9. ~ 3. 25." (끝 연도 생략)
        if (preg_match(
            '/(\d{4})\.\s*(\d{1,2})\.\s*(\d{1,2})\.\s*[~～\-]\s*(\d{1,2})\.\s*(\d{1,2})\.?/u',
            $text,
            $m
        )) {
            return $this->normalizeDate(sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[4], (int)$m[5]));
        }

        // 3) "YYYY-MM-DD ~ YYYY-MM-DD"
        if (preg_match(
            '/(\d{4}-\d{1,2}-\d{1,2})\s*[~～\-]\s*(\d{4}-\d{1,2}-\d{1,2})/u',
            $text,
            $m
        )) {
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

