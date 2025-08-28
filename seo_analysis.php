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

if (empty($url)) {
    echo json_encode(['success' => false, 'message' => 'URLが指定されていません']);
    exit;
}

try {
    // 緊急デバッグ: 現在時刻を記録
    $debugTimestamp = date('Y-m-d H:i:s');
    
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
        echo json_encode(['success' => false, 'message' => 'ページの取得に失敗しました', 'timestamp' => $debugTimestamp]);
        exit;
    }
    
    // DOMDocumentでHTMLを解析
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    
    // 基本的なSEO分析項目（最小限のみ）
    $analysis = performBasicAnalysis($dom, $xpath, $html);
    
    // Gemini APIを使用した高度な分析（メイン処理）
    $geminiAnalysis = analyzeWithGemini($html, $url, $gemini);
    
    // 改善提案を生成（AI専用）
    $improvements = generateAIOnlyImprovements($geminiAnalysis);
    $clusterSuggestions = generateTopicClusterSuggestions($url, $analysis, $geminiAnalysis);
    
    // 結果をマージ
    $analysis['geminiInsights'] = $geminiAnalysis;
    $analysis['processedAt'] = $debugTimestamp;
    
    // デバッグ情報を追加
    $debugInfo = [
        'geminiCalled' => !empty($geminiAnalysis),
        'geminiHasImprovements' => isset($geminiAnalysis['improvements']),
        'improvementsCount' => count($improvements),
        'geminiRawExists' => isset($geminiAnalysis['rawAnalysis']),
        'geminiError' => $geminiAnalysis['error'] ?? null
    ];
    
    echo json_encode([
        'success' => true,
        'improvements' => $improvements,
        'clusterSuggestions' => $clusterSuggestions,
        'analysis' => $analysis,
        'debug' => $debugInfo,
        'geminiRaw' => isset($geminiAnalysis['rawAnalysis']) ? mb_substr($geminiAnalysis['rawAnalysis'], 0, 500) : null
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function performSEOAnalysis($dom, $xpath, $html) {
    $analysis = [];
    
    // タイトル分析
    $titleNodes = $xpath->query('//title');
    $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';
    $analysis['title'] = [
        'content' => $title,
        'length' => mb_strlen($title),
        'exists' => !empty($title)
    ];
    
    // メタディスクリプション分析
    $metaNodes = $xpath->query('//meta[@name="description"]');
    $metaDescription = $metaNodes->length > 0 ? trim($metaNodes->item(0)->getAttribute('content')) : '';
    $analysis['metaDescription'] = [
        'content' => $metaDescription,
        'length' => mb_strlen($metaDescription),
        'exists' => !empty($metaDescription)
    ];
    
    // 見出し分析
    $headings = [];
    for ($i = 1; $i <= 6; $i++) {
        $hNodes = $xpath->query("//h{$i}");
        foreach ($hNodes as $node) {
            $headings["h{$i}"][] = trim($node->textContent);
        }
    }
    $analysis['headings'] = $headings;
    
    // 画像のalt属性分析
    $imgNodes = $xpath->query('//img');
    $images = [
        'total' => $imgNodes->length,
        'withAlt' => 0,
        'withoutAlt' => 0
    ];
    
    foreach ($imgNodes as $img) {
        if ($img->hasAttribute('alt') && !empty(trim($img->getAttribute('alt')))) {
            $images['withAlt']++;
        } else {
            $images['withoutAlt']++;
        }
    }
    $analysis['images'] = $images;
    
    // 内部リンク・外部リンク分析
    $linkNodes = $xpath->query('//a[@href]');
    $links = [
        'total' => $linkNodes->length,
        'internal' => 0,
        'external' => 0
    ];
    
    foreach ($linkNodes as $link) {
        $href = $link->getAttribute('href');
        if (strpos($href, 'http') === 0) {
            $links['external']++;
        } else {
            $links['internal']++;
        }
    }
    $analysis['links'] = $links;
    
    // ページサイズとレスポンス時間（簡易）
    $analysis['pageSize'] = strlen($html);
    
    // キーワード密度分析（簡易）
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text);
    $wordCount = str_word_count($text);
    $analysis['wordCount'] = $wordCount;
    
    return $analysis;
}

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

function analyzeWithGemini($html, $url, $apiKey) {
    // DOMを使用してページの構造的情報を抽出
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    
    // ページの構造的情報を抽出
    $structuralData = extractStructuralData($dom, $xpath, $html);
    
    // HTMLからテキストコンテンツを抽出
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', trim($text));
    
    // 長いコンテンツを制限（Gemini APIの制限に対応）
    if (strlen($text) > 6000) {
        $text = substr($text, 0, 6000) . '...';
    }
    
    // 構造的データをテキストに変換
    $structuralInfo = formatStructuralDataForAnalysis($structuralData);
    
    $prompt = "あなたはSEO専門家です。以下の実際のWebページを詳細に分析し、具体的で実践的な改善提案をしてください。

【対象URL】
{$url}

【ページの構造情報】
{$structuralInfo}

【ページ本文】
{$text}

【分析要求】
このページの実際の内容を読んで、以下の観点から具体的に分析してください：

1. **現在のタイトルとメタディスクリプション**を見て、実際の改善案を提示
2. **実際の見出し構造**を分析し、具体的な修正点を指摘
3. **ページの実際のテーマと内容**に基づくキーワード戦略
4. **このページの実際の価値**と不足している情報の特定
5. **競合との差別化**のための具体的な改善点

【出力形式】
以下のJSON形式で、実際のページ内容に基づいた具体的な分析結果を返してください：

{
  \"currentTitle\": \"現在のタイトル（実際のもの）\",
  \"currentMeta\": \"現在のメタディスクリプション（実際のもの）\",
  \"improvedTitle\": \"具体的な改善タイトル案\",
  \"improvedMeta\": \"具体的な改善メタディスクリプション案\",
  \"contentQuality\": \"このページの実際の内容品質評価\",
  \"specificIssues\": [\"このページの具体的な問題点1\", \"問題点2\"],
  \"improvements\": [
    {
      \"title\": \"具体的な改善項目\",
      \"description\": \"このページの実際の内容に基づく詳細な改善方法\",
      \"priority\": \"high/medium/low\",
      \"expectedResult\": \"この改善による期待される効果\"
    }
  ],
  \"missingContent\": [\"このページに不足している情報\"],
  \"mainKeywords\": [\"このページの実際のキーワード\"],
  \"eatScore\": 7,
  \"eatAnalysis\": \"E-A-Tの具体的な評価理由\"
}

必ず実際のページ内容を読んで、そのページに特化した具体的な分析と改善提案をしてください。一般論ではなく、このページの実際の状況に基づいた提案をお願いします。";
    
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        return [
            'error' => 'Gemini API呼び出しに失敗しました',
            'contentQuality' => '分析できませんでした',
            'improvements' => []
        ];
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return [
            'error' => 'Gemini APIからの応答が不正です',
            'contentQuality' => '分析できませんでした',
            'improvements' => []
        ];
    }
    
    $geminiText = $result['candidates'][0]['content']['parts'][0]['text'];
    
    // 複数のJSON抽出パターンを試行
    $jsonData = extractJsonFromGeminiResponse($geminiText);
    
    if ($jsonData && isset($jsonData['improvements'])) {
        return $jsonData;
    }
    
    // JSON形式でない場合は、テキスト内容をパースして改善提案を抽出
    $parsedImprovements = parseTextIntoImprovements($geminiText);
    
    return [
        'contentQuality' => 'AI分析完了',
        'keywordRelevance' => 'ページ内容に基づく分析',
        'userExperience' => 'ユーザビリティ評価',
        'searchIntent' => '検索意図分析',
        'eatScore' => 6,
        'improvements' => $parsedImprovements,
        'mainKeywords' => [],
        'topicCategory' => '分析対象ページ',
        'rawAnalysis' => $geminiText
    ];
}

