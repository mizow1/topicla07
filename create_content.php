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

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'JSONデータの解析に失敗しました']);
    exit;
}

// デバッグ情報を追加
if (!isset($input['title']) && !isset($input['headings'])) {
    echo json_encode([
        'success' => false,
        'message' => '必要なデータが不足しています',
        'debug' => [
            'received_data' => $input,
            'has_title' => isset($input['title']),
            'has_headings' => isset($input['headings']),
            'content_type' => $input['contentType'] ?? 'unknown'
        ]
    ]);
    exit;
}

try {
    // Gemini APIを使用してマークダウンコンテンツを生成
    $content = generateMarkdownContentWithGemini($input, $gemini);
    
    echo json_encode([
        'success' => true,
        'content' => $content
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function generateMarkdownContentWithGemini($structure, $apiKey) {
    // コンテンツタイプ別の処理
    $contentType = $structure['contentType'] ?? 'traditional';
    
    if ($contentType === 'pillar') {
        $title = $structure['title'] ?? 'ピラーページ';
        $metaDescription = $structure['metaDescription'] ?? 'ピラーページの説明';
        $headings = generatePillarHeadings($structure);
    } elseif ($contentType === 'cluster') {
        $title = $structure['title'] ?? 'クラスターページ';
        $metaDescription = $structure['metaDescription'] ?? 'クラスターページの説明';
        $headings = generateClusterHeadings($structure);
    } else {
        $title = $structure['title'] ?? 'SEO記事';
        $metaDescription = $structure['metaDescription'] ?? 'SEO記事の説明';
        $headings = is_array($structure['headings']) ? $structure['headings'] : [];
    }
    
    // Geminiに送る詳細な構成情報
    $structureInfo = [
        'title' => $title,
        'metaDescription' => $metaDescription,
        'headings' => $headings
    ];
    
    // 追加情報があれば含める
    if (isset($structure['geminiAnalysis'])) {
        $structureInfo['aiAnalysis'] = $structure['geminiAnalysis'];
    }
    if (isset($structure['pillarContent'])) {
        $structureInfo['pillarContent'] = $structure['pillarContent'];
    }
    if (isset($structure['clusterPages'])) {
        $structureInfo['clusterPages'] = $structure['clusterPages'];
    }
    
    $headingsText = is_array($headings) ? implode("\n", $headings) : '';
    
    $prompt = "以下の構成に基づいて、SEOに最適化された高品質なマークダウン形式の記事を作成してください。

## 記事構成
タイトル: {$title}
メタディスクリプション: {$metaDescription}

見出し構成:
{$headingsText}

## 要件
1. **マークダウン形式**: 完全なマークダウン記事として出力
2. **フロントマター**: YAML形式でメタデータを含める
3. **SEO最適化**: キーワードを自然に配置
4. **読みやすさ**: 段落、リスト、表を適切に使用
5. **実用性**: 実践的で価値のある内容
6. **構造**: 目次、本文、FAQ、関連情報を含む
7. **文字数**: 最低20,000文字以上の詳細で包括的な内容
8. **深い専門性**: 各セクションで具体例、データ、実践的なアドバイスを豊富に含める

## 内容の方向性
- 初心者から上級者まで役立つ包括的な情報
- 具体例とステップバイステップの説明
- 最新のベストプラクティス
- 実装可能な具体的なアドバイス
- 関連する業界動向と将来展望
- 各見出しごとに1,000-2,000文字程度の詳細な内容
- 実際のデータや統計、事例を豊富に含める
- 読者が実際に行動に移せる具体的な手順
- 専門用語の詳細な解説
- 関連する周辺知識も含めた包括的な情報

## マークダウン形式の例
```markdown
---
title: \"記事タイトル\"
description: \"メタディスクリプション\"
date: 2024-08-28
keywords: [\"キーワード1\", \"キーワード2\"]
author: \"AI Content Generator\"
---

# 記事タイトル

導入文...

## 目次

1. [セクション1](#section1)
2. [セクション2](#section2)

## セクション1 {#section1}

内容...

### サブセクション

詳細内容...

## FAQ

### Q: 質問
A: 回答

---
*この記事は2024年8月28日に更新されました。*
```

上記の要件に従って、完全なマークダウン記事を生成してください。";

    return callGeminiForContent($prompt, $apiKey);
}

function callGeminiForContent($prompt, $apiKey) {
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.5,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 8192, // 20,000文字以上の長いコンテンツのために大幅に増加
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 20,000文字以上の長いコンテンツ生成のためにタイムアウトを大幅に延長
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        // フォールバック: Gemini APIが失敗した場合は基本的なマークダウンを生成
        return generateFallbackMarkdown();
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return generateFallbackMarkdown();
    }
    
    $geminiText = $result['candidates'][0]['content']['parts'][0]['text'];
    
    // マークダウンコンテンツをクリーンアップ
    $geminiText = cleanupMarkdown($geminiText);
    
    return $geminiText;
}

function cleanupMarkdown($markdown) {
    // ```markdown タグがある場合は除去
    $markdown = preg_replace('/^```markdown\s*\n?/m', '', $markdown);
    $markdown = preg_replace('/\n?```\s*$/m', '', $markdown);
    
    // 余分な空白行を整理
    $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
    
    // 現在の日付に更新
    $currentDate = date('Y年m月d日');
    $markdown = preg_replace('/\*この記事は.*に更新されました。\*/', "*この記事は{$currentDate}に更新されました。*", $markdown);
    
    return trim($markdown);
}

function generatePillarHeadings($structure) {
    $mainTopic = $structure['mainTopic'] ?? '主要テーマ';
    $targetKeywords = $structure['targetKeywords'] ?? ['キーワード'];
    $keyword = $targetKeywords[0] ?? '主要テーマ';
    
    return [
        'H1: ' . $structure['title'],
        'H2: ' . $keyword . 'とは？包括的な解説',
        'H2: ' . $keyword . 'の重要性とメリット',
        'H2: ' . $keyword . 'の基本的な仕組み',
        'H2: ' . $keyword . 'の具体的な活用方法',
        'H3: 初心者向けの始め方',
        'H3: 中級者向けの応用テクニック',
        'H3: 上級者向けの最適化手法',
        'H2: ' . $keyword . 'を選ぶ際のポイント',
        'H2: ' . $keyword . 'でよくある課題と解決策',
        'H2: ' . $keyword . 'の最新トレンドと将来展望',
        'H2: まとめ：' . $keyword . 'で成功するための重要ポイント'
    ];
}

function generateClusterHeadings($structure) {
    $specificTopic = $structure['specificTopic'] ?? '特定トピック';
    $targetKeywords = $structure['targetKeywords'] ?? ['キーワード'];
    $keyword = $targetKeywords[0] ?? '主要テーマ';
    
    return [
        'H1: ' . $structure['title'],
        'H2: ' . $specificTopic . 'の概要',
        'H2: ' . $keyword . 'の詳細な説明',
        'H2: ' . $keyword . 'の具体的な実装方法',
        'H3: ステップ1：準備段階',
        'H3: ステップ2：実行段階',
        'H3: ステップ3：評価・改善段階',
        'H2: ' . $keyword . 'でよくある問題と対処法',
        'H2: ' . $keyword . 'の成功事例',
        'H2: ' . $keyword . 'に関するよくある質問',
        'H2: まとめ：' . $keyword . 'を効果的に活用するために'
    ];
}

function generateFallbackMarkdown() {
    $currentDate = date('Y-m-d');
    
    return "---
title: \"SEO最適化記事\"
description: \"Gemini APIによる高品質なSEOコンテンツ\"
date: {$currentDate}
keywords: [\"SEO\", \"コンテンツマーケティング\", \"最適化\"]
author: \"AI Content Generator\"
---

# SEO最適化記事

この記事はGemini 2.0 Flash APIを使用して生成される予定でしたが、API接続に問題が発生しました。

## 基本的なSEO原則

### 1. キーワード研究
効果的なキーワード戦略を立てることは、SEO成功の基盤です。

### 2. コンテンツ品質
ユーザーに価値を提供する高品質なコンテンツを作成しましょう。

### 3. 技術的SEO
Webサイトの技術的な最適化も重要な要素です。

## 実践的なアドバイス

- 定期的なコンテンツ更新
- ユーザーエクスペリエンスの向上
- モバイル対応の確保

## FAQ

### Q: SEOの効果はいつ現れますか？
A: 通常、SEOの効果が現れるまでに3-6ヶ月程度かかります。

### Q: 最も重要なSEO要素は何ですか？
A: コンテンツの品質とユーザーエクスペリエンスが最も重要です。

---

*この記事は" . date('Y年m月d日') . "に更新されました。最新の情報については専門サイトをご確認ください。*";
}
?>