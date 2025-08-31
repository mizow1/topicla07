/**
 * 改善議題ベースの分析結果表示システム
 * 構造: 改善議題 → 各AI提案 → 統合最終提案
 */

// AI分析状況を管理するオブジェクト
const aiAnalysisStatus = {
    gemini: { status: 'pending', startTime: null, endTime: null },
    openai: { status: 'pending', startTime: null, endTime: null },
    claude: { status: 'pending', startTime: null, endTime: null }
};

// 改善議題の順序と表示名
const improvementTopics = {
    title: 'タイトル改善',
    metaDescription: 'メタディスクリプション改善',
    headingStructure: '見出し構造改善',
    contentImprovement: 'コンテンツ内容改善',
    internalLinks: '内部リンク戦略改善'
};

/**
 * AI分析状況の初期化
 */
function initializeAIAnalysisStatus() {
    const container = document.getElementById('multiAIResults');
    if (!container) return;
    
    container.innerHTML = `
        <div class="analysis-status-container">
            <h3>３AI分析状況</h3>
            <div class="ai-status-grid">
                ${Object.keys(aiAnalysisStatus).map(ai => `
                    <div class="ai-status-card" id="status-${ai}">
                        <div class="ai-status-header">
                            <span class="ai-icon ${ai}"></span>
                            <span class="ai-name">${ai.toUpperCase()}</span>
                        </div>
                        <div class="ai-status-indicator">
                            <span class="status-text">待機中</span>
                            <div class="status-spinner" style="display: none;"></div>
                        </div>
                        <div class="ai-analysis-time"></div>
                    </div>
                `).join('')}
            </div>
        </div>
        <div class="topic-based-results" id="topicBasedResults" style="display: none;">
            <!-- 各改善議題ごとの結果がここに表示される -->
        </div>
    `;
}

/**
 * AI分析状況の更新
 */
function updateAIAnalysisStatus(aiName, status, data = null) {
    const statusCard = document.getElementById(`status-${aiName}`);
    if (!statusCard) return;
    
    const statusText = statusCard.querySelector('.status-text');
    const spinner = statusCard.querySelector('.status-spinner');
    const timeElement = statusCard.querySelector('.ai-analysis-time');
    
    aiAnalysisStatus[aiName].status = status;
    
    switch (status) {
        case 'analyzing':
            aiAnalysisStatus[aiName].startTime = new Date();
            statusText.textContent = '分析中...';
            spinner.style.display = 'inline-block';
            statusCard.classList.add('analyzing');
            statusCard.classList.remove('completed', 'error');
            break;
            
        case 'completed':
            aiAnalysisStatus[aiName].endTime = new Date();
            const duration = (aiAnalysisStatus[aiName].endTime - aiAnalysisStatus[aiName].startTime) / 1000;
            statusText.textContent = '完了';
            spinner.style.display = 'none';
            timeElement.textContent = `${duration.toFixed(1)}秒`;
            statusCard.classList.add('completed');
            statusCard.classList.remove('analyzing', 'error');
            
            // 該当AIの結果を表示に追加
            if (data) {
                addAIResultToTopics(aiName, data);
            }
            break;
            
        case 'error':
            aiAnalysisStatus[aiName].endTime = new Date();
            statusText.textContent = 'エラー';
            spinner.style.display = 'none';
            statusCard.classList.add('error');
            statusCard.classList.remove('analyzing', 'completed');
            
            // エラー内容を表示
            if (data && data.error) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'ai-error-details';
                errorDiv.textContent = data.error;
                statusCard.appendChild(errorDiv);
            }
            break;
    }
    
    // 全AI完了チェック
    checkAllAICompleted();
}

/**
 * 改善議題ベースの結果表示を初期化
 */
