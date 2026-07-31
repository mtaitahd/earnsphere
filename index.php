<?php
/**
 * EarnSphere - Landing Page
 * Mobile-first landing page for new visitors
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();

// Redirect logged-in users to dashboard
if (Auth::isLoggedIn()) {
    header('Location: ' . SITE_URL . '/dashboard');
    exit;
}

// Get system stats
$totalUsers = Database::count('users', 'status = ?', ['active']);
$totalPaid = Database::fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE status = 'completed'")['total'] ?? 0;

$pageTitle = 'Join EarnSphere';
$pageDesc = 'EarnSphere - Earn money online through referrals. Build your network, earn commissions, and start your financial journey. Sign up now!';
$pageKeywords = 'EarnSphere, earn money online, referral, commission, passive income, Tanzania, TZS, network marketing, side hustle, make money online, referral program';
include __DIR__ . '/includes/public_head.php';
?>
<style>body{padding-bottom:0 !important;}
/* --- Live Proof Section --- */
.proof-section {
    padding: 1.5rem 1.25rem;
    background: linear-gradient(160deg, var(--primary) 0%, var(--primary-dark) 100%);
}
.proof-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    font-weight: 800;
    font-size: 0.85rem;
    color: var(--white);
    letter-spacing: 0.5px;
    margin-bottom: 1rem;
}
.proof-live {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    background: rgba(16,185,129,0.25);
    color: #6ee7b7;
    border-radius: 20px;
    padding: 0.2rem 0.7rem;
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 1px;
}
.proof-live i { font-size: 0.45rem; animation: proofPulse 1.5s infinite; }
@keyframes proofPulse {
    0% { box-shadow: 0 0 0 0 rgba(110,231,183,0.6); }
    70% { box-shadow: 0 0 0 6px rgba(110,231,183,0); }
    100% { box-shadow: 0 0 0 0 rgba(110,231,183,0); }
}
.proof-ticker {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: var(--radius-lg);
    overflow: hidden;
    max-height: 220px;
    overflow-y: auto;
}
.proof-list { padding: 0.5rem; }
.proof-item {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.6rem 0.5rem;
    border-bottom: 1px solid rgba(255,255,255,0.15);
    font-size: 0.8rem;
    color: rgba(255,255,255,0.9);
}
.proof-item:last-child { border-bottom: none; }
.proof-item i { color: #6ee7b7; font-size: 0.9rem; }
.proof-name { font-weight: 700; color: var(--white); }
.proof-amount { margin-left: auto; font-weight: 800; color: #D4A843; flex-shrink: 0; }
.proof-time { font-size: 0.68rem; color: rgba(255,255,255,0.75); margin-left: 0.35rem; flex-shrink: 0; }
.proof-note {
    text-align: center;
    font-size: 0.72rem;
    color: rgba(255,255,255,0.9);
    margin-top: 0.75rem;
}
/* --- Landing Contest --- */
.landing-contest {
    padding: 2rem 1.25rem;
    background: linear-gradient(160deg, var(--primary-dark) 0%, var(--primary) 100%);
}
.contest-top { text-align: center; margin-bottom: 1.25rem; }
.contest-icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: rgba(212,168,67,0.2);
    color: #D4A843;
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 0.75rem;
    box-shadow: 0 0 25px rgba(212,168,67,0.25);
}
.contest-top h2 { font-size: 1.3rem; font-weight: 800; color: var(--white); margin-bottom: 0.35rem; }
.contest-top p { font-size: 0.82rem; color: rgba(255,255,255,0.85); margin: 0; }
.landing-prizes {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem;
    margin-bottom: 1.25rem;
}
.lp-prize {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.22);
    border-radius: var(--radius-md);
    padding: 0.75rem 0.4rem;
    text-align: center;
}
.lp-prize.lp-1 { border-color: rgba(212,168,67,0.6); }
.lp-prize.lp-2 { border-color: rgba(192,196,204,0.45); }
.lp-prize.lp-3 { border-color: rgba(205,127,50,0.5); }
.lp-medal { display: block; font-size: 1.2rem; margin-bottom: 0.3rem; }
.lp-prize strong { display: block; font-size: 0.75rem; color: var(--white); }
.contest-condition {
    background: rgba(255,255,255,0.1);
    border: 1px dashed rgba(255,255,255,0.35);
    border-radius: var(--radius-md);
    padding: 0.6rem 0.8rem;
    font-size: 0.74rem;
    color: rgba(255,255,255,0.95);
    text-align: center;
    margin-bottom: 1.25rem;
}
.landing-standings {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.22);
    border-radius: var(--radius-lg);
    padding: 1rem;
    margin-bottom: 1rem;
}
.ls-title {
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #D4A843;
    margin-bottom: 0.6rem;
}
.ls-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.15);
    font-size: 0.8rem;
}
.ls-row:last-child { border-bottom: none; }
.ls-pos {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: rgba(212,168,67,0.25);
    color: #D4A843;
    font-weight: 800;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.ls-name { font-weight: 700; color: var(--white); flex: 1; }
.ls-count { font-size: 0.7rem; color: rgba(255,255,255,0.8); flex-shrink: 0; }
.ls-empty { font-size: 0.78rem; color: rgba(255,255,255,0.85); text-align: center; padding: 0.5rem 0; }
/* --- Need Help? FAB + Modal --- */
.help-fab {
    position: fixed;
    bottom: 1.25rem;
    right: 1.25rem;
    z-index: 9990;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: var(--white);
    border: none;
    border-radius: 40px;
    padding: 0.7rem 1.2rem;
    font-weight: 800;
    font-size: 0.85rem;
    box-shadow: 0 8px 24px rgba(114,87,139,0.4);
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.help-fab:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(114,87,139,0.5); }
.help-fab i { font-size: 1.05rem; }
.help-modal {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    z-index: 10500;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}
.help-modal-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.4);
}
.help-modal-content {
    position: relative;
    background: #fff;
    border-radius: 20px 20px 0 0;
    width: 100%;
    max-width: 500px;
    max-height: 85vh;
    overflow-y: auto;
    animation: helpSlideUp 0.3s ease;
}
@keyframes helpSlideUp {
    from { transform: translateY(100%); }
    to { transform: translateY(0); }
}
.help-modal-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1.25rem 1.25rem 0.75rem;
    border-bottom: 1px solid var(--gray-100);
    position: sticky;
    top: 0;
    background: #fff;
}
.help-modal-header-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #f0ebf5;
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}
.help-modal-header h5 {
    flex: 1;
    font-weight: 800;
    font-size: 1rem;
    margin: 0;
}
.help-modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--gray-100);
    color: var(--gray-500);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.85rem;
}
.help-modal-close:hover { background: var(--gray-200); }
.help-modal-body { padding: 1rem 1.25rem 1.5rem; }
.help-modal-sub {
    font-size: 0.82rem;
    color: var(--gray-500);
    margin: 0 0 1rem;
}
</style>

