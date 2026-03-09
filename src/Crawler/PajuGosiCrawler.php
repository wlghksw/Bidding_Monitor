<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;
use \Database;

class PajuGosiCrawler implements NoticeCrawler
{
    private const BASE_URL    = 'https://www.paju.go.kr';
    private const LIST_PATH   = '/user/board/BD_board.list.do';
    private const SOURCE      = '파주시 고시공고';
    private const BBS_CD      = '1022';
    private const CTG_CD      = '4063';
    private const TARGET_YEAR = 2026; // 참고용 (현재 필터에는 사용하지 않음)
    private const MAX_PAGES   = 80;

    private ?Database $db = null;

    public function __construct(
        private HttpClient $http,
        private HtmlParser $parser,
    ) {}

    public function setDatabase(Database $db): void { $this->db = $db; }
    public function getSourceName(): string { return self::SOURCE; }

    public function crawl(): array
    {
        $rows = $this->collectRows();
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
        $result   = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $params = [
                'bbsCd'   => self::BBS_CD,
                'q_ctgCd' => self::CTG_CD,
            ];
            if ($page > 1) {
                // 실제 페이지네이션 파라미터는 q_currPage
                $params['q_currPage'] = $page;
            }
            $listUrl = self::BASE_URL . self::LIST_PATH . '?' . http_build_query($params);

            $html = $this->http->get($listUrl);
            if ($html === null) break;
            if (!$this->parser->loadHtml($html)) break;
            $xpath = $this->parser->getXPath();
            if ($xpath === null) break;

            $rows = $xpath->query('//table//tbody/tr[td[contains(@class,"cell-no")]]');
            if ($rows === false || $rows->length === 0) break;

            $foundOnPage = 0;

            foreach ($rows as $row) {
                $tds = $xpath->query('./td', $row);
                if ($tds === false || $tds->length < 5) continue;

                // ── 제목 ──
                $titleCell = null;
                foreach ($tds as $td) {
                    if ($td instanceof \DOMElement
                        && str_contains((string)$td->getAttribute('class'), 'cell-subject')) {
                        $titleCell = $td; break;
                    }
                }
                if ($titleCell === null) continue;

                $a = $xpath->query('.//a', $titleCell)->item(0);
                if ($a === null) continue;

                $title = HtmlParser::getTextContent($a);
                $title = preg_replace('/\s*N\s*$/u', '', trim(preg_replace('/\s+/u', ' ', $title) ?? ''));
                if ($title === '') continue;

                // ── URL ──
                $onclick = (string)$a->getAttribute('onclick');
                $bbsCd = self::BBS_CD; $seq = null;
                if (preg_match("/jsView\s*\(\s*'?(\d+)'?\s*,\s*'?(\d+)'?/", $onclick, $m)) {
                    $bbsCd = $m[1]; $seq = $m[2];
                }
                $url = self::BASE_URL . '/user/board/BD_board.view.do?bbsCd=' . urlencode($bbsCd);
                if ($seq !== null) $url .= '&seq=' . urlencode($seq);

                // ── 부서 ──
                $dept = '파주시';
                foreach ($tds as $td) {
                    if (!$td instanceof \DOMElement) continue;
                    $cls = (string)$td->getAttribute('class');
                    if (str_contains($cls, 'cell-default') && !str_contains($cls, 'cell-tit')) {
                        $dept = trim(HtmlParser::getTextContent($td)); break;
                    }
                }

                // ── 등록일 / 게재기간: cell-tit 인덱스 기반 ──
                $titCells = [];
                foreach ($tds as $td) {
                    if ($td instanceof \DOMElement
                        && str_contains((string)$td->getAttribute('class'), 'cell-tit')) {
                        $titCells[] = $td;
                    }
                }

                $regDate = null; $deadline = null;

                foreach ($titCells as $idx => $cell) {
                    $text = html_entity_decode(
                        strip_tags($cell->ownerDocument->saveHTML($cell)),
                        ENT_QUOTES | ENT_HTML5, 'UTF-8'
                    );
                    $text = preg_replace('/\s+/u', ' ', trim($text));

                    if ($idx === 0) {
                        // 등록일: 연도 제한 없이 그대로 저장
                        if (preg_match('/(\d{4})\/(\d{1,2})\/(\d{1,2})/', $text, $rm)) {
                            $year = (int)$rm[1];
                            $regDate = sprintf('%04d-%02d-%02d', $year, (int)$rm[2], (int)$rm[3]);
                        }
                    } elseif ($idx === 1) {
                        // 게재기간: 항상 뒷부분(종료일)이 마감일
                        if (preg_match(
                            '/(\d{4})\/(\d{1,2})\/(\d{1,2})\s*~\s*(\d{4})\/(\d{1,2})\/(\d{1,2})/',
                            $text, $pm
                        )) {
                            // 오른쪽 날짜 YYYY/MM/DD
                            $deadline = sprintf('%04d-%02d-%02d', (int)$pm[4], (int)$pm[5], (int)$pm[6]);
                        } elseif (preg_match(
                            '/(\d{4})\/(\d{1,2})\/(\d{1,2})\s*~\s*(\d{1,2})\/(\d{1,2})/',
                            $text, $pm
                        )) {
                            // 오른쪽이 MM/DD 형식이면, 앞쪽 연도를 그대로 사용
                            $deadline = sprintf('%04d-%02d-%02d', (int)$pm[1], (int)$pm[4], (int)$pm[5]);
                        }
                    }
                }

                if ($regDate === null) continue;

                $result[] = [
                    'title'    => $title,
                    'url'      => $url,
                    'dept'     => $dept,
                    'regDate'  => $regDate,
                    'deadline' => $deadline,
                ];
                $foundOnPage++;
            }

            if ($foundOnPage === 0) break;
        }

        return $result;
    }
}

