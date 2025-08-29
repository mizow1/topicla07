<?php
$servername = "localhost";
$username = "xs483187_ow";
$password = "password";
$dbname = "xs483187_topicla07";
$gemini = getenv('GEMINI_API_KEY');
$openai = getenv('OPENAI_API_KEY');
$claude = getenv('CLAUDE_API_KEY');

if (!$gemini || !$openai || !$claude) {
    die("Error: API keys not found. Please set environment variables GEMINI_API_KEY, OPENAI_API_KEY, and CLAUDE_API_KEY");
}


try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>