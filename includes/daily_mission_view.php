<?php if (!isset($user)) { http_response_code(404); exit; } ?>
<!-- Daily Mission -->
<?php if ($user['status'] === 'active' && $missionStatus && $missionStatus['has_mission']): ?>
<div class="dash-section referral-mission-section">
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
                <div class="reward-amount" id="missionReward"><?= formatCurrency($missionStatus['reward_amount'] ?? $missionStatus['amount'] ?? 0) ?></div>
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

