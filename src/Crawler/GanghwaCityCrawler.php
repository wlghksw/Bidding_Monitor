<?php
declare(strict_types=1);

namespace BiddingMonitor\Crawler;

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Notice;
use BiddingMonitor\Core\NoticeCrawler;
use \Database;

/**
 * 강화군 고시공고 크롤러
 * 목록: https://www.ganghwa.go.kr/open_content/main/eminwon/announce/eminwonList.do
 *
 * 전략:
 *  1단계) 목록 페이지를 순회하며 후보 링크 + 작성일 수집
 *  2단계) 작성일로 1차 필터 (너무 오래된 건 상세 접근 안 함)
 *  3단계) 상세 페이지에서 마감일 정규식 추출
 *  4단계) 마감일이 오늘 이후인 것만 저장
 */
class GanghwaCityCrawler implements NoticeCrawler
{
    private const BASE_URL    = 'https://www.ganghwa.go.kr';
    private const LIST_PATH   = '/open_content/main/eminwon/announce/eminwonList.do';
    private const SOURCE      = '강화군 고시공고';

    // 2026년 전체 수집을 위해 시작일 고정 (목록 파라미터)
    private const START_DATE  = '2025-12-02';
    private const TARGET_YEAR = 2026;

    // 페이지당 10건, 최대 페이지 수 (안전장치)
    private const MAX_PAGES   = 30;

    // 상세페이지 병렬 요청 청크 크기
    private const CHUNK_SIZE  = 10;
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
        // ── 1단계: 목록 순회 → 후보 링크 수집 ──
        $linkMap  = $this->collectLinks();
        if ($linkMap === []) {
            return [];
        }

        // ── 2단계: 상세페이지 병렬 요청 (청크 단위) ──
        $notices = [];

        // FIX: array_chunk를 linkMap 자체에 적용 → intersect_key 불필요
        $chunks  = array_chunk($linkMap, self::CHUNK_SIZE, true);

        $chunkCount = count($chunks);
        foreach ($chunks as $chunkMap) {
            $responses = $this->http->getMulti(array_keys($chunkMap));

            foreach ($responses as $url => $html) {
                if ($html === null) {
                    continue;
                }

                // ── 3단계: 상세페이지에서 마감일 추출 ──
                // 2026년 전부를 보고 싶어서, 여기서는 "필터"는 하지 않고
                // 마감일 정보를 참고용으로만 저장한다.
                $deadline = $this->extractDeadline($html);

                $meta      = $chunkMap[$url];
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

            // 청크 사이 딜레이 (서버 부하 방지)
            if ($chunkCount > 1) {
                usleep(self::CHUNK_DELAY_US);
            }
        }

        return $notices;
    }