<!-- Hero Section -->
<section class="landing-hero">
    <div class="brand-icon">
        <i class="fas fa-gem"></i>
    </div>
    <h1>EarnSphere</h1>
    <p class="tagline">Your gateway to opportunity and income</p>
    <p class="tagline" style="font-size: 0.9rem; opacity: 0.8; margin-bottom: 2rem;">
        Build your network, earn through referrals, and start your financial journey.
    </p>
    
    <div class="cta-buttons">
        <a href="register" class="btn btn-light btn-lg w-100 mb-3" style="font-weight: 800;">
            <i class="fas fa-rocket me-2"></i> Join Now
        </a>
        <a href="login" class="btn btn-outline-light btn-lg w-100">
            <i class="fas fa-sign-in-alt me-2"></i> Login
        </a>
    </div>
    
    
</section>

<!-- Live Proof of Payment -->
<section class="proof-section" id="proofSection">
    <div class="proof-header">
        <span class="proof-live"><i class="fas fa-circle"></i> LIVE</span>
        <span>Ushahidi wa Malipo</span>
    </div>
    <div class="proof-ticker">
        <div class="proof-list" id="proofList">
            <div class="proof-item">
                <i class="fas fa-check-circle"></i>
                <span class="proof-name">Inapakia...</span>
            </div>
        </div>
    </div>
    <div class="proof-note">
        <i class="fas fa-info-circle me-1"></i> Watu wanatolewa pesa halisi kila siku. Jiuunge nao!
    </div>
</section>

