<?php
$servername = "localhost";
$username = "xs483187_ow";
$password = "password";
$dbname = "xs483187_topicla07";
$gemini = $_ENV['GEMINI_API_KEY'] ?? '';
$openai = $_ENV['OPENAI_API_KEY'] ?? '';
$claude = $_ENV['CLAUDE_API_KEY'] ?? '';


try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>