<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;

/**
 * 수출바우처(수출지원기반활용사업) 공지사항 크롤러
 * 목록 URL: https://www.exportvoucher.com/portal/board/boardList?bbs_id=1
 */
class ExportVoucherCrawler implements NoticeCrawler
{
    private const LIST_URL = 'https://www.exportvoucher.com/portal/board/boardList?bbs_id=1';
    private const BASE_URL = 'https://www.exportvoucher.com';

    // 페이지당 건수 (사이트 form 필드명: pageUnit)
    private const PAGE_UNIT = 50;

    // 최대 페이지 안전장치
    private const MAX_PAGES = 30;

    public function __construct(
        private HttpClient $http,
        private HtmlParser $parser,
    ) {}

    public function getSourceName(): string
    {
        return '수출바우처';
    }

    public function crawl(): array
    {
        $notices = [];
        $seenSeq = [];

        for ($pageNo = 1; $pageNo <= self::MAX_PAGES; $pageNo++) {
            $listUrl = self::LIST_URL
                . '&pageNo=' . $pageNo
                . '&pageUnit=' . self::PAGE_UNIT;

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

            // 공지사항 테이블: 헤더에 '제목' 텍스트를 포함
            $rows = $xpath->query("//table[.//th[contains(normalize-space(.),'제목')]]//tbody/tr");
            if ($rows === false || $rows->length === 0) {
                break;
            }

            foreach ($rows as $row) {
                $tds = $xpath->query('./td', $row);
                if ($tds === false || $tds->length < 4) {
                    continue;
                }

                $isPinned = false;
                $pinImg   = $xpath->query('.//img', $tds->item(0));
                if ($pinImg !== false && $pinImg->length > 0) {
                    $isPinned = true;
                }

                // 제목/링크는 2번째 td
                $titleNode = $xpath->query('.//a', $tds->item(1))->item(0);
                if ($titleNode === null) {
                    continue;
                }
                $title = HtmlParser::getTextContent($titleNode);
                $title = trim(preg_replace('/\s+/u', ' ', $title));
                if ($title === '' || mb_strlen($title) < 2) {
                    continue;
                }

                // 상세 URL: onclick="goDetail(12345)" 형태에서 seq를 뽑아 URL 조합
                $href = '';
                $seq  = null;
                $onClick = (string)$titleNode->getAttribute('onclick');
                if ($onClick !== '' && preg_match('/goDetail\((\d+)\)/', $onClick, $m)) {
                    $seq = (int)$m[1];
                } else {
                    // 혹시 href에 query가 붙는 케이스가 있을 수 있어 fallback
                    $maybeHref = HtmlParser::getHref($titleNode, self::BASE_URL);
                    if ($maybeHref !== '' && !str_starts_with($maybeHref, 'javascript')) {
                        $href = $maybeHref;
                    }
                }

                if ($seq !== null) {
                    if (isset($seenSeq[$seq])) {
                        continue; // 공지(고정) 등이 페이지마다 반복 노출됨
                    }
                    $seenSeq[$seq] = true;
                    $href = self::BASE_URL . '/portal/board/boardView?bbs_id=1&ntt_id=' . $seq;
                }

                if ($href === '') {
                    $href = $listUrl;
                }

                // 등록일: "YYYY-MM-DD" 형식 (공지사항 리스트 3번째 열)
                $dateStr = HtmlParser::getTextContent($tds->item(2));
                $dateStr = trim($dateStr);
                $deadlineDate = null;
                if ($dateStr !== '') {
                    $dateNorm = str_replace('.', '-', $dateStr);
                    try {
                        $regDate = new \DateTime($dateNorm);
                        // 공고일 기준 +30일을 마감일로 추정
                        $deadline = clone $regDate;
                        $deadline->modify('+30 days');
                        $deadlineDate = $deadline->format('Y-m-d');
                    } catch (\Exception) {
                        $deadlineDate = null;
                    }
                }

                $notices[] = new Notice(
                    title:        $title,
                    url:          $href,
                    source:       $this->getSourceName(),
                    orgName:      'KOTRA',
                    deadlineDate: $deadlineDate,
                    budget:       '-',
                    budgetRaw:    0,
                );
            }

            // 다음 페이지로 계속 진행 (MAX_PAGES까지)
        }

        return $notices;
    }
}