<!-- Weekly Referral Contest -->
<section class="landing-contest" id="contestSection">
    <div class="contest-top">
        <div class="contest-icon"><i class="fas fa-trophy"></i></div>
        <h2>Weekly Referral Contest</h2>
        <p id="contestDesc">Leta wateja wengi waliolipa na ushinde zawadi kubwa kila wiki!</p>
    </div>
    <div class="landing-prizes" id="contestPrizes">
        <div class="lp-prize lp-1"><span class="lp-medal">🥇</span><strong>TZS 100,000</strong></div>
        <div class="lp-prize lp-2"><span class="lp-medal">🥈</span><strong>TZS 50,000</strong></div>
        <div class="lp-prize lp-3"><span class="lp-medal">🥉</span><strong>TZS 25,000</strong></div>
    </div>
    <div class="contest-condition" id="contestCondition">
        <i class="fas fa-gift me-1"></i> Zawadi hizi ni kwa washindi 3 wa juu wanaoleta wateja wengi waliolipa (min. 1) ndani ya kipindi cha mashindano.
    </div>
    <div class="landing-standings" id="contestStandings">
        <div class="ls-title"><i class="fas fa-fire me-1"></i> Wanaoongoza Wiki Hii</div>
        <div id="standingsList">
            <div class="ls-empty">Bado hakuna wateja wapya. Kuwa wa kwanza kuongoza!</div>
        </div>
    </div>
    <a href="register" class="btn btn-light btn-lg mt-3" style="font-weight:800;width:100%;">
        <i class="fas fa-rocket me-2"></i> Jiunge na Ushindane
    </a>
</section>



<!-- CTA Bottom -->
<section class="cta-bottom">
    <h2>Ready to Get Started?</h2>
    <p>Join thousands of members who are already earning money through EarnSphere.</p>
    <a href="register" class="btn btn-light btn-lg" style="font-weight: 800;">
        <i class="fas fa-rocket me-2"></i>Join Free
    </a>
</section>

<!-- Footer -->
<footer style="padding: 2rem 1.5rem; text-align: center; background: var(--gray-900); color: var(--gray-400); font-size: 0.8rem;">
    <p style="margin-bottom: 0.5rem;">
        <i class="fas fa-gem me-1" style="color: var(--primary-light);"></i>
        <strong style="color: var(--white);">EarnSphere</strong>
    </p>
    <p style="margin: 0;">Earn money online through referrals &copy; <?= date('Y') ?></p>
</footer>

<!-- Need Help? Button -->
<button type="button" class="help-fab" onclick="openHelpModal()" title="Need Help?">
    <i class="fas fa-life-ring"></i>
    <span>Need Help?</span>
</button>

