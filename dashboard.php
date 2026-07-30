<?php
/**
 * EarnSphere - User Dashboard
 * Main dashboard showing stats, referral link, and quick actions
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/CommissionEngine.php';
require_once __DIR__ . '/classes/Wallet.php';
require_once __DIR__ . '/classes/DailyMission.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

Wallet::autoExpirePending((int) $_SESSION['user_id']);

$user = Auth::getUser();
$wallet = Wallet::getWallet($_SESSION['user_id']);
$stats = CommissionEngine::getReferralStats($_SESSION['user_id']);
$referralLink = getReferralLink($user['referral_code']);

// Recent transactions
$recentTransactions = Database::fetchAll(
    "SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
    [$_SESSION['user_id']]
);

// Registration payment info
$registrationPayment = Database::fetchOne(
    "SELECT amount, status, completed_at, metadata FROM payments WHERE user_id = ? AND payment_type = 'registration' ORDER BY id DESC LIMIT 1",
    [$_SESSION['user_id']]
);

// Check if latest payment failed
$paymentFailed = ($registrationPayment && $registrationPayment['status'] === 'failed');
$paymentFailureReason = '';
if ($paymentFailed && !empty($registrationPayment['metadata'])) {
    $meta = json_decode($registrationPayment['metadata'], true) ?: [];
    $paymentFailureReason = $meta['failure_reason'] ?? $meta['api_error'] ?? '';
}

$csrf = Auth::generateCSRF();
$pageTitle = 'Dashboard';

// Daily Mission
$missionStatus = null;
$showMissionAnimation = false;
if ($user['status'] === 'active') {
    $missionStatus = DailyMission::getMissionStatus($_SESSION['user_id']);
    if ($missionStatus && isset($missionStatus['just_completed']) && $missionStatus['just_completed']) {
        $showMissionAnimation = true;
    }
}

// Handle AJAX referral link copy logging
if (isAjax() && isset($_GET['action']) && $_GET['action'] === 'track_copy') {
    Auth::logActivity($_SESSION['user_id'], 'referral_link_copied', 'Copied referral link');
    jsonResponse(['success' => true]);
}

include __DIR__ . '/includes/public_head.php';
?>

<!-- Dashboard Header -->
<div class="dash-header">
    <div class="top-bar">
        <div>
            <p class="greeting mb-0">Hello, 👋</p>
            <h4 class="user-name"><?= sanitize($user['full_name']) ?></h4>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="ai-assistant" class="notification-btn" style="text-decoration:none;position:relative;" title="AI Share Assistant">
                <i class="fas fa-wand-magic-sparkles" style="color:#D4A843;"></i>
                <span style="position:absolute;top:-4px;right:-6px;width:10px;height:10px;background:#D4A843;border-radius:50%;border:2px solid #72578B;"></span>
            </a>
            <a href="profile" class="notification-btn" style="text-decoration:none;">
                <i class="fas fa-bell"></i>
            </a>
            <a href="profile" style="text-decoration:none;">
                <div class="avatar" style="background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;color:white;font-weight:800;">
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                </div>
            </a>
        </div>
    </div>
    
    <!-- Account Status -->
    <?php if ($user['status'] !== 'active'): ?>
    <div style="background:linear-gradient(135deg,<?= $paymentFailed ? '#ef4444,#dc2626' : '#f59e0b,#ef4444' ?>);border-radius:12px;padding:1rem;margin-top:0.5rem;color:white;">
        <div class="d-flex align-items-center mb-2">
            <i class="fas fa-<?= $paymentFailed ? 'times-circle' : 'exclamation-triangle' ?> me-2" style="font-size:1.2rem;"></i>
            <strong><?= $paymentFailed ? 'Payment Failed!' : 'Your account is not yet activated!' ?></strong>
        </div>
        <?php if ($paymentFailed): ?>
        <p style="font-size:0.85rem;margin:0 0 0.75rem;opacity:0.9;">
            <?= $paymentFailureReason ? 'Reason: ' . sanitize($paymentFailureReason) . '.' : 'Your last payment was not successful.' ?>
            Please try again with a valid phone number.
        </p>
        <?php else: ?>
        <p style="font-size:0.85rem;margin:0 0 0.75rem;opacity:0.9;">Complete your <?= formatCurrency(app_setting('registration_fee', REGISTRATION_FEE)) ?> payment to activate your account and start earning commissions.</p>
        <?php endif; ?>
        <a href="payment?user_id=<?= $user['id'] ?>" style="display:inline-block;background:white;color:<?= $paymentFailed ? '#ef4444' : '#f59e0b' ?>;padding:0.5rem 1.5rem;border-radius:8px;font-weight:800;text-decoration:none;">
            <i class="fas fa-credit-card me-1"></i> <?= $paymentFailed ? 'Retry Payment' : 'Pay Now' ?>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($registrationPayment && $user['status'] === 'active'): ?>
    <div style="background:linear-gradient(135deg,#10b981,#059669);border-radius:12px;padding:1rem;margin-top:0.5rem;color:white;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <div style="font-size:0.8rem;opacity:0.85;"><i class="fas fa-check-circle me-1"></i> Registration Paid</div>
                <div style="font-size:1.3rem;font-weight:800;"><?= formatCurrency($registrationPayment['amount']) ?></div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:0.75rem;opacity:0.8;">Paid on</div>
                <div style="font-size:0.85rem;font-weight:600;"><?= date('d M Y', strtotime($registrationPayment['completed_at'])) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Stats Cards -->
<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= formatCurrency($wallet['withdrawable_balance'] ?? 0) ?></div>
            <div class="stat-label">Withdrawable Balance</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['total_referrals']) ?></div>
            <div class="stat-label">Total Referrals</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon amber">
            <i class="fas fa-coins"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= formatCurrency($wallet['total_earned']) ?></div>
            <div class="stat-label">Total Earnings</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= date('d M', strtotime($user['created_at'])) ?></div>
            <div class="stat-label">Registered</div>
        </div>
    </div>
</div>

<!-- Daily Mission -->
<?php if ($user['status'] === 'active' && $missionStatus && $missionStatus['has_mission']): ?>
<div class="dash-section px-3" style="margin-top:1rem;">
    <div class="mission-card" id="missionCard" data-completed="<?= $missionStatus['completed'] ? '1' : '0' ?>">
        <div class="mission-header">
            <div class="mission-icon">
                <i class="fas fa-trophy"></i>
            </div>
            <div class="mission-info">
                <h6 class="mission-title" id="missionTitle"><?= sanitize($missionStatus['title']) ?></h6>
                <p class="mission-desc" id="missionDesc"><?= sanitize($missionStatus['description']) ?></p>
            </div>
            <div class="mission-reward">
                <div class="reward-label">Reward</div>
                <div class="reward-amount" id="missionReward"><?= formatCurrency($missionStatus['reward_amount']) ?></div>
            </div>
        </div>

        <?php if ($missionStatus['completed']): ?>
        <div class="mission-completed" id="missionCompleted">
            <div class="completed-icon"><i class="fas fa-check-circle"></i></div>
            <div>
                <strong>Mission Complete!</strong>
                <div style="font-size:0.75rem;color:var(--gray-500);">Tafadhali subiri mission ya kesho</div>
            </div>
            <div class="completed-badge">Done</div>
        </div>
        <?php else: ?>
        <div class="mission-progress" id="missionProgress">
            <div class="progress-header">
                <span>Progress</span>
                <span id="missionProgressText"><?= $missionStatus['progress'] ?? '0/0' ?></span>
            </div>
            <div class="progress-track">
                <div class="progress-fill" id="missionProgressFill" style="width:<?= $missionStatus['percentage'] ?>%"></div>
            </div>
            <div class="progress-footer">
                <span><i class="fas fa-users me-1"></i> <span id="missionPaidCount"><?= $missionStatus['completed_count'] ?? 0 ?></span> paid referrals today</span>
                <span>Target: <?= $missionStatus['requirement_count'] ?? 2 ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Dashboard Content -->
<div class="dash-content mb-safe">
    
    <!-- Referral Link & Share -->
    <div class="referral-link-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="mb-0"><i class="fas fa-link me-1 text-primary"></i> Referral Link</h6>
        </div>
        
        <p style="font-size:0.8rem;color:var(--gray-500);margin-bottom:0.75rem;">
            Copy this link and share with friends. You earn money for every person who joins.
        </p>
        
        <div class="referral-link-box mb-3">
            <input type="text" id="referral-link-input" value="<?= $referralLink ?>" readonly>
            <button class="btn-copy" onclick="App.copyToClipboard('<?= $referralLink ?>').then(()=>App.showToast('Link copied!','success'))">
                <i class="fas fa-copy"></i>
            </button>
        </div>
        
        <!-- Commission Earnings per Level -->
        <div style="background:var(--primary-bg);border-radius:var(--radius-md);padding:1rem;margin-bottom:1rem;">
            <h6 style="font-weight:700;font-size:0.8rem;color:var(--gray-600);margin-bottom:0.5rem;">
                <i class="fas fa-coins me-1"></i> Earnings Per Referral
            </h6>
            <div class="d-flex justify-content-between mb-1" style="font-size:0.85rem;">
                <span style="color:var(--gray-600);"><i class="fas fa-user-plus me-1" style="color:var(--primary);"></i> Level 1 (Direct)</span>
                <strong style="color:var(--primary);">TZS <?= number_format((int) app_setting('commission_l1', COMMISSION_L1)) ?></strong>
            </div>
            <div class="d-flex justify-content-between mb-1" style="font-size:0.85rem;">
                <span style="color:var(--gray-600);"><i class="fas fa-user-group me-1" style="color:var(--secondary);"></i> Level 2 (Grand)</span>
                <strong style="color:var(--secondary);">TZS <?= number_format((int) app_setting('commission_l2', COMMISSION_L2)) ?></strong>
            </div>
            <div class="d-flex justify-content-between" style="font-size:0.85rem;">
                <span style="color:var(--gray-600);"><i class="fas fa-people-arrows me-1" style="color:var(--accent);"></i> Level 3 (Great-grand)</span>
                <strong style="color:var(--accent);">TZS <?= number_format((int) app_setting('commission_l3', COMMISSION_L3)) ?></strong>
            </div>
        </div>
        
        <!-- QR Code -->
        <div class="qr-container">
            <canvas id="referralQR"></canvas>
            <p>Scan QR code to join</p>
        </div>
    </div>
    
    <!-- Referral Stats -->
    <div class="dash-section">
        <div class="section-header">
            <h6><i class="fas fa-chart-bar me-1"></i> Referral Stats</h6>
        </div>
        
        <div class="stat-grid">
            <div class="stat-box">
                <div class="stat-number"><?= number_format($stats['level_1']) ?></div>
                <div class="stat-label">Level 1 (Direct)</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" style="color:var(--secondary);"><?= number_format($stats['level_2']) ?></div>
                <div class="stat-label">Level 2 (Grand)</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" style="color:var(--accent);"><?= number_format($stats['level_3']) ?></div>
                <div class="stat-label">Level 3 (Great-grand)</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" style="color:var(--info);"><?= formatCurrency($wallet['total_earned']) ?></div>
                <div class="stat-label">Total Earnings</div>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="dash-section">
        <div class="section-header">
            <h6><i class="fas fa-bolt me-1"></i> Quick Actions</h6>
        </div>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
            <a href="wallet" class="btn btn-outline-primary" style="padding:0.75rem;">
                <i class="fas fa-wallet me-1"></i> Wallet
            </a>
            <a href="withdrawal" class="btn btn-outline-primary" style="padding:0.75rem;">
                <i class="fas fa-money-bill-wave me-1"></i> Withdraw
            </a>
            <a href="referrals" class="btn btn-outline-primary" style="padding:0.75rem;">
                <i class="fas fa-share me-1"></i> Referrals
            </a>
            <a href="transactions" class="btn btn-outline-primary" style="padding:0.75rem;">
                <i class="fas fa-history me-1"></i> History
            </a>
            <?php if ($user['status'] === 'active'): ?>
            <a href="ai-assistant" class="btn btn-outline-primary" style="padding:0.75rem;border-color:#D4A843;color:#72578B;">
                <i class="fas fa-wand-magic-sparkles me-1" style="color:#D4A843;"></i> AI Assistant
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Earnings Calculator -->
    <?php if ($user['status'] === 'active'): ?>
    <div class="dash-section" id="earningsCalculator">
        <div class="section-header">
            <h6><i class="fas fa-calculator me-1" style="color:#72578B;"></i> Earnings Calculator</h6>
        </div>

        <div class="calc-card">
            <div class="calc-input-group">
                <label class="calc-label">Paid Direct Referrals</label>
                <div class="calc-input-row">
                    <input type="range" id="calcSlider" class="calc-slider" min="0" max="1000" value="10" oninput="updateCalculator()">
                    <input type="number" id="calcInput" class="calc-number" min="0" max="1000" value="10" oninput="syncSlider(this.value)">
                </div>
            </div>

            <div class="calc-grid">
                <div class="calc-level">
                    <div class="calc-level-header">
                        <span><i class="fas fa-user-plus" style="color:#72578B;"></i> Level 1 (Direct)</span>
                        <span class="calc-level-amount" id="calcL1Amount">TZS 25,000</span>
                    </div>
                    <div class="calc-bar-track">
                        <div class="calc-bar-fill" id="calcL1Bar" style="width:15.6%;background:#72578B;"></div>
                    </div>
                    <div class="calc-level-detail">
                        <span class="calc-count" id="calcL1Count">10</span>
                        <span class="calc-rate">× TZS 2,500</span>
                    </div>
                </div>
                <div class="calc-level">
                    <div class="calc-level-header">
                        <span><i class="fas fa-user-group" style="color:#D4A843;"></i> Level 2</span>
                        <span class="calc-level-amount" id="calcL2Amount">TZS 45,000</span>
                    </div>
                    <div class="calc-bar-track">
                        <div class="calc-bar-fill" id="calcL2Bar" style="width:28.1%;background:#D4A843;"></div>
                    </div>
                    <div class="calc-level-detail">
                        <span class="calc-count" id="calcL2Count">30</span>
                        <span class="calc-rate">× TZS 1,500</span>
                    </div>
                </div>
                <div class="calc-level">
                    <div class="calc-level-header">
                        <span><i class="fas fa-people-arrows" style="color:#0EA5E9;"></i> Level 3</span>
                        <span class="calc-level-amount" id="calcL3Amount">TZS 90,000</span>
                    </div>
                    <div class="calc-bar-track">
                        <div class="calc-bar-fill" id="calcL3Bar" style="width:56.3%;background:#0EA5E9;"></div>
                    </div>
                    <div class="calc-level-detail">
                        <span class="calc-count" id="calcL3Count">90</span>
                        <span class="calc-rate">× TZS 1,000</span>
                    </div>
                </div>
            </div>

            <div class="calc-total" id="calcTotal">
                <div class="calc-total-label">Estimated Total Earnings</div>
                <div class="calc-total-amount" id="calcTotalAmount">TZS 160,000</div>
            </div>

            <div class="calc-chart-row">
                <div class="calc-chart-container">
                    <canvas id="calcChart" width="120" height="120" style="width:120px;height:120px;display:block;"></canvas>
                </div>
                <div class="calc-legend">
                    <div class="legend-item"><span class="legend-dot" style="background:#72578B;"></span> Level 1</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#D4A843;"></span> Level 2</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#0EA5E9;"></span> Level 3</div>
                </div>
            </div>

            <div class="calc-network">
                <div class="calc-network-title"><i class="fas fa-sitemap me-1"></i> Network Preview</div>
                <div class="network-tree" id="networkTree">
                    <div class="network-node root">YOU</div>
                    <div class="network-conn"><i class="fas fa-chevron-down"></i></div>
                    <div class="network-level" id="networkL1"><span class="network-badge">10</span></div>
                    <div class="network-conn"><i class="fas fa-chevron-down"></i></div>
                    <div class="network-level" id="networkL2"><span class="network-badge">30</span></div>
                    <div class="network-conn"><i class="fas fa-chevron-down"></i></div>
                    <div class="network-level" id="networkL3"><span class="network-badge">90</span></div>
                </div>
            </div>

            <?php if ($missionStatus && $missionStatus['has_mission']): ?>
            <div class="calc-mission" id="calcMission">
                <div class="calc-mission-header">
                    <i class="fas fa-trophy me-1" style="color:#D4A843;"></i>
                    <strong>Today's Mission</strong>
                </div>
                <div class="calc-mission-body">
                    <span>Invite 2 Paid Members</span>
                    <span class="calc-mission-reward">+ TZS <?= number_format((int)($missionStatus['reward_amount'] ?? 500)) ?></span>
                </div>
                <div class="calc-mission-status" id="calcMissionStatus">
                    <i class="fas fa-check-circle" style="color:#10b981;"></i>
                    <span id="calcMissionText">Mission Achievable Today</span>
                </div>
            </div>
            <?php endif; ?>

            <div class="calc-actions">
                <button class="calc-btn" onclick="copyEarningsSummary()"><i class="fas fa-copy me-1"></i> Copy Summary</button>
                <button class="calc-btn" onclick="exportCalcPNG()"><i class="fas fa-image me-1"></i> Download PNG</button>
                <button class="calc-btn" onclick="window.print()"><i class="fas fa-file-pdf me-1"></i> Download PDF</button>
            </div>

            <div class="calc-cta">
                <p>Want to reach this income faster?</p>
                <a href="ai-assistant" class="calc-cta-btn">
                    <i class="fas fa-wand-magic-sparkles me-1"></i> Generate Marketing Content
                </a>
            </div>

            <div class="calc-disclaimer">
                <i class="fas fa-info-circle me-1"></i>
                This is an estimated earning based on average referral growth. Actual earnings depend on referral activity.
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    let calcChart = null;
    const COMM_L1 = 2500, COMM_L2 = 1500, COMM_L3 = 1000;

    // Initial render on load
    document.addEventListener('DOMContentLoaded', updateCalculator);

    function updateCalculator() {
        const slider = document.getElementById('calcSlider');
        const input = document.getElementById('calcInput');
        const x = parseInt(slider.value) || 0;
        input.value = x;

        const l1 = x;
        const l2 = x * 3;
        const l3 = x * 3 * 3;
        const amt1 = l1 * COMM_L1;
        const amt2 = l2 * COMM_L2;
        const amt3 = l3 * COMM_L3;
        const total = amt1 + amt2 + amt3;
        const maxTotal = 1000 * COMM_L1 + 3000 * COMM_L2 + 9000 * COMM_L3;

        animateNumber('calcL1Amount', amt1, 'TZS ');
        animateNumber('calcL2Amount', amt2, 'TZS ');
        animateNumber('calcL3Amount', amt3, 'TZS ');
        animateTotal('calcTotalAmount', total);

        document.getElementById('calcL1Count').textContent = l1.toLocaleString();
        document.getElementById('calcL2Count').textContent = l2.toLocaleString();
        document.getElementById('calcL3Count').textContent = l3.toLocaleString();

        document.getElementById('calcL1Bar').style.width = (maxTotal > 0 ? (amt1 / maxTotal * 100) : 0) + '%';
        document.getElementById('calcL2Bar').style.width = (maxTotal > 0 ? (amt2 / maxTotal * 100) : 0) + '%';
        document.getElementById('calcL3Bar').style.width = (maxTotal > 0 ? (amt3 / maxTotal * 100) : 0) + '%';

        document.getElementById('networkL1').innerHTML = `<span class="network-badge">${l1.toLocaleString()}</span>`;
        document.getElementById('networkL2').innerHTML = `<span class="network-badge">${l2.toLocaleString()}</span>`;
        document.getElementById('networkL3').innerHTML = `<span class="network-badge">${l3.toLocaleString()}</span>`;

        // Mission check
        const missionStatus = document.getElementById('calcMissionStatus');
        if (missionStatus) {
            const missionText = document.getElementById('calcMissionText');
            if (x >= 2) {
                missionStatus.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981;"></i> <span>Mission Achievable Today</span>';
            } else {
                const needed = 2 - x;
                missionStatus.innerHTML = '<i class="fas fa-hourglass-half" style="color:#D4A843;"></i> <span>Need ' + needed + ' more referral' + (needed > 1 ? 's' : '') + '</span>';
            }
        }

        updateChart(l1, l2, l3, amt1, amt2, amt3);
        triggerCoinAnimation();
    }

    function syncSlider(val) {
        const v = Math.min(1000, Math.max(0, parseInt(val) || 0));
        document.getElementById('calcSlider').value = v;
        updateCalculator();
    }

    function animateNumber(elId, value, prefix) {
        const el = document.getElementById(elId);
        const current = parseInt(el.textContent.replace(/[^0-9]/g, '')) || 0;
        const start = current;
        const duration = 500;
        const startTime = performance.now();

        function step(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const currentVal = Math.round(start + (value - start) * eased);
            el.textContent = prefix + currentVal.toLocaleString();
            if (progress < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    function animateTotal(elId, value) {
        const el = document.getElementById(elId);
        const current = parseInt(el.textContent.replace(/[^0-9]/g, '')) || 0;
        const start = current;
        const duration = 800;
        const startTime = performance.now();

        function step(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const currentVal = Math.round(start + (value - start) * eased);
            el.textContent = 'TZS ' + currentVal.toLocaleString();
            if (progress < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    function updateChart(l1, l2, l3, a1, a2, a3) {
        const total = a1 + a2 + a3;
        if (total === 0) {
            if (calcChart) calcChart.destroy();
            calcChart = null;
            return;
        }
        const ctx = document.getElementById('calcChart').getContext('2d');
        if (calcChart) {
            calcChart.data.datasets[0].data = [a1, a2, a3];
            calcChart.update();
        } else {
            calcChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Level 1', 'Level 2', 'Level 3'],
                    datasets: [{
                        data: [a1, a2, a3],
                        backgroundColor: ['#72578B', '#D4A843', '#0EA5E9'],
                        borderWidth: 0,
                        hoverOffset: 8,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '70%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ctx.label + ': TZS ' + ctx.parsed.toLocaleString()
                            }
                        }
                    }
                }
            });
        }
    }

    function triggerCoinAnimation() {
        const total = document.getElementById('calcTotal');
        total.classList.remove('coin-flash');
        void total.offsetWidth;
        total.classList.add('coin-flash');
    }

    function copyEarningsSummary() {
        const x = parseInt(document.getElementById('calcInput').value) || 0;
        const l1 = x, l2 = x * 3, l3 = x * 3 * 3;
        const a1 = l1 * COMM_L1, a2 = l2 * COMM_L2, a3 = l3 * COMM_L3;
        const total = a1 + a2 + a3;
        const text = `EarnSphere Earnings Estimate\n\n` +
            `Direct Referrals: ${x}\n` +
            `Level 1 (${l1} × TZS ${COMM_L1.toLocaleString()}): TZS ${a1.toLocaleString()}\n` +
            `Level 2 (${l2} × TZS ${COMM_L2.toLocaleString()}): TZS ${a2.toLocaleString()}\n` +
            `Level 3 (${l3} × TZS ${COMM_L3.toLocaleString()}): TZS ${a3.toLocaleString()}\n` +
            `\nEstimated Total: TZS ${total.toLocaleString()}\n\n` +
            `Join EarnSphere today: ${window.location.origin}/register`;
        navigator.clipboard.writeText(text).then(() => {
            App.showToast('Summary copied!', 'success');
        }).catch(() => {
            App.showToast('Failed to copy', 'error');
        });
    }

    function exportCalcPNG() {
        const canvas = document.getElementById('calcChart');
        const total = document.getElementById('calcTotalAmount').textContent;
        if (!canvas) { App.showToast('No data to export', 'error'); return; }
        const chartCanvas = document.createElement('canvas');
        chartCanvas.width = 400;
        chartCanvas.height = 500;
        const ctx = chartCanvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, 400, 500);
        ctx.fillStyle = '#72578B';
        ctx.font = 'bold 20px Nunito, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('EarnSphere Earnings Estimate', 200, 40);
        ctx.fillStyle = '#333';
        ctx.font = '14px Nunito, sans-serif';
        ctx.fillText('Total: ' + total, 200, 70);
        ctx.drawImage(canvas, 100, 90, 200, 200);
        const x = document.getElementById('calcInput').value;
        const l1 = document.getElementById('calcL1Count').textContent;
        const l2 = document.getElementById('calcL2Count').textContent;
        const l3 = document.getElementById('calcL3Count').textContent;
        ctx.textAlign = 'left';
        ctx.font = '13px Nunito, sans-serif';
        ctx.fillStyle = '#72578B';
        ctx.fillRect(40, 310, 12, 12);
        ctx.fillStyle = '#333';
        ctx.fillText('Level 1: ' + l1 + ' referrals', 60, 322);
        ctx.fillStyle = '#D4A843';
        ctx.fillRect(40, 335, 12, 12);
        ctx.fillStyle = '#333';
        ctx.fillText('Level 2: ' + l2 + ' referrals', 60, 347);
        ctx.fillStyle = '#0EA5E9';
        ctx.fillRect(40, 360, 12, 12);
        ctx.fillStyle = '#333';
        ctx.fillText('Level 3: ' + l3 + ' referrals', 60, 372);
        ctx.textAlign = 'center';
        ctx.fillStyle = '#999';
        ctx.font = '11px Nunito, sans-serif';
        ctx.fillText('earnsphere.co.tz', 200, 480);
        const link = document.createElement('a');
        link.download = 'earnsphere-estimate.png';
        link.href = chartCanvas.toDataURL('image/png');
        link.click();
        App.showToast('Image downloaded!', 'success');
    }
    </script>
    <?php endif; ?>

    <!-- Recent Transactions -->
    <div class="dash-section">
        <div class="section-header">
            <h6><i class="fas fa-history me-1"></i> Transactions</h6>
            <a href="transactions">View all</a>
        </div>
        
        <?php if (empty($recentTransactions)): ?>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <h5>No transactions</h5>
                <p>Your earnings will appear here.</p>
            </div>
        <?php else: ?>
            <?php foreach ($recentTransactions as $tx): ?>
                <div class="list-item">
                    <div class="item-icon" style="background:<?= $tx['amount'] > 0 ? '#ecfdf5' : '#fef2f2' ?>; color:<?= $tx['amount'] > 0 ? 'var(--secondary)' : 'var(--danger)' ?>;">
                        <i class="fas fa-<?= $tx['amount'] > 0 ? 'arrow-down' : 'arrow-up' ?>"></i>
                    </div>
                    <div class="item-info">
                        <p class="item-title"><?= sanitize(truncate($tx['description'] ?: ucfirst($tx['type']), 40)) ?></p>
                        <p class="item-subtitle"><?= timeAgo($tx['created_at']) ?></p>
                    </div>
                    <div class="item-amount <?= $tx['amount'] > 0 ? 'credit' : 'debit' ?>">
                        <?= $tx['amount'] > 0 ? '+' : '' ?><?= formatCurrency(abs($tx['amount'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Bottom Navigation -->
<nav class="mobile-nav">
    <a href="dashboard" class="nav-item active">
        <i class="fas fa-home"></i>
        <span>Home</span>
    </a>
    <a href="referrals" class="nav-item">
        <i class="fas fa-users"></i>
        <span>Referrals</span>
    </a>
    <a href="wallet" class="nav-item center-action">
        <i class="fas fa-wallet"></i>
        <span>Wallet</span>
    </a>
    <a href="transactions" class="nav-item">
        <i class="fas fa-receipt"></i>
        <span>History</span>
    </a>
    <a href="profile" class="nav-item">
        <i class="fas fa-user"></i>
        <span>Profile</span>
    </a>
</nav>

<style>
.mission-card {
    background: linear-gradient(135deg, #72578B, #5a3f72);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    color: #fff;
    box-shadow: 0 4px 15px rgba(114, 87, 139, 0.3);
    margin-bottom: 1rem;
    position: relative;
    overflow: hidden;
}
.mission-card::before {
    content: '';
    position: absolute;
    top: -30%;
    right: -20%;
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: rgba(212, 168, 67, 0.1);
}
.mission-header {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}
.mission-icon {
    width: 44px;
    height: 44px;
    background: rgba(212, 168, 67, 0.2);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #D4A843;
    flex-shrink: 0;
}
.mission-info {
    flex: 1;
    min-width: 0;
}
.mission-title {
    font-weight: 800;
    font-size: 0.95rem;
    margin: 0 0 0.15rem;
}
.mission-desc {
    font-size: 0.75rem;
    opacity: 0.85;
    margin: 0;
}
.mission-reward {
    text-align: right;
    flex-shrink: 0;
}
.reward-label {
    font-size: 0.6rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.7;
}
.reward-amount {
    font-size: 1rem;
    font-weight: 800;
    color: #D4A843;
}
.mission-progress {
    margin-top: 1rem;
    padding-top: 0.75rem;
    border-top: 1px solid rgba(255,255,255,0.15);
}
.progress-header {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    margin-bottom: 0.4rem;
    opacity: 0.9;
}
.progress-track {
    height: 6px;
    background: rgba(255,255,255,0.15);
    border-radius: 3px;
    overflow: hidden;
}
.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #D4A843, #F5D77B);
    border-radius: 3px;
    transition: width 0.5s ease;
}
.progress-footer {
    display: flex;
    justify-content: space-between;
    font-size: 0.7rem;
    margin-top: 0.4rem;
    opacity: 0.7;
}
.mission-completed {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.completed-icon {
    width: 40px;
    height: 40px;
    background: rgba(16, 185, 129, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #10b981;
    flex-shrink: 0;
}
.completed-badge {
    margin-left: auto;
    background: #10b981;
    color: #fff;
    padding: 0.2rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
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
</style>

<script>
if (typeof QRCode !== 'undefined') {
    QRCode.toCanvas(document.getElementById('referralQR'), <?= json_encode($referralLink) ?>, {
        width: 160,
        margin: 1,
        color: { dark: '#72578B', light: '#FFFFFF' }
    });
}

<?php if ($showMissionAnimation): ?>
showMissionConfetti();
<?php endif; ?>

function showMissionConfetti() {
    const container = document.createElement('div');
    container.className = 'confetti-container';
    document.body.appendChild(container);
    const colors = ['#D4A843', '#5a3f72', '#F5D77B', '#10b981', '#72578B', '#fff'];

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
        container.remove();
    }, 4000);

    const toast = document.createElement('div');
    toast.className = 'custom-toast';
    toast.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#72578B;color:#fff;padding:1.5rem 2rem;border-radius:16px;text-align:center;z-index:10000;box-shadow:0 10px 40px rgba(0,0,0,0.3);min-width:280px;';
    toast.innerHTML = '<div style="font-size:3rem;margin-bottom:0.5rem;">🎉</div>' +
        '<div style="font-size:1.25rem;font-weight:800;color:#D4A843;">Mission Complete!</div>' +
        '<div style="font-size:0.9rem;margin-top:0.25rem;">Hongera! Umepata bonus ya leo!</div>';
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.transition = 'all 0.3s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translate(-50%, -50%) scale(0.8)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

<?php if ($missionStatus && !$missionStatus['completed'] && $missionStatus['has_mission']): ?>
function checkMissionProgress() {
    fetch('api/missions.php?action=status', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.has_mission && data.completed && data.just_completed) {
            const card = document.getElementById('missionCard');
            if (card) {
                card.dataset.completed = '1';
                document.getElementById('missionProgress')?.remove();
                const completedDiv = document.createElement('div');
                completedDiv.className = 'mission-completed';
                completedDiv.id = 'missionCompleted';
                completedDiv.innerHTML = '<div class="completed-icon"><i class="fas fa-check-circle"></i></div>' +
                    '<div><strong>Mission Complete!</strong><div style="font-size:0.75rem;color:rgba(255,255,255,0.7);">Hongera! Umepata bonus ya leo!</div></div>' +
                    '<div class="completed-badge">Done</div>';
                card.appendChild(completedDiv);
                showMissionConfetti();
            }
        } else if (data.has_mission && !data.completed) {
            if (document.getElementById('missionProgressText')) {
                document.getElementById('missionProgressText').textContent = data.progress || (data.completed_count + '/' + data.requirement_count);
                document.getElementById('missionProgressFill').style.width = data.percentage + '%';
                document.getElementById('missionPaidCount').textContent = data.completed_count || 0;
            }
        }
    })
    .catch(() => {});
}

setInterval(checkMissionProgress, 30000);
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/public_foot.php'; ?>
