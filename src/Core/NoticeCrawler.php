<?php
declare(strict_types=1);

namespace BiddingMonitor\Core;

/**
 * 사이트별 크롤러 인터페이스
 * API / HTML 크롤러 모두 이 인터페이스 구현
 */
interface NoticeCrawler
{
    /** 수집 소스 표시명 (예: 나라장터, 영등포구청) */
    public function getSourceName(): string;

    /** 공고 목록 수집 (실패 시 빈 배열 또는 예외) */
    public function crawl(): array;
}
