<?php
declare(strict_types=1);

namespace BiddingMonitor\Core;

/**
 * 공고 데이터 DTO (통합 스키마)
 */
class Notice
{
    public function __construct(
        public string $title,
        public string $url,
        public string $source,
        public string $orgName = '',
        public ?string $deadlineDate = null,
        public string $budget = '-',
        public float $budgetRaw = 0.0,
    ) {}

    /** 마감일이 수집일(오늘)보다 앞선 경우 true (저장 제외 대상) */
    public function isExpired(): bool
    {
        if ($this->deadlineDate === null || $this->deadlineDate === '') {
            return false;
        }
        return strtotime($this->deadlineDate) < strtotime('today');
    }

    /** DB saveBid() 형식으로 변환 */
    public function toBidParams(): array
    {
        return [
            ':title'         => $this->title,
            ':url'           => $this->url,
            ':source'        => $this->source,
            ':org_name'      => $this->orgName,
            ':budget'        => $this->budget,
            ':budget_raw'    => $this->budgetRaw,
            ':deadline_date' => $this->deadlineDate,
        ];
    }
}
