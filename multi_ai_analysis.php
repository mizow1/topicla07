<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$url = $_POST['url'] ?? '';
$forceReanalyze = $_POST['force_reanalyze'] ?? false;

if (empty($url)) {
    echo json_encode(['success' => false, 'message' => 'URLが指定されていません']);
    exit;
}

try {
    // APIキーの存在チェック
    if (empty($gemini) || empty($openai) || empty($claude)) {
        echo json_encode([
            'success' => false, 
            'message' => 'APIキーが設定されていません。環境変数GEMINI_API_KEY、OPENAI_API_KEY、CLAUDE_API_KEYを設定してください。',
            'debug' => [
                'gemini' => !empty($gemini),
                'openai' => !empty($openai),
                'claude' => !empty($claude)
            ]
        ]);
        exit;
    }
    
    // テーブル存在チェック
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'multi_ai_analyses'");
        if ($tableCheck->rowCount() === 0) {
            // テーブルが存在しない場合は作成
            $createTableSQL = "CREATE TABLE IF NOT EXISTS multi_ai_analyses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                url VARCHAR(2048) NOT NULL,
                results TEXT,
                final_suggestion TEXT,
                status ENUM('analyzing', 'completed', 'failed') DEFAULT 'analyzing',
                analysis_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_url (url(255)),
                INDEX idx_status (status),
                INDEX idx_date (analysis_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $pdo->exec($createTableSQL);
        }
    } catch (PDOException $e) {
        // テーブル作成に失敗してもAPIキーがあれば継続
        error_log("Table creation failed: " . $e->getMessage());
    }
    
    // 既存の分析結果があるかチェック（再分析が強制されていない場合のみ）
    $existingAnalysis = null;
    if (!$forceReanalyze) {
        $existingAnalysis = checkExistingMultiAIAnalysis($url, $pdo);
        if ($existingAnalysis) {
            echo json_encode([
                'success' => true,
                'multiAIResults' => json_decode($existingAnalysis['results'], true) ?? [],
                'finalSuggestion' => json_decode($existingAnalysis['final_suggestion'], true) ?? [],
                'fromCache' => true,
                'analysisDate' => $existingAnalysis['analysis_date']
            ]);
            exit;
        }
    }
    
    // 分析レコードを作成または更新
    if ($forceReanalyze) {
        $existingAnalysis = checkExistingMultiAIAnalysis($url, $pdo);
        if ($existingAnalysis) {
            $analysisId = $existingAnalysis['id'];
            updateMultiAIAnalysisStatus($analysisId, 'analyzing', $pdo);
        } else {
            $analysisId = createMultiAIAnalysisRecord($url, $pdo);
        }
    } else {
        $analysisId = createMultiAIAnalysisRecord($url, $pdo);
    }
    
    // URLからページ内容を取得
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'timeout' => 30
        ]
    ]);
    
    $html = @file_get_contents($url, false, $context);
    
    if ($html === false) {
        updateMultiAIAnalysisStatus($analysisId, 'failed', $pdo);
        echo json_encode(['success' => false, 'message' => 'ページの取得に失敗しました']);
        exit;
    }
    
    // ページの構造的情報を抽出
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    $structuralData = extractStructuralData($dom, $xpath, $html);
    
    // 3AIで同時に分析
    $aiResults = analyzeWith3AI($html, $url, $structuralData);
    
    // 3AIの結果をさらに3AIで分析して最終案を作成
    $finalSuggestion = analyzeFinalSuggestion($aiResults, $url);
    
    // 結果をデータベースに保存
    saveMultiAIAnalysisResults($analysisId, $aiResults, $finalSuggestion, $pdo);
    
    echo json_encode([
        'success' => true,
        'multiAIResults' => $aiResults,
        'finalSuggestion' => $finalSuggestion,
        'fromCache' => false
    ]);
    
} catch (Exception $e) {
    if (isset($analysisId)) {
        updateMultiAIAnalysisStatus($analysisId, 'failed', $pdo);
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// 3AI同時分析関数
function analyzeWith3AI($html, $url, $structuralData) {
    global $gemini, $openai, $claude;
    
    $results = [
        'gemini' => [],
        'openai' => [],
        'claude' => []
    ];
    
    // 共通のプロンプトベース
    $basePrompt = createAnalysisPrompt($html, $url, $structuralData);
    
    // Gemini分析
    try {
        $results['gemini'] = analyzeWithGeminiAPI($basePrompt, $gemini);
    } catch (Exception $e) {
        $results['gemini'] = ['error' => 'Gemini分析エラー: ' . $e->getMessage()];
    }
    
    // OpenAI分析
    try {
        $results['openai'] = analyzeWithOpenAI($basePrompt, $openai);
    } catch (Exception $e) {
        $results['openai'] = ['error' => 'OpenAI分析エラー: ' . $e->getMessage()];
    }
    
    // Claude分析
    try {
        $results['claude'] = analyzeWithClaude($basePrompt, $claude);
    } catch (Exception $e) {
        $results['claude'] = ['error' => 'Claude分析エラー: ' . $e->getMessage()];
    }
    
    return $results;
}

// 最終分析関数（3AIの結果を3AIでさらに分析）
function analyzeFinalSuggestion($aiResults, $url) {
    global $gemini, $openai, $claude;
    
    $finalPrompt = createFinalAnalysisPrompt($aiResults, $url);
    
    $finalResults = [
        'gemini' => [],
        'openai' => [],
        'claude' => []
    ];
    
    // 3AIでそれぞれ最終分析
    try {
        $finalResults['gemini'] = analyzeWithGeminiAPI($finalPrompt, $gemini);
    } catch (Exception $e) {
        $finalResults['gemini'] = ['error' => 'Gemini最終分析エラー: ' . $e->getMessage()];
    }
    
    try {
        $finalResults['openai'] = analyzeWithOpenAI($finalPrompt, $openai);
    } catch (Exception $e) {
        $finalResults['openai'] = ['error' => 'OpenAI最終分析エラー: ' . $e->getMessage()];
    }
    
    try {
        $finalResults['claude'] = analyzeWithClaude($finalPrompt, $claude);
    } catch (Exception $e) {
        $finalResults['claude'] = ['error' => 'Claude最終分析エラー: ' . $e->getMessage()];
    }
    
    return $finalResults;
}

// 基本分析プロンプト作成
function createAnalysisPrompt($html, $url, $structuralData) {
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', trim($text));
    if (strlen($text) > 6000) {
        $text = substr($text, 0, 6000) . '...';
    }
    
    $structuralInfo = formatStructuralDataForAnalysis($structuralData);
    
    return "あなたはSEO専門家です。以下のWebページを分析し、具体的で実践的な改善提案をしてください。

【重要】まず結論を提示し、コピペでそのまま実際のサイトに使えるレベルの具体的な改善案を最初に示してください。その後に根拠となる説明を追加してください。

【対象URL】{$url}

【ページの構造情報】
{$structuralInfo}

【ページ本文】{$text}

【分析要求】
以下の改善項目について、まず結論（実際に使える改善案）を提示し、その後に説明を追加してください：

1. **タイトルの改善**
2. **メタディスクリプションの改善**
3. **見出し構造の改善**
4. **コンテンツ内容の改善**
5. **内部リンク戦略の改善**

【出力形式】
JSON形式で以下のように回答してください：

{
  \"improvements\": [
    {
      \"title\": \"改善項目名\",
      \"conclusion\": \"【結論】コピペで使える具体的な改善案\",
      \"explanation\": \"この改善案の根拠と詳細説明\",
      \"priority\": \"high/medium/low\",
      \"expectedResult\": \"期待される効果\"
    }
  ]
}";
}

// 最終分析プロンプト作成
function createFinalAnalysisPrompt($aiResults, $url) {
    $geminiText = isset($aiResults['gemini']['improvements']) ? json_encode($aiResults['gemini']['improvements'], JSON_UNESCAPED_UNICODE) : '分析結果なし';
    $openaiText = isset($aiResults['openai']['improvements']) ? json_encode($aiResults['openai']['improvements'], JSON_UNESCAPED_UNICODE) : '分析結果なし';
    $claudeText = isset($aiResults['claude']['improvements']) ? json_encode($aiResults['claude']['improvements'], JSON_UNESCAPED_UNICODE) : '分析結果なし';
    
    return "あなたは統合SEOアナリストです。3つのAIによる以下の分析結果を統合し、最も効果的な改善案を作成してください。

【対象URL】{$url}

【Gemini分析結果】
{$geminiText}

【OpenAI分析結果】
{$openaiText}

【Claude分析結果】
{$claudeText}

【重要】
1. まず結論として、コピペで実際のサイトに使える具体的な改善案を提示
2. 3つのAI分析結果の共通点と相違点を分析
3. 最も効果的な改善案を統合的に判断
4. 実装難易度と効果の両方を考慮

【出力形式】
JSON形式で以下のように回答してください：

{
  \"finalImprovement\": {
    \"title\": \"統合最終改善案\",
    \"conclusion\": \"【最終結論】最も効果的でコピペで使える改善案\",
    \"analysis\": \"3AI分析結果の統合評価\",
    \"commonPoints\": [\"3AIの共通指摘事項\"],
    \"bestPractice\": \"統合的に判断した最適解\",
    \"implementationOrder\": [\"実装する順序\"],
    \"expectedImpact\": \"期待される効果の詳細\"
  }
}";
}

