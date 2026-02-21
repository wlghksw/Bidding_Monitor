<?php
// migrate.php - DB 스키마 확장
require_once 'config.php';
require_once 'db.php';

$db = new Database();
$pdo = $db->getPdo();

$alter = [
    "ALTER TABLE bids ADD COLUMN region VARCHAR(100) DEFAULT NULL AFTER org_name",
    "ALTER TABLE bids ADD COLUMN bid_type VARCHAR(50) DEFAULT NULL AFTER budget_raw",
    "ALTER TABLE bids ADD COLUMN status VARCHAR(50) DEFAULT '입찰공고' AFTER bid_type",
    "ALTER TABLE bids ADD COLUMN notice_date DATE DEFAULT NULL AFTER deadline_date",
];

foreach ($alter as $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: " . substr($sql, 0, 60) . "...\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "SKIP (already exists): " . substr($sql, 0, 50) . "\n";
        } else {
            echo "ERR: " . $e->getMessage() . "\n";
        }
    }
}

// bookmarks 테이블
// notice_date 인덱스 (기간 검색용)
try {
    $pdo->exec("CREATE INDEX idx_bids_notice ON bids (notice_date)");
    echo "OK: idx_bids_notice\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) echo "SKIP: idx_bids_notice\n";
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bookmarks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bid_id INT NOT NULL,
        session_id VARCHAR(64) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_bid_session (bid_id, session_id),
        FOREIGN KEY (bid_id) REFERENCES bids(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: bookmarks table\n";
} catch (PDOException $e) {
    echo "SKIP bookmarks: " . $e->getMessage() . "\n";
}

echo "Migration done.\n";
