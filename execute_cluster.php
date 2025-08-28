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
    
    // DOMDocumentでHTMLを解析
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    
    // 現在のページ情報を分析
    $pageAnalysis = analyzePageForCluster($dom, $xpath, $url);
    
    // Gemini APIを使用してトピッククラスター構成を生成
    $clusterStructure = generateTopicClusterStructure($pageAnalysis, $url, $html, $gemini);
    
    echo json_encode([
        'success' => true,
        'result' => $clusterStructure
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function analyzePageForCluster($dom, $xpath, $url) {
    // タイトル取得
    $titleNodes = $xpath->query('//title');
    $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';
    
    // メタディスクリプション取得
    $metaNodes = $xpath->query('//meta[@name="description"]');
    $metaDescription = $metaNodes->length > 0 ? trim($metaNodes->item(0)->getAttribute('content')) : '';
    
    // 見出し取得
    $headings = [];
    for ($i = 1; $i <= 3; $i++) {
        $hNodes = $xpath->query("//h{$i}");
        foreach ($hNodes as $node) {
            $headings["h{$i}"][] = trim($node->textContent);
        }
    }
    
    // URL分析
    $urlParts = parse_url($url);
    $path = trim($urlParts['path'] ?? '', '/');
    $pathSegments = explode('/', $path);
    
    return [
        'title' => $title,
        'metaDescription' => $metaDescription,
        'headings' => $headings,
        'url' => $url,
        'pathSegments' => $pathSegments,
        'domain' => $urlParts['host'] ?? ''
    ];
}

function generateTopicClusterStructure($analysis, $url, $html, $apiKey) {
    $title = $analysis['title'];
    $currentMeta = $analysis['metaDescription'];
    $pathSegments = $analysis['pathSegments'];
    
    // 従来の分析結果
    $mainKeywords = extractMainKeywords($title, $currentMeta, $pathSegments);
    $industry = detectIndustry($title, $currentMeta, $url);
    
    // Gemini APIを使用してより高度なトピッククラスター分析
    $geminiClusterAnalysis = analyzeTopicClusterWithGemini($html, $url, $apiKey);
    
    // 従来の結果とGemini分析を組み合わせ
    $clusterStructure = [
        'pillarPage' => $geminiClusterAnalysis['pillarPage'] ?? [
            'type' => 'pillar',
            'title' => generateOptimizedTitle($mainKeywords, $industry),
            'metaDescription' => generateOptimizedMetaDescription($mainKeywords, $industry),
            'mainTopic' => !empty($mainKeywords) ? $mainKeywords[0] : '主要テーマ',
            'targetKeywords' => $mainKeywords,
            'description' => 'このピラーページでは、' . (!empty($mainKeywords) ? $mainKeywords[0] : '主要テーマ') . 'に関する包括的な情報を提供し、関連する全ての側面を網羅します。初心者から上級者まで役立つ実践的な内容で構成されています。'
        ],
        'clusterPages' => $geminiClusterAnalysis['clusterPages'] ?? generateEnhancedClusterPages($mainKeywords, $industry),
        'linkingStrategy' => $geminiClusterAnalysis['linkingStrategy'] ?? generateLinkingStrategy($mainKeywords),
        'measurableGoals' => $geminiClusterAnalysis['measurableGoals'] ?? [
            'primaryKeywords' => $mainKeywords,
            'expectedTraffic' => '月間検索流入を30-50%増加',
            'conversionGoals' => 'お問い合わせ率の向上とエンゲージメント指標の改善'
        ],
        'geminiAnalysis' => $geminiClusterAnalysis
    ];
    
    return $clusterStructure;
}

function analyzeTopicClusterWithGemini($html, $url, $apiKey) {
    $text = strip_tags($html);
    $text = preg_replace('/\\s+/', ' ', trim($text));
    
    if (strlen($text) > 8000) {
        $text = substr($text, 0, 8000) . '...';
    }
    
    $prompt = "このWebページを中心としたトピッククラスター戦略を設計してください。

URL: {$url}
ページ内容: {$text}

## トピッククラスター理論について
トピッククラスターは以下の構造で構成されます：
1. **ピラーページ（Hub）**: 広範囲なトピックを包括的にカバーする中心的なページ
2. **クラスターページ（Spoke）**: ピラーページのトピックに関連する個別の詳細記事
3. **内部リンク**: ピラーページとクラスターページを相互にリンクし、権威性を向上

以下のJSON形式で、正確なトピッククラスター構造を提案してください：

{
  \"pillarPage\": {
    \"type\": \"pillar\",
    \"title\": \"包括的なピラーページタイトル（50-60文字）\",
    \"metaDescription\": \"ピラーページ用メタディスクリプション（140-160文字）\",
    \"mainTopic\": \"中心となる広範囲なトピック\",
    \"targetKeywords\": [\"メインキーワード\", \"関連キーワード\"],
    \"description\": \"このピラーページが網羅すべき内容の概要\"
  },
  \"clusterPages\": [
    {
      \"type\": \"cluster\",
      \"title\": \"個別トピック記事のタイトル\",
      \"metaDescription\": \"クラスター記事用メタディスクリプション\",
      \"specificTopic\": \"このページが深掘りする特定のサブトピック\",
      \"targetKeywords\": [\"ロングテールキーワード1\", \"ロングテールキーワード2\"],
      \"relationToPillar\": \"ピラーページとの関係性\",
      \"searchIntent\": \"検索意図の分類（情報収集/商品調査/取引型/ナビゲーション）\"
    }
  ],
  \"linkingStrategy\": {
    \"pillarToCluster\": \"ピラーページからクラスターページへのリンク戦略\",
    \"clusterToPillar\": \"クラスターページからピラーページへのリンク戦略\",
    \"clusterToCluster\": \"クラスター同士のリンク戦略\"
  },
  \"contentGaps\": [\"現在不足している関連コンテンツ\"],
  \"competitorAnalysis\": \"競合サイトとの差別化ポイント\",
  \"measurableGoals\": {
    \"primaryKeywords\": [\"順位向上を目指すキーワード\"],
    \"expectedTraffic\": \"期待される流入増加\",
    \"conversionGoals\": \"コンバージョン目標\"
  }
}

