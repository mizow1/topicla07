<?php
require_once 'config.php';

function getAllLinksWithProgress($url, $siteId, $pdo) {
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
    $insertStmt = $pdo->prepare("INSERT INTO pages (site_id, url) VALUES (?, ?)");
    
    $processedCount = 0;
    $totalLinks = $links->length;
    
    // ベースURLを最初に追加
    $urls[] = $url;
    $insertStmt->execute([$siteId, $url]);
    $processedCount++;
    
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
        if ($linkDomain === $domain && !in_array($fullUrl, $urls)) {
            $urls[] = $fullUrl;
            $insertStmt->execute([$siteId, $fullUrl]);
            $processedCount++;
            
            // 10個ずつ処理したら進捗を返す
            if ($processedCount % 10 === 0) {
                echo json_encode([
                    'type' => 'progress',
                    'processed' => $processedCount,
                    'total' => $totalLinks + 1,
                    'currentUrl' => $fullUrl
                ]) . "\n";
                ob_flush();
                flush();
            }
        }
    }
    
    return array_unique($urls);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['site_id'])) {
    $siteId = $_POST['site_id'];
    
    header('Content-Type: text/plain; charset=utf-8');
    
    try {
        $stmt = $pdo->prepare("SELECT domain FROM sites WHERE id = ?");
        $stmt->execute([$siteId]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$site) {
            echo json_encode(['type' => 'error', 'message' => 'サイトが見つかりません']) . "\n";
            exit;
        }
        
        $domain = $site['domain'];
        $baseUrl = 'http://' . $domain;
        
        // 既存のページをクリア
        $stmt = $pdo->prepare("DELETE FROM pages WHERE site_id = ?");
        $stmt->execute([$siteId]);
        
        echo json_encode(['type' => 'start', 'message' => 'URL取得を開始します']) . "\n";
        ob_flush();
        flush();
        
        $urls = getAllLinksWithProgress($baseUrl, $siteId, $pdo);
        
        echo json_encode([
            'type' => 'complete',
            'message' => count($urls) . ' 個のページURLを取得しました',
            'count' => count($urls)
        ]) . "\n";
        
    } catch(Exception $e) {
        echo json_encode(['type' => 'error', 'message' => 'エラー: ' . $e->getMessage()]) . "\n";
    }
} else {
    echo json_encode(['type' => 'error', 'message' => '無効なリクエストです']) . "\n";
}
?>