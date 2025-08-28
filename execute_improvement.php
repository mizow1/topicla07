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

$input = json_decode(file_get_contents('php://input'), true);

$url = $input['url'] ?? '';
$index = $input['index'] ?? 0;
$type = $input['type'] ?? '';
$title = $input['title'] ?? '';

if (empty($url) || empty($type)) {
    echo json_encode(['success' => false, 'message' => '必要なパラメータが不足しています']);
    exit;
}

try {
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
        echo json_encode(['success' => false, 'message' => 'ページの取得に失敗しました']);
        exit;
    }
    
    // Gemini APIを使用した改善提案を取得
    $result = executeImprovement($html, $url, $type, $gemini, $title);
    
    echo json_encode([
        'success' => true,
        'result' => $result
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function executeImprovement($html, $url, $type, $apiKey, $title = '') {
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    
    // フロントエンドからの改善タイプを処理
    if ($type === 'ai-generated' || $type === 'debug' || $type === 'fallback') {
        // AI分析結果の場合は個別の改善提案を実行
        return getGeminiSpecificImprovement($html, $url, $apiKey, $title);
    }
    
    // 従来の分析結果を取得
    $traditionalResult = '';
    
    switch ($type) {
        case 'title':
            $traditionalResult = improveTitleTag($dom, $xpath, $url);
            break;
            
        case 'meta':
            $traditionalResult = improveMetaDescription($dom, $xpath, $url);
            break;
            
        case 'heading':
            $traditionalResult = improveHeadingStructure($dom, $xpath, $url);
            break;
            
        case 'images':
            $traditionalResult = improveImageAltTags($dom, $xpath);
            break;
            
        case 'links':
            $traditionalResult = improveInternalLinks($dom, $xpath, $url);
            break;
            
        case 'gemini':
            // Gemini特化の詳細分析
            return getGeminiSpecificImprovement($html, $url, $apiKey, $title);
            
        default:
            // 不明なタイプの場合は包括的な分析を実行
            return getGeminiSpecificImprovement($html, $url, $apiKey, $title);
    }
    
    // GeminiのAI分析を追加
    $geminiEnhancement = enhanceWithGemini($html, $url, $type, $traditionalResult, $apiKey);
    
    return $traditionalResult . "\n\n" . $geminiEnhancement;
}

function enhanceWithGemini($html, $url, $type, $traditionalResult, $apiKey) {
    $text = strip_tags($html);
    $text = preg_replace('/\\s+/', ' ', trim($text));
    
    if (strlen($text) > 6000) {
        $text = substr($text, 0, 6000) . '...';
    }
    
    $typeDescriptions = [
        'title' => 'タイトルタグの最適化',
        'meta' => 'メタディスクリプションの改善',
        'heading' => '見出し構造の最適化',
        'images' => '画像のalt属性改善',
        'links' => '内部リンク戦略'
    ];
    
    $typeDescription = $typeDescriptions[$type] ?? '一般的なSEO改善';
    
    $prompt = "以下のWebページの{$typeDescription}について、より具体的で実践的な改善提案をしてください。

URL: {$url}

既存の分析結果:
{$traditionalResult}

ページ内容:
{$text}

以下の形式で回答してください：

## 💡 改善のポイント
- 現在の問題点の詳細分析
- 業界のベストプラクティスに基づく具体的な改善案
- 実装の優先度と期待される効果
- 競合他社との差別化ポイント
- ユーザー体験向上の観点

## ✅ 改善結果（コピー&ペースト可能）

**実装すべき具体的なコード・文案：**

```html
<!-- ここに実装可能なHTMLコード、メタタグ、構造化データなどを記載 -->
```

**改善後のテキスト文案：**
- 具体的な文案や設定値を記載

**設定・実装手順：**
1. ステップバイステップの手順

**測定・評価方法：**
- 効果測定の具体的な方法

回答は実践的で具体的な内容にし、マークダウン形式で見やすく整理してください。実際に本番サイトで使用できる具体的なコードと文案を必ず含めてください。";
    
    return callGeminiAPI($prompt, $apiKey);
}

function getGeminiSpecificImprovement($html, $url, $apiKey, $title = '') {
    $text = strip_tags($html);
    $text = preg_replace('/\\s+/', ' ', trim($text));
    
    if (strlen($text) > 6000) {
        $text = substr($text, 0, 6000) . '...';
    }
    
    $improvementFocus = !empty($title) ? "「{$title}」に特化した" : '';
    
    $prompt = "以下のWebページについて、{$improvementFocus}具体的で実装可能なSEO改善施策を提案してください。

URL: {$url}
改善対象: {$title}

ページ内容:
{$text}

以下の形式で、実装しやすい具体的な改善コードや文案を提供してください：

## 🎯 改善対象: {$title}

### 💡 改善のポイント
- このページの該当箇所の現状分析
- 具体的な問題点の特定
- 業界ベストプラクティスに基づく改善案
- 期待される効果（SEO・ユーザビリティ・CVR向上）
- 実装の優先度と注意点

### ✅ 改善結果（コピー&ペースト可能）
**実装すべき具体的なコード・文案：**

```html
<!-- ここに実装可能なHTMLコード、メタタグ、構造化データなどを記載 -->
```

**改善後のテキスト文案：**
- タイトル案: 「...」
- メタディスクリプション案: 「...」
- 見出し案: 「...」
- その他の具体的な文案

**設定・実装手順：**
1. 具体的なステップ1
2. 具体的なステップ2
3. 具体的なステップ3

**測定・評価方法：**
- 改善前後の比較項目
- 使用すべき分析ツール
- 効果測定の期間と指標

実際に本番サイトで使用できる具体的なコードと文案を必ず含めてください。";
    
    return callGeminiAPI($prompt, $apiKey);
}

function callGeminiAPI($prompt, $apiKey) {
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.4,
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
        return "\n\n## 🤖 AI分析結果\n\nGemini APIへの接続に失敗しました。基本的な分析結果をご確認ください。";
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return "\n\n## 🤖 AI分析結果\n\nGemini APIからの応答が不正でした。基本的な分析結果をご確認ください。";
    }
    
    $geminiText = $result['candidates'][0]['content']['parts'][0]['text'];
    
    return "\n\n## 🤖 AI分析による詳細改善提案\n\n" . $geminiText;
}

function improveTitleTag($dom, $xpath, $url) {
    $titleNodes = $xpath->query('//title');
    $currentTitle = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';
    
    // URLからページテーマを推測
    $urlParts = parse_url($url);
    $path = trim($urlParts['path'] ?? '', '/');
    $pathParts = explode('/', $path);
    $lastPart = end($pathParts);
    
    $suggestions = [];
    
    // 改善のポイントセクション
    $suggestions[] = "## 💡 改善のポイント";
    $suggestions[] = "";
    $suggestions[] = "**現在の状況:**";
    if (empty($currentTitle)) {
        $suggestions[] = "- タイトルタグが設定されていません";
        $suggestions[] = "- 検索結果での表示が不適切になる可能性があります";
    } else {
        $length = mb_strlen($currentTitle);
        $suggestions[] = "- 現在のタイトル: {$currentTitle}";
        $suggestions[] = "- 文字数: {$length}文字";
        
        if ($length < 30) {
            $suggestions[] = "- 問題点: タイトルが短すぎます（30文字未満）";
        } elseif ($length > 60) {
            $suggestions[] = "- 問題点: タイトルが長すぎます（60文字超）";
        } else {
            $suggestions[] = "- 状況: 文字数は適切ですが、さらに最適化可能です";
        }
    }
    
    $suggestions[] = "";
    $suggestions[] = "**SEOベストプラクティス:**";
    $suggestions[] = "- 30-60文字以内に収める";
    $suggestions[] = "- 主要キーワードを前方に配置";
    $suggestions[] = "- 各ページで重複しないユニークなタイトル";
    $suggestions[] = "- ユーザーがクリックしたくなる魅力的な文言";
    
    // 改善結果セクション
    $suggestions[] = "";
    $suggestions[] = "## ✅ 改善結果（コピー&ペースト可能）";
    $suggestions[] = "";
    $suggestions[] = "**実装すべき具体的なコード:**";
    $suggestions[] = "";
    $suggestions[] = "```html";
    
    if (empty($currentTitle)) {
        $improvedTitle = ucfirst(str_replace(['-', '_'], ' ', $lastPart)) . " - サイト名";
        $suggestions[] = "<title>{$improvedTitle}</title>";
    } else {
        $length = mb_strlen($currentTitle);
        if ($length < 30) {
            $improvedTitle = $currentTitle . " - 詳細説明";
            $suggestions[] = "<title>{$improvedTitle}</title>";
        } elseif ($length > 60) {
            $improvedTitle = mb_substr($currentTitle, 0, 50) . "...";
            $suggestions[] = "<title>{$improvedTitle}</title>";
        } else {
            $improvedTitle = $currentTitle . " | 改善版";
            $suggestions[] = "<title>{$improvedTitle}</title>";
        }
    }
    
    $suggestions[] = "```";
    $suggestions[] = "";
    $suggestions[] = "**改善後のタイトル案:**";
    if (empty($currentTitle)) {
        $suggestions[] = "- 案1: " . ucfirst(str_replace(['-', '_'], ' ', $lastPart)) . " - サイト名";
        $suggestions[] = "- 案2: 関連キーワードを含むタイトル - サイト名";
        $suggestions[] = "- 案3: 具体的なサービス・商品名 - 説明文";
    } else {
        $length = mb_strlen($currentTitle);
        if ($length < 30) {
            $suggestions[] = "- 案1: " . $currentTitle . " - 詳細説明を追加";
            $suggestions[] = "- 案2: " . $currentTitle . " | 関連キーワード | サイト名";
        } elseif ($length > 60) {
            $shorterTitle = mb_substr($currentTitle, 0, 50);
            $suggestions[] = "- 案1: " . $shorterTitle;
            $suggestions[] = "- 案2: 核となるキーワードのみに絞った短縮版";
        } else {
            $suggestions[] = "- 案1: より具体的なキーワードを含む版";
            $suggestions[] = "- 案2: ユーザーの検索意図により合致した版";
        }
    }
    
    $suggestions[] = "";
    $suggestions[] = "**設定・実装手順:**";
    $suggestions[] = "1. HTMLの<head>セクション内の<title>タグを確認";
    $suggestions[] = "2. 上記のコードで既存のタイトルを置き換え";
    $suggestions[] = "3. ページの内容に合わせて具体的なキーワードに調整";
    $suggestions[] = "4. 他のページと重複しないことを確認";
    
    $suggestions[] = "";
    $suggestions[] = "**測定・評価方法:**";
    $suggestions[] = "- Google Search Consoleでクリック率（CTR）を監視";
    $suggestions[] = "- 検索順位の変動を3-4週間追跡";
    $suggestions[] = "- タイトル変更前後のオーガニック流入数を比較";
    
    return implode("\n", $suggestions);
}

function improveMetaDescription($dom, $xpath, $url) {
    $metaNodes = $xpath->query('//meta[@name="description"]');
    $currentMeta = $metaNodes->length > 0 ? trim($metaNodes->item(0)->getAttribute('content')) : '';
    
    $suggestions = [];
    
    // 改善のポイントセクション
    $suggestions[] = "## 💡 改善のポイント";
    $suggestions[] = "";
    $suggestions[] = "**現在の状況:**";
    
    if (empty($currentMeta)) {
        $suggestions[] = "- メタディスクリプションが設定されていません";
        $suggestions[] = "- 検索エンジンが自動で抜粋を生成するため、最適でない可能性があります";
    } else {
        $length = mb_strlen($currentMeta);
        $suggestions[] = "- 現在のメタディスクリプション: {$currentMeta}";
        $suggestions[] = "- 文字数: {$length}文字";
        
        if ($length < 120) {
            $suggestions[] = "- 問題点: 説明が短すぎます（120文字未満）";
        } elseif ($length > 160) {
            $suggestions[] = "- 問題点: 説明が長すぎます（160文字超）";
        } else {
            $suggestions[] = "- 状況: 文字数は適切ですが、さらに最適化可能です";
        }
    }
    
    $suggestions[] = "";
    $suggestions[] = "**ベストプラクティス:**";
    $suggestions[] = "- 120-160文字以内に収める";
    $suggestions[] = "- 各ページでユニークな内容";
    $suggestions[] = "- 主要キーワードを自然に含める";
    $suggestions[] = "- ユーザーのクリックを促す魅力的な内容";
    $suggestions[] = "- 検索結果のスニペット（説明文）として表示される";
    
    // 改善結果セクション
    $suggestions[] = "";
    $suggestions[] = "## ✅ 改善結果（コピー&ペースト可能）";
    $suggestions[] = "";
    $suggestions[] = "**実装すべき具体的なコード:**";
    $suggestions[] = "";
    $suggestions[] = "```html";
    
    if (empty($currentMeta)) {
        $improvedMeta = "このページの主要な内容を120-160文字で魅力的に要約した説明文。主要キーワードを含み、ユーザーがクリックしたくなる内容に調整してください。";
        $suggestions[] = "<meta name=\"description\" content=\"{$improvedMeta}\">";
    } else {
        $length = mb_strlen($currentMeta);
        if ($length < 120) {
            $improvedMeta = $currentMeta . "より詳細な説明を追加して120-160文字に拡張。具体的なメリットやCTAを含める。";
            if (mb_strlen($improvedMeta) > 160) {
                $improvedMeta = mb_substr($improvedMeta, 0, 157) . "...";
            }
            $suggestions[] = "<meta name=\"description\" content=\"{$improvedMeta}\">";
        } elseif ($length > 160) {
            $improvedMeta = mb_substr($currentMeta, 0, 157) . "...";
            $suggestions[] = "<meta name=\"description\" content=\"{$improvedMeta}\">";
        } else {
            $improvedMeta = $currentMeta . " | 最適化版";
            if (mb_strlen($improvedMeta) > 160) {
                $improvedMeta = mb_substr($currentMeta, 0, 147) . " | 最適化版";
            }
            $suggestions[] = "<meta name=\"description\" content=\"{$improvedMeta}\">";
        }
    }
    
    $suggestions[] = "```";
    $suggestions[] = "";
    $suggestions[] = "**改善後のメタディスクリプション案:**";
    
    if (empty($currentMeta)) {
        $suggestions[] = "- 案1: このページの主要な内容を120-160文字で魅力的に要約";
        $suggestions[] = "- 案2: 検索ユーザーがクリックしたくなる説明文";
        $suggestions[] = "- 案3: 主要キーワードを自然に含めた説明";
    } else {
        $length = mb_strlen($currentMeta);
        if ($length < 120) {
            $suggestions[] = "- 案1: " . $currentMeta . " + 詳細説明を追加";
            $suggestions[] = "- 案2: 具体的なメリットや特徴を含めた拡張版";
            $suggestions[] = "- 案3: CTA（行動喚起）を含めた魅力的な版";
        } elseif ($length > 160) {
            $suggestions[] = "- 案1: 160文字以内に短縮した版";
            $suggestions[] = "- 案2: 最も重要な情報に絞った版";
            $suggestions[] = "- 案3: 魅力的な要点を維持した短縮版";
        } else {
            $suggestions[] = "- 案1: より魅力的な表現に改善した版";
            $suggestions[] = "- 案2: 数字や具体的な情報を含めた版";
            $suggestions[] = "- 案3: 感情に訴える表現を追加した版";
        }
    }
    
    $suggestions[] = "";
    $suggestions[] = "**設定・実装手順:**";
    $suggestions[] = "1. HTMLの<head>セクション内のメタディスクリプションを確認";
    $suggestions[] = "2. 上記のコードで既存の記述を置き換え";
    $suggestions[] = "3. ページの内容に合わせて具体的な説明に調整";
    $suggestions[] = "4. 120-160文字以内になることを確認";
    $suggestions[] = "5. 他のページと重複しないユニークな内容にする";
    
    $suggestions[] = "";
    $suggestions[] = "**測定・評価方法:**";
    $suggestions[] = "- Google Search Consoleでクリック率（CTR）を監視";
    $suggestions[] = "- 検索結果でのスニペット表示を確認";
    $suggestions[] = "- オーガニック流入数の変動を2-3週間追跡";
    $suggestions[] = "- 検索順位とクリック率の相関を分析";
    
    return implode("\n", $suggestions);
}

function improveHeadingStructure($dom, $xpath, $url) {
    $suggestions = [];
    $headingStructure = [];
    
    // 現在の見出し構造を分析
    for ($i = 1; $i <= 6; $i++) {
        $hNodes = $xpath->query("//h{$i}");
        foreach ($hNodes as $node) {
            $headingStructure["h{$i}"][] = trim($node->textContent);
        }
    }
    
    $suggestions[] = "見出し構造の改善提案:";
    $suggestions[] = "";
    
    // H1タグの分析
    if (empty($headingStructure['h1'])) {
        $suggestions[] = "H1タグが見つかりません:";
        $suggestions[] = "• ページの最も重要なテーマを表すH1タグを1つ追加";
        $suggestions[] = "• タイトルタグと関連性のある内容にする";
        $suggestions[] = "• 主要キーワードを含める";
    } elseif (count($headingStructure['h1']) > 1) {
        $suggestions[] = "H1タグが複数あります (" . count($headingStructure['h1']) . "個):";
        $suggestions[] = "• H1タグは1ページに1つが推奨";
        $suggestions[] = "• 最も重要な1つを残し、他はH2以下に変更";
        $suggestions[] = "現在のH1タグ:";
        foreach ($headingStructure['h1'] as $h1) {
            $suggestions[] = "  - " . $h1;
        }
    } else {
        $suggestions[] = "H1タグ: " . $headingStructure['h1'][0];
        $suggestions[] = "✓ H1タグは適切に設定されています";
    }
    
    $suggestions[] = "";
    
    // H2, H3タグの構造提案
    $h2Count = count($headingStructure['h2'] ?? []);
    $h3Count = count($headingStructure['h3'] ?? []);
    
    if ($h2Count === 0) {
        $suggestions[] = "H2タグが見つかりません:";
        $suggestions[] = "• コンテンツを論理的なセクションに分割";
        $suggestions[] = "• 各セクションにH2タグを追加";
        $suggestions[] = "• H1の下位概念となるサブトピックを設定";
    } else {
        $suggestions[] = "現在のH2タグ (" . $h2Count . "個):";
        foreach ($headingStructure['h2'] ?? [] as $h2) {
            $suggestions[] = "  - " . $h2;
        }
    }
    
    $suggestions[] = "";
    $suggestions[] = "理想的な見出し構造の例:";
    $suggestions[] = "H1: ページのメインテーマ";
    $suggestions[] = "  H2: 主要なセクション1";
    $suggestions[] = "    H3: セクション1の詳細項目";
    $suggestions[] = "    H3: セクション1の詳細項目";
    $suggestions[] = "  H2: 主要なセクション2";
    $suggestions[] = "    H3: セクション2の詳細項目";
    $suggestions[] = "  H2: 主要なセクション3";
    
    $suggestions[] = "";
    $suggestions[] = "見出しタグのベストプラクティス:";
    $suggestions[] = "• 階層構造を正しく保つ（H1→H2→H3...）";
    $suggestions[] = "• 見出しレベルを飛ばさない";
    $suggestions[] = "• 各見出しは内容を適切に表現";
    $suggestions[] = "• キーワードを自然に含める";
    
    return implode("\n", $suggestions);
}

function improveImageAltTags($dom, $xpath) {
    $imgNodes = $xpath->query('//img');
    $suggestions = [];
    $imagesWithoutAlt = [];
    $imagesWithAlt = [];
    
    foreach ($imgNodes as $img) {
        $src = $img->getAttribute('src');
        $alt = $img->getAttribute('alt');
        
        if (empty(trim($alt))) {
            $imagesWithoutAlt[] = $src;
        } else {
            $imagesWithAlt[] = ['src' => $src, 'alt' => $alt];
        }
    }
    
    $suggestions[] = "画像のalt属性改善提案:";
    $suggestions[] = "";
    
    if (!empty($imagesWithoutAlt)) {
        $suggestions[] = "alt属性が未設定の画像 (" . count($imagesWithoutAlt) . "個):";
        foreach (array_slice($imagesWithoutAlt, 0, 5) as $src) {
            $suggestions[] = "• " . $src;
            $suggestions[] = "  → 画像の内容を具体的に説明するalt属性を追加";
        }
        
        if (count($imagesWithoutAlt) > 5) {
            $suggestions[] = "• ...他 " . (count($imagesWithoutAlt) - 5) . " 個";
        }
        $suggestions[] = "";
    }
    
    if (!empty($imagesWithAlt)) {
        $suggestions[] = "alt属性が設定済みの画像例:";
        foreach (array_slice($imagesWithAlt, 0, 3) as $img) {
            $suggestions[] = "• " . $img['src'];
            $suggestions[] = "  alt: " . $img['alt'];
        }
        $suggestions[] = "";
    }
    
    $suggestions[] = "alt属性のベストプラクティス:";
    $suggestions[] = "• 画像の内容を具体的かつ簡潔に説明";
    $suggestions[] = "• 装飾的な画像には空のalt属性 (alt=\"\")";
    $suggestions[] = "• 文脈に合った説明を心がける";
    $suggestions[] = "• キーワードスタッフィングは避ける";
    $suggestions[] = "• 125文字以内を目安に";
    
    $suggestions[] = "";
    $suggestions[] = "alt属性の例:";
    $suggestions[] = "❌ 悪い例: alt=\"画像\"";
    $suggestions[] = "✅ 良い例: alt=\"青いシャツを着た男性がラップトップで作業している様子\"";
    $suggestions[] = "❌ 悪い例: alt=\"SEO対策 マーケティング 集客 売上\"";
    $suggestions[] = "✅ 良い例: alt=\"SEO対策による検索順位向上を示すグラフ\"";
    
    return implode("\n", $suggestions);
}

function improveInternalLinks($dom, $xpath, $url) {
    $linkNodes = $xpath->query('//a[@href]');
    $suggestions = [];
    $internalLinks = [];
    $externalLinks = [];
    
    $urlParts = parse_url($url);
    $domain = $urlParts['host'] ?? '';
    
    foreach ($linkNodes as $link) {
        $href = $link->getAttribute('href');
        $text = trim($link->textContent);
        
        if (strpos($href, 'http') === 0) {
            $linkDomain = parse_url($href, PHP_URL_HOST);
            if ($linkDomain === $domain) {
                $internalLinks[] = ['href' => $href, 'text' => $text];
            } else {
                $externalLinks[] = ['href' => $href, 'text' => $text];
            }
        } else {
            $internalLinks[] = ['href' => $href, 'text' => $text];
        }
    }
    
    $suggestions[] = "内部リンク改善提案:";
    $suggestions[] = "";
    $suggestions[] = "現在の状況:";
    $suggestions[] = "• 内部リンク: " . count($internalLinks) . "個";
    $suggestions[] = "• 外部リンク: " . count($externalLinks) . "個";
    $suggestions[] = "";
    
    if (count($internalLinks) < 3) {
        $suggestions[] = "内部リンクが不足しています:";
        $suggestions[] = "• 関連する他のページへのリンクを追加";
        $suggestions[] = "• サイト内の主要ページへのリンクを含める";
        $suggestions[] = "• コンテンツの文脈に自然に組み込む";
        $suggestions[] = "";
        
        $suggestions[] = "追加を検討すべき内部リンク:";
        $suggestions[] = "• ホームページへのリンク";
        $suggestions[] = "• 関連記事・関連商品ページ";
        $suggestions[] = "• カテゴリーページ";
        $suggestions[] = "• お問い合わせページ";
        $suggestions[] = "• 会社情報・プロフィールページ";
        $suggestions[] = "";
    }
    
    if (!empty($internalLinks)) {
        $suggestions[] = "現在の内部リンク例:";
        foreach (array_slice($internalLinks, 0, 5) as $link) {
            $suggestions[] = "• " . $link['text'] . " → " . $link['href'];
        }
        $suggestions[] = "";
    }
    
    $suggestions[] = "内部リンクのベストプラクティス:";
    $suggestions[] = "• 関連性の高いページにリンク";
    $suggestions[] = "• アンカーテキストは具体的かつ自然に";
    $suggestions[] = "• 「こちら」「詳しくは」などの曖昧な表現は避ける";
    $suggestions[] = "• 1ページあたり3-5個の内部リンクが理想的";
    $suggestions[] = "• リンク先ページの内容を適切に表現";
    
    $suggestions[] = "";
    $suggestions[] = "アンカーテキストの例:";
    $suggestions[] = "❌ 悪い例: 「こちらをクリック」";
    $suggestions[] = "✅ 良い例: 「SEO対策の詳細ガイド」";
    $suggestions[] = "❌ 悪い例: 「詳しくはこちら」";
    $suggestions[] = "✅ 良い例: 「WordPressのSEOプラグイン設定方法」";
    
    return implode("\n", $suggestions);
}
?>