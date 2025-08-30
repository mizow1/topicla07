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
        
        // 新しく作成されたテーブルなので結果はない
        echo json_encode([
            'success' => false,
            'message' => '分析結果が見つかりません（新規テーブル作成）'
        ]);
        exit;
    }
    
    // 既存の分析結果を取得
    $stmt = $pdo->prepare("SELECT * FROM multi_ai_analyses WHERE url = ? AND status = 'completed' ORDER BY analysis_date DESC LIMIT 1");
    $stmt->execute([$url]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'multiAIResults' => json_decode($result['results'], true) ?? [],
            'finalSuggestion' => json_decode($result['final_suggestion'], true) ?? [],
            'analysisDate' => $result['analysis_date'],
            'fromCache' => true
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '分析結果が見つかりません'
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Database error in get_analysis_results.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'データベースエラー: ' . $e->getMessage(),
        'debug' => $e->getMessage() // デバッグ用
    ]);
}
?>