<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;

/**
 * 한국과학창의재단 입찰공고 크롤러
 * 목록 URL: https://www.kosac.re.kr/menus/275/boards/403/posts
 */
class KosacCrawler implements NoticeCrawler
{
    private const LIST_URL = 'https://www.kosac.re.kr/menus/275/boards/403/posts';

    public function __construct(
        private HttpClient $http,
        private HtmlParser $parser,
    ) {}

    public function getSourceName(): string
    {
        return '한국과학창의재단';
    }

    public function crawl(): array
    {
        $notices = [];

        // 1~3페이지까지 확인 (접수예정/접수중 공고가 3페이지까지 있을 수 있어서)
        for ($page = 1; $page <= 3; $page++) {
            $url = self::LIST_URL . ($page > 1 ? '?page=' . $page : '');
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

            $rows = $xpath->query("//table//tbody/tr");
            if ($rows === false || $rows->length === 0) {
                break;
            }

            foreach ($rows as $row) {
                $tds = $xpath->query('./td', $row);
                if ($tds === false || $tds->length < 2) {
                    continue;
                }

                // 두 번째 td 안에 '입찰공고' 텍스트와 G2B 링크가 함께 존재
                $titleCell = $tds->item(1);
                if ($titleCell === null) {
                    continue;
                }

                // 사전규격/입찰공고 구분 없이, 두 번째 칸의 첫 번째 링크를 사용
                $linkNode = $xpath->query('.//a', $titleCell)->item(0);
                if ($linkNode === null) {
                    continue;
                }

                $title = HtmlParser::getTextContent($linkNode);
                $title = trim(preg_replace('/\s+/u', ' ', $title));
                if ($title === '' || mb_strlen($title) < 5) {
                    continue;
                }

                $href = HtmlParser::getHref($linkNode);
                if ($href === '') {
                    continue;
                }

                // 접수상태: 세 번째 td 의 <em class="receipt ...">텍스트
                $statusOk = false;
                if ($tds->length >= 3) {
                    $statusNode = $xpath->query('.//em[contains(@class,"receipt")]', $tds->item(2))->item(0);
                    $statusText = $statusNode ? HtmlParser::getTextContent($statusNode) : '';
                    $statusText = trim($statusText);
                    if ($statusText === '접수예정' || $statusText === '접수중') {
                        $statusOk = true;
                    }
                }
                if (!$statusOk) {
                    // 접수예정/접수중이 아닌 건(접수마감 등)은 수집하지 않음
                    continue;
                }

                // 접수기간: 세 번째 td 의 "YYYY-MM-DD~YYYY-MM-DD" 형식
                $deadlineDate = null;
                if ($tds->length >= 3) {
                    $periodNode = $xpath->query('.//p[contains(@class,"period")]', $tds->item(2))->item(0);
                    $periodStr = $periodNode ? HtmlParser::getTextContent($periodNode) : HtmlParser::getTextContent($tds->item(2));
                    $periodStr = trim($periodStr);
                    if ($periodStr !== '' && str_contains($periodStr, '~')) {
                        [$start, $end] = array_map('trim', explode('~', $periodStr, 2));
                        $endNorm = str_replace('.', '-', $end);
                        try {
                            $d = new \DateTime($endNorm);
                            $deadlineDate = $d->format('Y-m-d');
                        } catch (\Exception) {
                            $deadlineDate = null;
                        }
                    }
                }

                $notices[] = new Notice(
                    title:        $title,
                    url:          $href,
                    source:       $this->getSourceName(),
                    orgName:      '한국과학창의재단',
                    deadlineDate: $deadlineDate,
                    budget:       '-',
                    budgetRaw:    0,
                );
            }
        }

        return $notices;
    }
}

