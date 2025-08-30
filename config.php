<?php
// .envファイルを読み込む
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}

$servername = "localhost";
$username = "xs483187_ow";
$password = "password";
$dbname = "xs483187_topicla07";
$gemini = $_ENV['GEMINI_API_KEY'] ?? '';
$openai = $_ENV['OPENAI_API_KEY'] ?? '';
$claude = $_ENV['CLAUDE_API_KEY'] ?? '';

// APIキーのチェックは実際にAPI呼び出し時のみ行う


try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>