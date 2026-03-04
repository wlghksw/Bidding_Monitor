<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;

/**
 * 영등포구청 고시/공고 HTML 크롤러
 * 목록 URL: https://www.ydp.go.kr/www/selectBbsNttList.do?bbsNo=40&key=2848
 * 사이트 구조에 맞게 XPath/CSS selector 조정 필요
 */
class YdpGuCrawler implements NoticeCrawler
{
    private const LIST_URL = 'https://www.ydp.go.kr/www/selectBbsNttList.do?bbsNo=40&key=2848';
    // 페이지당 건수 50, 3페이지까지 = 최대 150건 수집
    private const MAX_PAGES = 3;
    private const KEEP_DAYS = 365; // 최근 1년치만 수집 (삭제 로직 기준과 맞춰 조정 가능)

    public function __construct(
        private HttpClient $http,
        private HtmlParser $parser,
    ) {}

    public function getSourceName(): string
    {
        return '영등포구청';
    }

    public function crawl(): array
    {
        $notices = [];
        // 실제 사이트 경로가 /www 하위이므로 baseUrl 에 /www 포함
        $baseUrl = 'https://www.ydp.go.kr/www';
        // fetch.php 의 삭제 규칙과 맞추기 위해,
        // 마감일 기준으로 "오늘 - 1개월" 이전 공고는 굳이 크롤링하지 않아도 되는 것으로 간주
        $deleteCutoff = new \DateTime('-1 month');

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            // 페이지당 건수 50으로 확장
            $url  = self::LIST_URL . '&pageUnit=50&pageIndex=' . $page;
            $html = $this->http->get($url);
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

            // 영등포구청 목록: 첫 번째 목록 table 의 tbody 만 대상으로 함
            $rows = $xpath->query("(//table//tbody)[1]/tr");
            if ($rows === false || $rows->length === 0) {
                break; // 더 이상 목록이 없으면 중단
            }

            $pageHasUseful = false; // 이 페이지에서 보존 대상 공고가 있었는지 여부

            foreach ($rows as $row) {
                // 2. 링크 XPath - selectBbsNttView.do 명시
                $link = $xpath->query(
                    './/a[contains(@href,"selectBbsNttView.do") or contains(@href,"nttNo")]',
                    $row
                )->item(0);
                if ($link === null) {
                    continue;
                }

                $title = HtmlParser::getTextContent($link);
                // NEW 배지 텍스트 제거 후 공백 정리 (단어 경계 기준)
                $title = preg_replace('/\bNEW\b/u', '', $title);
                $title = trim(preg_replace('/\s+/u', ' ', $title));
                if ($title === '' || mb_strlen($title) < 2) {
                    continue;
                }

                $href = HtmlParser::getHref($link, $baseUrl);
                $href = str_replace('/./', '/', $href);
                if ($href === '') {
                    continue;
                }

                // 3. 작성일을 목록 td에서 직접 파싱 (상세페이지 요청 불필요)
                // 테이블 컬럼 순서: 번호 | 제목 | 부서 | 작성일 | 조회수 | 파일
                $regDate = null;
                $tds = $xpath->query('.//td', $row);
                if ($tds !== false && $tds->length >= 4) {
                    $dateStr = HtmlParser::getTextContent($tds->item(3)); // 4번째 td = 작성일
                    $dateStr = trim(str_replace('.', '-', $dateStr));
                    if ($dateStr !== '') {
                        try {
                            $regDate = new \DateTime($dateStr);
                        } catch (\Exception) {
                        }
                    }
                }

                $deadlineDate = null;
                if ($regDate instanceof \DateTime) {
                    // 영등포구청은 별도 마감일이 없으므로, 작성일 + 30일을 마감일로 추정
                    $deadline = clone $regDate;
                    $deadline->modify('+30 days');

                    // fetch.php 의 삭제 조건:
                    // deadline_date < (오늘 - 1개월) 인 데이터는 결국 삭제 대상이므로,
                    // 그런 공고만 있는 페이지 이후는 더 크롤링하지 않아도 됨.
                    if ($deadline < $deleteCutoff) {
                        continue; // 이 행은 저장하지 않음
                    }

                    $deadlineDate = $deadline->format('Y-m-d');
                    $pageHasUseful = true;
                }

                $notices[] = new Notice(
                    title:        $title,
                    url:          $href,
                    source:       $this->getSourceName(),
                    orgName:      '영등포구청',
                    deadlineDate: $deadlineDate,
                    budget:       '-',
                    budgetRaw:    0,
                );
            }

            // 이 페이지에서 보존 대상 공고가 하나도 없었다면,
            // 이후 페이지는 더 오래된 공고들만 있을 것이므로 크롤링 중단
            if (!$pageHasUseful) {
                break;
            }
        }

        if (php_sapi_name() === 'cli') {
            echo "[영등포구청] 최종 Notice " . count($notices) . "건 생성\n";
        }

        return $notices;
    }
}
