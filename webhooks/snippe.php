<?php
/**
 * EarnSphere - Snippe Webhook Handler
 * 
 * Receives payment confirmations from Snippe API.
 * Security: HMAC-SHA256 signature verification
 * Pattern: respond 200 immediately, process async (like mtaita-tech)
 * 
 * API Reference: https://docs.snippe.sh
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/snippe.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/SnippePayment.php';
require_once dirname(__DIR__) . '/classes/CommissionEngine.php';
require_once dirname(__DIR__) . '/classes/Wallet.php';

// Ensure logs directory exists
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function webhookLog(string $msg): void {
    file_put_contents(
        dirname(__DIR__) . '/logs/webhook.log',
        date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Read raw body
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

if (!$body) {
    webhookLog('REJECT: Invalid JSON body');
    http_response_code(400);
    exit;
}

// Get headers
$headers = getallheaders();
$headersLower = array_change_key_case($headers, CASE_LOWER);

$webhookEvent    = $headersLower['x-webhook-event'] ?? ($body['type'] ?? $body['event'] ?? '');
$webhookTimestamp = $headersLower['x-webhook-timestamp'] ?? '';
$webhookSignature = $headersLower['x-webhook-signature'] ?? '';

webhookLog("INCOMING | event={$webhookEvent} | ts={$webhookTimestamp} | sig=" . substr($webhookSignature, 0, 16) . '... | body_len=' . strlen($rawBody));

// ================================================================
// VERIFY SIGNATURE
// ================================================================
$secret = SNIPPE_WEBHOOK_SECRET;
$signatureValid = false;

if (!empty($secret) && !empty($webhookSignature)) {
    $snippe = new SnippePayment();
    $signatureValid = $snippe->verifyWebhookSignature($headersLower, $rawBody);
    
    if (!$signatureValid) {
        webhookLog('REJECT: HMAC verification failed (secret_len=' . strlen($secret) . ')');
    }
} else {
    webhookLog('WARN: No webhook secret configured — skipping HMAC');
    $signatureValid = true;
}

// ================================================================
// RESPOND 200 IMMEDIATELY — prevent Snippe retries
// ================================================================
http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('ob_flush')) {
    ob_flush();
    flush();
}

if (!$signatureValid) {
    exit;
}

// ================================================================
// PROCESS EVENT (async, after 200 sent)
// ================================================================
$eventId   = $body['id'] ?? '';
$eventType = $body['type'] ?? $body['event'] ?? '';
$eventData = $body['data'] ?? $body['payment'] ?? $body;

webhookLog("PROCESS | event={$eventType} | id={$eventId} | data_keys=" . implode(',', array_keys($eventData)));

try {
    $reference    = $eventData['reference'] ?? $eventData['id'] ?? '';
    $apiStatus    = $eventData['status'] ?? '';
    $paymentId    = $eventData['metadata']['payment_id'] ?? '';
    $failureReason = $eventData['failure_reason'] ?? null;

    // Map status
    $newStatus = match($apiStatus) {
        'completed', 'successful' => 'completed',
        'failed', 'voided', 'expired' => 'failed',
        default => 'pending',
    };

    webhookLog("MAPPED | ref={$reference} | api_status={$apiStatus} → new_status={$newStatus} | payment_id_meta={$paymentId}");

    // Find payment by snippe_reference OR metadata.payment_id OR order_id
    $payment = null;
    
    if (!empty($reference)) {
        $payment = Database::fetchOne(
            "SELECT * FROM payments WHERE snippe_reference = ? LIMIT 1",
            [$reference]
        );
        webhookLog("LOOKUP snippe_reference={$reference} → " . ($payment ? "FOUND id={$payment['id']}" : "NOT FOUND"));
    }
    
    if (!$payment && !empty($paymentId)) {
        $payment = Database::fetchOne(
            "SELECT * FROM payments WHERE id = ? LIMIT 1",
            [(int)$paymentId]
        );
        webhookLog("LOOKUP id={$paymentId} → " . ($payment ? "FOUND id={$payment['id']}" : "NOT FOUND"));
    }

    // Fallback: match by order_id stored in metadata
    if (!$payment) {
        $orderId = $eventData['metadata']['order_id'] ?? $eventData['orderId'] ?? $eventData['metadata']['payment_id'] ?? '';
        if (!empty($orderId)) {
            $payment = Database::fetchOne(
                "SELECT * FROM payments WHERE order_id = ? LIMIT 1",
                [$orderId]
            );
            webhookLog("LOOKUP order_id={$orderId} → " . ($payment ? "FOUND id={$payment['id']}" : "NOT FOUND"));
        }
    }

    // Last resort: match pending payment by user_id from metadata
    if (!$payment) {
        $userId = $eventData['metadata']['user_id'] ?? '';
        if (!empty($userId)) {
            $payment = Database::fetchOne(
                "SELECT * FROM payments WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
                [(int)$userId]
            );
            webhookLog("LOOKUP user_id={$userId} (pending) → " . ($payment ? "FOUND id={$payment['id']}" : "NOT FOUND"));
        }
    }

    if (!$payment) {
        webhookLog("ERROR: No payment found for ref={$reference} payment_id={$paymentId}");
        exit;
    }

    // Log current state
    webhookLog("CURRENT | db_id={$payment['id']} | db_status={$payment['status']} | webhook_received={$payment['webhook_received']} | user_id={$payment['user_id']}");

    // Skip if already processed
    if ($payment['webhook_received'] == 1 && $payment['status'] === $newStatus) {
        webhookLog("SKIP: Already processed (status={$newStatus})");
        exit;
    }

    // Update payment status
    Database::update('payments', [
        'status'           => $newStatus,
        'snippe_reference' => $reference ?: $payment['snippe_reference'],
        'webhook_received' => 1,
        'metadata'         => json_encode($eventData),
        'completed_at'     => $newStatus === 'completed' ? date('Y-m-d H:i:s') : null,
    ], 'id = ?', [$payment['id']]);

    webhookLog("UPDATED | id={$payment['id']} → status={$newStatus}");

    // Activate account if completed
    if ($newStatus === 'completed' && $payment['status'] !== 'completed') {
        webhookLog("ACTIVATING user_id={$payment['user_id']}");
        
        Database::beginTransaction();
        try {
            $user = Database::fetchOne("SELECT status FROM users WHERE id = ?", [$payment['user_id']]);
            
            if ($user && $user['status'] !== 'active') {
                Database::update('users', ['status' => 'active'], 'id = ?', [$payment['user_id']]);
                CommissionEngine::processRegistrationCommissions($payment['user_id']);
                Auth::logActivity($payment['user_id'], 'account_activated', 'Account activated via webhook');
                webhookLog("ACTIVATED user_id={$payment['user_id']} — account now active + commissions processed");
            } elseif ($user) {
                webhookLog("ALREADY ACTIVE user_id={$payment['user_id']}");
            } else {
                webhookLog("ERROR: User not found user_id={$payment['user_id']}");
            }
            
            Database::commit();
        } catch (Exception $e) {
            Database::rollback();
            webhookLog("ACTIVATION ERROR: " . $e->getMessage());
        }
    } elseif ($newStatus === 'failed') {
        webhookLog("PAYMENT FAILED id={$payment['id']} reason={$failureReason}");
    }

} catch (Exception $e) {
    webhookLog("EXCEPTION: " . $e->getMessage() . ' | ' . $e->getTraceAsString());
}

webhookLog("DONE");