    // ──────────────────────────────────────────────
    // 목록 페이지 순회 → [url => ['title', 'dept', 'regDate']]
    // ──────────────────────────────────────────────
    private function collectLinks(): array
    {
        $linkMap = [];
        $symd    = self::START_DATE;
        $cutoff = $this->db !== null ? new \DateTime('-' . self::RECENT_SKIP_DAYS . ' days') : null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            // FIX: announce_div의 쉼표가 http_build_query에서 %2C로 인코딩되는 문제 방지
            // → 쿼리 문자열 직접 조합
            $query = 'keyfield=title'
                . '&symd=' . $symd
                . '&announce_div=%2C01%2C02%2C04%2C05%2C06%2C07'
                . '&pgno=' . $page;
            $url   = self::BASE_URL . self::LIST_PATH . '?' . $query;

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

            // 테이블 행 파싱
            // 실제 구조: <table> tbody > tr : no | 공고번호 | 제목 | 담당부서 | 작성일 | 조회수
            // tbody 유무에 안전하게: eminwonDetail 링크가 있는 tr만 선택
            $rows = $xpath->query("//tr[.//a[contains(@href,'eminwonDetail.do')]]");
            if ($rows === false || $rows->length === 0) {
                break;
            }

            $hasTargetYearOnPage = false;
            $pageMap = [];

            foreach ($rows as $row) {
                $tds = $xpath->query('.//td', $row);
                if ($tds === false || $tds->length < 5) {
                    continue;
                }

                // 작성일 (5번째 td) - "YYYY-MM-DD"
                $regDateStr = trim(HtmlParser::getTextContent($tds->item(4)));
                $regDate = null;
                if ($regDateStr !== '') {
                    try {
                        $regDate = new \DateTime($regDateStr);
                    } catch (\Exception) {}
                }
                if (!($regDate instanceof \DateTime)) {
                    continue;
                }
                $year = (int)$regDate->format('Y');
                if ($year < self::TARGET_YEAR) {
                    continue;
                }
                if ($year > self::TARGET_YEAR) {
                    continue;
                }
                $hasTargetYearOnPage = true;

                // 제목 링크: td[2] 우선, 없으면 td[1] fallback
                $linkNode = $xpath->query('.//a', $tds->item(2))->item(0);
                if ($linkNode === null) {
                    $linkNode = $xpath->query('.//a', $tds->item(1))->item(0);
                }
                if ($linkNode === null) {
                    continue;
                }

                $title = HtmlParser::getTextContent($linkNode);
                $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
                if ($title === '') {
                    continue;
                }

                $href = HtmlParser::getHref($linkNode, self::BASE_URL);
                if ($href === '') {
                    continue;
                }

                // 담당부서 (4번째 td)
                $dept = HtmlParser::getTextContent($tds->item(3));

                $pageMap[$href] = [
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
                foreach ($pageMap as $href => $meta) {
                    $row = $info[$href] ?? null;
                    if (!is_array($row)) {
                        $pageFullyKnown = false;
                        $linkMap[$href] = $meta;
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

                    // 마감일이 비어있고 최근 재시도도 아님 → 상세 필요
                    $pageFullyKnown = false;
                    $linkMap[$href] = $meta;
                }

                if ($pageFullyKnown) {
                    break;
                }
            } else {
                foreach ($pageMap as $href => $meta) {
                    $linkMap[$href] = $meta;
                }
            }

            $nextLink = $xpath->query("//a[contains(@href,'pgno=" . ($page + 1) . "')]");
            if ($nextLink === false || $nextLink->length === 0) {
                break;
            }
        }

        return $linkMap;
    }

    // ──────────────────────────────────────────────
    // 상세 페이지 HTML에서 마감일 추출
    // FIX: 본문 영역 먼저 잘라낸 뒤 정규식 적용 → 네비/푸터 날짜 오파싱 방지
    // FIX: 키워드 있는 패턴을 1순위로 변경
    // ──────────────────────────────────────────────
    private function extractDeadline(string $html): ?string
    {
        // 본문 추출 (여러 패턴 시도)
        $body = $html;
        foreach ([
            '/<div[^>]+class=["\'][^"\']*con[^"\']*["\'][^>]*>(.*?)<\/div>/si',
            '/<div[^>]+id=["\']contents["\'][^>]*>(.*?)<\/div>/si',
            '/<div[^>]+class=["\'][^"\']*view[^"\']*["\'][^>]*>(.*?)<\/div>/si',
            '/<div[^>]+class=["\'][^"\']*board[^"\']*["\'][^>]*>(.*?)<\/div>/si',
            '/<td[^>]+class=["\'][^"\']*content[^"\']*["\'][^>]*>(.*?)<\/td>/si',
            // 강화군 특정 구조 - table 기반 본문
            '/<table[^>]*>(.*?)<\/table>/si',
        ] as $pattern) {
            if (preg_match($pattern, $html, $bm)) {
                $body = $bm[1];
                break;
            }
        }

        // 태그 제거 후 HTML 엔티티 복원 → 순수 텍스트에서 패턴 검색 (태그가 날짜 사이에 끼어도 OK)
        $body = preg_replace('/<[^>]+>/', ' ', $body);
        $text = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim((string)$text));

        // 0순위: "열람 및 공고기간: 2026. 2. 9.(월) ~ 2026. 2. 23.(월) [15일간]" 등 (끝에 연도 있음)
        if (preg_match(
            '/(\d{4})[.\s\x{00A0}]+(\d{1,2})[.\s\x{00A0}]+(\d{1,2})\s*(?:\([^)]*\))?\s*(?:\d{1,2}:\d{2})?\s*[~～\-]\s*(\d{4})[.\s\x{00A0}]+(\d{1,2})[.\s\x{00A0}]+(\d{1,2})/u',
            $text,
            $m
        )) {
            $endStr = sprintf('%04d-%02d-%02d', (int)$m[4], (int)$m[5], (int)$m[6]);
            return $this->normalizeDate($endStr);
        }

        // 0-2: "접수기간: 2026.3.4.(수) ~ 10.30.(금) 09:00 ~ 18:00" 처럼 끝 날짜에 연도 생략 (시작 연도 사용)
        if (preg_match(
            '/(?:접수기간|모집기간|공고기간|신청기간|열람[^\d]*공고기간)[^\d]{0,20}(\d{4})[.\s\x{00A0}]+(\d{1,2})[.\s\x{00A0}]+(\d{1,2})\s*\([^)]*\)\s*[~～\-]\s*(\d{1,2})[.\s\x{00A0}]+(\d{1,2})\s*\([^)]*\)/u',
            $text,
            $m
        )) {
            $y = (int)$m[1];
            $endStr = sprintf('%04d-%02d-%02d', $y, (int)$m[4], (int)$m[5]);
            return $this->normalizeDate($endStr);
        }

        // 1순위: 키워드 + 날짜 범위 (YYYY-MM-DD ~ YYYY-MM-DD)
        if (preg_match(
            '/(?:접수기간|모집기간|공고기간|신청기간|사업기간|공모기간)[^\d]{0,30}(\d{4}[-\.]\d{2}[-\.]\d{2})\s*[~～\-]\s*(\d{4}[-\.]\d{2}[-\.]\d{2})/u',
            $text,
            $m
        )) {
            return $this->normalizeDate(str_replace('.', '-', $m[2]));
        }

        // 2순위: 마감일/접수마감 키워드 + 단독 날짜
        if (preg_match(
            '/(?:마감일|접수마감|모집마감|신청마감)[^\d]{0,20}(\d{4}[-\.]\d{2}[-\.]\d{2})/u',
            $text,
            $m
        )) {
            return $this->normalizeDate(str_replace('.', '-', $m[1]));
        }

        // 2-2: 한글 날짜 "2026년 3월 31일"
        if (preg_match(
            '/(\d{4})년\s*(\d{1,2})월\s*(\d{1,2})일/u',
            $text,
            $m
        )) {
            $endStr = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
            return $this->normalizeDate($endStr);
        }

        // 2-3: "~ 2026. 3. 31." 처럼 끝 날짜만 있는 경우
        if (preg_match(
            '/[~～]\s*(\d{4})[.\s\x{00A0}]+(\d{1,2})[.\s\x{00A0}]+(\d{1,2})/u',
            $text,
            $m
        )) {
            $endStr = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
            return $this->normalizeDate($endStr);
        }

        // 3순위: 키워드 없는 날짜 범위 (본문 한정)
        if (preg_match(
            '/(\d{4}[-\.]\d{2}[-\.]\d{2})\s*[~～]\s*(\d{4}[-\.]\d{2}[-\.]\d{2})/u',
            $text,
            $m
        )) {
            return $this->normalizeDate(str_replace('.', '-', $m[2]));
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