function initializeTopicBasedResults() {
    const container = document.getElementById('topicBasedResults');
    if (!container) return;
    
    container.innerHTML = `
        <div class="topic-based-analysis">
            <h3>改善議題別分析結果</h3>
            <div class="topics-container">
                ${Object.entries(improvementTopics).map(([key, title]) => `
                    <div class="topic-section" id="topic-${key}">
                        <div class="topic-header">
                            <h4>${title}</h4>
                            <div class="topic-progress">
                                <span class="ai-count">0/3 AI完了</span>
                            </div>
                        </div>
                        <div class="ai-proposals">
                            <div class="ai-proposal gemini" id="proposal-${key}-gemini" style="display: none;">
                                <div class="ai-proposal-header">
                                    <span class="ai-icon gemini"></span>
                                    <span>Gemini提案</span>
                                </div>
                                <div class="ai-proposal-content"></div>
                            </div>
                            <div class="ai-proposal openai" id="proposal-${key}-openai" style="display: none;">
                                <div class="ai-proposal-header">
                                    <span class="ai-icon openai"></span>
                                    <span>OpenAI提案</span>
                                </div>
                                <div class="ai-proposal-content"></div>
                            </div>
                            <div class="ai-proposal claude" id="proposal-${key}-claude" style="display: none;">
                                <div class="ai-proposal-header">
                                    <span class="ai-icon claude"></span>
                                    <span>Claude提案</span>
                                </div>
                                <div class="ai-proposal-content"></div>
                            </div>
                        </div>
                        <div class="final-integrated-proposal" id="final-${key}" style="display: none;">
                            <div class="final-proposal-header">
                                <h5>📊 統合最終提案</h5>
                            </div>
                            <div class="final-proposal-content"></div>
                        </div>
                    </div>
                `).join('')}
            </div>
        </div>
    `;
    
    container.style.display = 'block';
}

/**
 * 各AIの結果を改善議題別に追加
 */
function addAIResultToTopics(aiName, data) {
    if (!data.improvementTopics) return;
    
    Object.entries(improvementTopics).forEach(([topicKey, topicTitle]) => {
        const topicData = data.improvementTopics[topicKey];
        if (!topicData) return;
        
        const proposalElement = document.getElementById(`proposal-${topicKey}-${aiName}`);
        if (!proposalElement) return;
        
        const contentDiv = proposalElement.querySelector('.ai-proposal-content');
        contentDiv.innerHTML = `
            <div class="proposal-conclusion">
                <h6>💡 提案内容</h6>
                <div class="conclusion-text">${topicData.conclusion || 'データなし'}</div>
            </div>
            <div class="proposal-explanation">
                <h6>📝 根拠・説明</h6>
                <div class="explanation-text">${topicData.explanation || 'データなし'}</div>
            </div>
            <div class="proposal-metadata">
                <span class="priority-badge ${topicData.priority || 'medium'}">${topicData.priority || 'medium'}</span>
                <div class="expected-result">
                    <strong>期待効果:</strong> ${topicData.expectedResult || 'データなし'}
                </div>
            </div>
        `;
        
        proposalElement.style.display = 'block';
        
        // 完了AIカウントを更新
        updateTopicProgress(topicKey);
    });
}

/**
 * 改善議題ごとの進捗を更新
 */
function updateTopicProgress(topicKey) {
    const topicSection = document.getElementById(`topic-${topicKey}`);
    if (!topicSection) return;
    
    const proposals = topicSection.querySelectorAll('.ai-proposal[style*="display: block"], .ai-proposal:not([style*="display: none"])');
    const visibleProposals = Array.from(proposals).filter(p => 
        p.style.display !== 'none' && p.querySelector('.ai-proposal-content').innerHTML.trim() !== ''
    );
    
    const progressElement = topicSection.querySelector('.ai-count');
    if (progressElement) {
        progressElement.textContent = `${visibleProposals.length}/3 AI完了`;
        
        // すべてのAI提案が完了した場合のスタイル更新
        if (visibleProposals.length === 3) {
            topicSection.classList.add('all-ai-completed');
        }
    }
}

/**
 * 統合最終提案の表示
 */
function displayFinalSuggestions(finalSuggestion) {
    if (!finalSuggestion.finalTopicSuggestions) return;
    
    Object.entries(improvementTopics).forEach(([topicKey, topicTitle]) => {
        const finalData = finalSuggestion.finalTopicSuggestions[topicKey];
        if (!finalData) return;
        
        const finalElement = document.getElementById(`final-${topicKey}`);
        if (!finalElement) return;
        
        const contentDiv = finalElement.querySelector('.final-proposal-content');
        contentDiv.innerHTML = `
            <div class="final-conclusion">
                <h6>🎯 統合最終結論</h6>
                <div class="final-conclusion-text">${finalData.finalConclusion || 'データなし'}</div>
            </div>
            <div class="ai-comparison">
                <h6>⚖️ 3AI提案比較</h6>
                <div class="comparison-text">${finalData.aiComparison || 'データなし'}</div>
            </div>
            <div class="best-choice">
                <h6>🏆 最適選択と理由</h6>
                <div class="best-choice-text">${finalData.bestChoice || 'データなし'}</div>
            </div>
            <div class="final-metadata">
                <div class="implementation-priority">
                    <span class="priority-badge ${finalData.implementationPriority || 'medium'}">${finalData.implementationPriority || 'medium'}</span>
                </div>
                <div class="expected-impact">
                    <strong>期待される効果:</strong> ${finalData.expectedImpact || 'データなし'}
                </div>
            </div>
        `;
        
        finalElement.style.display = 'block';
    });
    
    // 全体サマリーの表示
    displayOverallSummary(finalSuggestion.overallSummary);
}

