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
    }
}
header('Location: index.php');
