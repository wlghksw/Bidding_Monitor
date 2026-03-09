<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;
use \Database;

/**
 * 화성시 고시공고 크롤러
 * 목록: https://www.hscity.go.kr/www/gosi/BD_selectGosiList.do
 *
 * 목록 구조 (tbody > tr, 5열):
 *  td[0]=공고번호, td[1]=제목(javascript:opGosiView('id')), td[2]=담당부서, td[3]=게재일, td[4]=게재기간(YYYY-MM-DD ~ YYYY-MM-DD)
 *
 * 상세 URL은 목록의 opGosiView(id)에서 id를 추출해 BD_selectGosiDetail.do로 조합.
 */
class HsCityGosiCrawler implements NoticeCrawler
{
    private const BASE_URL    = 'https://www.hscity.go.kr';
    private const LIST_PATH   = '/www/gosi/BD_selectGosiList.do';
    private const DETAIL_PATH = '/www/gosi/BD_selectGosiDetail.do';
    private const SOURCE      = '화성시 고시공고';

    private const NOT_ANCMT_SE_CODE = '04';
    private const TARGET_YEAR = 2026;
    private const MAX_PAGES = 50;

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
        $notices = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $listUrl = self::BASE_URL . self::LIST_PATH . '?' . http_build_query([
                'q_notAncmtSeCode' => self::NOT_ANCMT_SE_CODE,
                'q_currPage'       => $page,
                'q_cp'             => $page,
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

            $rows = $xpath->query("//tbody/tr[.//a[contains(@href,'opGosiView')]]");
            if ($rows === false || $rows->length === 0) {
                break;
            }

            $hasTargetYearOnPage = false;
            $pageMap = []; // url => Notice

            foreach ($rows as $row) {
                $tds = $xpath->query('./td', $row);
                if ($tds === false || $tds->length < 5) {
                    continue;
                }

                $dateStr = trim(HtmlParser::getTextContent($tds->item(3)));
                if ($dateStr === '') {
                    continue;
                }
                $year = (int)substr($dateStr, 0, 4);
                if ($year !== self::TARGET_YEAR) {
                    continue;
                }
                $hasTargetYearOnPage = true;

                $a = $xpath->query('.//a', $tds->item(1))->item(0);
                if ($a === null) {
                    continue;
                }
                $title = trim(preg_replace('/\s+/u', ' ', HtmlParser::getTextContent($a)) ?? '');
                if ($title === '') {
                    continue;
                }

                $hrefAttr = $a->getAttribute('href');
                $mgtNo = null;
                if (preg_match("/opGosiView\\('?(\\d+)'?\\)/", $hrefAttr, $m)) {
                    $mgtNo = $m[1];
                }

                $detailUrl = $listUrl;
                if ($mgtNo !== null) {
                    $detailUrl = self::BASE_URL . self::DETAIL_PATH . '?' . http_build_query([
                        'q_notAncmtSeCode' => self::NOT_ANCMT_SE_CODE,
                        'q_notAncmtMgtNo'  => $mgtNo,
                        'q_currPage'       => $page,
                        'q_cp'             => $page,
                    ]);
                }

                $dept = trim(HtmlParser::getTextContent($tds->item(2)));

                $period = trim(HtmlParser::getTextContent($tds->item(4)));
                $deadlineDate = null;
                if (preg_match('/(\\d{4}-\\d{2}-\\d{2})\\s*~\\s*(\\d{4}-\\d{2}-\\d{2})/u', $period, $pm)) {
                    $deadlineDate = $pm[2];
                }

                $pageMap[$detailUrl] = new Notice(
                    title:        $title,
                    url:          $detailUrl,
                    source:       self::SOURCE,
                    orgName:      $dept !== '' ? $dept : '화성시',
                    deadlineDate: $deadlineDate,
                    budget:       '-',
                    budgetRaw:    0,
                );
            }

            if (!$hasTargetYearOnPage) {
                break;
            }

            // DB가 있으면 "이 페이지 전부 이미 처리됨"이면 여기서 종료 (URL 기반, 존재만으로 스킵)
            if ($this->db !== null && $pageMap !== []) {
                $info = $this->db->getExistingBidInfoByUrls($this->getSourceName(), array_keys($pageMap));
                $pageFullyKnown = true;
                foreach ($pageMap as $u => $notice) {
                    if (isset($info[$u])) {
                        continue;
                    }
                    $pageFullyKnown = false;
                    $notices[] = $notice;
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

