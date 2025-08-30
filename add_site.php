<?php
header('Content-Type: application/json');
require_once 'config.php';

function extractDomain($url) {
    $url = trim($url);
    
    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
        $url = "http://" . $url;
    }
    
    $parsed = parse_url($url);
    
    if (!$parsed || !isset($parsed['host'])) {
        return false;
    }
    
    $host = $parsed['host'];
    
    $host = preg_replace('/^www\./', '', $host);
    
    return $host;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url'])) {
    $inputUrl = $_POST['url'];
    $domain = extractDomain($inputUrl);
    
    if (!$domain) {
        echo json_encode(['success' => false, 'message' => '無効なURLです']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id FROM sites WHERE domain = ?");
        $stmt->execute([$domain]);
        
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'このサイトは既に登録されています']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO sites (domain) VALUES (?)");
            $stmt->execute([$domain]);
            echo json_encode(['success' => true, 'message' => 'サイトが正常に登録されました', 'domain' => $domain]);
        }
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'データベースエラー: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => '無効なリクエストです']);
}
?>