// Gemini API分析
function analyzeWithGeminiAPI($prompt, $apiKey) {
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.3,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 2048,
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=" . $apiKey);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        throw new Exception('Gemini API呼び出しに失敗しました');
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception('Gemini APIからの応答が不正です');
    }
    
    $geminiText = $result['candidates'][0]['content']['parts'][0]['text'];
    
    // JSONを抽出
    $jsonData = extractJsonFromResponse($geminiText);
    
    return $jsonData ?: ['rawText' => $geminiText];
}

// OpenAI API分析
function analyzeWithOpenAI($prompt, $apiKey) {
    $data = [
        'model' => 'gpt-4-turbo-preview',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.3,
        'max_tokens' => 2048
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        throw new Exception('OpenAI API呼び出しに失敗しました');
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['choices'][0]['message']['content'])) {
        throw new Exception('OpenAI APIからの応答が不正です');
    }
    
    $openaiText = $result['choices'][0]['message']['content'];
    
    // JSONを抽出
    $jsonData = extractJsonFromResponse($openaiText);
    
    return $jsonData ?: ['rawText' => $openaiText];
}

// Claude API分析
function analyzeWithClaude($prompt, $apiKey) {
    $data = [
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 2048,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.3
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        throw new Exception('Claude API呼び出しに失敗しました');
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['content'][0]['text'])) {
        throw new Exception('Claude APIからの応答が不正です');
    }
    
    $claudeText = $result['content'][0]['text'];
    
    // JSONを抽出
    $jsonData = extractJsonFromResponse($claudeText);
    
    return $jsonData ?: ['rawText' => $claudeText];
}

