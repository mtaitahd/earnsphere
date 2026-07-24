<?php
/**
 * EarnSphere - Payment Page
 * Dashboard-styled payment form with color-coded elements
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/SnippePayment.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();

$userId = (int)($_GET['user_id'] ?? $_SESSION['pending_user_id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? 'show';

if (!$userId) {
    redirect(SITE_URL . '/register', 'error', 'User not found');
}

$user = Database::fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
if (!$user) {
    redirect(SITE_URL . '/register', 'error', 'User not found');
}

if ($user['status'] === 'active') {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_status'] = $user['status'];
    redirect(SITE_URL . '/dashboard', 'success', 'Your account is already activated!');
}

$existingPayment = Database::fetchOne(
    "SELECT * FROM payments WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
    [$userId]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['pay', 'change_number'])) {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Security: Please try again.';
    } else {
        $phone = trim($_POST['phone'] ?? '');
        
        if (empty($phone)) {
            $error = 'Enter payment phone number.';
        } else {
            $snippe = new SnippePayment();
            $result = $snippe->initiatePayment($userId, $phone);
            
            if ($result['success']) {
                $_SESSION['pending_user_id'] = $userId;
                $orderId = $result['order_id'];
                $ref = $result['reference'] ?? '';
                header('Location: ' . SITE_URL . '/payment?user_id=' . $userId . '&action=waiting&order_id=' . $orderId . '&ref=' . urlencode($ref));
                exit;
            } else {
                $error = $result['error'] ?? 'Payment error. Please try again.';
                $action = 'show';
            }
        }
    }
    $action = 'show';
}

$csrf = Auth::generateCSRF();
$csrfName = CSRF_TOKEN_NAME;
$csrfVal = $csrf;
$siteUrl = SITE_URL;
$phone = $user['phone'];
$fullName = sanitize($user['full_name']);
$refCode = $user['referral_code'];
$userIdVal = $user['id'];
$orderIdVal = sanitize($_GET['order_id'] ?? '');
$refVal = sanitize($_GET['ref'] ?? '');
$referralLink = getReferralLink($refCode);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#72578B">
    <title>Complete Payment | EarnSphere</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/app.css">
    <style>
        .payment-hero {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 50%, #3d2660 100%);
            color: var(--white);
            padding: 1.5rem 1.25rem 3rem;
            position: relative;
            overflow: hidden;
        }
        .payment-hero::before {
            content: '';
            position: absolute;
            top: -30%;
            right: -15%;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255,255,255,0.06);
        }
        .payment-hero .back-link {
            color: rgba(255,255,255,0.8);
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            margin-bottom: 1rem;
        }
        .payment-hero .back-link:hover { color: var(--white); }
        .payment-hero h2 {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
        }
        .payment-hero p {
            opacity: 0.85;
            font-size: 0.9rem;
            margin: 0;
        }

        .payment-body {
            padding: 0 1rem 2rem;
            margin-top: -1.5rem;
            position: relative;
            z-index: 2;
        }

        .amount-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--card-shadow-lg);
            text-align: center;
            margin-bottom: 1rem;
        }
        .amount-card .icon-circle {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: var(--primary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            font-size: 1.5rem;
            color: var(--primary);
        }
        .amount-card .amount-label {
            font-size: 0.8rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .amount-card .amount-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary);
            margin: 0.25rem 0;
        }
        .amount-card .amount-sub {
            font-size: 0.8rem;
            color: var(--gray-400);
        }

        .info-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1rem 1.25rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1rem;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 0;
        }
        .info-row:not(:last-child) {
            border-bottom: 1px solid var(--gray-100);
        }
        .info-row .label {
            font-size: 0.85rem;
            color: var(--gray-500);
        }
        .info-row .value {
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--gray-800);
        }
        .info-row .value.primary {
            color: var(--primary);
        }
        .info-row .value.green {
            color: var(--secondary);
        }

        .form-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--card-shadow-lg);
            margin-bottom: 1rem;
        }
        .form-card .card-title {
            font-size: 1rem;
            font-weight: 800;
            color: var(--gray-800);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-card .card-title i {
            color: var(--primary);
        }

        .pay-btn {
            background: linear-gradient(135deg, var(--secondary), var(--secondary-dark));
            border: none;
            color: var(--white);
            width: 100%;
            padding: 1rem;
            border-radius: var(--radius-lg);
            font-size: 1.1rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(16,185,129,0.3);
            transition: all 0.2s ease;
        }
        .pay-btn:active {
            transform: scale(0.98);
        }
        .pay-btn:disabled {
            opacity: 0.7;
        }

        .secure-badge {
            text-align: center;
            padding: 0.75rem;
            font-size: 0.8rem;
            color: var(--gray-400);
        }
        .secure-badge i { color: var(--secondary); }

        /* Waiting state */
        .waiting-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 2rem 1.5rem;
            box-shadow: var(--card-shadow-lg);
            text-align: center;
        }
        .waiting-spinner {
            width: 64px;
            height: 64px;
            border: 4px solid var(--gray-200);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1.5rem;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body style="padding-bottom:0;">

<?php if ($action === 'show'): ?>
<div class="payment-hero">
    <a href="<?= $siteUrl ?>/register" class="back-link">
        <i class="fas fa-arrow-left"></i> Back
    </a>
    <h2><i class="fas fa-credit-card me-2"></i>Complete Payment</h2>
    <p>Pay the registration fee to activate your account</p>
</div>

<div class="payment-body">

    <?php if (isset($error)): ?>
        <div class="alert alert-danger d-flex align-items-center mt-3" role="alert" style="border-radius:var(--radius-md);">
            <i class="fas fa-exclamation-circle me-2"></i> <?= sanitize($error) ?>
        </div>
    <?php endif; ?>

    <!-- Amount Card -->
    <div class="amount-card" style="margin-top:-0.5rem;">
        <div class="icon-circle">
            <i class="fas fa-gem"></i>
        </div>
    <div class="amount-label">Registration Fee</div>
    <div class="amount-value"><?= formatCurrency(app_setting('registration_fee', REGISTRATION_FEE)) ?></div>
    <div class="amount-sub">One-time payment only</div>
    </div>

    <!-- User Info Card -->
    <div class="info-card">
        <div class="info-row">
            <span class="label"><i class="fas fa-user me-1"></i> Name</span>
            <span class="value"><?= $fullName ?></span>
        </div>
        <div class="info-row">
            <span class="label"><i class="fas fa-tag me-1"></i> Referral Code</span>
            <span class="value primary"><?= $refCode ?></span>
        </div>
    </div>

    <!-- Payment Form -->
    <div class="form-card">
        <div class="card-title">
            <i class="fas fa-mobile-alt"></i> Mobile Payment
        </div>

        <form method="POST" action="">
            <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
            <input type="hidden" name="action" value="pay">

            <div class="form-floating mb-3">
                <input type="tel" class="form-control" id="phone" name="phone"
                       placeholder="Payment Phone Number" required
                       value="<?= sanitize($phone) ?>">
                <label for="phone"><i class="fas fa-mobile-screen me-1"></i> Phone Number</label>
                <small class="text-muted ms-1">M-Pesa, Tigo Pesa, Airtel Money, or HaloPesa</small>
            </div>

            <button type="submit" class="pay-btn">
                <i class="fas fa-lock"></i> Pay Now — <?= formatCurrency(app_setting('registration_fee', REGISTRATION_FEE)) ?>
            </button>
        </form>
    </div>

    <div class="secure-badge">
        <i class="fas fa-shield-halved me-1"></i>
        Your payment is secure — powered by Snippe API
    </div>
</div>

<?php elseif ($action === 'waiting'): ?>
<div class="payment-hero">
    <a href="<?= $siteUrl ?>/payment?user_id=<?= $userIdVal ?>&action=show" class="back-link">
        <i class="fas fa-arrow-left"></i> Change Number
    </a>
    <h2><i class="fas fa-hourglass-half me-2"></i>Processing Payment</h2>
    <p>Please check your phone to complete the payment</p>
</div>

<div class="payment-body">
    <div class="waiting-card" style="text-align:center;">
        <div class="waiting-spinner"></div>

        <h5 style="font-weight:800;color:var(--gray-800);">Waiting for Payment Confirmation</h5>

        <p style="color:var(--gray-500);font-size:0.9rem;margin:0.5rem 0 1.5rem;">
            <i class="fas fa-mobile-screen me-1"></i> A payment prompt has been sent to your phone.<br>
            Enter your PIN to confirm the payment.
        </p>

        <?php if ($orderIdVal): ?>
        <div class="info-card" style="text-align:left;box-shadow:none;">
            <div class="info-row">
                <span class="label">Order ID</span>
                <span class="value primary" style="font-size:0.8rem;"><?= $orderIdVal ?></span>
            </div>
        </div>
        <?php endif; ?>

        <div id="payment-status" style="display:none;"></div>

        <div id="payment-error" class="alert alert-danger d-none" style="font-size:0.85rem;">
            <i class="fas fa-exclamation-triangle me-1"></i> <span id="error-msg"></span>
        </div>

        <button type="button" class="btn btn-primary mt-2 mb-2" id="checkStatusBtn" onclick="checkStatusNow()" style="font-size:0.9rem;padding:0.6rem 1.5rem;">
            <i class="fas fa-check me-1"></i> I've Confirmed Payment
        </button>

        <div style="margin-top:1rem;">
            <button type="button" class="btn btn-outline-primary" id="toggleChangeNumBtn" onclick="toggleChangeNum()" style="font-size:0.85rem;">
                <i class="fas fa-sync me-1"></i> Change Number
            </button>
            <a href="<?= $siteUrl ?>/dashboard"
               class="btn btn-outline-secondary ms-2" style="font-size:0.85rem;">
                <i class="fas fa-home me-1"></i> Dashboard
            </a>
        </div>

        <div id="changeNumSection" style="display:none;margin-top:1rem;text-align:left;background:var(--gray-50);padding:1rem;border-radius:var(--radius-md);">
            <p style="font-size:0.85rem;font-weight:700;color:var(--gray-800);margin-bottom:0.5rem;">
                <i class="fas fa-mobile-screen me-1"></i> Send USSD push to a different number
            </p>
            <form method="POST" action="<?= $siteUrl ?>/payment?user_id=<?= $userIdVal ?>&action=change_number">
                <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                <div class="input-group" style="border-radius:var(--radius-sm);overflow:hidden;">
                    <span class="input-group-text" style="background:var(--gray-100);border:none;font-size:0.9rem;"><i class="fas fa-phone"></i></span>
                    <input type="tel" name="phone" class="form-control" placeholder="e.g. 0712 345 678" required
                           style="border:none;font-size:0.9rem;padding:0.6rem 0.8rem;">
                    <button type="submit" class="btn btn-primary" style="border:none;font-size:0.85rem;padding:0.6rem 1rem;">
                        <i class="fas fa-paper-plane me-1"></i> Send
                    </button>
                </div>
                <small style="color:var(--gray-500);font-size:0.75rem;">M-Pesa, Tigo Pesa, Airtel Money, or HaloPesa</small>
            </form>
        </div>
    </div>
</div>

<script>
var orderId = <?= json_encode($orderIdVal) ?>;
var checkInterval;

function toggleChangeNum() {
    var section = document.getElementById('changeNumSection');
    var btn = document.getElementById('toggleChangeNumBtn');
    if (section.style.display === 'none') {
        section.style.display = 'block';
        btn.innerHTML = '<i class="fas fa-times me-1"></i> Cancel';
    } else {
        section.style.display = 'none';
        btn.innerHTML = '<i class="fas fa-sync me-1"></i> Change Number';
    }
}

function checkStatusNow() {
    var btn = document.getElementById('checkStatusBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Checking...';
    document.getElementById('payment-error').classList.add('d-none');

    fetch('<?= $siteUrl ?>/api/check_payment.php?order_id=' + encodeURIComponent(orderId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check me-1"></i> I\'ve Confirmed Payment';

            if (data.status === 'completed') {
                clearInterval(checkInterval);
                document.getElementById('payment-status').innerHTML =
                    '<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i> Payment confirmed! Redirecting...</div>';
                document.getElementById('payment-status').style.display = 'block';
                setTimeout(function() { window.location.href = '<?= $siteUrl ?>/dashboard'; }, 1000);
            } else if (data.status === 'failed' || data.status === 'voided' || data.status === 'expired') {
                clearInterval(checkInterval);
                document.getElementById('payment-status').innerHTML =
                    '<div class="alert alert-danger"><i class="fas fa-times-circle me-1"></i> Payment failed. <a href="<?= $siteUrl ?>/payment?user_id=<?= $userIdVal ?>&action=show" class="alert-link">Try again</a></div>';
                document.getElementById('payment-status').style.display = 'block';
            } else {
                document.getElementById('payment-error').classList.remove('d-none');
                document.getElementById('error-msg').textContent = 'Payment not yet confirmed. Please check your phone and try again.';
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check me-1"></i> I\'ve Confirmed Payment';
            document.getElementById('payment-error').classList.remove('d-none');
            document.getElementById('error-msg').textContent = 'Connection error. Please try again.';
        });
}

if (orderId) {
    checkInterval = setInterval(function() {
        fetch('<?= $siteUrl ?>/api/check_payment.php?order_id=' + encodeURIComponent(orderId))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 'completed') {
                    clearInterval(checkInterval);
                    window.location.href = '<?= $siteUrl ?>/dashboard';
                } else if (data.status === 'failed' || data.status === 'voided' || data.status === 'expired') {
                    clearInterval(checkInterval);
                    document.getElementById('payment-status').innerHTML =
                        '<div class="alert alert-danger"><i class="fas fa-times-circle me-1"></i> Payment failed. <a href="<?= $siteUrl ?>/payment?user_id=<?= $userIdVal ?>&action=show" class="alert-link">Try again</a></div>';
                    document.getElementById('payment-status').style.display = 'block';
                }
            })
            .catch(function() {});
    }, 5000);
}
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
