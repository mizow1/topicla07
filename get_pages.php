<?php
require_once 'config.php';

if (isset($_GET['site_id'])) {
    $siteId = $_GET['site_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT url FROM pages WHERE site_id = ? ORDER BY created_at DESC");
        $stmt->execute([$siteId]);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'pages' => $pages]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'サイトIDが指定されていません']);
}
?>