// レスポンスからJSONを抽出
function extractJsonFromResponse($text) {
    $patterns = [
        '/\{[\s\S]*?\}(?=\s*$|$)/m',
        '/\{[\s\S]*?\}/m',
        '/```json\s*(\{[\s\S]*?\})\s*```/m',
        '/```\s*(\{[\s\S]*?\})\s*```/m'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $jsonString = isset($matches[1]) ? $matches[1] : $matches[0];
            $data = json_decode($jsonString, true);
            if ($data !== null && json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
    }
    
    return null;
}

// 構造的データをテキストに変換
function formatStructuralDataForAnalysis($data) {
    $info = [];
    
    $info[] = "タイトル: " . ($data['title'] ?: '[タイトルなし]');
    $info[] = "メタディスクリプション: " . ($data['metaDescription'] ?: '[メタディスクリプションなし]');
    
    if (!empty($data['headings'])) {
        $info[] = "見出し構造:";
        foreach ($data['headings'] as $heading) {
            $info[] = "  " . $heading;
        }
    } else {
        $info[] = "見出し: [見出しなし]";
    }
    
    $imagesWithAlt = array_filter($data['images'], function($img) { return $img['hasAlt']; });
    $imagesWithoutAlt = array_filter($data['images'], function($img) { return !$img['hasAlt']; });
    
    $info[] = "画像: 総数" . count($data['images']) . "個 (alt属性あり: " . count($imagesWithAlt) . "個、なし: " . count($imagesWithoutAlt) . "個)";
    
    $externalLinks = array_filter($data['links'], function($link) { return $link['isExternal']; });
    $internalLinks = array_filter($data['links'], function($link) { return !$link['isExternal']; });
    
    $info[] = "リンク: 内部リンク" . count($internalLinks) . "個、外部リンク" . count($externalLinks) . "個";
    $info[] = "ページサイズ: " . number_format($data['pageSize']) . "バイト";
    $info[] = "テキスト文字数: " . number_format($data['textLength']) . "文字";
    
    return implode("\n", $info);
}

// 構造的データを抽出
function extractStructuralData($dom, $xpath, $html) {
    $data = [];
    
    // タイトル
    $titleNodes = $xpath->query('//title');
    $data['title'] = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';
    
    // メタディスクリプション
    $metaNodes = $xpath->query('//meta[@name="description"]');
    $data['metaDescription'] = $metaNodes->length > 0 ? trim($metaNodes->item(0)->getAttribute('content')) : '';
    
    // すべての見出し
    $data['headings'] = [];
    for ($i = 1; $i <= 6; $i++) {
        $hNodes = $xpath->query("//h{$i}");
        foreach ($hNodes as $node) {
            $data['headings'][] = "H{$i}: " . trim($node->textContent);
        }
    }
    
    // 画像情報
    $imgNodes = $xpath->query('//img');
    $data['images'] = [];
    foreach ($imgNodes as $img) {
        $data['images'][] = [
            'src' => $img->getAttribute('src'),
            'alt' => $img->getAttribute('alt'),
            'hasAlt' => !empty(trim($img->getAttribute('alt')))
        ];
    }
    
    // リンク情報
    $linkNodes = $xpath->query('//a[@href]');
    $data['links'] = [];
    foreach ($linkNodes as $link) {
        $href = $link->getAttribute('href');
        $data['links'][] = [
            'href' => $href,
            'text' => trim($link->textContent),
            'isExternal' => strpos($href, 'http') === 0
        ];
    }
    
    // ページサイズ
    $data['pageSize'] = strlen($html);
    
    // 文字数
    $text = strip_tags($html);
    $data['textLength'] = mb_strlen($text);
    
    return $data;
}

// データベース関連関数
function checkExistingMultiAIAnalysis($url, $pdo) {
    try {
        // テーブル存在チェック
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'multi_ai_analyses'");
        if ($tableCheck->rowCount() === 0) {
            return null;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM multi_ai_analyses WHERE url = ? AND status = 'completed' ORDER BY analysis_date DESC LIMIT 1");
        $stmt->execute([$url]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in checkExistingMultiAIAnalysis: " . $e->getMessage());
        return null;
    }
}

function createMultiAIAnalysisRecord($url, $pdo) {
    try {
        $stmt = $pdo->prepare("INSERT INTO multi_ai_analyses (url, status) VALUES (?, 'analyzing')");
        $stmt->execute([$url]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error in createMultiAIAnalysisRecord: " . $e->getMessage());
        return 0;
    }
}

function updateMultiAIAnalysisStatus($analysisId, $status, $pdo) {
    if ($analysisId === 0) return;
    try {
        $stmt = $pdo->prepare("UPDATE multi_ai_analyses SET status = ? WHERE id = ?");
        $stmt->execute([$status, $analysisId]);
    } catch (PDOException $e) {
        error_log("Error in updateMultiAIAnalysisStatus: " . $e->getMessage());
    }
}

function saveMultiAIAnalysisResults($analysisId, $aiResults, $finalSuggestion, $pdo) {
    if ($analysisId === 0) return;
    try {
        $stmt = $pdo->prepare("UPDATE multi_ai_analyses SET 
            results = ?, 
            final_suggestion = ?, 
            status = 'completed',
            analysis_date = NOW()
            WHERE id = ?");
        
        $stmt->execute([
            json_encode($aiResults, JSON_UNESCAPED_UNICODE),
            json_encode($finalSuggestion, JSON_UNESCAPED_UNICODE),
            $analysisId
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Error in saveMultiAIAnalysisResults: " . $e->getMessage());
        return false;
    }
}
?>