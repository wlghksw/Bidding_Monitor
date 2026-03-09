<?php
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/src/autoload.php';

$db = new Database();
$http = new BiddingMonitor\Core\HttpClient();
$parser = new BiddingMonitor\Core\HtmlParser();
$crawler = new BiddingMonitor\Crawler\PajuGosiCrawler($http, $parser);
$start = microtime(true);
$count = BiddingMonitor\Core\Runner::runOneCrawler($db, $crawler);
$elapsed = microtime(true) - $start;
echo '[파주시 고시공고] 공고 ' . $count . '건 저장 완료 time=' . round($elapsed, 2) . "s\n";
