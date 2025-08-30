-- Multi-AI分析システムのためのテーブル作成
CREATE TABLE IF NOT EXISTS multi_ai_analyses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(2048) NOT NULL,
    results TEXT,
    final_suggestion TEXT,
    status ENUM('analyzing', 'completed', 'failed') DEFAULT 'analyzing',
    analysis_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_url (url(255)),
    INDEX idx_status (status),
    INDEX idx_date (analysis_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 既存のテーブル構造も確認用に含める
DESCRIBE multi_ai_analyses;