<?php
declare(strict_types=1);

namespace BiddingMonitor\Core;

/** db.php 전역 클래스 (타입힌트 통일) */
use \Database;

/**
 * 크롤러 일괄 실행 및 DB 저장
 * API/HTML 크롤러를 동일하게 다룸
 */
class Runner
{
    private string $logPath;
    /** 마감 지난 공고도 저장할 소스 */
    private const SAVE_EXPIRED_SOURCES = ['강화군 고시공고', '수출바우처'];

    public function __construct(
        private Database $db,
        private HttpClient $http,
        private HtmlParser $parser,
    ) {
        $logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $this->logPath = $logDir . '/crawler.log';
    }

    /**
     * @param NoticeCrawler[] $crawlers
     * @return array<string, int> 소스별 저장 건수 (키워드 매칭 건수)
     */
    public function run(array $crawlers): array
    {
        if ($this->canRunParallel() && count($crawlers) > 1) {
            return $this->runParallel($crawlers);
        }
        return $this->runSequential($crawlers);
    }

    /** CLI + pcntl 사용 가능 시 병렬 실행 */
    private function canRunParallel(): bool
    {
        return php_sapi_name() === 'cli'
            && function_exists('pcntl_fork')
            && function_exists('pcntl_wait');
    }

    /**
     * @param NoticeCrawler[] $crawlers
     * @return array<string, int>
     */
    private function runParallel(array $crawlers): array
    {
        $results = array_fill_keys(array_map(fn ($c) => $c->getSourceName(), $crawlers), 0);
        $tempDir = sys_get_temp_dir() . '/bidding_crawler_' . getmypid();
        @mkdir($tempDir, 0755, true);
        $pids = [];

        for ($i = 0; $i < count($crawlers); $i++) {
            $resultFile = $tempDir . '/' . $i . '.txt';
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->log('pcntl_fork 실패, 순차 실행');
                return $this->runSequential($crawlers);
            }
            if ($pid === 0) {
                $sourceName = $crawlers[$i]->getSourceName();
                try {
                    $db = new Database();
                    $count = self::runOneCrawler($db, $crawlers[$i]);
                    file_put_contents($resultFile, (string)$count);
                } catch (\Throwable $e) {
                    file_put_contents($resultFile, '0');
                    $this->log("[{$sourceName}] " . $e->getMessage());
                }
                exit(0);
            }
            $pids[$i] = $pid;
        }

        foreach ($pids as $i => $pid) {
            pcntl_waitpid($pid, $status);
            $resultFile = $tempDir . '/' . $i . '.txt';
            if (is_file($resultFile)) {
                $results[$crawlers[$i]->getSourceName()] = (int)file_get_contents($resultFile);
                @unlink($resultFile);
            }
        }
        @rmdir($tempDir);
        return $results;
    }

    /**
     * @param NoticeCrawler[] $crawlers
     * @return array<string, int>
     */
    private function runSequential(array $crawlers): array
    {
        $results = [];

        foreach ($crawlers as $crawler) {
            $sourceName = $crawler->getSourceName();
            try {
                $count = self::runOneCrawler($this->db, $crawler);
                $results[$sourceName] = $count;
            } catch (\Throwable $e) {
                $msg = "[{$sourceName}] 크롤링 오류: " . $e->getMessage();
                trigger_error($msg, E_USER_WARNING);
                $this->log($msg);
                $results[$sourceName] = 0;
            }
        }
        return $results;
    }

    /** 크롤러 1개 실행 후 DB 저장, 키워드 매칭 건수 반환 */
    public static function runOneCrawler(Database $db, NoticeCrawler $crawler): int
    {
        $keywords = $db->getKeywords();
        $notices = $crawler->crawl();
        $saved = 0;
        foreach ($notices as $notice) {
            if ($notice->isExpired() && !in_array($crawler->getSourceName(), self::SAVE_EXPIRED_SOURCES, true)) {
                continue;
            }
            $params = $notice->toBidParams();
            $bidId = $db->saveBid($params);
            $saved++;
            $matched = self::matchKeywordsStatic($notice->title, $keywords);
            if ($matched !== []) {
                $db->saveBidKeywords($bidId, $matched);
            }
        }
        return $saved;
    }

    private static function matchKeywordsStatic(string $title, array $keywords): array
    {
        $matched = [];
        foreach ($keywords as $kw) {
            if (mb_strpos($title, $kw['keyword']) !== false) {
                $matched[] = (int)$kw['id'];
            }
        }
        return $matched;
    }

    private function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        @file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
