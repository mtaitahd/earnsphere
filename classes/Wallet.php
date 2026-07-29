<?php
/**
 * EarnSphere - Wallet System
 * Manages user wallets, balance, transactions, and withdrawals
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

class Wallet {
    
    /**
     * Get or create wallet for a user
     */
    public static function getWallet(int $userId): array {
        $wallet = Database::fetchOne(
            "SELECT * FROM wallets WHERE user_id = ?",
            [$userId]
        );
        
        if (!$wallet) {
            $walletId = Database::insert('wallets', [
                'user_id' => $userId,
                'balance' => 0.00,
            ]);
            $wallet = Database::fetchOne("SELECT * FROM wallets WHERE id = ?", [$walletId]);
        }
        
        return $wallet;
    }
    
    /**
     * Credit user wallet (add money)
     * Only 'commission' type credits are withdrawable.
     * Registration fees and admin adjustments are NOT withdrawable.
     */
    public static function credit(
        int $userId,
        float $amount,
        string $type,
        string $description = '',
        ?int $referenceId = null,
        ?string $referenceType = null
    ): int {
        $wallet = self::getWallet($userId);
        $balanceBefore = (float) $wallet['balance'];
        $balanceAfter = $balanceBefore + $amount;
        
        // Only commissions are withdrawable
        $isCommission = ($type === 'commission');
        $withdrawableBefore = (float) ($wallet['withdrawable_balance'] ?? 0);
        $withdrawableAfter = $isCommission ? $withdrawableBefore + $amount : $withdrawableBefore;
        
        Database::beginTransaction();
        
        try {
            $updateData = [
                'balance'      => $balanceAfter,
                'total_earned' => $wallet['total_earned'] + $amount,
            ];
            if ($isCommission) {
                $updateData['withdrawable_balance'] = $withdrawableAfter;
            }
            
            Database::update('wallets', $updateData, 'id = ?', [$wallet['id']]);
            
            // Record transaction
            $transactionId = Database::insert('wallet_transactions', [
                'wallet_id'      => $wallet['id'],
                'user_id'        => $userId,
                'type'           => $type,
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'description'    => $description,
                'reference_id'   => $referenceId,
                'reference_type' => $referenceType,
                'status'         => 'completed',
            ]);
            
            Database::commit();
            return $transactionId;
            
        } catch (Exception $e) {
            Database::rollback();
            error_log("Wallet credit error: " . $e->getMessage());
            ErrorLogger::logException($e, 'wallet', $userId, 'Wallet::credit');
            throw $e;
        }
    }
    
    /**
     * Debit user wallet (subtract money)
     * For withdrawals, also deducts from withdrawable_balance
     */
    public static function debit(
        int $userId,
        float $amount,
        string $type,
        string $description = '',
        ?int $referenceId = null,
        ?string $referenceType = null,
        string $txStatus = 'completed'
    ): int {
        $wallet = self::getWallet($userId);
        $balanceBefore = (float) $wallet['balance'];
        
        if ($balanceBefore < $amount) {
            ErrorLogger::log('wallet', 'Wallet debit failed: insufficient balance', [
                'amount'          => $amount,
                'balance_before'  => $balanceBefore,
                'transaction_type'=> $type,
            ], $userId, 'warning', 'Wallet::debit');
            throw new Exception("Insufficient balance");
        }
        
        $balanceAfter = $balanceBefore - $amount;
        
        // For withdrawals, also reduce withdrawable_balance
        $isWithdrawal = ($type === 'withdrawal');
        $withdrawableBefore = (float) ($wallet['withdrawable_balance'] ?? 0);
        $withdrawableAfter = $isWithdrawal ? max(0, $withdrawableBefore - $amount) : $withdrawableBefore;
        
        Database::beginTransaction();
        
        try {
            $updateData = [
                'balance'         => $balanceAfter,
                'total_withdrawn' => $wallet['total_withdrawn'] + $amount,
            ];
            if ($isWithdrawal) {
                $updateData['withdrawable_balance'] = $withdrawableAfter;
            }
            
            Database::update('wallets', $updateData, 'id = ?', [$wallet['id']]);
            
            $transactionId = Database::insert('wallet_transactions', [
                'wallet_id'      => $wallet['id'],
                'user_id'        => $userId,
                'type'           => $type,
                'amount'         => -$amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'description'    => $description,
                'reference_id'   => $referenceId,
                'reference_type' => $referenceType,
                'status'         => $txStatus,
            ]);
            
            Database::commit();
            return $transactionId;
            
        } catch (Exception $e) {
            Database::rollback();
            error_log("Wallet debit error: " . $e->getMessage());
            ErrorLogger::logException($e, 'wallet', $userId, 'Wallet::debit');
            throw $e;
        }
    }
    
    /**
     * Get wallet balance
     */
    public static function getBalance(int $userId): float {
        $wallet = self::getWallet($userId);
        return (float) $wallet['balance'];
    }
    
    /**
     * Get transaction history with pagination
     */
    public static function getTransactions(int $userId, int $page = 1, int $perPage = 20): array {
        $offset = ($page - 1) * $perPage;
        
        $total = Database::count('wallet_transactions', 'user_id = ?', [$userId]);
        
        $transactions = Database::fetchAll(
            "SELECT * FROM wallet_transactions 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT ? OFFSET ?",
            [$userId, $perPage, $offset]
        );
        
        return [
            'transactions' => $transactions,
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'total_pages'  => ceil($total / $perPage),
        ];
    }
    
    /**
     * Request withdrawal - holds withdrawable balance, sends payout via Snippe
     * Balance is only deducted when payout succeeds (webhook confirms)
     */
    public static function requestWithdrawal(int $userId, float $amount, string $phone): array {
        $errors = [];
        
        if ($amount < app_setting('min_withdrawal', MIN_WITHDRAWAL)) {
            $errors[] = "Minimum amount is TZS " . number_format(app_setting('min_withdrawal', MIN_WITHDRAWAL));
        }
        
        if ($amount > app_setting('max_withdrawal', MAX_WITHDRAWAL)) {
            $errors[] = "Maximum amount is TZS " . number_format(app_setting('max_withdrawal', MAX_WITHDRAWAL));
        }
        
        $wallet = self::getWallet($userId);
        $withdrawable = (float) ($wallet['withdrawable_balance'] ?? 0);
        $pendingAmount = (float) ($wallet['pending_amount'] ?? 0);
        $available = $withdrawable - $pendingAmount;
        
        if ($amount > $available) {
            $errors[] = "Insufficient available balance. You have TZS " . number_format($available) . " available (earnings minus pending withdrawals)";
        }
        
        if (!empty($errors)) {
            ErrorLogger::log('withdrawal', 'Withdrawal request failed validation', [
                'amount' => $amount,
                'phone'  => $phone,
                'errors' => $errors,
            ], $userId, 'warning', 'Wallet::requestWithdrawal');
            return ['success' => false, 'errors' => $errors];
        }
        
        try {
            Database::beginTransaction();
            
            $balanceBefore = (float) $wallet['balance'];
            $balanceAfter = $balanceBefore;
            $withdrawableBefore = (float) $wallet['withdrawable_balance'];
            $withdrawableAfter = $withdrawableBefore - $amount;
            
            Database::update('wallets', [
                'withdrawable_balance' => $withdrawableAfter,
                'pending_amount'       => $pendingAmount + $amount,
            ], 'id = ?', [$wallet['id']]);
            
            $transactionId = Database::insert('wallet_transactions', [
                'wallet_id'      => $wallet['id'],
                'user_id'        => $userId,
                'type'           => 'withdrawal',
                'amount'         => -$amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'description'    => "Withdrawal request TZS " . number_format($amount),
                'status'         => 'pending',
            ]);
            
            $withdrawalId = Database::insert('withdrawals', [
                'user_id'        => $userId,
                'amount'         => $amount,
                'phone'          => $phone,
                'payment_method' => 'mobile_money',
                'status'         => 'pending',
            ]);
            
            Database::update('wallet_transactions', [
                'reference_id'   => $withdrawalId,
                'reference_type' => 'withdrawal',
            ], 'id = ?', [$transactionId]);
            
            Database::commit();
            
            Auth::logActivity($userId, 'withdrawal_requested', "Requested TZS " . number_format($amount));
            
            $user = Database::fetchOne("SELECT full_name, phone FROM users WHERE id = ?", [$userId]);
            $wname = $user['full_name'] ?? 'Unknown';
            $wphone = $user['phone'] ?? 'Unknown';
            @notifyAdmin("Withdrawal Request - TZS " . number_format($amount), 
                "New Withdrawal Request\n\nUser: {$wname}\nPhone: {$wphone}\nAmount: TZS " . number_format($amount) . "\nPayment: {$phone}");
            
            require_once __DIR__ . '/SnippePayment.php';
            $snippe = new SnippePayment();
            $payoutResult = $snippe->sendPayout($withdrawalId, $userId, $amount, $phone, $wname);
            
            if ($payoutResult['success']) {
                $payoutStatus = $payoutResult['status'] ?? 'pending';
                $fees = $payoutResult['fees'] ?? 0;
                return [
                    'success'       => true,
                    'withdrawal_id' => $withdrawalId,
                    'payout_status' => $payoutStatus,
                    'amount'        => $amount,
                    'phone'         => $phone,
                    'fees'          => $fees,
                    'reference'     => $payoutResult['reference'] ?? null,
                    'message'       => $payoutStatus === 'completed'
                        ? 'Money has been sent to your mobile money account.'
                        : 'Payout is being processed. You will receive the money shortly.',
                ];
            } else {
                $payoutStatus = $payoutResult['status'] ?? 'pending';
                $errorMsg = $payoutResult['error'] ?? 'Payment service is temporarily unavailable.';
                return [
                    'success'       => true,
                    'withdrawal_id' => $withdrawalId,
                    'payout_status' => $payoutStatus,
                    'amount'        => $amount,
                    'phone'         => $phone,
                    'fees'          => 0,
                    'reference'     => $payoutResult['reference'] ?? null,
                    'message'       => $errorMsg,
                ];
            }
            
        } catch (Exception $e) {
            error_log("Withdrawal error: " . $e->getMessage());
            ErrorLogger::logException($e, 'withdrawal', $userId, 'Wallet::requestWithdrawal');
            return ['success' => false, 'errors' => ['System error: ' . $e->getMessage()]];
        }
    }
    
    /**
     * Reverse a failed withdrawal - restore withdrawable balance only (balance was never debited)
     */
    private static function reverseWithdrawal(int $userId, int $withdrawalId, float $amount): void {
        try {
            $wallet = self::getWallet($userId);
            $withdrawableBefore = (float) ($wallet['withdrawable_balance'] ?? 0);
            $withdrawableAfter = $withdrawableBefore + $amount;
            
            Database::update('wallets', [
                'withdrawable_balance' => $withdrawableAfter,
                'pending_amount'       => max(0, (float)$wallet['pending_amount'] - $amount),
            ], 'id = ?', [$wallet['id']]);
            
            Database::update('withdrawals', [
                'status' => 'failed',
            ], 'id = ?', [$withdrawalId]);
            
            Database::update('wallet_transactions', [
                'status' => 'failed',
            ], 'user_id = ? AND reference_id = ? AND reference_type = ? AND status = ?', [
                $userId, $withdrawalId, 'withdrawal', 'pending'
            ]);
            
        } catch (Exception $e) {
            error_log("Reverse withdrawal error: " . $e->getMessage());
            ErrorLogger::logException($e, 'withdrawal', $userId, 'Wallet::reverseWithdrawal');
        }
    }
    
    /**
     * Admin: Process withdrawal (approve/reject)
     */
    public static function processWithdrawal(int $withdrawalId, string $status, ?string $adminNote = null, int $adminId = 0): array {
        $withdrawal = Database::fetchOne(
            "SELECT * FROM withdrawals WHERE id = ?",
            [$withdrawalId]
        );
        
        if (!$withdrawal) {
            ErrorLogger::log('withdrawal', 'Admin withdrawal processing failed: request not found', [
                'withdrawal_id' => $withdrawalId,
                'admin_id'      => $adminId,
            ], $adminId ?: null, 'warning', 'Wallet::processWithdrawal');
            return ['success' => false, 'errors' => ['Withdrawal request not found']];
        }
        
        if ($withdrawal['status'] !== 'pending') {
            ErrorLogger::log('withdrawal', 'Admin withdrawal processing failed: invalid status', [
                'withdrawal_id' => $withdrawalId,
                'current_status'=> $withdrawal['status'],
                'new_status'    => $status,
                'admin_id'      => $adminId,
            ], (int) $withdrawal['user_id'], 'warning', 'Wallet::processWithdrawal');
            return ['success' => false, 'errors' => ['This request has already been processed']];
        }
        
        try {
            Database::beginTransaction();
            
            Database::update('withdrawals', [
                'status'       => $status,
                'admin_note'   => $adminNote,
                'processed_by' => $adminId,
                'processed_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$withdrawalId]);
            
            // If rejected, restore withdrawable balance only (balance was never debited)
            if ($status === 'rejected') {
                $wallet = self::getWallet($withdrawal['user_id']);
                $withdrawableBefore = (float) ($wallet['withdrawable_balance'] ?? 0);
                $withdrawableAfter = $withdrawableBefore + $withdrawal['amount'];
                
                Database::update('wallets', [
                    'withdrawable_balance' => $withdrawableAfter,
                    'pending_amount'       => max(0, (float)$wallet['pending_amount'] - $withdrawal['amount']),
                ], 'id = ?', [$wallet['id']]);
                
                Database::update('wallet_transactions', [
                    'status' => 'rejected',
                ], 'user_id = ? AND reference_id = ? AND reference_type = ? AND status = ?', [
                    $withdrawal['user_id'], $withdrawalId, 'withdrawal', 'pending'
                ]);
            }
            
            Database::commit();
            
            Auth::logActivity($adminId, 'withdrawal_processed', "Withdrawal #{$withdrawalId} {$status}");
            
            return ['success' => true];
            
        } catch (Exception $e) {
            Database::rollback();
            error_log("Process withdrawal error: " . $e->getMessage());
            ErrorLogger::logException($e, 'withdrawal', (int) $withdrawal['user_id'], 'Wallet::processWithdrawal');
            return ['success' => false, 'errors' => ['System error']];
        }
    }
    
    /**
     * Auto-expire pending withdrawals older than 1 hour.
     * Called automatically when user visits dashboard/wallet/withdrawal page.
     */
    public static function autoExpirePending(int $userId): int {
        $stuck = Database::fetchAll(
            "SELECT w.id, w.user_id, w.amount, w.created_at
             FROM withdrawals w
             WHERE w.user_id = ? AND w.status IN ('pending', 'processing')
             AND w.created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
             ORDER BY w.created_at ASC
             LIMIT 10",
            [$userId]
        );

        $expired = 0;
        foreach ($stuck as $wd) {
            try {
                Database::beginTransaction();

                $payout = Database::fetchOne(
                    "SELECT id FROM payouts WHERE withdrawal_id = ? AND status IN ('pending', 'processing')",
                    [$wd['id']]
                );

                if ($payout) {
                    Database::update('payouts', [
                        'status'        => 'failed',
                        'error_message' => 'Auto-expired: pending for over 1 hour',
                    ], 'id = ?', [$payout['id']]);
                }

                Database::update('withdrawals', [
                    'status'     => 'failed',
                    'admin_note' => 'Auto-expired: pending for over 1 hour',
                ], 'id = ?', [$wd['id']]);

                $wallet = Database::fetchOne("SELECT * FROM wallets WHERE user_id = ?", [$userId]);
                if ($wallet) {
                    Database::update('wallets', [
                        'withdrawable_balance' => (float)($wallet['withdrawable_balance'] ?? 0) + $wd['amount'],
                        'pending_amount'       => max(0, (float)$wallet['pending_amount'] - $wd['amount']),
                    ], 'id = ?', [$wallet['id']]);
                }

                Database::update('wallet_transactions', [
                    'status' => 'failed',
                ], 'user_id = ? AND reference_id = ? AND reference_type = ? AND status = ?', [
                    $userId, $wd['id'], 'withdrawal', 'pending'
                ]);

                Database::commit();
                $expired++;

                ErrorLogger::log('withdrawal', "Auto-expired pending withdrawal #{$wd['id']}", [
                    'withdrawal_id' => $wd['id'],
                    'amount'        => $wd['amount'],
                    'created_at'    => $wd['created_at'],
                ], $userId, 'warning', 'Wallet::autoExpirePending');

            } catch (Exception $e) {
                Database::rollback();
                error_log("Auto-expire withdrawal error #{$wd['id']}: " . $e->getMessage());
                ErrorLogger::logException($e, 'withdrawal', $userId, 'Wallet::autoExpirePending');
            }
        }

        return $expired;
    }

    /**
     * Get all withdrawals for admin
     */
    public static function getWithdrawals(string $status = '', int $page = 1, int $perPage = 20): array {
        $offset = ($page - 1) * $perPage;
        $where = $status ? "WHERE w.status = ?" : "";
        $params = $status ? [$status, $perPage, $offset] : [$perPage, $offset];
        
        $total = Database::count('withdrawals', $status ? "status = ?" : "1=1", $status ? [$status] : []);
        
        $withdrawals = Database::fetchAll(
            "SELECT w.*, u.full_name, u.phone as user_phone 
             FROM withdrawals w 
             JOIN users u ON w.user_id = u.id 
             {$where}
             ORDER BY w.created_at DESC 
             LIMIT ? OFFSET ?",
            $params
        );
        
        return [
            'withdrawals'  => $withdrawals,
            'total'        => $total,
            'page'         => $page,
            'total_pages'  => ceil($total / $perPage),
        ];
    }
}
