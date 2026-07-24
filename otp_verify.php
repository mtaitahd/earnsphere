<?php
/**
 * EarnSphere - OTP Verify
 * Step 2: User enters 6-digit OTP with split boxes
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/OTP.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();

$userId = (int)($_SESSION['reset_user_id'] ?? 0);
if (!$userId) {
    header('Location: ' . SITE_URL . '/forgot_password');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Security: Please try again.';
    } else {
        $otpCode = trim($_POST['otp'] ?? '');

        if (empty($otpCode) || strlen($otpCode) !== OTP_LENGTH) {
            $error = 'Please enter a ' . OTP_LENGTH . '-digit code.';
        } else {
            if (OTP::verify($userId, $otpCode, 'reset')) {
                $_SESSION['reset_verified'] = true;
                header('Location: ' . SITE_URL . '/reset_password');
                exit;
            } else {
                $error = 'Invalid or expired code. Please try again.';
            }
        }
    }
}

$csrf = Auth::generateCSRF();
$pageTitle = 'Verify Code';
$pageDesc = 'Enter the verification code sent to your email to reset your EarnSphere account password.';
$pageKeywords = 'EarnSphere verify code, OTP verification, password reset code';
include __DIR__ . '/includes/public_head.php';
?>

<style>
.otp-container {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
    margin: 1.5rem 0;
}
.otp-box {
    width: 48px;
    height: 56px;
    text-align: center;
    font-size: 1.5rem;
    font-weight: 800;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    background: #f9fafb;
    color: #1f2937;
    outline: none;
    transition: all 0.2s;
    caret-color: transparent;
}
.otp-box:focus {
    border-color: #72578B;
    box-shadow: 0 0 0 3px rgba(114, 87, 139, 0.15);
    background: #fff;
}
.otp-box.filled {
    border-color: #72578B;
    background: #f3eef7;
}
.otp-box.error {
    border-color: #E74A3B;
    box-shadow: 0 0 0 3px rgba(231, 74, 59, 0.15);
}
#otp-hidden {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
@media (max-width: 400px) {
    .otp-box {
        width: 42px;
        height: 50px;
        font-size: 1.3rem;
    }
}
</style>

<div class="auth-page">
    <div class="auth-header">
        <div class="brand-icon">
            <i class="fas fa-shield-alt"></i>
        </div>
        <h2>Enter Code</h2>
        <p>We've sent a <?= OTP_LENGTH ?>-digit code to your email</p>
    </div>
    
    <div class="auth-body">
        <div class="auth-card">
            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i> <?= sanitize($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="otpForm">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                <input type="hidden" name="otp" id="otp-hidden">
                
                <div class="otp-container" id="otpContainer">
                    <?php for ($i = 0; $i < OTP_LENGTH; $i++): ?>
                        <input type="text" class="otp-box" maxlength="1" inputmode="numeric" 
                               pattern="[0-9]" autocomplete="one-time-code" data-index="<?= $i ?>">
                    <?php endfor; ?>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block btn-lg" id="verifyBtn" disabled>
                    <i class="fas fa-check me-1"></i> Verify
                </button>
            </form>
            
            <div class="text-center mt-3">
                <p style="font-size:0.85rem;color:var(--gray-500);">Didn't receive the code?</p>
                <button type="button" class="btn btn-outline-primary btn-sm" id="resendBtn" onclick="resendOTP()">
                    <i class="fas fa-redo me-1"></i> Resend Code
                </button>
                <div id="resendMsg" class="mt-2" style="display:none;font-size:0.85rem;"></div>
            </div>
        </div>
        
        <div class="auth-footer">
            <p><a href="forgot_password" style="font-weight:700;"><i class="fas fa-arrow-left me-1"></i> Change email</a></p>
        </div>
    </div>
</div>

<script>
(function() {
    const boxes = document.querySelectorAll('.otp-box');
    const hidden = document.getElementById('otp-hidden');
    const form = document.getElementById('otpForm');
    const verifyBtn = document.getElementById('verifyBtn');
    const otpLength = <?= OTP_LENGTH ?>;
    
    // Focus first box on load
    setTimeout(() => boxes[0]?.focus(), 100);
    
    // Handle typing
    boxes.forEach((box, i) => {
        box.addEventListener('input', (e) => {
            const val = e.target.value.replace(/[^0-9]/g, '');
            e.target.value = val;
            
            if (val && i < otpLength - 1) {
                boxes[i + 1].focus();
            }
            
            updateHidden();
            updateStyles();
        });
        
        // Handle backspace
        box.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace') {
                if (!box.value && i > 0) {
                    boxes[i - 1].focus();
                    boxes[i - 1].value = '';
                }
                updateHidden();
                updateStyles();
            }
            
            // Arrow key navigation
            if (e.key === 'ArrowLeft' && i > 0) {
                e.preventDefault();
                boxes[i - 1].focus();
            }
            if (e.key === 'ArrowRight' && i < otpLength - 1) {
                e.preventDefault();
                boxes[i + 1].focus();
            }
        });
        
        // Handle paste
        box.addEventListener('paste', (e) => {
            e.preventDefault();
            const pasteData = (e.clipboardData || window.clipboardData)
                .getData('text')
                .replace(/[^0-9]/g, '')
                .slice(0, otpLength);
            
            if (pasteData) {
                for (let j = 0; j < pasteData.length && j < otpLength; j++) {
                    boxes[j].value = pasteData[j];
                }
                // Focus last filled box or next empty
                const focusIdx = Math.min(pasteData.length, otpLength - 1);
                boxes[focusIdx].focus();
                
                updateHidden();
                updateStyles();
            }
        });
        
        // Handle focus styles
        box.addEventListener('focus', () => {
            box.select();
        });
    });
    
    function updateHidden() {
        let code = '';
        boxes.forEach(b => code += b.value);
        hidden.value = code;
        verifyBtn.disabled = code.length !== otpLength;
    }
    
    function updateStyles() {
        boxes.forEach((box, i) => {
            box.classList.remove('filled', 'error');
            if (box.value) box.classList.add('filled');
            if (box.classList.contains('shake')) box.classList.add('error');
        });
    }
    
    // Shake animation on error
    <?php if ($error): ?>
    boxes.forEach(b => {
        b.classList.add('shake', 'error');
    });
    setTimeout(() => {
        boxes.forEach(b => b.classList.remove('shake'));
    }, 600);
    <?php endif; ?>
})();
</script>

<style>
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    20% { transform: translateX(-6px); }
    40% { transform: translateX(6px); }
    60% { transform: translateX(-4px); }
    80% { transform: translateX(4px); }
}
.otp-box.shake {
    animation: shake 0.5s ease;
}
</style>

<?php include __DIR__ . '/includes/public_foot.php'; ?>
