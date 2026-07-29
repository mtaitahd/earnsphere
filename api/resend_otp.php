<?php
/**
 * EarnSphere - Resend OTP API
 * AJAX endpoint to resend OTP code
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/OTP.php';
require_once dirname(__DIR__) . '/classes/ErrorLogger.php';

header('Content-Type: application/json');

Auth::initSession();

$userId = (int)($_SESSION['reset_user_id'] ?? 0);

if (!$userId) {
    ErrorLogger::log('api', 'OTP resend failed: reset session missing', [], null, 'warning', 'api/resend_otp.php');
    echo json_encode(['success' => false, 'message' => 'Session imekwisha. Anza upya.']);
    exit;
}

if (OTP::sendUserOTP($userId, 'reset')) {
    echo json_encode(['success' => true, 'message' => 'Msimbo mpya umetumwa kwenye barua pepe yako.']);
} else {
    ErrorLogger::log('api', 'OTP resend failed', [
        'otp_type' => 'reset',
    ], $userId, 'error', 'api/resend_otp.php');
    echo json_encode(['success' => false, 'message' => 'Imeshindwa kutuma msimbo. Jaribu tena.']);
}
