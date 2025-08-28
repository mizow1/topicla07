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
    $result = executeImprovement($html, $url, $type, $gemini);
    
    echo json_encode([
        'success' => true,
        'result' => $result
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function executeImprovement($html, $url, $type, $apiKey) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    
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
            return getGeminiSpecificImprovement($html, $url, $apiKey);
            
        default:
            return '不明な改善タイプです';
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

以下の観点から具体的な改善案を提案してください：
1. 現在の問題点の詳細分析
2. 業界のベストプラクティスに基づく具体的な改善案
3. 実装の優先度と期待される効果
4. 競合他社との差別化ポイント
5. ユーザー体験向上の観点

回答は実践的で具体的な内容にし、マークダウン形式で見やすく整理してください。";
    
    return callGeminiAPI($prompt, $apiKey);
}

function getGeminiSpecificImprovement($html, $url, $apiKey) {
    $text = strip_tags($html);
    $text = preg_replace('/\\s+/', ' ', trim($text));
    
    if (strlen($text) > 6000) {
        $text = substr($text, 0, 6000) . '...';
    }
    
    $prompt = "以下のWebページについて、包括的なSEO改善戦略を提案してください。

URL: {$url}

ページ内容:
{$text}

以下の点を含めて詳細な分析と改善提案をしてください：

## 分析項目
1. **コンテンツ品質評価**
   - 独自性と価値
   - 情報の正確性と信頼性
   - ユーザーニーズへの適合性

2. **技術的SEO要素**
   - ページ構造の最適化
   - メタデータの改善
   - 読み込み速度の観点

3. **ユーザーエクスペリエンス**
   - 可読性と理解しやすさ
   - ナビゲーションの改善
   - モバイル対応

4. **E-A-T（専門性・権威性・信頼性）**
   - 専門知識の深さ
   - 情報源の信頼性
   - 著者・サイトの権威性

## 具体的な改善提案
- 優先度の高い改善項目（3-5個）
- 中長期的な戦略
- 競合との差別化ポイント
- 測定可能な目標設定

マークダウン形式で、実装しやすい具体的な提案をしてください。";
    
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
    
    if (empty($currentTitle)) {
        // タイトルが存在しない場合の提案
        $suggestions[] = "URLから推測される改善タイトル案:";
        $suggestions[] = "• " . ucfirst(str_replace(['-', '_'], ' ', $lastPart)) . " - サイト名";
        $suggestions[] = "• 関連キーワードを含むタイトル - サイト名";
        $suggestions[] = "• 具体的なサービス・商品名 - 説明文";
    } else {
        // 既存タイトルの改善提案
        $length = mb_strlen($currentTitle);
        
        if ($length < 30) {
            $suggestions[] = "現在のタイトルを拡張した改善案:";
            $suggestions[] = "• " . $currentTitle . " - 詳細説明を追加";
            $suggestions[] = "• " . $currentTitle . " | 関連キーワード | サイト名";
        } elseif ($length > 60) {
            $suggestions[] = "タイトルを短縮した改善案:";
            $shorterTitle = mb_substr($currentTitle, 0, 50) . "...";
            $suggestions[] = "• " . $shorterTitle;
            $suggestions[] = "• 核となるキーワードのみに絞った短縮版";
        } else {
            $suggestions[] = "現在のタイトル最適化案:";
            $suggestions[] = "• より具体的なキーワードを含む版";
            $suggestions[] = "• ユーザーの検索意図により合致した版";
        }
    }
    
    $suggestions[] = "";
    $suggestions[] = "SEOベストプラクティス:";
    $suggestions[] = "• 30-60文字以内";
    $suggestions[] = "• 主要キーワードを前方に配置";
    $suggestions[] = "• 各ページで重複しないユニークなタイトル";
    $suggestions[] = "• ユーザーがクリックしたくなる魅力的な文言";
    
    return implode("\n", $suggestions);
}

function improveMetaDescription($dom, $xpath, $url) {
    $metaNodes = $xpath->query('//meta[@name="description"]');
    $currentMeta = $metaNodes->length > 0 ? trim($metaNodes->item(0)->getAttribute('content')) : '';
    
    $suggestions = [];
    
    if (empty($currentMeta)) {
        $suggestions[] = "メタディスクリプション作成提案:";
        $suggestions[] = "• このページの主要な内容を120-160文字で要約";
        $suggestions[] = "• 検索ユーザーがクリックしたくなる魅力的な説明文";
        $suggestions[] = "• 主要キーワードを自然に含める";
    } else {
        $length = mb_strlen($currentMeta);
        
        if ($length < 120) {
            $suggestions[] = "メタディスクリプション拡張提案:";
            $suggestions[] = "現在: " . $currentMeta;
            $suggestions[] = "";
            $suggestions[] = "改善案:";
            $suggestions[] = "• より詳細な説明を追加して120-160文字に";
            $suggestions[] = "• 具体的なメリットや特徴を含める";
            $suggestions[] = "• CTA（行動喚起）を含める";
        } elseif ($length > 160) {
            $suggestions[] = "メタディスクリプション短縮提案:";
            $suggestions[] = "現在: " . $currentMeta;
            $suggestions[] = "";
            $suggestions[] = "改善案:";
            $suggestions[] = "• 160文字以内に短縮";
            $suggestions[] = "• 最も重要な情報に絞る";
            $suggestions[] = "• 魅力的な要点を維持";
        } else {
            $suggestions[] = "メタディスクリプション最適化提案:";
            $suggestions[] = "現在: " . $currentMeta;
            $suggestions[] = "";
            $suggestions[] = "改善案:";
            $suggestions[] = "• より魅力的な表現に改善";
            $suggestions[] = "• 数字や具体的な情報を含める";
            $suggestions[] = "• 感情に訴える表現を追加";
        }
    }
    
    $suggestions[] = "";
    $suggestions[] = "メタディスクリプションのベストプラクティス:";
    $suggestions[] = "• 120-160文字以内";
    $suggestions[] = "• 各ページでユニークな内容";
    $suggestions[] = "• 主要キーワードを含める";
    $suggestions[] = "• ユーザーのクリックを促す魅力的な内容";
    $suggestions[] = "• スニペット（検索結果画面の説明文）として表示される";
    
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