重要：
- ピラーページは1つの広範囲なトピックを扱う
- クラスターページは最低5-8個提案し、それぞれ異なる検索意図に対応
- 各ページが独立したSEO価値を持ちつつ、相互に支え合う構造
- 実際に実装可能な具体的な内容で提案";
    
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
            'insights' => 'AI分析を取得できませんでした'
        ];
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return [
            'error' => 'Gemini APIからの応答が不正です',
            'insights' => 'AI分析を取得できませんでした'
        ];
    }
    
    $geminiText = $result['candidates'][0]['content']['parts'][0]['text'];
    
    // JSONレスポンスを抽出
    if (preg_match('/\\{[\\s\\S]*\\}/', $geminiText, $matches)) {
        $jsonData = json_decode($matches[0], true);
        if ($jsonData) {
            return $jsonData;
        }
    }
    
    // JSON形式でない場合は、テキストをパースして構造化
    return [
        'insights' => $geminiText,
        'title' => 'AI生成タイトル（JSON解析失敗）',
        'metaDescription' => 'AI生成メタディスクリプション（JSON解析失敗）',
        'headings' => ['H1: AI分析結果を確認してください'],
        'error' => 'JSON解析に失敗しましたが、テキスト分析は取得できました'
    ];
}

function extractMainKeywords($title, $metaDescription, $pathSegments) {
    $keywords = [];
    
    // タイトルからキーワード抽出
    if (!empty($title)) {
        $titleWords = preg_split('/[\s\-_|]+/', $title);
        $keywords = array_merge($keywords, array_filter($titleWords, function($word) {
            return mb_strlen($word) > 2 && !in_array(strtolower($word), ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'について', 'とは', 'する', 'です', 'ます']);
        }));
    }
    
    // URLパスからキーワード抽出
    foreach ($pathSegments as $segment) {
        if (!empty($segment) && $segment !== 'index' && $segment !== 'page') {
            $segmentWords = preg_split('/[\-_]+/', $segment);
            $keywords = array_merge($keywords, $segmentWords);
        }
    }
    
    // 重複除去と最大5個まで
    $keywords = array_unique($keywords);
    return array_slice($keywords, 0, 5);
}

