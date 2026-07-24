<?php
/**
 * EarnSphere - Snippe Payment Gateway
 * 
 * Handles both:
 * 1. Mobile Money Collection (Registration payments)
 * 2. Mobile Money Disbursement (Commission payouts)
 * 
 * API Docs: https://docs.snippe.sh
 * API Version: 2026-01-25
 */

require_once __DIR__ . '/../config/snippe.php';

class SnippePayment {
    
    private array $config;
    
    public function __construct() {
        $this->config = getSnippeConfig();
    }
    
    // ================================================================
    // COLLECTION - Registration Payment (POST /v1/payments)
    // ================================================================
    
    /**
     * Initiate a registration payment via Snippe Mobile Money Collection.
     *
     * @param int    $userId  User making payment
     * @param string $phone   Phone number for mobile money
     * @param float  $amount  Amount in TZS (default: registration fee)
     * @return array          ['success' => bool, ...]
     */
    public function initiatePayment(int $userId, string $phone, float $amount = 0): array {
        $phone = $this->normalizePhone($phone);
        
        $amount = $amount ?: app_setting('registration_fee', REGISTRATION_FEE);
        
        $user = Database::fetchOne("SELECT id, full_name, email, phone FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        $orderId = 'ES-' . $userId . '-' . time();
        
        $nameParts = explode(' ', $user['full_name'], 2);
        $firstName = $nameParts[0] ?? '';
        $lastName  = $nameParts[1] ?? '';
        
        $idempotencyKey = generateIdempotencyKey('pay');
        
        $paymentId = Database::insert('payments', [
            'user_id'          => $userId,
            'order_id'         => $orderId,
            'amount'           => $amount,
            'currency'         => CURRENCY,
            'payment_type'     => 'registration',
            'phone'            => $phone,
            'payment_method'   => 'mobile_money',
            'status'           => 'pending',
        ]);
        
        $payload = [
            'payment_type' => 'mobile',
            'details'      => [
                'amount'   => (int) $amount,
                'currency' => CURRENCY,
            ],
            'phone_number' => $phone,
            'customer'     => [
                'firstname' => $firstName,
                'lastname'  => $lastName,
                'email'     => $user['email'] ?? '',
            ],
            'metadata'     => [
                'user_id'     => (string) $userId,
                'payment_id'  => (string) $paymentId,
                'payment_type'=> 'registration',
            ],
            'webhook_url'  => SITE_URL . '/webhooks/snippe.php',
        ];
        
        $response = $this->makeRequest('POST', '/v1/payments', $payload, $idempotencyKey);
        
        error_log("Snippe Collection Response: " . json_encode($response));
        error_log("Snippe Collection Payload: " . json_encode($payload));
        
        if ($response['success']) {
            $data = $response['data'];
            $snippeRef = $data['reference'] ?? $data['id'] ?? null;
            
            Database::update('payments', [
                'snippe_reference' => $snippeRef,
            ], 'id = ?', [$paymentId]);
            
            // Snippe automatically sends USSD push when payment is created — no need to call /push
            
            Auth::logActivity($userId, 'payment_initiated', "Payment TZS " . number_format($amount) . " initiated via Snippe");
            
            return [
                'success'    => true,
                'payment_id' => $paymentId,
                'order_id'   => $orderId,
                'reference'  => $snippeRef,
                'data'       => $data,
            ];
        }
        
        return [
            'success' => false,
            'error'   => $response['error'] ?? 'Payment could not be initiated',
        ];
    }
    
    /**
     * Verify a collection payment status by reference.
     * GET /v1/payments/{reference}
     */
    public function verifyPayment(string $reference): array {
        $response = $this->makeRequest('GET', "/v1/payments/{$reference}");
        
        if ($response['success']) {
            $data   = $response['data'];
            $status = $data['status'] ?? 'pending';
            
            $mappedStatus = match($status) {
                'completed', 'successful' => 'completed',
                'failed', 'voided', 'expired' => 'failed',
                default => 'pending',
            };
            
            return [
                'success' => true,
                'status'  => $mappedStatus,
                'data'    => $data,
            ];
        }
        
        return [
            'success' => false,
            'error'   => $response['error'] ?? 'Failed to verify payment',
        ];
    }
    
    // ================================================================
    // DISBURSEMENT - Payout (POST /v1/payouts/send)
    // ================================================================
    
    /**
     * Send a mobile money payout via Snippe Disbursement.
     */
    public function sendPayout(int $withdrawalId, int $userId, float $amount, string $phone, string $name): array {
        $phone = $this->normalizePhone($phone);
        
        if ($amount < app_setting('min_withdrawal', MIN_WITHDRAWAL)) {
            return ['success' => false, 'error' => 'Minimum payout amount is TZS ' . number_format(app_setting('min_withdrawal', MIN_WITHDRAWAL))];
        }
        
        $withdrawal = Database::fetchOne(
            "SELECT * FROM withdrawals WHERE id = ? AND user_id = ?",
            [$withdrawalId, $userId]
        );
        if (!$withdrawal) {
            return ['success' => false, 'error' => 'Withdrawal request not found'];
        }
        if (!in_array($withdrawal['status'], ['approved', 'failed'])) {
            return ['success' => false, 'error' => 'This request cannot be retried (status: ' . $withdrawal['status'] . ')'];
        }
        
        $existingPayout = Database::fetchOne(
            "SELECT id FROM payouts WHERE withdrawal_id = ? AND status IN ('pending', 'completed')",
            [$withdrawalId]
        );
        if ($existingPayout) {
            return ['success' => false, 'error' => 'Payout already sent for this request'];
        }
        
        $idempotencyKey = generateIdempotencyKey('payout');
        $narration = 'EarnSphere - Your commission payout';
        
        $payload = [
            'amount'          => (int) $amount,
            'channel'         => $this->config['payout_channel'],
            'recipient_phone' => $phone,
            'recipient_name'  => $name,
            'narration'       => $narration,
            'webhook_url'     => $this->config['payout_webhook'],
            'metadata'        => [
                'user_id'       => (string) $userId,
                'withdrawal_id' => (string) $withdrawalId,
            ],
        ];
        
        $payoutId = Database::insert('payouts', [
            'user_id'           => $userId,
            'withdrawal_id'     => $withdrawalId,
            'amount'            => $amount,
            'total'             => $amount,
            'channel'           => $this->config['payout_channel'],
            'recipient_phone'   => $phone,
            'recipient_name'    => $name,
            'narration'         => $narration,
            'status'            => 'pending',
            'idempotency_key'   => $idempotencyKey,
        ]);
        
        Database::update('withdrawals', [
            'status'   => 'processing',
            'provider' => $this->config['payout_channel'],
        ], 'id = ?', [$withdrawalId]);
        
        $response = $this->makeRequest('POST', '/v1/payouts/send', $payload, $idempotencyKey);
        
        if ($response['success']) {
            $data = $response['data'];
            
            $payoutRef     = $data['reference'] ?? null;
            $externalRef   = $data['external_reference'] ?? null;
            $fees          = $this->extractObjectValue($data['fees'] ?? 0);
            $totalDeducted = $this->extractObjectValue($data['total'] ?? ($amount + $fees));
            $provider      = $data['channel']['provider'] ?? $this->config['payout_channel'];
            $payoutStatus  = $data['status'] ?? '';
            $errorMsg      = $data['error_message'] ?? $data['message'] ?? ($data['error'] ?? null);
            
            $validStatuses = ['completed', 'successful', 'pending', 'processing', 'failed'];
            if (!in_array($payoutStatus, $validStatuses) || $errorMsg) {
                $this->handlePayoutFailure($payoutId, $withdrawalId, $userId, $amount, $errorMsg ?: 'Payout API returned invalid status: ' . $payoutStatus);
                
                Database::update('payouts', [
                    'status'       => 'failed',
                    'error_message' => $errorMsg ?: 'Invalid API response',
                    'metadata'     => json_encode($data),
                ], 'id = ?', [$payoutId]);
                
                return [
                    'success' => false,
                    'error'   => $errorMsg ?: 'Payout failed: invalid API response',
                ];
            }

            if (in_array($payoutStatus, ['completed', 'successful'])) {
                $payoutStatus = 'completed';
            } elseif ($payoutStatus === 'failed') {
                $payoutStatus = 'failed';
            } else {
                $payoutStatus = 'pending';
            }
            
            Database::update('payouts', [
                'reference'          => $payoutRef,
                'external_reference' => $externalRef,
                'fees'               => $fees,
                'total'              => $totalDeducted,
                'provider'           => $provider,
                'status'             => $payoutStatus,
                'metadata'           => json_encode($data),
            ], 'id = ?', [$payoutId]);
            
            Database::update('withdrawals', [
                'snippe_reference'   => $payoutRef,
                'external_reference' => $externalRef,
                'fees'               => $fees,
            ], 'id = ?', [$withdrawalId]);
            
            if ($payoutStatus === 'completed') {
                $this->finalizePayoutSuccess($payoutId, $withdrawalId, $userId, $amount);
            } elseif ($payoutStatus === 'failed') {
                $this->handlePayoutFailure($payoutId, $withdrawalId, $userId, $amount, $errorMsg ?: 'Payout failed');
            }
            
            Auth::logActivity($userId, 'payout_sent', "Payout TZS " . number_format($amount) . " sent via Snippe");
            
            return [
                'success'   => true,
                'payout_id' => $payoutId,
                'reference' => $payoutRef,
                'fees'      => $fees,
                'total'     => $totalDeducted,
                'provider'  => $provider,
                'status'    => $payoutStatus,
                'data'      => $data,
            ];
        }
        
        $this->handlePayoutFailure($payoutId, $withdrawalId, $userId, $amount, $response['error'] ?? 'Payout failed');
        
        return [
            'success' => false,
            'error'   => $response['error'] ?? 'Payout failed',
        ];
    }
    
    /**
     * Retry USSD push for a pending payment.
     * POST /v1/payments/{reference}/push
     */
    public function retryPush(string $reference, string $phone = ''): array {
        $phone = $this->normalizePhone($phone);
        
        $payload = [];
        if (!empty($phone)) {
            $payload['phone_number'] = $phone;
        }
        
        $response = $this->makeRequest('POST', "/v1/payments/{$reference}/push", $payload);
        
        error_log("Snippe Retry Push Response: " . json_encode($response));
        
        return $response;
    }
    
    /**
     * Verify payout status by reference.
     * GET /v1/payouts/{reference}
     */
    public function verifyPayout(string $reference): array {
        $response = $this->makeRequest('GET', "/v1/payouts/{$reference}");
        
        if ($response['success']) {
            $data   = $response['data'];
            $status = $data['status'] ?? 'pending';
            
            if (in_array($status, ['completed', 'successful'])) {
                $status = 'completed';
            } elseif ($status === 'failed') {
                $status = 'failed';
            } else {
                $status = 'pending';
            }
            
            return [
                'success' => true,
                'status'  => $status,
                'data'    => $data,
            ];
        }
        
        return [
            'success' => false,
            'error'   => $response['error'] ?? 'Failed to verify payout',
        ];
    }
    
    /**
     * Calculate payout fee before sending.
     * GET /v1/payouts/fee?amount={amount}
     */
    public function calculatePayoutFee(float $amount): array {
        $response = $this->makeRequest('GET', "/v1/payouts/fee?amount=" . (int) $amount);
        
        if ($response['success']) {
            $data = $response['data'];
            return [
                'success'      => true,
                'amount'       => (float) ($data['amount'] ?? $amount),
                'fee'          => (float) ($data['fee_amount'] ?? 0),
                'total'        => (float) ($data['total_amount'] ?? ($amount + ($data['fee_amount'] ?? 0))),
                'currency'     => $data['currency'] ?? 'TZS',
            ];
        }
        
        return [
            'success' => false,
            'error'   => $response['error'] ?? 'Failed to calculate fee',
        ];
    }
    
    // ================================================================
    // WEBHOOK PROCESSING
    // ================================================================
    
    /**
     * Process incoming webhook from Snippe (collection OR payout).
     * 
     * Now accepts raw body for correct HMAC signature verification.
     * Supports both API versions: 2026-01-25 (envelope) and 2026-01-01 (legacy flat).
     *
     * @param array  $headers  HTTP headers from webhook request
     * @param array  $body     Decoded JSON body
     * @param string $rawBody  Raw request body string (for HMAC verification)
     * @return array           ['success' => bool, ...]
     */
    public function processWebhook(array $headers, array $body, string $rawBody = ''): array {
        // 1. Verify webhook HMAC signature using raw body
        if (!$this->verifyWebhookSignature($headers, $rawBody)) {
            error_log("EarnSphere: Webhook HMAC verification failed");
            return ['success' => false, 'error' => 'Invalid webhook signature'];
        }
        
        // 2. Determine event type — supports both API versions
        // New format (2026-01-25): $body['type'] = "payment.completed"
        // Legacy format (2026-01-01): $body['event'] = "payment.completed"
        $event = $body['type'] ?? $body['event'] ?? '';
        
        // 3. Log webhook event
        $logEntry = [
            'timestamp'  => date('Y-m-d H:i:s'),
            'event'      => $event,
            'api_version'=> $body['api_version'] ?? 'unknown',
            'body_keys'  => array_keys($body),
        ];
        $logDir = dirname(__DIR__) . '/logs';
        if (is_dir($logDir)) {
            file_put_contents($logDir . '/webhook.log', json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
        }
        
        // 4. Dispatch to appropriate handler
        if ($this->isPayoutEvent($event)) {
            return $this->processPayoutWebhook($body);
        }
        
        // Default: process as collection payment
        return $this->processCollectionWebhook($body);
    }
    
    /**
     * Process a collection (registration payment) webhook.
     * Handles both new envelope format and legacy flat format.
     */
    private function processCollectionWebhook(array $body): array {
        $paymentData = $body['data'] ?? $body['payment'] ?? $body;
        $reference   = $paymentData['reference'] ?? $paymentData['id'] ?? '';
        $status      = $paymentData['status'] ?? '';
        $paymentId   = $paymentData['metadata']['payment_id'] ?? '';
        $orderId     = $paymentData['metadata']['order_id'] ?? $paymentData['orderId'] ?? '';
        $failureReason = $paymentData['failure_reason'] ?? null;
        
        $mappedStatus = match($status) {
            'completed', 'successful' => 'completed',
            'failed', 'voided', 'expired' => 'failed',
            default => 'pending',
        };
        
        // Lookup: snippe_reference → DB id → order_id → pending payment by user_id
        $paymentRecord = null;
        
        if (!empty($reference)) {
            $paymentRecord = Database::fetchOne(
                "SELECT * FROM payments WHERE snippe_reference = ? LIMIT 1",
                [$reference]
            );
        }
        
        if (!$paymentRecord && !empty($paymentId)) {
            $paymentRecord = Database::fetchOne(
                "SELECT * FROM payments WHERE id = ? LIMIT 1",
                [(int)$paymentId]
            );
        }
        
        if (!$paymentRecord && !empty($orderId)) {
            $paymentRecord = Database::fetchOne(
                "SELECT * FROM payments WHERE order_id = ? LIMIT 1",
                [$orderId]
            );
        }
        
        if (!$paymentRecord) {
            $userId = $paymentData['metadata']['user_id'] ?? '';
            if (!empty($userId)) {
                $paymentRecord = Database::fetchOne(
                    "SELECT * FROM payments WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
                    [(int)$userId]
                );
            }
        }
        
        if (!$paymentRecord) {
            error_log("EarnSphere: Collection webhook - payment not found for ref={$reference} pid={$paymentId} order={$orderId}");
            return ['success' => false, 'error' => 'Payment record not found'];
        }
        
        if ($paymentRecord['webhook_received'] == 1 && $paymentRecord['status'] === $mappedStatus) {
            return ['success' => true, 'status' => $mappedStatus, 'duplicate' => true];
        }
        
        $updateData = [
            'status'           => $mappedStatus,
            'snippe_reference' => $reference ?: $paymentRecord['snippe_reference'],
            'webhook_received' => 1,
            'metadata'         => json_encode($paymentData),
            'completed_at'     => $mappedStatus === 'completed' ? date('Y-m-d H:i:s') : null,
        ];
        
        if ($failureReason && $mappedStatus === 'failed') {
            $existingMeta = json_decode($paymentRecord['metadata'] ?? '{}', true) ?: [];
            $existingMeta['failure_reason'] = $failureReason;
            $updateData['metadata'] = json_encode($existingMeta);
        }
        
        Database::update('payments', $updateData, 'id = ?', [$paymentRecord['id']]);
        
        if ($mappedStatus === 'completed' && $paymentRecord['status'] !== 'completed') {
            $this->activateUserAccount($paymentRecord['user_id']);
        }
        
        return [
            'success'    => true,
            'type'       => 'collection',
            'status'     => $mappedStatus,
            'payment_id' => $paymentRecord['id'],
            'user_id'    => $paymentRecord['user_id'],
        ];
    }
    
    /**
     * Process a payout (disbursement) webhook.
     * Handles both new envelope format and legacy flat format.
     */
    private function processPayoutWebhook(array $body): array {
        $payoutData   = $body['data'] ?? $body['payout'] ?? $body;
        $reference    = $payoutData['reference'] ?? $payoutData['id'] ?? '';
        $status       = $payoutData['status'] ?? '';
        $withdrawalId = $payoutData['metadata']['withdrawal_id'] ?? $payoutData['metadata']['withdrawalId'] ?? '';
        $userId       = $payoutData['metadata']['user_id'] ?? $payoutData['metadata']['userId'] ?? '';
        $errorCode    = $payoutData['error_code'] ?? $payoutData['error'] ?? null;
        $errorMsg     = $payoutData['error_message'] ?? $payoutData['message'] ?? null;
        $provider     = $payoutData['channel']['provider'] ?? $payoutData['provider'] ?? null;
        
        // fees and total are objects in new format: {"value": 1000, "currency": "TZS"}
        $fees  = $this->extractObjectValue($payoutData['fees'] ?? 0);
        $total = $this->extractObjectValue($payoutData['total'] ?? 0);
        
        $payoutRecord = Database::fetchOne(
            "SELECT * FROM payouts WHERE reference = ? OR (withdrawal_id = ? AND status = 'pending')",
            [$reference, $withdrawalId]
        );
        
        if (!$payoutRecord) {
            error_log("EarnSphere: Payout webhook - payout not found for ref={$reference} wd_id={$withdrawalId}");
            return ['success' => false, 'error' => 'Payout record not found'];
        }
        
        if ($payoutRecord['webhook_received'] == 1 && $payoutRecord['status'] === $status) {
            return ['success' => true, 'status' => $status, 'duplicate' => true];
        }
        
        // Map status — includes 'reversed' which should restore wallet like 'failed'
        $mappedStatus = match($status) {
            'completed', 'successful' => 'completed',
            'failed', 'reversed' => 'failed',
            default     => 'pending',
        };
        
        Database::update('payouts', [
            'status'          => $mappedStatus,
            'webhook_received'=> 1,
            'fees'            => $fees > 0 ? $fees : $payoutRecord['fees'],
            'total'           => $total > 0 ? $total : $payoutRecord['total'],
            'provider'        => $provider ?: $payoutRecord['provider'],
            'error_code'      => $errorCode,
            'error_message'   => $errorMsg,
            'metadata'        => json_encode($payoutData),
        ], 'id = ?', [$payoutRecord['id']]);
        
        if ($mappedStatus === 'completed' && $payoutRecord['status'] !== 'completed') {
            $this->finalizePayoutSuccess(
                $payoutRecord['id'],
                $payoutRecord['withdrawal_id'],
                $payoutRecord['user_id'],
                $payoutRecord['amount']
            );
        } elseif ($mappedStatus === 'failed' && $payoutRecord['status'] !== 'failed') {
            $reason = $errorMsg ?: ($status === 'reversed' ? 'Payout reversed' : 'Payout failed');
            $this->handlePayoutFailure(
                $payoutRecord['id'],
                $payoutRecord['withdrawal_id'],
                $payoutRecord['user_id'],
                $payoutRecord['amount'],
                $reason
            );
        }
        
        return [
            'success'   => true,
            'type'      => 'payout',
            'status'    => $mappedStatus,
            'payout_id' => $payoutRecord['id'],
            'user_id'   => $payoutRecord['user_id'],
        ];
    }
    
    // ================================================================
    // INTERNAL HELPERS
    // ================================================================
    
    /**
     * Activate user account + trigger commission engine after payment.
     */
    private function activateUserAccount(int $userId): void {
        Database::beginTransaction();
        try {
            $user = Database::fetchOne("SELECT status FROM users WHERE id = ?", [$userId]);
            if ($user && $user['status'] === 'active') {
                Database::rollback();
                return;
            }
            
            Database::update('users', ['status' => 'active'], 'id = ?', [$userId]);
            CommissionEngine::processRegistrationCommissions($userId);
            Auth::logActivity($userId, 'account_activated', 'Account activated after Snippe payment');
            
            Database::commit();
        } catch (Exception $e) {
            Database::rollback();
            error_log("EarnSphere: Activation error for user {$userId}: " . $e->getMessage());
        }
    }
    
    /**
     * Finalize payout success: mark withdrawal completed.
     */
    private function finalizePayoutSuccess(int $payoutId, int $withdrawalId, int $userId, float $amount): void {
        Database::beginTransaction();
        try {
            Database::update('withdrawals', [
                'status'       => 'completed',
                'processed_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$withdrawalId]);
            
            Auth::logActivity($userId, 'payout_completed', "Payout TZS " . number_format($amount) . " completed");
            
            Database::commit();
        } catch (Exception $e) {
            Database::rollback();
            error_log("EarnSphere: Payout finalize error: " . $e->getMessage());
        }
    }
    
    /**
     * Handle payout failure/reversal: restore wallet balance + mark withdrawal failed.
     */
    public function handlePayoutFailure(int $payoutId, int $withdrawalId, int $userId, float $amount, string $reason): void {
        Database::beginTransaction();
        try {
            Database::update('payouts', [
                'status'        => 'failed',
                'error_message' => $reason,
            ], 'id = ?', [$payoutId]);
            
            $previousFailed = Database::count(
                'payouts',
                'withdrawal_id = ? AND id != ? AND status = ?',
                [$withdrawalId, $payoutId, 'failed']
            );
            
            if ($previousFailed === 0) {
                $wallet = Database::fetchOne("SELECT * FROM wallets WHERE user_id = ?", [$userId]);
                if ($wallet) {
                    $balanceBefore = (float) $wallet['balance'];
                    $balanceAfter  = $balanceBefore + $amount;
                    $withdrawableBefore = (float) ($wallet['withdrawable_balance'] ?? 0);
                    $withdrawableAfter = $withdrawableBefore + $amount;
                    
                    Database::update('wallets', [
                        'balance'              => $balanceAfter,
                        'withdrawable_balance' => $withdrawableAfter,
                        'total_withdrawn'      => max(0, (float)$wallet['total_withdrawn'] - $amount),
                    ], 'id = ?', [$wallet['id']]);
                    
                    Database::insert('wallet_transactions', [
                        'wallet_id'      => $wallet['id'],
                        'user_id'        => $userId,
                        'type'           => 'admin_adjustment',
                        'amount'         => $amount,
                        'balance_before' => $balanceBefore,
                        'balance_after'  => $balanceAfter,
                        'description'    => "Payout failed/reversed: {$reason}",
                        'reference_id'   => $payoutId,
                        'reference_type' => 'payout',
                        'status'         => 'completed',
                    ]);
                }
            }
            
            Database::update('withdrawals', [
                'status'     => 'approved',
                'admin_note' => "Payout failed: {$reason}",
            ], 'id = ?', [$withdrawalId]);
            
            Auth::logActivity($userId, 'payout_failed', "Payout TZS " . number_format($amount) . " failed: {$reason}");
            
            Database::commit();
        } catch (Exception $e) {
            Database::rollback();
            error_log("EarnSphere: Payout failure handling error: " . $e->getMessage());
        }
    }
    
    /**
     * Determine if a webhook event is a payout event.
     */
    private function isPayoutEvent(string $event): bool {
        $event = strtolower($event);
        $payoutEvents = ['payout.completed', 'payout.failed', 'payout.reversed', 'payout.pending',
                         'disbursement.completed', 'disbursement.failed'];
        return in_array($event, $payoutEvents) || str_starts_with($event, 'payout');
    }
    
    /**
     * Extract value from Snippe object format.
     * New API returns amounts as objects: {"value": 5000, "currency": "TZS"}
     * Legacy API returns plain numbers: 5000
     * This helper handles both.
     */
    private function extractObjectValue(mixed $input): float {
        if (is_array($input)) {
            return (float) ($input['value'] ?? 0);
        }
        return (float) $input;
    }
    
    /**
     * Make HTTP request to Snippe API.
     * Supports GET, POST, PUT with idempotency key.
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], ?string $idempotencyKey = null): array {
        $url = rtrim($this->config['api_url'], '/') . $endpoint;
        
        $headers = [
            'Authorization: Bearer ' . $this->config['api_key'],
            'Content-Type: application/json',
        ];
        
        if (!empty($this->config['api_version'])) {
            $headers[] = 'X-Api-Version: ' . $this->config['api_version'];
        }
        
        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'GET':
            default:
                break;
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        if ($curlErr) {
            error_log("Snippe API cURL error [{$method} {$endpoint}]: {$curlErr} (errno: {$curlErrno})");
            return ['success' => false, 'error' => "Payment service unavailable: {$curlErr}"];
        }
        
        $decoded = json_decode($response, true);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => $decoded];
        }
        
        $errorMsg = $decoded['message'] ?? $decoded['error'] ?? $decoded['errors'][0] ?? "API returned HTTP {$httpCode}";
        $errorCode = $decoded['error_code'] ?? null;
        
        error_log("Snippe API error [{$method} {$endpoint}] HTTP {$httpCode}: {$response}");
        
        return [
            'success'    => false,
            'error'      => $errorMsg,
            'error_code' => $errorCode,
            'http_code'  => $httpCode,
        ];
    }
    
    /**
     * Verify webhook HMAC-SHA256 signature.
     * 
     * CRITICAL: Uses raw body string, NOT json_encode($body).
     * Snippe signs: HMAC-SHA256(webhook_secret, "{timestamp}.{raw_body}")
     * Headers: X-Webhook-Timestamp, X-Webhook-Signature
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): bool {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = $value;
        }
        
        $timestamp = $normalized['x-webhook-timestamp'] ?? '';
        $signature = $normalized['x-webhook-signature'] ?? '';
        
        if (empty($this->config['webhook_secret'])) {
            error_log("EarnSphere: Webhook HMAC skipped - no secret configured");
            return true;
        }
        
        if (empty($timestamp) || empty($signature)) {
            error_log("EarnSphere: Webhook rejected - missing timestamp or signature");
            return false;
        }
        
        $webhookTime = (int) $timestamp;
        $currentTime = time();
        if (abs($currentTime - $webhookTime) > 300) {
            error_log("EarnSphere: Webhook rejected - stale timestamp (diff=" . abs($currentTime - $webhookTime) . "s)");
            return false;
        }
        
        // Use raw body exactly as received — NOT json_encode($body)
        $payloadString = $timestamp . '.' . $rawBody;
        $expected = hash_hmac('sha256', $payloadString, $this->config['webhook_secret']);
        
        return hash_equals($expected, $signature);
    }
    
    /**
     * Normalize phone number to 255XXXXXXXXX format.
     * 
     * Handles:
     *   0616123456    → 255616123456  (Halotel 10-digit)
     *   0712345678    → 255712345678  (Vodacom/Airtel/Tigo)
     *   +255712345678 → 255712345678
     *   255712345678  → 255712345678
     *   616123456     → 255616123456  (9-digit without leading 0)
     */
    public function normalizePhone(string $phone): string {
        $phone = preg_replace('/[\s\-()]/', '', trim($phone));
        
        // +255XXXXXXXXX → 255XXXXXXXXX
        if (strpos($phone, '+') === 0) {
            $phone = substr($phone, 1);
        }
        
        // 0XXXXXXXXX (10 digits) → 255XXXXXXXXX
        if (strpos($phone, '0') === 0 && strlen($phone) === 10) {
            return '255' . substr($phone, 1);
        }
        
        // 9-digit number without leading 0 (e.g. 616123456 for Halotel)
        if (strlen($phone) === 9 && strpos($phone, '0') !== 0) {
            return '255' . $phone;
        }
        
        // Already 255XXXXXXXXX or raw number
        return $phone;
    }
}
