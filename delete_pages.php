<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['urls']) && is_array($input['urls']) && isset($input['site_id'])) {
        $urls = $input['urls'];
        $siteId = $input['site_id'];
        
        try {
            $placeholders = str_repeat('?,', count($urls) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM pages WHERE site_id = ? AND url IN ($placeholders)");
            
            $params = array_merge([$siteId], $urls);
            $stmt->execute($params);
            
            $deletedCount = $stmt->rowCount();
            
            echo json_encode([
                'success' => true, 
                'message' => $deletedCount . ' 個のURLを削除しました',
                'deletedCount' => $deletedCount
            ]);
            
        } catch(PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => '無効なリクエストです']);
    }
} else {
    echo json_encode(['success' => false, 'message' => '無効なリクエストメソッドです']);
}
?>