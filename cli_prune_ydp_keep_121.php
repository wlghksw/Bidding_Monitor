<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$db  = new Database();
$pdo = $db->getPdo();

$keep = 121; // 최신 121개만 유지

$stmt = $pdo->prepare(
    'SELECT id FROM bids WHERE source = ? ORDER BY fetched_at DESC LIMIT ' . (int)$keep
);
$stmt->execute(['영등포구청']);
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!$ids) {
    echo "no_rows\n";
    return;
}

$in  = implode(',', array_map('intval', $ids));
$sql = "DELETE FROM bids WHERE source = '영등포구청' AND id NOT IN ($in)";
$deleted = $pdo->exec($sql);

echo 'kept=' . count($ids) . ' deleted=' . $deleted . PHP_EOL;

