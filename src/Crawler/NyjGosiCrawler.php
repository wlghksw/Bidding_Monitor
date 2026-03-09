<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;
use \Database;

/**
 * 남양주시 고시공고 크롤러
 *
 * 목록: https://nyj.go.kr/www/selectEminwonWebList.do?key=2492&sa1=01&sa1=02&sa1=04&sa1=05&sc4=2024
 *
 * 목록 테이블에 "번호 / 고시공고 번호 / 제목 / 담당부서 / 공고일" 이 모두 있으므로
 * 목록만 보고 저장하고, 상세 페이지에서는 별도 추가 정보는 보지 않는다.
 */
class NyjGosiCrawler implements NoticeCrawler
{
    private const BASE_URL    = 'https://nyj.go.kr';
    private const LIST_PATH   = '/www/selectEminwonWebList.do';
    private const SOURCE      = '남양주시 고시공고';

    // key / sa1 / sc4 값은 현재 링크 그대로 사용
    private const MENU_KEY    = '2492';
    /** @var string[] */
    private const SA1_CODES   = ['01', '02', '04', '05'];
    private const SC4         = '2024';

    private const TARGET_YEAR = 2026;
    private const MAX_PAGES   = 200; // 10건 * 200페이지 = 2,000건까지 안전

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

    /**
     * @return Notice[]
     */
    public function crawl(): array
    {
        $rows = $this->collectRows();
        if ($rows === []) {
            return [];
        }

        // 상세 페이지에서 공고기간(끝 날짜)을 마감일로 추출
        $rows = $this->attachDeadlines($rows);

        $notices = [];
        foreach ($rows as $row) {
            $notices[] = new Notice(
                title:        $row['title'],
                url:          $row['url'],
                source:       self::SOURCE,
                orgName:      $row['dept'],
                deadlineDate: $row['deadline'],
                budget:       '-',
                budgetRaw:    0,
            );
        }

        return $notices;
    }

    /**
     * @return array<int, array{title:string,url:string,dept:string,regDate:string,deadline:?string}>
     */
    private function collectRows(): array
    {
        $result = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $query = 'key=' . self::MENU_KEY;
            foreach (self::SA1_CODES as $code) {
                $query .= '&sa1=' . urlencode($code);
            }
            $query .= '&sc4=' . urlencode(self::SC4);
            if ($page > 1) {
                // 페이지 번호 파라미터
                $query .= '&cpn=' . $page;
            }

            $listUrl = self::BASE_URL . self::LIST_PATH . '?' . $query;

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

            // 고시공고 목록 테이블의 행
            $rows = $xpath->query('//table[contains(@class,"p-table") and contains(@class,"simple")]//tbody/tr');
            if ($rows === false || $rows->length === 0) {
                break;
            }

            $foundOnPage = 0;
            $stopPaging  = false;

            foreach ($rows as $row) {
                $tds = $xpath->query('./td', $row);
                if ($tds === false || $tds->length < 4) {
                    continue;
                }

                // ── 제목 / 상세 URL (두 번째 td 안의 a) ──
                $titleTd = $tds->item(1);
                $a       = $xpath->query('.//a', $titleTd)->item(0);
                if ($a === null) {
                    continue;
                }
                $title = HtmlParser::getTextContent($a);
                $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
                if ($title === '') {
                    continue;
                }

                $url = HtmlParser::getHref($a, self::BASE_URL . '/www');
                if ($url === '') {
                    continue;
                }

                // ── 담당부서 (세 번째 td) ──
                $dept = HtmlParser::getTextContent($tds->item(2));
                if ($dept === '') {
                    $dept = '남양주시';
                }

                // ── 공고일 (네 번째 td, YYYY-MM-DD) ──
                $regDateStr = HtmlParser::getTextContent($tds->item(3));
                $regDate    = null;
                if ($regDateStr !== '') {
                    try {
                        $reg = new \DateTime($regDateStr);
                        $year = (int)$reg->format('Y');
                        if ($year < self::TARGET_YEAR) {
                            // 2026 이전 연도를 만나면 이후 페이지는 더 오래된 데이터이므로 중단
                            $stopPaging = true;
                            break;
                        }
                        if ($year > self::TARGET_YEAR) {
                            // 미래 연도(안 나올 가능성이 높지만)라면 스킵
                            continue;
                        }
                        $regDate = $reg->format('Y-m-d');
                    } catch (\Exception) {
                        continue;
                    }
                }

                if ($regDate === null) {
                    continue;
                }

                $result[] = [
                    'title'    => $title,
                    'url'      => $url,
                    'dept'     => $dept,
                    'regDate'  => $regDate,
                    'deadline' => null,
                ];
                $foundOnPage++;
            }

            if ($stopPaging || $foundOnPage === 0) {
                break;
            }
        }

        return $result;
    }

    /**
     * 상세 페이지에서 공고기간을 읽어와 deadline 필드를 채운다.
     *
     * @param array<int, array{title:string,url:string,dept:string,regDate:string,deadline:?string}> $rows
     * @return array<int, array{title:string,url:string,dept:string,regDate:string,deadline:?string}>
     */
    private function attachDeadlines(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        // URL 기준으로 인덱스 맵 구성
        $indexByUrl = [];
        foreach ($rows as $idx => $row) {
            $indexByUrl[$row['url']][] = $idx;
        }

        $urls   = array_keys($indexByUrl);
        $chunks = array_chunk($urls, 20);

        foreach ($chunks as $chunk) {
            $responses = $this->http->getMulti($chunk);
            foreach ($responses as $url => $html) {
                if ($html === null) {
                    continue;
                }
                $deadline = $this->extractDeadline($html);
                if ($deadline === null) {
                    continue;
                }
                foreach ($indexByUrl[$url] as $idx) {
                    $rows[$idx]['deadline'] = $deadline;
                }
            }
        }

        return $rows;
    }

    private function extractDeadline(string $html): ?string
    {
        if (!$this->parser->loadHtml($html)) {
            return null;
        }
        $xpath = $this->parser->getXPath();
        if ($xpath === null) {
            return null;
        }

        // 상세 테이블에서 "공고기간" 행의 첫 번째 td
        $td = $xpath
            ->query('//tr[th[contains(normalize-space(.),"공고기간")]]/td[1]')
            ?->item(0);

        if ($td === null) {
            return null;
        }

        $text = HtmlParser::getTextContent($td); // 예: "2026-03-10 ~ 2026-03-24"
        if ($text === '') {
            return null;
        }
        $textNorm = preg_replace('/\s+/u', ' ', $text);

        // 1) "YYYY-MM-DD ~ YYYY-MM-DD" 패턴
        if (preg_match(
            '/(\d{4})-(\d{1,2})-(\d{1,2})\s*~\s*(\d{4})-(\d{1,2})-(\d{1,2})/u',
            $textNorm,
            $m
        )) {
            return sprintf('%04d-%02d-%02d', (int)$m[4], (int)$m[5], (int)$m[6]);
        }

        // 2) 날짜가 한 개만 있는 경우 → 그 날짜를 마감일로 사용
        if (preg_match_all('/(\d{4})-(\d{1,2})-(\d{1,2})/u', $textNorm, $mm)) {
            $last = count($mm[0]) > 0 ? count($mm[0]) - 1 : null;
            if ($last !== null) {
                return sprintf('%04d-%02d-%02d', (int)$mm[1][$last], (int)$mm[2][$last], (int)$mm[3][$last]);
            }
        }

        return null;
    }
}

