<?php
require_once 'config.php';

function getAllLinks($url) {
    $domain = parse_url($url, PHP_URL_HOST);
    $scheme = parse_url($url, PHP_URL_SCHEME) ?? 'http';
    
    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "timeout" => 30,
            "user_agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        ]
    ]);
    
    $html = @file_get_contents($url, false, $context);
    
    if ($html === false) {
        return [];
    }
    
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    
    $xpath = new DOMXPath($dom);
    $links = $xpath->query('//a[@href]');
    
    $urls = [];
    
    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        
        if (empty($href) || $href[0] === '#') {
            continue;
        }
        
        if (strpos($href, 'javascript:') === 0 || strpos($href, 'mailto:') === 0) {
            continue;
        }
        
        if (strpos($href, 'http') === 0) {
            $fullUrl = $href;
        } else {
            if ($href[0] === '/') {
                $fullUrl = $scheme . '://' . $domain . $href;
            } else {
                $fullUrl = rtrim($url, '/') . '/' . ltrim($href, './');
            }
        }
        
        $linkDomain = parse_url($fullUrl, PHP_URL_HOST);
        if ($linkDomain === $domain) {
            $urls[] = $fullUrl;
        }
    }
    
    return array_unique($urls);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['site_id'])) {
    $siteId = $_POST['site_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT domain FROM sites WHERE id = ?");
        $stmt->execute([$siteId]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$site) {
            echo json_encode(['success' => false, 'message' => 'サイトが見つかりません']);
            exit;
        }
        
        $domain = $site['domain'];
        $baseUrl = 'http://' . $domain;
        
        $urls = getAllLinks($baseUrl);
        $urls[] = $baseUrl;
        $urls = array_unique($urls);
        
        $stmt = $pdo->prepare("DELETE FROM pages WHERE site_id = ?");
        $stmt->execute([$siteId]);
        
        $insertStmt = $pdo->prepare("INSERT INTO pages (site_id, url) VALUES (?, ?)");
        
        foreach ($urls as $url) {
            $insertStmt->execute([$siteId, $url]);
        }
        
        echo json_encode([
            'success' => true, 
            'message' => count($urls) . ' 個のページURLを取得しました',
            'count' => count($urls)
        ]);
        
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => 'エラー: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => '無効なリクエストです']);
}
?>