function detectIndustry($title, $metaDescription, $url) {
    $content = strtolower($title . ' ' . $metaDescription . ' ' . $url);
    
    $industries = [
        'technology' => ['tech', 'technology', 'software', 'app', 'web', 'システム', 'テクノロジー', 'ソフトウェア', 'IT', 'プログラミング'],
        'business' => ['business', 'marketing', 'sales', 'consulting', 'ビジネス', 'マーケティング', '営業', 'コンサルティング', '経営'],
        'health' => ['health', 'medical', 'healthcare', 'fitness', '健康', '医療', 'フィットネス', 'ヘルスケア'],
        'education' => ['education', 'learning', 'school', 'university', '教育', '学習', '学校', '大学', 'スクール'],
        'ecommerce' => ['shop', 'store', 'ecommerce', 'retail', 'shopping', 'ショップ', '通販', 'EC', '販売'],
        'finance' => ['finance', 'investment', 'banking', 'money', '金融', '投資', '銀行', 'お金'],
        'travel' => ['travel', 'tourism', 'hotel', 'trip', '旅行', '観光', 'ホテル', '旅']
    ];
    
    foreach ($industries as $industry => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($content, $keyword) !== false) {
                return $industry;
            }
        }
    }
    
    return 'general';
}

function generateOptimizedTitle($keywords, $industry) {
    $mainKeyword = !empty($keywords) ? $keywords[0] : '主要テーマ';
    
    $templates = [
        'technology' => [
            $mainKeyword . 'の完全ガイド：初心者から上級者まで',
            $mainKeyword . 'を効率的に活用する方法｜最新技術動向',
            $mainKeyword . 'の導入から運用まで｜実践的ノウハウ'
        ],
        'business' => [
            $mainKeyword . 'で売上を伸ばす実践的な戦略',
            $mainKeyword . 'の成功事例と効果的な活用法',
            $mainKeyword . '入門：ビジネス成長のためのステップ'
        ],
        'health' => [
            $mainKeyword . 'で健康改善｜科学的根拠に基づく方法',
            $mainKeyword . 'の効果と正しい実践方法',
            $mainKeyword . '完全マニュアル｜健康的な生活のために'
        ],
        'education' => [
            $mainKeyword . 'を効率的に学ぶ方法｜学習ガイド',
            $mainKeyword . 'の基礎から応用まで｜体系的学習法',
            $mainKeyword . 'スキル向上のための実践的アプローチ'
        ],
        'general' => [
            $mainKeyword . 'の総合ガイド｜基本から実践まで',
            $mainKeyword . 'を効果的に活用する方法',
            $mainKeyword . 'の全てがわかる完全マニュアル'
        ]
    ];
    
    $industryTemplates = $templates[$industry] ?? $templates['general'];
    return $industryTemplates[array_rand($industryTemplates)];
}

function generateOptimizedMetaDescription($keywords, $industry) {
    $mainKeyword = !empty($keywords) ? $keywords[0] : '主要テーマ';
    $subKeywords = array_slice($keywords, 1, 2);
    $subKeywordText = !empty($subKeywords) ? implode('、', $subKeywords) . 'について' : '';
    
    $templates = [
        'technology' => $mainKeyword . 'の導入から活用まで、初心者にもわかりやすく解説。' . $subKeywordText . '実践的なノウハウと最新情報をお届けします。効率的な' . $mainKeyword . '活用で業務改善を実現しましょう。',
        'business' => $mainKeyword . 'でビジネス成長を実現するための実践的な戦略を紹介。' . $subKeywordText . '成功事例と効果的な手法で、売上向上と業務効率化を目指します。',
        'health' => $mainKeyword . 'による健康改善の方法を科学的根拠とともに解説。' . $subKeywordText . '正しい知識と実践方法で、健康的な生活をサポートします。',
        'education' => $mainKeyword . 'を効率的に学ぶための体系的なガイド。' . $subKeywordText . '基礎から応用まで段階的に学習し、実践的なスキルを身につけましょう。',
        'general' => $mainKeyword . 'に関する包括的な情報をわかりやすくまとめました。' . $subKeywordText . '基本知識から実践的な活用法まで、あなたの目的に合った情報を見つけられます。'
    ];
    
    return $templates[$industry] ?? $templates['general'];
}

