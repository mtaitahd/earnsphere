<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Wallet.php';
require_once __DIR__ . '/classes/AIAssistant.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

Wallet::autoExpirePending((int) $_SESSION['user_id']);

$user = Auth::getUser();
$csrf = Auth::generateCSRF();
$pageTitle = 'AI Share Assistant';

$contentTypes = AIAssistant::CONTENT_TYPES;
$tones = AIAssistant::TONES;
$platforms = AIAssistant::PLATFORMS;

include __DIR__ . '/includes/public_head.php';
?>

<style>
.ai-header {
    background: linear-gradient(135deg, #0A3622, #0d4a2e);
    color: #fff;
    padding: 1.25rem 1.25rem 2.5rem;
}
.ai-header .top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}
.ai-header .back-btn {
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    font-size: 1.25rem;
}
.ai-header h2 {
    font-size: 1.25rem;
    font-weight: 800;
    margin: 0;
}
.ai-header p {
    font-size: 0.85rem;
    opacity: 0.85;
    margin: 0;
}
.ai-content {
    padding: 1rem;
    margin-top: -1.5rem;
    position: relative;
    z-index: 2;
}
.ai-card {
    background: #fff;
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    box-shadow: var(--card-shadow);
    margin-bottom: 1rem;
}
.ai-card .card-title {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--gray-800);
    margin-bottom: 0.75rem;
}
.content-type-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.5rem;
}
.content-type-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.3rem;
    padding: 0.75rem 0.5rem;
    background: var(--gray-50);
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--gray-600);
    text-align: center;
    line-height: 1.2;
}
.content-type-btn i {
    font-size: 1.25rem;
    color: var(--gray-400);
}
.content-type-btn:hover {
    border-color: #D4A843;
    background: #fffbeb;
}
.content-type-btn.active {
    border-color: #D4A843;
    background: #fffbeb;
    color: #0A3622;
}
.content-type-btn.active i {
    color: #D4A843;
}
.tone-selector {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}
.tone-btn {
    padding: 0.4rem 0.75rem;
    border: 2px solid var(--gray-200);
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    color: var(--gray-600);
    background: #fff;
}
.tone-btn:hover {
    border-color: #D4A843;
    color: #0A3622;
}
.tone-btn.active {
    border-color: #D4A843;
    background: #0A3622;
    color: #fff;
}
.ai-result {
    background: var(--gray-50);
    border-radius: var(--radius-md);
    padding: 1rem;
    min-height: 120px;
    position: relative;
    white-space: pre-wrap;
    font-size: 0.875rem;
    line-height: 1.6;
    color: var(--gray-700);
    max-height: 400px;
    overflow-y: auto;
}
.ai-result .placeholder {
    color: var(--gray-400);
    text-align: center;
    padding: 2rem 1rem;
}
.ai-result .placeholder i {
    font-size: 2rem;
    display: block;
    margin-bottom: 0.75rem;
    color: var(--gray-300);
}
.generate-btn {
    background: linear-gradient(135deg, #0A3622, #0d4a2e);
    border: none;
    color: #fff;
    border-radius: var(--radius-md);
    padding: 0.85rem;
    font-weight: 800;
    font-size: 1rem;
    width: 100%;
    transition: all 0.2s ease;
}
.generate-btn:hover {
    background: linear-gradient(135deg, #0d4a2e, #0A3622);
    color: #fff;
}
.generate-btn:disabled {
    opacity: 0.6;
}
.share-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.75rem;
}
.share-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.85rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    color: #fff;
    text-decoration: none;
}
.share-btn:active {
    transform: scale(0.95);
}
.share-btn.whatsapp { background: #25D366; }
.share-btn.facebook { background: #1877F2; }
.share-btn.telegram { background: #0088cc; }
.share-btn.twitter { background: #1DA1F2; }
.share-btn.linkedin { background: #0A66C2; }
.share-btn.sms { background: #0A3622; }
.share-btn.copy { background: #6b7280; }
.rate-limit-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.7rem;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    background: var(--gray-100);
    color: var(--gray-500);
}
.prompt-input {
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-md);
    padding: 0.75rem;
    font-size: 0.85rem;
    width: 100%;
    resize: vertical;
    min-height: 60px;
    transition: border-color 0.2s ease;
}
.prompt-input:focus {
    border-color: #D4A843;
    outline: none;
    box-shadow: 0 0 0 3px rgba(212, 168, 67, 0.1);
}
.confetti-container {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 9999;
    overflow: hidden;
}
.confetti-piece {
    position: absolute;
    width: 10px;
    height: 10px;
    animation: confettiFall linear forwards;
}
@keyframes confettiFall {
    0% { transform: translateY(-10px) rotate(0deg); opacity: 1; }
    100% { transform: translateY(100vh) rotate(720deg); opacity: 0; }
}
.success-animation {
    text-align: center;
    padding: 1rem;
}
.success-animation .checkmark {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0A3622, #0d4a2e);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 0.75rem;
    animation: scaleIn 0.3s ease;
}
.success-animation .checkmark i {
    color: #D4A843;
    font-size: 1.5rem;
}
@keyframes scaleIn {
    0% { transform: scale(0); }
    50% { transform: scale(1.2); }
    100% { transform: scale(1); }
}
.history-item {
    padding: 0.75rem;
    border-bottom: 1px solid var(--gray-100);
    cursor: pointer;
    transition: background 0.2s;
}
.history-item:hover {
    background: var(--gray-50);
}
.history-item:last-child {
    border-bottom: none;
}
@media (min-width: 768px) {
    .content-type-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 360px) {
    .content-type-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .content-type-btn {
        padding: 0.5rem 0.3rem;
        font-size: 0.65rem;
    }
    .tone-btn {
        font-size: 0.7rem;
        padding: 0.3rem 0.6rem;
    }
}
</style>

<div class="ai-header">
    <div class="top-bar">
        <a href="dashboard" class="back-btn"><i class="fas fa-arrow-left"></i></a>
        <h2><i class="fas fa-wand-magic-sparkles me-1"></i> AI Share Assistant</h2>
        <span class="rate-limit-badge" id="rateLimitBadge">
            <i class="fas fa-bolt"></i>
            <span id="rateLimitText">10 remaining</span>
        </span>
    </div>
    <p>Generate marketing content, share with friends, and track your results</p>
</div>

<div class="ai-content mb-safe">
    <div id="rateLimitWarning" style="display:none;background:#fef2f2;border-radius:var(--radius-md);padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.85rem;color:#dc2626;border:1px solid #fecaca;">
        <i class="fas fa-exclamation-triangle me-1"></i>
        <span id="rateLimitWarningText">Umeshindwa kufikia kikomo cha AI generation. Jaribu tena baada ya saa moja.</span>
    </div>

    <div class="ai-card">
        <div class="card-title"><i class="fas fa-pen-fancy me-1" style="color:#D4A843;"></i> Select Content Type</div>
        <div class="content-type-grid" id="contentTypeGrid">
            <?php foreach ($contentTypes as $type): 
                $icon = match($type) {
                    'whatsapp_message' => 'fa-brands fa-whatsapp',
                    'facebook_post' => 'fa-brands fa-facebook',
                    'instagram_caption' => 'fa-brands fa-instagram',
                    'tiktok_caption' => 'fa-brands fa-tiktok',
                    'telegram_message' => 'fa-brands fa-telegram',
                    'twitter_post' => 'fa-brands fa-twitter',
                    'blog_article' => 'fa-solid fa-newspaper',
                    'referral_sms' => 'fa-solid fa-sms',
                    'referral_email' => 'fa-solid fa-envelope',
                    'marketing_slogan' => 'fa-solid fa-quote-right',
                    'catchy_headline' => 'fa-solid fa-heading',
                    'video_script' => 'fa-solid fa-video',
                    'voiceover_script' => 'fa-solid fa-microphone',
                    'cta' => 'fa-solid fa-bullhorn',
                    'referral_story' => 'fa-solid fa-book-open',
                    'carousel_text' => 'fa-solid fa-images',
                    'poster_content' => 'fa-solid fa-image',
                    'short_ad' => 'fa-solid fa-ad',
                    'long_ad' => 'fa-solid fa-file-lines',
                    'hook' => 'fa-solid fa-fish',
                    'follow_up_message' => 'fa-solid fa-message',
                    'customer_reply' => 'fa-solid fa-reply',
                    'objection_handling' => 'fa-solid fa-shield',
                    default => 'fa-solid fa-file',
                };
                $label = AIAssistant::getContentTypeLabel($type);
            ?>
            <div class="content-type-btn" data-type="<?= $type ?>" onclick="selectContentType(this)">
                <i class="<?= $icon ?>"></i>
                <span><?= $label ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="ai-card">
        <div class="card-title"><i class="fas fa-palette me-1" style="color:#D4A843;"></i> Select Tone</div>
        <div class="tone-selector" id="toneSelector">
            <?php foreach ($tones as $tone): 
                $label = AIAssistant::getToneLabel($tone);
                $isActive = $tone === 'professional' ? 'active' : '';
            ?>
            <div class="tone-btn <?= $isActive ?>" data-tone="<?= $tone ?>" onclick="selectTone(this)"><?= $label ?></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="ai-card">
        <div class="card-title"><i class="fas fa-pencil me-1" style="color:#D4A843;"></i> Custom Instructions <span style="font-weight:400;font-size:0.7rem;color:var(--gray-400);">(optional)</span></div>
        <textarea class="prompt-input" id="customPrompt" placeholder="Add specific instructions... e.g., 'Mention the TZS 500 bonus' or 'Focus on students'"></textarea>
    </div>

    <button class="generate-btn mb-3" id="generateBtn" onclick="generateContent()">
        <i class="fas fa-wand-magic-sparkles me-1"></i> Generate Content
    </button>

    <div id="loadingSpinner" style="display:none;text-align:center;padding:2rem;">
        <div style="width:48px;height:48px;border:4px solid var(--gray-200);border-top-color:#0A3622;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 1rem;"></div>
        <div style="font-size:0.85rem;color:var(--gray-500);">AI inaandaa maudhui yako...</div>
    </div>

    <div class="ai-card" id="resultCard" style="display:none;">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="card-title mb-0"><i class="fas fa-file-alt me-1" style="color:#D4A843;"></i> Generated Content</div>
            <div style="display:flex;gap:0.5rem;">
                <button class="btn btn-sm" style="background:var(--gray-100);border:none;font-size:0.75rem;padding:0.3rem 0.6rem;" onclick="copyContent()">
                    <i class="fas fa-copy me-1"></i> Copy
                </button>
                <button class="btn btn-sm" style="background:var(--gray-100);border:none;font-size:0.75rem;padding:0.3rem 0.6rem;" onclick="regenerateContent()">
                    <i class="fas fa-redo me-1"></i> Regenerate
                </button>
            </div>
        </div>
        <div class="ai-result" id="aiResult"></div>
        <div class="share-buttons" id="shareButtons">
            <?php foreach ($platforms as $platform): 
                $icon = match($platform) {
                    'whatsapp' => 'fa-brands fa-whatsapp',
                    'facebook' => 'fa-brands fa-facebook',
                    'instagram' => 'fa-brands fa-instagram',
                    'telegram' => 'fa-brands fa-telegram',
                    'tiktok' => 'fa-brands fa-tiktok',
                    'linkedin' => 'fa-brands fa-linkedin',
                    'sms' => 'fa-solid fa-sms',
                    default => 'fa-solid fa-share',
                };
            ?>
            <button class="share-btn <?= $platform ?>" data-platform="<?= $platform ?>" onclick="shareContent('<?= $platform ?>')">
                <i class="<?= $icon ?>"></i> <?= ucfirst($platform) ?>
            </button>
            <?php endforeach; ?>
            <button class="share-btn copy" onclick="copyContent()">
                <i class="fas fa-link"></i> Copy
            </button>
        </div>
    </div>

    <div class="ai-card" id="historyCard" style="display:none;">
        <div class="card-title"><i class="fas fa-history me-1" style="color:#D4A843;"></i> Recent History</div>
        <div id="historyList"></div>
    </div>
</div>

<div id="confettiContainer" class="confetti-container" style="display:none;"></div>

<?php include __DIR__ . '/includes/public_foot.php'; ?>

<script>
let selectedType = '';
let selectedTone = 'professional';
let lastGeneratedContent = '';
let lastGeneratedType = '';

function selectContentType(el) {
    document.querySelectorAll('.content-type-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    selectedType = el.dataset.type;
}

function selectTone(el) {
    document.querySelectorAll('.tone-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    selectedTone = el.dataset.tone;
}

async function generateContent() {
    if (!selectedType) {
        App.showToast('Tafadhali chagua aina ya maudhui', 'warning');
        return;
    }

    const btn = document.getElementById('generateBtn');
    const spinner = document.getElementById('loadingSpinner');
    const resultCard = document.getElementById('resultCard');
    const aiResult = document.getElementById('aiResult');
    const customPrompt = document.getElementById('customPrompt').value.trim();

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Inazalisha...';
    spinner.style.display = 'block';
    resultCard.style.display = 'none';
    document.getElementById('rateLimitWarning').style.display = 'none';

    try {
        const formData = new FormData();
        formData.append('content_type', selectedType);
        formData.append('tone', selectedTone);
        formData.append('language', selectedTone === 'simple_swahili' || selectedTone === 'mixed_swahili_english' ? 'swahili' : 'english');
        if (customPrompt) formData.append('custom_prompt', customPrompt);

        const response = await fetch('api/ai_generate.php?action=generate', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
            },
        });

        const data = await response.json();
        spinner.style.display = 'none';

        if (data.success) {
            lastGeneratedContent = data.content;
            lastGeneratedType = data.type;
            aiResult.textContent = data.content;
            resultCard.style.display = 'block';

            if (data.rate_limit) {
                document.getElementById('rateLimitText').textContent = data.rate_limit.remaining + ' remaining';
            }

            App.showToast('Maudhui yamezalishwa!', 'success');
        } else {
            if (data.error && data.error.includes('kikomo')) {
                const warning = document.getElementById('rateLimitWarning');
                document.getElementById('rateLimitWarningText').textContent = data.error;
                warning.style.display = 'block';
            }
            App.showToast(data.error || 'Hitilafu imetokea', 'error');
        }
    } catch (err) {
        spinner.style.display = 'none';
        App.showToast('Hitilafu ya mtandao', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-wand-magic-sparkles me-1"></i> Generate Content';
    }
}

async function regenerateContent() {
    if (lastGeneratedType) {
        selectedType = lastGeneratedType;
        await generateContent();
    }
}

async function copyContent() {
    if (!lastGeneratedContent) return;
    await App.copyToClipboard(lastGeneratedContent);
    App.showToast('Imenakiliwa!', 'success');
}

async function shareContent(platform) {
    if (!lastGeneratedContent) return;

    const refCode = '<?= $user['referral_code'] ?>';
    const shareUrl = '<?= SITE_URL ?>';
    const text = encodeURIComponent(lastGeneratedContent + '\n\n' + shareUrl + '/register?ref=' + refCode);

    let url = '';
    switch (platform) {
        case 'whatsapp':
            url = 'https://wa.me/?text=' + text;
            break;
        case 'facebook':
            url = 'https://www.facebook.com/sharer/sharer.php?quote=' + text + '&u=' + encodeURIComponent(shareUrl + '/register?ref=' + refCode);
            break;
        case 'telegram':
            url = 'https://t.me/share/url?url=' + encodeURIComponent(shareUrl + '/register?ref=' + refCode) + '&text=' + encodeURIComponent(lastGeneratedContent);
            break;
        case 'linkedin':
            url = 'https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(shareUrl + '/register?ref=' + refCode);
            break;
        case 'sms':
            url = 'sms:?&body=' + text;
            break;
        case 'instagram':
        case 'tiktok':
            await copyContent();
            App.showToast('Content copied! Paste it on ' + platform.charAt(0).toUpperCase() + platform.slice(1), 'info');
            return;
    }

    if (url) {
        window.open(url, '_blank', 'width=600,height=600');
    }

    try {
        const formData = new FormData();
        formData.append('content_type', lastGeneratedType || selectedType);
        formData.append('platform', platform);
        await fetch('api/ai_share_track.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
        });
    } catch (e) {}

    App.showToast('Shared to ' + platform.charAt(0).toUpperCase() + platform.slice(1), 'success');
}

function showConfetti() {
    const container = document.getElementById('confettiContainer');
    container.style.display = 'block';
    const colors = ['#D4A843', '#0A3622', '#F5D77B', '#10b981', '#72578B', '#f59e0b'];

    for (let i = 0; i < 60; i++) {
        const piece = document.createElement('div');
        piece.className = 'confetti-piece';
        piece.style.left = Math.random() * 100 + '%';
        piece.style.top = '-10px';
        piece.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
        piece.style.width = (Math.random() * 8 + 4) + 'px';
        piece.style.height = (Math.random() * 8 + 4) + 'px';
        piece.style.borderRadius = Math.random() > 0.5 ? '50%' : '2px';
        piece.style.animationDuration = (Math.random() * 2 + 1.5) + 's';
        piece.style.animationDelay = (Math.random() * 1.5) + 's';
        container.appendChild(piece);
    }

    setTimeout(() => {
        container.innerHTML = '';
        container.style.display = 'none';
    }, 4000);
}

document.addEventListener('DOMContentLoaded', () => {
    fetch('api/ai_generate.php?action=history&page=1', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.data.items.length > 0) {
            const card = document.getElementById('historyCard');
            const list = document.getElementById('historyList');
            card.style.display = 'block';
            list.innerHTML = data.data.items.slice(0, 5).map(item => {
                const labels = <?= json_encode(array_combine($contentTypes, array_map('AIAssistant::getContentTypeLabel', $contentTypes))) ?>;
                const typeLabel = labels[item.content_type] || item.content_type;
                return '<div class="history-item" onclick="loadHistoryContent(' + item.id + ')">' +
                    '<div style="font-weight:600;font-size:0.85rem;">' + typeLabel + '</div>' +
                    '<div style="font-size:0.75rem;color:var(--gray-400);">' + item.created_at + '</div>' +
                    '</div>';
            }).join('');
        }
    })
    .catch(() => {});
});

function loadHistoryContent(id) {
    fetch('api/ai_generate.php?action=history&page=1', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const item = data.data.items.find(i => i.id == id);
            if (item) {
                lastGeneratedContent = item.generated_content;
                lastGeneratedType = item.content_type;
                document.getElementById('aiResult').textContent = item.generated_content;
                document.getElementById('resultCard').style.display = 'block';
                selectedType = item.content_type;
                selectedTone = item.tone;
                document.querySelectorAll('.content-type-btn').forEach(b => {
                    b.classList.toggle('active', b.dataset.type === item.content_type);
                });
                document.querySelectorAll('.tone-btn').forEach(b => {
                    b.classList.toggle('active', b.dataset.tone === item.tone);
                });
            }
        }
    })
    .catch(() => {});
}

<?php if (isset($_GET['show_mission']) && $_GET['show_mission'] === '1'): ?>
showConfetti();
<?php endif; ?>
</script>