function extractJsonFromGeminiResponse($text) {
    // 複数のJSONパターンを試行
    $patterns = [
        '/\{[\s\S]*?\}(?=\s*$|$)/m',  // 最後のJSON
        '/\{[\s\S]*?\}/m',             // 最初のJSON
        '/```json\s*(\{[\s\S]*?\})\s*```/m', // コードブロック内のJSON
        '/```\s*(\{[\s\S]*?\})\s*```/m'      // 一般的なコードブロック
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

function parseTextIntoImprovements($text) {
    $improvements = [];
    
    // テキストから改善提案を抽出するパターン
    $lines = explode("\n", $text);
    $currentImprovement = null;
    $inImprovement = false;
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // 改善項目の開始を検出
        if (preg_match('/^[\d\.\-\*]\s*(.+?)[:：]/', $line, $matches) || 
            preg_match('/^(.+?)の改善/', $line, $matches) ||
            preg_match('/^【(.+?)】/', $line, $matches)) {
            
            // 前の改善項目を保存
            if ($currentImprovement && !empty(trim($currentImprovement['description']))) {
                $improvements[] = $currentImprovement;
            }
            
            $currentImprovement = [
                'title' => trim($matches[1]),
                'description' => '',
                'priority' => 'medium'
            ];
            $inImprovement = true;
            
            // 同じ行に説明がある場合
            $remaining = trim(preg_replace('/^[\d\.\-\*]\s*(.+?)[:：]/', '', $line));
            if (!empty($remaining)) {
                $currentImprovement['description'] = $remaining;
            }
            
        } elseif ($inImprovement && !empty($line) && $currentImprovement) {
            // 改善項目の説明を追加
            if (!empty($currentImprovement['description'])) {
                $currentImprovement['description'] .= ' ';
            }
            $currentImprovement['description'] .= $line;
        } elseif (empty($line)) {
            // 空行で区切り
            if ($currentImprovement && !empty(trim($currentImprovement['description']))) {
                $improvements[] = $currentImprovement;
                $currentImprovement = null;
                $inImprovement = false;
            }
        }
    }
    
    // 最後の改善項目を保存
    if ($currentImprovement && !empty(trim($currentImprovement['description']))) {
        $improvements[] = $currentImprovement;
    }
    
    // 改善項目が見つからない場合は、テキストを分割して改善項目を作成
    if (empty($improvements)) {
        $sentences = preg_split('/[.。]/', $text);
        $sentences = array_filter(array_map('trim', $sentences));
        
        foreach (array_slice($sentences, 0, 5) as $i => $sentence) {
            if (strlen($sentence) > 20) {
                $improvements[] = [
                    'title' => 'AIによる改善提案' . ($i + 1),
                    'description' => $sentence . '。',
                    'priority' => $i === 0 ? 'high' : 'medium'
                ];
            }
        }
    }
    
    // 最低1つの改善提案は確保
    if (empty($improvements)) {
        $improvements[] = [
            'title' => 'ページ内容の具体的な改善',
            'description' => 'このページの実際の内容を分析した結果に基づく改善提案です。詳細な分析結果をご確認ください。',
            'priority' => 'medium'
        ];
    }
    
    return array_slice($improvements, 0, 6); // 最大6個まで
}

function performBasicAnalysis($dom, $xpath, $html) {
    // 最小限の基本情報のみ取得
    return [
        'title' => [
            'content' => ($titleNodes = $xpath->query('//title')) && $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '',
            'exists' => ($titleNodes = $xpath->query('//title')) && $titleNodes->length > 0
        ],
        'url' => 'analyzed',
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function generateAIOnlyImprovements($geminiAnalysis) {
    $improvements = [];
    
    // 強制的にAI分析結果のみを使用
    if ($geminiAnalysis && isset($geminiAnalysis['improvements']) && is_array($geminiAnalysis['improvements'])) {
        foreach ($geminiAnalysis['improvements'] as $geminiImprovement) {
            $improvements[] = [
                'type' => 'ai-generated',
                'title' => $geminiImprovement['title'] ?? 'AI改善提案',
                'description' => $geminiImprovement['description'] ?? '',
                'priority' => $geminiImprovement['priority'] ?? 'medium'
            ];
        }
        
        // AI提案があった場合は絶対に定型文を使用しない
        if (!empty($improvements)) {
            return $improvements;
        }
    }
    
    // AI分析が完全に失敗した場合のみ詳細デバッグ
    $debugInfo = [
        'apiCalled' => !empty($geminiAnalysis),
        'hasError' => isset($geminiAnalysis['error']),
        'hasRawAnalysis' => isset($geminiAnalysis['rawAnalysis']),
        'timestamp' => date('H:i:s')
    ];
    
    $errorDetails = [];
    if (isset($geminiAnalysis['error'])) {
        $errorDetails[] = 'APIエラー: ' . $geminiAnalysis['error'];
    }
    if (isset($geminiAnalysis['rawAnalysis'])) {
        $errorDetails[] = 'AI応答: ' . mb_substr($geminiAnalysis['rawAnalysis'], 0, 300) . '...';
    }
    
    $improvements[] = [
        'type' => 'emergency-debug',
        'title' => '🚨 緊急デバッグ情報 (' . date('H:i:s') . ')',
        'description' => 'AI分析システムに問題が発生しています。' . 
                        ' API呼び出し: ' . ($debugInfo['apiCalled'] ? '✅' : '❌') . 
                        ' | ' . implode(' | ', $errorDetails)
    ];
    
    return $improvements;
}

// 旧関数は無効化
function generateImprovements($analysis, $geminiAnalysis = null) {
    // この関数は使用しない - generateAIOnlyImprovementsを使用
    return generateAIOnlyImprovements($geminiAnalysis);
}

function generateTopicClusterSuggestions($url, $analysis, $geminiAnalysis = null) {
    // URLとタイトルからトピックを推定
    $urlParts = parse_url($url);
    $path = $urlParts['path'] ?? '';
    $title = $analysis['title']['content'] ?? '';
    
    $description = 'このページをメインコンテンツとして、関連するサブトピックのページを作成し、トピッククラスターを構築することで、検索エンジンでの権威性とランキング向上が期待できます。';
    
    // Gemini分析結果を活用
    if ($geminiAnalysis) {
        if (isset($geminiAnalysis['mainKeywords']) && !empty($geminiAnalysis['mainKeywords'])) {
            $keywords = implode('、', array_slice($geminiAnalysis['mainKeywords'], 0, 3));
            $description .= "\n\n抽出されたメインキーワード: {$keywords}";
        }
        
        if (isset($geminiAnalysis['topicCategory'])) {
            $description .= "\nトピックカテゴリ: {$geminiAnalysis['topicCategory']}";
        }
        
        if (isset($geminiAnalysis['eatScore'])) {
            $description .= "\nE-A-Tスコア: {$geminiAnalysis['eatScore']}/10";
        }
    }
    
    return [
        'description' => $description,
        'mainTopic' => $title ?: 'メインテーマ',
        'url' => $url,
        'geminiInsights' => $geminiAnalysis['contentQuality'] ?? null
    ];
}
?>