function generateHeadingStructure($keywords, $industry) {
    $mainKeyword = !empty($keywords) ? $keywords[0] : '主要テーマ';
    
    $structures = [
        'technology' => [
            'H1: ' . $mainKeyword . 'とは？基礎知識から最新動向まで',
            'H2: ' . $mainKeyword . 'の基本概念と重要性',
            'H2: ' . $mainKeyword . 'の導入メリットとデメリット',
            'H2: ' . $mainKeyword . 'の具体的な活用方法',
            'H3: 初心者向けの始め方',
            'H3: 中級者向けの応用技術',
            'H3: 上級者向けの最適化手法',
            'H2: ' . $mainKeyword . 'の選び方と比較ポイント',
            'H2: ' . $mainKeyword . '導入時の注意点とトラブル対処法',
            'H2: ' . $mainKeyword . 'の将来性と最新トレンド',
            'H2: まとめ：' . $mainKeyword . 'で成功するためのポイント'
        ],
        'business' => [
            'H1: ' . $mainKeyword . 'でビジネスを成功させる完全ガイド',
            'H2: ' . $mainKeyword . 'がビジネスに与える影響',
            'H2: ' . $mainKeyword . 'を活用した成功事例',
            'H2: ' . $mainKeyword . 'の実践的な導入方法',
            'H3: 小規模企業での活用法',
            'H3: 中規模企業での戦略的活用',
            'H3: 大企業でのスケール活用',
            'H2: ' . $mainKeyword . 'のROIを最大化する方法',
            'H2: ' . $mainKeyword . '導入時の課題と解決策',
            'H2: ' . $mainKeyword . 'の最新トレンドと将来展望',
            'H2: まとめ：' . $mainKeyword . 'でビジネス成長を実現する'
        ],
        'general' => [
            'H1: ' . $mainKeyword . 'の完全ガイド：知っておくべき全ての情報',
            'H2: ' . $mainKeyword . 'とは？基礎知識の解説',
            'H2: ' . $mainKeyword . 'の種類と特徴',
            'H2: ' . $mainKeyword . 'の効果的な活用方法',
            'H3: 初心者が知るべき基本',
            'H3: 実践的な応用テクニック',
            'H3: 上級者向けの活用法',
            'H2: ' . $mainKeyword . 'を選ぶ際のポイント',
            'H2: ' . $mainKeyword . 'でよくある質問と回答',
            'H2: ' . $mainKeyword . 'の最新情報とトレンド',
            'H2: まとめ：' . $mainKeyword . 'を成功に活かすために'
        ]
    ];
    
    return $structures[$industry] ?? $structures['general'];
}

