<?php
// =============================================
// keyword_add.php
// =============================================
require_once 'config.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keyword = trim($_POST['keyword'] ?? '');
    if ($keyword) {
        $db = new Database();
        $db->addKeyword($keyword);

        // 수집 후 키워드 추가 시에도 기존 공고에 매칭되도록 재매칭(단일 키워드만)
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare("SELECT id, keyword FROM keywords WHERE keyword = ? LIMIT 1");
        $stmt->execute([$keyword]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['id'])) {
            $kid = (int)$row['id'];
            $kwText = (string)$row['keyword'];
            // 공고명/기관명 LIKE 기반으로 키워드 매칭 테이블 채우기
            $like = '%' . $kwText . '%';
            $ins = $pdo->prepare("
                INSERT IGNORE INTO bid_keywords (bid_id, keyword_id)
                SELECT b.id, :kid
                FROM bids b
                WHERE (b.title LIKE :like OR b.org_name LIKE :like)
            ");
            $ins->execute([':kid' => $kid, ':like' => $like]);
        }
    }
}
header('Location: index.php');
