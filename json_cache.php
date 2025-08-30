<?php
// JSON応答のキャッシュクラス
class JsonCache {
    private $cacheDir;
    private $defaultExpiry;
    
    public function __construct($cacheDir = 'cache', $defaultExpiry = 3600) {
        $this->cacheDir = $cacheDir;
        $this->defaultExpiry = $defaultExpiry;
        
        // キャッシュディレクトリが存在しない場合は作成
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    // キャッシュキーを生成
    private function generateCacheKey($key) {
        return md5($key) . '.json';
    }
    
    // キャッシュファイルパスを取得
    private function getCacheFilePath($key) {
        return $this->cacheDir . '/' . $this->generateCacheKey($key);
    }
    
    // キャッシュに保存
    public function set($key, $data, $expiry = null) {
        if ($expiry === null) {
            $expiry = $this->defaultExpiry;
        }
        
        $cacheData = [
            'data' => $data,
            'timestamp' => time(),
            'expiry' => $expiry
        ];
        
        $filePath = $this->getCacheFilePath($key);
        return file_put_contents($filePath, json_encode($cacheData, JSON_UNESCAPED_UNICODE));
    }
    
    // キャッシュから取得
    public function get($key) {
        $filePath = $this->getCacheFilePath($key);
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        $cacheData = json_decode(file_get_contents($filePath), true);
        
        if (!$cacheData || !isset($cacheData['timestamp'])) {
            return null;
        }
        
        // 有効期限チェック
        $currentTime = time();
        $cacheTime = $cacheData['timestamp'];
        $expiry = $cacheData['expiry'];
        
        if (($currentTime - $cacheTime) > $expiry) {
            // 期限切れの場合はファイルを削除
            @unlink($filePath);
            return null;
        }
        
        return $cacheData['data'];
    }
    
    // キャッシュが存在するかチェック
    public function exists($key) {
        return $this->get($key) !== null;
    }
    
    // キャッシュを削除
    public function delete($key) {
        $filePath = $this->getCacheFilePath($key);
        if (file_exists($filePath)) {
            return @unlink($filePath);
        }
        return true;
    }
    
    // すべてのキャッシュを削除
    public function clear() {
        $files = glob($this->cacheDir . '/*.json');
        $count = 0;
        
        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    // キャッシュの統計情報を取得
    public function getStats() {
        $files = glob($this->cacheDir . '/*.json');
        $totalSize = 0;
        $totalFiles = count($files);
        $expiredFiles = 0;
        $currentTime = time();
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
            
            $cacheData = json_decode(file_get_contents($file), true);
            if ($cacheData && isset($cacheData['timestamp'])) {
                $cacheTime = $cacheData['timestamp'];
                $expiry = $cacheData['expiry'];
                
                if (($currentTime - $cacheTime) > $expiry) {
                    $expiredFiles++;
                }
            }
        }
        
        return [
            'totalFiles' => $totalFiles,
            'totalSize' => $totalSize,
            'totalSizeFormatted' => $this->formatBytes($totalSize),
            'expiredFiles' => $expiredFiles,
            'validFiles' => $totalFiles - $expiredFiles
        ];
    }
    
    // バイト数をフォーマット
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    // 期限切れキャッシュを削除
    public function cleanExpired() {
        $files = glob($this->cacheDir . '/*.json');
        $cleanedCount = 0;
        $currentTime = time();
        
        foreach ($files as $file) {
            $cacheData = json_decode(file_get_contents($file), true);
            if ($cacheData && isset($cacheData['timestamp'])) {
                $cacheTime = $cacheData['timestamp'];
                $expiry = $cacheData['expiry'];
                
                if (($currentTime - $cacheTime) > $expiry) {
                    if (@unlink($file)) {
                        $cleanedCount++;
                    }
                }
            }
        }
        
        return $cleanedCount;
    }
}

// 使用例
/*
$cache = new JsonCache();

// データを保存（1時間有効）
$cache->set('user_data_123', ['name' => 'John', 'email' => 'john@example.com'], 3600);

// データを取得
$userData = $cache->get('user_data_123');
if ($userData) {
    echo json_encode($userData);
} else {
    echo "キャッシュが見つかりません";
}

// 統計情報を表示
$stats = $cache->getStats();
print_r($stats);

// 期限切れキャッシュを削除
$cleaned = $cache->cleanExpired();
echo "削除されたキャッシュファイル数: " . $cleaned;
*/
?>