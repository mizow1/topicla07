<?php
require_once 'config.php';

try {
    $stmt = $pdo->prepare("SELECT id, domain, created_at FROM sites ORDER BY created_at DESC");
    $stmt->execute();
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'sites' => $sites]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]);
}
?>