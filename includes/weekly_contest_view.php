<?php if (!isset($user)) { http_response_code(404); exit; } ?>
    <!-- Weekly Referral Contest -->
    <?php if ($contest): ?>
    <div class="dash-section referral-contest-section">
        <div class="contest-card">
            <div class="contest-header">
                <div class="contest-trophy"><i class="fas fa-trophy"></i></div>
                <div class="contest-info">
                    <div class="contest-badge">SHINDANO LA WIKI</div>
                    <h6 class="contest-title"><?= sanitize($contest['title']) ?></h6>
                    <p class="contest-desc"><?= sanitize($contest['description']) ?></p>
                </div>
                <div class="contest-ends">
                    <div class="ends-label">Ends</div>
                    <div class="ends-date"><?= date('d M', strtotime($contest['end_date'])) ?></div>
                </div>
            </div>

            <div class="prize-row">
                <div class="prize-item gold">
                    <span class="prize-icon">01</span>
                    <div class="prize-val"><?= formatCurrency($contest['prize1']) ?></div>
                    <div class="prize-label">1st</div>
                </div>
                <div class="prize-item silver">
                    <span class="prize-icon">02</span>
                    <div class="prize-val"><?= formatCurrency($contest['prize2']) ?></div>
                    <div class="prize-label">2nd</div>
                </div>
                <div class="prize-item bronze">
                    <span class="prize-icon">03</span>
                    <div class="prize-val"><?= formatCurrency($contest['prize3']) ?></div>
                    <div class="prize-label">3rd</div>
                </div>
            </div>

            <div class="lb-title"><i class="fas fa-fire me-1"></i> Wanaoongoza</div>
            <div class="lb-list">
                <?php if (empty($contestStandings)): ?>
                    <div class="lb-empty">Hakuna wateja wapya bado. Kuwa wa kwanza!</div>
                <?php else: ?>
                    <?php foreach ($contestStandings as $s): ?>
                    <div class="lb-row <?= $s['user_id'] == $_SESSION['user_id'] ? 'me-row' : '' ?>">
                        <span class="lb-pos"><?= $s['position'] ?></span>
                        <span class="lb-name"><?= sanitize($s['name']) ?></span>
                        <span class="lb-count"><?= $s['count'] ?> referrals</span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="contest-cta">
                <?php if ($contestUserRank && $contestUserRank['count'] > 0): ?>
                    <div class="user-rank"><i class="fas fa-medal me-1"></i> Wewe uko <strong>#<?= $contestUserRank['rank'] ?></strong> — <?= $contestUserRank['count'] ?> referral<?= $contestUserRank['count'] > 1 ? 's' : '' ?></div>
                <?php else: ?>
                    <div class="user-rank muted"><i class="fas fa-rocket me-1"></i> Leta wateja <?= (int) $contest['min_referrals'] ?>+ waliolipa na ushinde zawadi!</div>
                <?php endif; ?>
                <a href="referrals" class="contest-share">
                    <i class="fas fa-share me-1"></i> Shiriki link yako
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

