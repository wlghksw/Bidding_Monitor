<?php
// =============================================
// fetch.php - 수동/자동(cron) 수집 스크립트
// cron 설정: 0 9 * * * php /var/www/html/bid_monitor/fetch.php
// =============================================

set_time_limit(600); // 수집 시간 10분까지 허용 (50+150 페이지 API 호출)

require_once 'config.php';
require_once 'db.php';
require_once __DIR__ . '/src/autoload.php';

use BiddingMonitor\Core\HttpClient;
use BiddingMonitor\Core\HtmlParser;
use BiddingMonitor\Core\Runner;
use BiddingMonitor\Crawler\NaraJangteoCrawler;
use BiddingMonitor\Crawler\KStartupCrawler;
use BiddingMonitor\Crawler\Smes24Crawler;
use BiddingMonitor\Crawler\BizInfoCrawler;
use BiddingMonitor\Crawler\YdpGuCrawler;
use BiddingMonitor\Crawler\GanghwaCityCrawler;
use BiddingMonitor\Crawler\ExportVoucherCrawler;
use BiddingMonitor\Crawler\KosacCrawler;
use BiddingMonitor\Crawler\HsCityGosiCrawler;
use BiddingMonitor\Crawler\Ui4uGosiCrawler;
use BiddingMonitor\Crawler\GjCityGosiCrawler;
use BiddingMonitor\Crawler\SiheungGosiCrawler;
use BiddingMonitor\Crawler\PocheonEminwonCrawler;
use BiddingMonitor\Crawler\GimpoGosiCrawler;
use BiddingMonitor\Crawler\PyeongtaekGosiCrawler;
use BiddingMonitor\Crawler\PajuGosiCrawler;
use BiddingMonitor\Crawler\OsanGosiCrawler;
use BiddingMonitor\Crawler\YonginGosiCrawler;

$isCli    = php_sapi_name() === 'cli';
$isManual = isset($_GET['manual']);

if (!$isCli && !$isManual) {
    http_response_code(403);
    exit('직접 접근 불가');
}

$db    = new Database();
$http  = new HttpClient();
$parser = new HtmlParser();

$crawlers = [
    new NaraJangteoCrawler($http, $db->getLastFetchedAt('나라장터')),
    new KStartupCrawler($http),
    new Smes24Crawler($http, $db->getLastFetchedAt('중소벤처24')),
    new BizInfoCrawler($http),
    new KosacCrawler($http, $parser),
    new ExportVoucherCrawler($http, $parser),
    new YdpGuCrawler($http, $parser),
    new GanghwaCityCrawler($http, $parser),
    new HsCityGosiCrawler($http, $parser),
    new Ui4uGosiCrawler($http, $parser),
    new GjCityGosiCrawler($http, $parser),
    new SiheungGosiCrawler($http, $parser),
    new PocheonEminwonCrawler($http, $parser),
    new GimpoGosiCrawler($http, $parser),
    new PyeongtaekGosiCrawler($http, $parser),
    new PajuGosiCrawler($http, $parser),
    new OsanGosiCrawler($http, $parser),
    new YonginGosiCrawler($http, $parser),
];

$runner  = new Runner($db, $http, $parser);

echo $isCli ? '' : '<pre>';
echo "[" . date('Y-m-d H:i:s') . "] 수집 시작\n";

$results = $runner->run($crawlers);

foreach ($results as $source => $count) {
    echo "[{$source}] 공고 {$count}건 저장 완료\n";
}

// ── 수집 후 오래된 데이터 정리(삭제) ──
// 조건:
// 1) deadline_date가 NULL이 아니고, 1970-01-01도 아닌 경우: deadline_date로부터 1달 지난 데이터 삭제
// 2) deadline_date가 NULL이거나 1970-01-01인 경우: created_at(등록일)로부터 1년 지난 데이터 삭제
try {
    // 긴 DELETE는 커넥션이 끊길 수 있어 청크로 삭제
    $cleanupDb = new Database();
    $pdo = $cleanupDb->getPdo();
    // 마감 지난 공고도 보관하는 소스는 자동 삭제에서 제외 (재수집/삭제 반복 방지)
    $cleanupExcludeSources = ['강화군 고시공고', '수출바우처', '파주시 고시공고'];
    $excludeSql = implode(',', array_map([$pdo, 'quote'], $cleanupExcludeSources));
    $deletedTotal = 0;
    $chunk = 3000;
    for ($i = 0; $i < 50; $i++) {
        $sql = "
            DELETE FROM bids
            WHERE source NOT IN ({$excludeSql})
              AND (
                (
                    deadline_date IS NOT NULL
                    AND deadline_date <> '1970-01-01'
                    AND deadline_date < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                ) OR (
                    (deadline_date IS NULL OR deadline_date = '1970-01-01')
                    AND created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)
                )
              )
            LIMIT {$chunk}
        ";
        $deleted = $pdo->exec($sql);
        $deletedTotal += (int)$deleted;
        if ($deleted < $chunk) {
            break;
        }
        usleep(200_000);
    }
    echo "[정리] 지난 데이터 삭제 {$deletedTotal}건\n";
} catch (Throwable $e) {
    echo "[정리] 삭제 실패: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] 수집 완료\n";
echo $isCli ? '' : '</pre>';

if ($isManual) {
    header('Location: index.php');
}