<!-- Help Modal -->
<div id="helpModal" class="help-modal" style="display:none;">
    <div class="help-modal-overlay" onclick="closeHelpModal()"></div>
    <div class="help-modal-content">
        <div class="help-modal-header">
            <div class="help-modal-header-icon"><i class="fas fa-life-ring"></i></div>
            <h5>Need Help?</h5>
            <button class="help-modal-close" onclick="closeHelpModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="help-modal-body">
            <p class="help-modal-sub">Tell us your problem and our team will get back to you.</p>

            <div id="helpFormWrap">
                <form id="helpForm" onsubmit="submitHelp(event)">
                    <div class="form-floating mb-2">
                        <input type="text" class="form-control" id="helpName" placeholder="Full Name" required maxlength="150">
                        <label for="helpName"><i class="fas fa-user me-1"></i> Full Name</label>
                    </div>
                    <div class="form-floating mb-2">
                        <input type="tel" class="form-control" id="helpPhone" placeholder="Phone (e.g. 0712 345 678)">
                        <label for="helpPhone"><i class="fas fa-phone me-1"></i> Phone (optional)</label>
                    </div>
                    <div class="form-floating mb-2">
                        <input type="text" class="form-control" id="helpSubject" placeholder="Subject" maxlength="200">
                        <label for="helpSubject"><i class="fas fa-tag me-1"></i> Subject (optional)</label>
                    </div>
                    <div class="form-floating mb-3">
                        <textarea class="form-control" id="helpMessage" placeholder="Describe your problem..." required style="height:110px;resize:vertical;" minlength="5"></textarea>
                        <label for="helpMessage"><i class="fas fa-comment-dots me-1"></i> Your Problem</label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="helpSubmitBtn" style="font-weight:800;">
                        <i class="fas fa-paper-plane me-2"></i> Send Message
                    </button>
                </form>
            </div>

            <div id="helpSuccessWrap" style="display:none;text-align:center;padding:1.5rem 0;">
                <div style="width:64px;height:64px;border-radius:50%;background:#e8f9f0;color:#1CC88A;display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin:0 auto 1rem;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h5 style="font-weight:800;color:var(--gray-800);">Message Sent!</h5>
                <p style="font-size:0.85rem;color:var(--gray-500);margin:0.5rem 0 1.25rem;" id="helpSuccessMsg">Your message has been sent. We will reply soon!</p>
                <button class="btn btn-outline-secondary btn-sm" onclick="closeHelpModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    /* Live Proof of Payment */
    fetch('api/live_proof.php?limit=8')
        .then(r => r.json())
        .then(res => {
            const list = document.getElementById('proofList');
            if (!list) return;
            if (!res.success || res.data.length === 0) {
                list.innerHTML = '<div class="proof-item"><i class="fas fa-check-circle"></i><span class="proof-name">Malipo yanaingia kila siku</span></div>';
                return;
            }
            let html = '';
            res.data.forEach(x => {
                html += '<div class="proof-item">' +
                    '<i class="fas fa-check-circle"></i>' +
                    '<span class="proof-name">' + escapeHtml(x.name) + '</span>' +
                    '<span class="proof-amount">TZS ' + Number(x.amount).toLocaleString() + '</span>' +
                    '<span class="proof-time">' + escapeHtml(x.time) + '</span>' +
                '</div>';
            });
            list.innerHTML = html;
        })
        .catch(() => {});

    /* Weekly Contest */
    fetch('api/contest.php?action=standings&limit=5')
        .then(r => r.json())
        .then(res => {
            if (!res.success || !res.contest) return;
            document.getElementById('contestDesc').textContent = res.contest.description || 'Leta wateja wengi waliolipa na ushinde zawadi kubwa kila wiki!';

            const prizes = document.getElementById('contestPrizes');
            if (prizes && res.contest.prize1) {
                const p = res.contest;
                prizes.innerHTML =
                    '<div class="lp-prize lp-1"><span class="lp-medal">🥇</span><strong>TZS ' + Number(p.prize1).toLocaleString() + '</strong></div>' +
                    '<div class="lp-prize lp-2"><span class="lp-medal">🥈</span><strong>TZS ' + Number(p.prize2).toLocaleString() + '</strong></div>' +
                    '<div class="lp-prize lp-3"><span class="lp-medal">🥉</span><strong>TZS ' + Number(p.prize3).toLocaleString() + '</strong></div>';
            }

            const cond = document.getElementById('contestCondition');
            if (cond) {
                const min = Number(res.contest.min_referrals) || 1;
                const fmt = d => {
                    const dt = new Date(String(d).slice(0,10) + 'T00:00:00');
                    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                    return dt.getDate() + ' ' + months[dt.getMonth()];
                };
                cond.innerHTML = '<i class="fas fa-gift me-1"></i> Zawadi hizi ni kwa washindi 3 wa juu wanaoleta wateja ' + min + '+ waliolipa ndani ya kipindi cha ' + fmt(res.contest.start_date) + ' - ' + fmt(res.contest.end_date) + '.';
            }

            const slist = document.getElementById('standingsList');
            if (!slist) return;
            if (res.standings && res.standings.length > 0) {
                let html = '';
                res.standings.forEach(s => {
                    html += '<div class="ls-row">' +
                        '<span class="ls-pos">' + s.position + '</span>' +
                        '<span class="ls-name">' + escapeHtml(s.name) + '</span>' +
                        '<span class="ls-count">' + s.count + ' referral' + (s.count > 1 ? 's' : '') + '</span>' +
                    '</div>';
                });
                slist.innerHTML = html;
            }
        })
        .catch(() => {});

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
});

/* --- Need Help? Modal --- */
function openHelpModal() {
    document.getElementById('helpModal').style.display = 'flex';
    document.getElementById('helpFormWrap').style.display = '';
    document.getElementById('helpSuccessWrap').style.display = 'none';
    if (!document.getElementById('helpName').value) {
        document.getElementById('helpName').focus();
    }
}
function closeHelpModal() {
    document.getElementById('helpModal').style.display = 'none';
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeHelpModal();
});

async function submitHelp(e) {
    e.preventDefault();
    const btn = document.getElementById('helpSubmitBtn');
    const name = document.getElementById('helpName').value.trim();
    const phone = document.getElementById('helpPhone').value.trim();
    const subject = document.getElementById('helpSubject').value.trim();
    const message = document.getElementById('helpMessage').value.trim();

    if (message.length < 5) {
        alert('Please describe your problem (at least 5 characters).');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';

    try {
        const body = new URLSearchParams({ name, phone, subject, message });
        const resp = await fetch('<?= SITE_URL ?>/api/support.php?action=create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
            },
            body: body.toString()
        });
        const data = await resp.json();
        if (data.success) {
            document.getElementById('helpForm').reset();
            document.getElementById('helpFormWrap').style.display = 'none';
            document.getElementById('helpSuccessMsg').textContent = data.message || 'Your message has been sent. We will reply soon!';
            document.getElementById('helpSuccessWrap').style.display = 'block';
        } else {
            alert(data.message || 'Something went wrong. Please try again.');
        }
    } catch (err) {
        alert('Network error. Please check your connection and try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Send Message';
    }
}
</script>

<?php include __DIR__ . '/includes/public_foot.php'; ?>
