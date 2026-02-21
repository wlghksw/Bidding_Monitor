<?php
// =============================================
// keyword_delete.php
// =============================================
require_once 'config.php';
require_once 'db.php';

$id = intval($_GET['id'] ?? 0);
if ($id > 0) {
    $db = new Database();
    $db->deleteKeyword($id);
}
header('Location: index.php');
