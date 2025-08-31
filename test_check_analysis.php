<?php
// テスト用：check_analysis_results.phpの動作確認
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>Database Connection Test</h2>";

try {
    require_once 'config.php';
    echo "✓ Config loaded successfully<br>";
    
    // PDO接続テスト
    if (isset($pdo)) {
        echo "✓ PDO connection exists<br>";
        
        // テーブル確認
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'multi_ai_analyses'");
        if ($tableCheck->rowCount() > 0) {
            echo "✓ multi_ai_analyses table exists<br>";
            
            // カラム構造確認
            $columns = $pdo->query("SHOW COLUMNS FROM multi_ai_analyses");
            echo "<h3>Table Structure:</h3>";
            while ($column = $columns->fetch(PDO::FETCH_ASSOC)) {
                echo "- " . $column['Field'] . " (" . $column['Type'] . ")<br>";
            }
            
        } else {
            echo "❌ multi_ai_analyses table does not exist<br>";
        }
        
    } else {
        echo "❌ PDO connection not found<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<h2>POST Request Test</h2>";
echo "<form method='POST' action='check_analysis_results.php'>";
echo "<input type='text' name='url' placeholder='Enter URL to test' required>";
echo "<input type='submit' value='Test Check Analysis'>";
echo "</form>";
?>