function generateEnhancedClusterPages($keywords, $industry) {
    $mainKeyword = !empty($keywords) ? $keywords[0] : '主要テーマ';
    
    $clusterPages = [
        [
            'type' => 'cluster',
            'title' => $mainKeyword . '入門：初心者のための基礎ガイド',
            'metaDescription' => $mainKeyword . 'の基本から学べる初心者向けガイド。わかりやすい解説で、基礎知識を確実に身につけることができます。',
            'specificTopic' => $mainKeyword . 'の基礎知識と導入方法',
            'targetKeywords' => [$mainKeyword . ' 初心者', $mainKeyword . ' 基礎', $mainKeyword . ' 入門', $mainKeyword . ' やり方'],
            'relationToPillar' => 'ピラーページで紹介される基礎部分を詳細に解説し、初心者が理解しやすい形で情報を提供',
            'searchIntent' => '情報収集'
        ],
        [
            'type' => 'cluster',
            'title' => $mainKeyword . '活用事例：成功例と失敗例から学ぶ実践ガイド',
            'metaDescription' => $mainKeyword . 'の実際の活用事例を紹介。成功例と失敗例の両方から学び、効果的な実践方法を理解できます。',
            'specificTopic' => '具体的な活用事例とケーススタディ',
            'targetKeywords' => [$mainKeyword . ' 事例', $mainKeyword . ' 成功例', $mainKeyword . ' ケーススタディ', $mainKeyword . ' 実例'],
            'relationToPillar' => 'ピラーページの理論を実践例で補完し、読者により具体的な理解を提供',
            'searchIntent' => '情報収集/商品調査'
        ],
        [
            'type' => 'cluster',
            'title' => $mainKeyword . 'ツール比較2024：おすすめの選び方と評価基準',
            'metaDescription' => $mainKeyword . '関連ツールを徹底比較。機能・価格・使いやすさから最適なツールを選ぶためのガイドです。',
            'specificTopic' => '関連ツールの比較と選定基準',
            'targetKeywords' => [$mainKeyword . ' ツール', $mainKeyword . ' 比較', $mainKeyword . ' おすすめ', $mainKeyword . ' 選び方'],
            'relationToPillar' => 'ピラーページで触れるツール選択について詳細な比較情報を提供',
            'searchIntent' => '商品調査/取引型'
        ],
        [
            'type' => 'cluster',
            'title' => $mainKeyword . '最新トレンド2024：業界動向と将来展望',
            'metaDescription' => $mainKeyword . '業界の最新トレンドと将来の展望を解説。今後の動向を把握し、戦略立案に活用できます。',
            'specificTopic' => '最新の業界動向と将来の展望',
            'targetKeywords' => [$mainKeyword . ' トレンド', $mainKeyword . ' 最新', $mainKeyword . ' 将来', $mainKeyword . ' 2024'],
            'relationToPillar' => 'ピラーページの内容をより時事性の高い情報で補完し、最新情報を提供',
            'searchIntent' => '情報収集'
        ],
        [
            'type' => 'cluster',
            'title' => $mainKeyword . 'でよくある質問50選：疑問解決FAQ集',
            'metaDescription' => $mainKeyword . 'に関するよくある質問を網羅的に回答。初心者から上級者まで役立つFAQ集です。',
            'specificTopic' => 'よくある質問と詳細な回答',
            'targetKeywords' => [$mainKeyword . ' FAQ', $mainKeyword . ' 質問', $mainKeyword . ' 疑問', $mainKeyword . ' 回答'],
            'relationToPillar' => 'ピラーページでカバーしきれない細かな疑問点に対する詳細回答を提供',
            'searchIntent' => '情報収集/ナビゲーション'
        ],
        [
            'type' => 'cluster',
            'title' => $mainKeyword . '実装手順：ステップバイステップガイド',
            'metaDescription' => $mainKeyword . 'の実装を段階的に解説。具体的な手順で、確実に導入・活用できるガイドです。',
            'specificTopic' => '具体的な実装手順と実践方法',
            'targetKeywords' => [$mainKeyword . ' 実装', $mainKeyword . ' 手順', $mainKeyword . ' やり方', $mainKeyword . ' 方法'],
            'relationToPillar' => 'ピラーページの概要を実際の作業手順に落とし込んだ実践的な情報を提供',
            'searchIntent' => '取引型'
        ],
        [
            'type' => 'cluster',
            'title' => $mainKeyword . 'のメリット・デメリット：詳細分析と対策',
            'metaDescription' => $mainKeyword . 'の利点と注意点を詳細分析。メリットを最大化し、デメリットを軽減する方法を解説。',
            'specificTopic' => 'メリット・デメリットの詳細分析',
            'targetKeywords' => [$mainKeyword . ' メリット', $mainKeyword . ' デメリット', $mainKeyword . ' 利点', $mainKeyword . ' 欠点'],
            'relationToPillar' => 'ピラーページでの概要説明を更に深掘りし、判断材料となる詳細情報を提供',
            'searchIntent' => '情報収集/商品調査'
        ],
        [
            'type' => 'cluster',
            'title' => $mainKeyword . '費用対効果：コスト分析と投資判断ガイド',
            'metaDescription' => $mainKeyword . 'の費用対効果を詳細分析。投資判断に必要な情報と、コスト最適化の方法を解説。',
            'specificTopic' => '費用対効果とコスト分析',
            'targetKeywords' => [$mainKeyword . ' 費用', $mainKeyword . ' コスト', $mainKeyword . ' 料金', $mainKeyword . ' 価格'],
            'relationToPillar' => 'ピラーページの概要を経済的観点から詳細分析し、投資判断を支援',
            'searchIntent' => '商品調査/取引型'
        ]
    ];
    
    return $clusterPages;
}

function generateLinkingStrategy($keywords) {
    $mainKeyword = !empty($keywords) ? $keywords[0] : '主要テーマ';
    
    return [
        'pillarToCluster' => 'ピラーページから関連する各クラスターページへ適切なアンカーテキストでリンク。「' . $mainKeyword . 'の詳細な実装方法」「' . $mainKeyword . '事例集」などの自然な文脈でリンクを配置。',
        'clusterToPillar' => '各クラスターページからピラーページに「' . $mainKeyword . '完全ガイド」「' . $mainKeyword . '総合情報」として戻るリンクを設置。読者がより包括的な情報にアクセスできるように。',
        'clusterToCluster' => 'クラスター間では関連性の高いページ同士をリンク。例：「実装手順」ページから「よくある質問」ページへ、「メリット・デメリット」から「費用対効果」ページへのリンクなど。'
    ];
}
?>