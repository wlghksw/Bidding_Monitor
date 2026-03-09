<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;
use \Database;

/**
 * 평택시 고시공고 크롤러 (새올)
 * 목록: https://www.pyeongtaek.go.kr/pyeongtaek/saeol/gosi/list.do?seCode=01&mid=0401020100
 * 상세: https://www.pyeongtaek.go.kr/pyeongtaek/saeol/gosi/view.do?notAncmtMgtNo=116900&mid=0401020100
 *
 * 목록의 제목 링크는 data-action에 상세 path를 가지며,
 * 상세의 게재기간(예: "2026-03-09 ~ 2026-03-24")에서 끝 날짜를 마감일로 저장.
 */
class PyeongtaekGosiCrawler implements NoticeCrawler
{
    private const BASE_URL  = 'https://www.pyeongtaek.go.kr';
    private const LIST_PATH = '/pyeongtaek/saeol/gosi/list.do';
    private const SOURCE    = '평택시 고시공고';

    private const SE_CODE = '01';
    private const M_ID    = '0401020100';

    private const TARGET_YEAR = 2026;
    private const MAX_PAGES   = 80;
    private const CHUNK_SIZE  = 10;
    private const RECENT_SKIP_DAYS = 7;
    private const CHUNK_DELAY_US   = 100_000;

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
                'mid'    => self::M_ID,
                'page'   => $page,
            ]);

            $html = $this->http->get($listUrl);
            if ($html === null) break;
            if (!$this->parser->loadHtml($html)) break;
            $xpath = $this->parser->getXPath();
            if ($xpath === null) break;

            $rows = $xpath->query('//tbody/tr');
            if ($rows === false || $rows->length === 0) break;

            $hasTargetYearOnPage = false;
            $pageMap = [];

            foreach ($rows as $row) {
                $tds = $xpath->query('./td', $row);
                if ($tds === false || $tds->length < 5) continue;

                // 등록일 (YYYY-MM-DD)
                $regDateStr = trim(HtmlParser::getTextContent($tds->item(4)));
                if ($regDateStr === '') continue;
                $year = (int)substr($regDateStr, 0, 4);
                if ($year !== self::TARGET_YEAR) continue;
                $hasTargetYearOnPage = true;

                // 제목 링크: td[2] a[data-action]
                $a = $xpath->query('.//a[@data-action]', $tds->item(2))->item(0);
                if ($a === null) continue;

                $title = HtmlParser::getTextContent($a);
                $title = preg_replace('/\s*새글\s*/u', ' ', $title) ?? $title;
                $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
                if ($title === '') continue;

                $dataAction = trim((string)$a->getAttribute('data-action'));
                if ($dataAction === '') continue;
                $detailUrl = str_starts_with($dataAction, 'http')
                    ? $dataAction
                    : self::BASE_URL . $dataAction;

                $dept = trim(HtmlParser::getTextContent($tds->item(3)));
                if ($dept === '') $dept = '평택시';

                $meta = [
                    'title'   => $title,
                    'dept'    => $dept,
                    'regDate' => $regDateStr,
                ];
                $pageMap[$detailUrl] = $meta;
            }

            if (!$hasTargetYearOnPage) break;

            // DB가 있으면 "이 페이지 전부 이미 처리됨"이면 여기서 종료 (URL 기반 존재 여부)
            if ($this->db !== null && $pageMap !== []) {
                $info = $this->db->getExistingBidInfoByUrls($this->getSourceName(), array_keys($pageMap));
                $pageFullyKnown = true;
                foreach ($pageMap as $detailUrl => $meta) {
                    if (isset($info[$detailUrl])) {
                        continue;
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
        // 게재기간이 들어 있는 li.view_date 또는 view_info 영역만 추출
        $body = $html;
        foreach ([
            '/<li[^>]+class=["\'][^"\']*view_date[^"\']*["\'][^>]*>(.*?)<\/li>/si',
            '/<div[^>]+class=["\'][^"\']*view_info[^"\']*["\'][^>]*>(.*?)<\/div>/si',
        ] as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $body = $m[1];
                break;
            }
        }

        $body = preg_replace('/<[^>]+>/', ' ', $body);
        $text = html_entity_decode((string)$body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim((string)$text));

        // "게재기간 : 2026-03-09 ~ 2026-03-24"
        if (preg_match('/게재기간[^0-9]*(\d{4}-\d{2}-\d{2})\s*[~～\-]\s*(\d{4}-\d{2}-\d{2})/u', $text, $m)) {
            return $this->normalizeDate($m[2]);
        }

        // 그냥 날짜 범위만 있는 경우 "2026-03-09 ~ 2026-03-24"
        if (preg_match('/(\d{4}-\d{2}-\d{2})\s*[~～\-]\s*(\d{4}-\d{2}-\d{2})/u', $text, $m)) {
            return $this->normalizeDate($m[2]);
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

