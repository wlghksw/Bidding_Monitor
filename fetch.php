<?php
// =============================================
// fetch.php - 수동/자동(cron) 수집 스크립트
// cron 설정: 0 9 * * * php /var/www/html/bid_monitor/fetch.php
// =============================================

require_once 'config.php';
require_once 'db.php';
require_once 'api_fetch.php';

$isCli    = php_sapi_name() === 'cli';
$isManual = isset($_GET['manual']);

if (!$isCli && !$isManual) {
    http_response_code(403);
    exit('직접 접근 불가');
}

$db      = new Database();
$fetcher = new ApiFetch($db);

echo $isCli ? '' : '<pre>';
echo "[" . date('Y-m-d H:i:s') . "] 수집 시작\n";

$results = $fetcher->fetchAll();

foreach ($results as $source => $count) {
    echo "[{$source}] 키워드 매칭 공고 {$count}건 저장 완료\n";
}

echo "[" . date('Y-m-d H:i:s') . "] 수집 완료\n";
echo $isCli ? '' : '</pre>';

if ($isManual) {
    header('Location: index.php');
}
