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

require_once __DIR__.'/classes/Marketplace.php';
$landingProducts=[];
try {$landingProducts=Marketplace::publicCatalog(4);} catch(Throwable $e) {error_log('Landing marketplace: '.$e->getMessage());}

// Get system stats
$totalUsers = Database::count('users', 'status = ?', ['active']);
$totalPaid = Database::fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE status = 'completed'")['total'] ?? 0;

$pageTitle = 'Join EarnSphere';
$pageDesc = 'EarnSphere - Earn money online through referrals. Build your network, earn commissions, and start your financial journey. Sign up now!';
$pageKeywords = 'EarnSphere, earn money online, referral, commission, passive income, Tanzania, TZS, network marketing, side hustle, make money online, referral program';
include __DIR__ . '/includes/public_head.php';
?>
<link rel="stylesheet" href="<?= sanitize(SITE_URL) ?>/assets/css/landing.css?v=<?= filemtime(__DIR__.'/assets/css/landing.css') ?>">

<link rel="stylesheet" href="<?= sanitize(SITE_URL) ?>/assets/css/member-services.css?v=<?= filemtime(__DIR__.'/assets/css/member-services.css') ?>">
<nav class="landing-public-nav" aria-label="Main navigation"><a class="landing-brand" href="./">EarnSphere</a><div><a href="marketplace"><i class="fas fa-store" aria-hidden="true"></i> Soko</a><a href="login">Login</a></div></nav>
<!-- Hero Section -->
<section class="landing-hero home-hero">
    <div class="home-hero-inner">
        <div class="home-hero-copy">
            <p class="home-eyebrow">KARIBU EARNSPHERE</p>
            <h1>Jifunze. Ungana.<br>Fanya biashara.</h1>
            <p class="home-intro">Shiriki tasks, jenga mtandao wa referrals na ugundue bidhaa za wauzaji wetu. Huduma zako zote, sehemu moja.</p>
            <div class="home-hero-actions"><a href="register" class="btn btn-light">Create Account <i class="fas fa-arrow-right" aria-hidden="true"></i></a><a href="marketplace" class="btn btn-outline-light">Browse Soko</a></div>
            <p class="home-hero-note">Kuangalia bidhaa hakuhitaji account.</p>
        </div>
        <div class="home-feature-list" aria-label="EarnSphere services">
            <div><span>01</span><div><h2>Referrals</h2><p>Shiriki link yako na fuatilia mtandao wako.</p></div></div>
            <div><span>02</span><div><h2>Tasks &amp; Quiz</h2><p>Fuata maelekezo, jibu maswali na fuatilia rewards.</p></div></div>
            <div><span>03</span><div><h2>Soko</h2><p>Tafuta bidhaa na wasiliana na muuzaji moja kwa moja.</p></div></div>
        </div>
    </div>
</section>

<?php include __DIR__.'/includes/landing_marketplace.php'; ?>

<section class="home-how" aria-labelledby="home-how-title"><p class="home-section-kicker">NI RAHISI KUANZA</p><h2 id="home-how-title">Chagua unachohitaji.</h2><ol><li><span>01</span><h3>Tafuta bidhaa</h3><p>Tumia Search Goods kupata bidhaa kwa jina, category au eneo.</p></li><li><span>02</span><h3>Ongea na muuzaji</h3><p>Fungua bidhaa, kisha jadiliana kuhusu bei na delivery kupitia WhatsApp.</p></li><li><span>03</span><h3>Jiunge na shiriki</h3><p>Fungua account kwa ajili ya tasks, referrals na huduma za wanachama.</p></li></ol></section>
<div class="home-activity">
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
        <i class="fas fa-info-circle me-1"></i> Malipo yaliyothibitishwa ndani ya saa 5 zilizopita.
    </div>
</section>

<!-- Weekly Referral Contest -->
<details class="landing-contest" id="contestSection"><summary>Shindano la wiki <i class="fas fa-chevron-down" aria-hidden="true"></i></summary>
    <div class="contest-top">
        <p id="contestDesc">Leta wateja wengi waliolipa na ushinde zawadi kubwa kila wiki!</p>
    </div>
    <div class="landing-prizes" id="contestPrizes"></div>
    <div class="contest-condition" id="contestCondition">Taarifa za shindano zinapakiwa...</div>
    <div class="landing-standings" id="contestStandings">
        <div class="ls-title"><i class="fas fa-fire me-1"></i> Wanaoongoza Wiki Hii</div>
        <div id="standingsList">
            <div class="ls-empty">Bado hakuna wateja wapya. Kuwa wa kwanza kuongoza!</div>
        </div>
    </div>
</details>
</div>

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
            <p class="help-modal-sub">Tell us your problem and our team will get back to you on your dashboard.</p>

            <div id="helpFormWrap">
                <form id="helpForm" onsubmit="submitHelp(event)">
                    <div class="form-floating mb-2">
                        <input type="text" class="form-control" id="helpName" placeholder="Full Name" required maxlength="150">
                        <label for="helpName"><i class="fas fa-user me-1"></i> Full Name</label>
                    </div>
                    <div class="form-floating mb-2">
                        <input type="tel" class="form-control" id="helpPhone" placeholder="Phone (e.g. 0712 345 678)" required maxlength="15">
                        <label for="helpPhone"><i class="fas fa-phone me-1"></i> Phone</label>
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
                <div style="width:64px;height:64px;border-radius: 0;background:#e8f9f0;color:#1CC88A;display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin:0 auto 1rem;">
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
                list.innerHTML = '<div class="proof-item"><i class="fas fa-check-circle"></i><span class="proof-name">Hakuna malipo mapya ndani ya saa 5.</span></div>';
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
            if (!res.success || !res.contest) { document.getElementById('contestSection').hidden = true; return; }
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
            alert(data.error || data.message || 'Something went wrong. Please try again.');
        }
    } catch (err) {
        alert('Network error. Please check your connection and try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Send Message';
    }
}
</script>

<script>if (window.location.hash === '#help') { window.addEventListener('load', function () { if (typeof openHelpModal === 'function') openHelpModal(); }); }</script>
<?php include __DIR__ . '/includes/public_foot.php'; ?>
