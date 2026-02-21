<?php
// api_suggest.php - 키워드 자동완성 API
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';
require_once 'db.php';

$q = trim($_GET['q'] ?? '');
$limit = min(15, max(5, (int)($_GET['limit'] ?? 10)));

$db = new Database();
$list = $db->getKeywordSuggestions($q, $limit);
echo json_encode(['keywords' => $list]);
