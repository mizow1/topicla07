<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$url = $_POST['url'] ?? '';

if (empty($url)) {
    echo json_encode(['success' => false, 'message' => 'URLが指定されていません']);
    exit;
}

try {
    // テーブルが存在するかチェック
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'multi_ai_analyses'");
    if ($tableCheck->rowCount() === 0) {
        // テーブルが存在しない場合は作成
        $createTableSQL = "CREATE TABLE IF NOT EXISTS multi_ai_analyses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            url VARCHAR(2048) NOT NULL,
            results TEXT,
            final_suggestion TEXT,
            status ENUM('analyzing', 'completed', 'failed') DEFAULT 'analyzing',
            analysis_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_url (url(255)),
            INDEX idx_status (status),
            INDEX idx_date (analysis_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($createTableSQL);
    }
    
    // 既存の分析結果があるかチェック
    $stmt = $pdo->prepare("SELECT * FROM multi_ai_analyses WHERE url = ? AND status = 'completed' ORDER BY analysis_date DESC LIMIT 1");
    $stmt->execute([$url]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'hasAnalysis' => true,
            'analysisDate' => $result['analysis_date']
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'hasAnalysis' => false
        ]);
    }
    
} catch (PDOException $e) {
    // データベースエラーの場合は詳細をログに記録（本番環境では削除）
    error_log("Database error in check_analysis_results.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => true,
        'hasAnalysis' => false,
        'debug' => $e->getMessage() // デバッグ用（本番環境では削除）
    ]);
}
?>