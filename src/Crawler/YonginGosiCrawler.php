<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;
use \Database;

/**
 * 용인시 고시공고 크롤러
 * 목록: https://www.yongin.go.kr/home/yiNw/yiNwStable/yiNwStable02/yiNwStable02_01.jsp
 *
 * 목록에 "게재기간 : 2026-03-10 ~ 2026-03-24" 형식의 기간이 있으므로,
 * 목록만으로 마감일(끝 날짜)을 저장하고, 별도 상세 페이지 요청은 하지 않는다.
 */
class YonginGosiCrawler implements NoticeCrawler
{
    // 실제 목록은 용인시 포털의 iframe 이 호출하는 액션 URL을 통해 로드된다.
    // 폼 action: https://eminwon.yongin.go.kr/emwp/gov/mogaha/ntis/web/ofr/action/OfrAction.do
    private const BASE_URL     = 'https://eminwon.yongin.go.kr';
    private const LIST_PATH    = '/emwp/gov/mogaha/ntis/web/ofr/action/OfrAction.do';
    // 사용자가 실제로 보는 목록 화면 (상세는 이 화면에서 searchDetail JS 로 열림)
    private const PUBLIC_LIST  = 'https://www.yongin.go.kr/home/yiNw/yiNwStable/yiNwStable02/yiNwStable02_01.jsp';
    private const SOURCE    = '용인시 고시공고';

    private const TARGET_YEAR      = 2026;
    private const MAX_PAGES        = 1;  // 필요 시 페이지 파라미터 파악 후 확장
    private const RECENT_SKIP_DAYS = 7;

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
        $rows = $this->collectRows();
        if ($rows === []) {
            return [];
        }

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
            // 원본 폼의 hidden 필드 + 목록 조회용 method/methodnm을 그대로 POST
            // list_gubun:
            //   ""  : 게재기간 중 데이터만
            //   "Y" : 게재기간 지난 데이터만
            //   "A" : 게재기간과 무관하게 전체
            $fields = [
                'epcCheck'          => 'Y',
                'pageIndex'         => (string)$page,
                'jndinm'            => 'OfrNotAncmtEJB',
                'context'           => 'NTIS',
                'method'            => 'selectListOfrNotAncmt',
                'methodnm'          => 'selectListOfrNotAncmtHomepage',
                'not_ancmt_mgt_no'  => '',
                'homepage_pbs_yn'   => 'Y',
                'jspPageName'       => 'OfrNotAncmtLSub.jsp',
                'subCheck'          => 'Y',
                'not_ancmt_se_code' => '01,04',
                'title'             => '고시공고',
                'cha_dep_code_nm'   => '',
                'initValue'         => '',
                'countYn'           => 'Y',
                // 연도 기준으로 2026년 이후 데이터만 가져오도록 설정
                'yyyy'              => (string)self::TARGET_YEAR,
                'yyyymmdd'          => '',
                'recent_mm'         => '',
                'last_mm'           => '',
                'nodate_recent_mm'  => '',
                'nodate_last_mm'    => '',
                'not_ancmt_sj'      => '',
                'not_ancmt_cn'      => '',
                'dept_nm'           => '',
                'cgg_code'          => '',
                'not_ancmt_reg_no'  => '',
                // 페이지당 게시글 수
                'ofr_pageSize'      => 100,
                // 검색 키/텍스트 (빈 값으로 전체)
                'Key'               => 'B_Subject',
                'temp'              => '',
            ];

            $listUrl = self::BASE_URL . self::LIST_PATH;
            $html = $this->http->postForm($listUrl, $fields);
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

            // boardDefalut 테이블의 tr (DOMDocument가 tbody를 생략할 수 있어 tbody에 고정하지 않음)
            $rows = $xpath->query('//table[contains(@class,"boardDefalut")]//tr[td]');
            if ($rows === false || $rows->length === 0) {
                break;
            }

            $foundOnPage = 0;
            $stopPaging  = false;

            foreach ($rows as $row) {
                $tds = $xpath->query('./td', $row);
                // 7개 컬럼 (번호|고시번호|제목|부서|등록일|게재기간|조회수)
                if ($tds === false || $tds->length < 7) {
                    continue;
                }

                // ── 제목 (3번째 td, index=2) ──
                $titleTd = $tds->item(2);
                $title = trim(HtmlParser::getTextContent($titleTd));
                if ($title === '') {
                    continue;
                }

                // 상세 URL은 사용자가 실제로 보는 목록 페이지로 통일
                $url = self::PUBLIC_LIST;

                // ── 부서 (4번째 td, index=3) ──
                $dept = trim(HtmlParser::getTextContent($tds->item(3)));
                if ($dept === '') {
                    $dept = '용인시';
                }

                // ── 등록일 (5번째 td, index=4) ──
                $regDateStr = trim(HtmlParser::getTextContent($tds->item(4)));
                $regDate = null;
                if ($regDateStr !== '') {
                    try {
                        $reg = new \DateTime($regDateStr);
                        $year = (int)$reg->format('Y');
                        if ($year < self::TARGET_YEAR) {
                            $stopPaging = true;
                            break;
                        }
                        if ($year > self::TARGET_YEAR) {
                            continue;
                        }
                        $regDate = $reg->format('Y-m-d');
                    } catch (\Exception) {
                        continue;
                    }
                }

                // ── 게재기간 (6번째 td, index=5) "2026-03-10 ~ 2026-03-24" ──
                $periodStr = trim(HtmlParser::getTextContent($tds->item(5)));
                $deadline  = null;
                if ($periodStr !== '') {
                    $periodNorm = preg_replace('/\s+/u', ' ', $periodStr);
                    if (preg_match(
                        '/(\\d{4})-(\\d{1,2})-(\\d{1,2})\\s*~\\s*(\\d{4})-(\\d{1,2})-(\\d{1,2})/',
                        $periodNorm,
                        $pm
                    )) {
                        $deadline = sprintf('%04d-%02d-%02d', (int)$pm[4], (int)$pm[5], (int)$pm[6]);
                    }
                }

                $result[] = [
                    'title'    => $title,
                    'url'      => $url,
                    'dept'     => $dept,
                    'regDate'  => $regDate ?? '',
                    'deadline' => $deadline,
                ];
                $foundOnPage++;
            }

            if ($stopPaging || $foundOnPage === 0) {
                break;
            }
        }

        return $result;
    }
}