/**
 * 全体サマリーの表示
 */
function displayOverallSummary(overallSummary) {
    if (!overallSummary) return;
    
    const container = document.getElementById('topicBasedResults');
    const summaryHtml = `
        <div class="overall-summary-section">
            <h4>📈 総合分析サマリー</h4>
            <div class="summary-content">
                <div class="total-analysis">
                    <h6>全体分析</h6>
                    <div>${overallSummary.totalAnalysis || 'データなし'}</div>
                </div>
                <div class="implementation-order">
                    <h6>推奨実装順序</h6>
                    <ol>
                        ${(overallSummary.implementationOrder || []).map(item => `<li>${item}</li>`).join('')}
                    </ol>
                </div>
                <div class="total-expected-impact">
                    <h6>総合期待効果</h6>
                    <div>${overallSummary.totalExpectedImpact || 'データなし'}</div>
                </div>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', summaryHtml);
}

/**
 * すべてのAI分析完了チェック
 */
function checkAllAICompleted() {
    const allCompleted = Object.values(aiAnalysisStatus).every(ai => 
        ai.status === 'completed' || ai.status === 'error'
    );
    
    if (allCompleted) {
        // 分析状況表示を最小化または非表示にする
        const statusContainer = document.querySelector('.analysis-status-container');
        if (statusContainer) {
            statusContainer.classList.add('minimized');
        }
        
        console.log('すべてのAI分析が完了しました');
    }
}

/**
 * 新しいレイアウトでの分析開始
 */
function startTopicBasedAnalysis(url, forceReanalyze = false) {
    // 初期化
    initializeAIAnalysisStatus();
    initializeTopicBasedResults();
    
    // 各AIの分析状況を「分析中」に設定
    Object.keys(aiAnalysisStatus).forEach(ai => {
        updateAIAnalysisStatus(ai, 'analyzing');
    });
    
    // リクエストボディを作成
    const formData = new FormData();
    formData.append('url', url);
    if (forceReanalyze) {
        formData.append('force_reanalyze', '1');
    }
    
    // 分析開始（既存のmulti_ai_analysis.phpを呼び出し）
    return fetch('multi_ai_analysis.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // 各AIの結果を処理
            Object.entries(data.multiAIResults || {}).forEach(([aiName, result]) => {
                if (result.error) {
                    updateAIAnalysisStatus(aiName, 'error', result);
                } else {
                    updateAIAnalysisStatus(aiName, 'completed', result);
                }
            });
            
            // 統合最終提案を表示
            if (data.finalSuggestion) {
                displayFinalSuggestions(data.finalSuggestion);
            }
            
            // キャッシュ情報の表示
            if (data.fromCache) {
                const container = document.getElementById('topicBasedResults');
                if (container) {
                    container.insertAdjacentHTML('afterbegin', 
                        '<div class="analysis-cache-info">💾 データベースから既存の分析結果を表示しています</div>'
                    );
                }
            }
        } else {
            // エラー時はすべてのAIをエラー状態に
            Object.keys(aiAnalysisStatus).forEach(ai => {
                updateAIAnalysisStatus(ai, 'error', { error: data.message || 'API呼び出しに失敗しました' });
            });
        }
        return data;
    })
    .catch(error => {
        console.error('分析エラー:', error);
        // エラー時はすべてのAIをエラー状態に
        Object.keys(aiAnalysisStatus).forEach(ai => {
            updateAIAnalysisStatus(ai, 'error', { error: error.message });
        });
        throw error;
    });
}

// グローバルに公開
window.TopicBasedAnalysis = {
    start: startTopicBasedAnalysis,
    initializeStatus: initializeAIAnalysisStatus,
    initializeResults: initializeTopicBasedResults,
    updateStatus: updateAIAnalysisStatus
};