<?php
require_once 'config.php';

try {
    // SEO分析結果を保存するテーブルを作成
    $sql = "CREATE TABLE IF NOT EXISTS seo_analyses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        url VARCHAR(2048) NOT NULL,
        site_id INT,
        analysis_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        title VARCHAR(255),
        meta_description TEXT,
        analysis_data JSON,
        improvements JSON,
        gemini_analysis TEXT,
        status ENUM('analyzing', 'completed', 'failed') DEFAULT 'analyzing',
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
        INDEX idx_url (url(255)),
        INDEX idx_site_id (site_id),
        INDEX idx_analysis_date (analysis_date)
    )";
    
    $pdo->exec($sql);
    
    echo "SEO分析テーブルが正常に作成されました。\n";
    
} catch(PDOException $e) {
    echo "テーブル作成エラー: " . $e->getMessage() . "\n";
}
?>