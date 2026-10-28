<?php
/**
 * EarnSphere - Wallet System
 * Manages user wallets, balance, transactions, and withdrawals
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

class Wallet {
    
    private const EARNING_TYPES = ['commission','daily_mission_bonus','contest_bonus','social_reward'];

    public static function earningsProjection(string $userExpression): string {
        if ($userExpression!=='?' && !preg_match('/^[a-z_]+\.[a-z_]+$/iD',$userExpression)) throw new InvalidArgumentException('Invalid earnings user expression.');
        $types="'".implode("','",self::EARNING_TYPES)."'";
        return "(SELECT COALESCE(SUM(wt.amount),0) FROM wallet_transactions wt WHERE wt.user_id = $userExpression AND wt.status = 'completed' AND wt.type IN ($types))";
    }

    public static function earnedTotal(int $userId): float {
        $row=Database::fetchOne('SELECT '.self::earningsProjection('?').' AS earned',[$userId]);
        return (float)($row['earned']??0);
    }

    /**
     * Get or create wallet for a user
     */
    public static function getWallet(int $userId): array {
        $wallet = Database::fetchOne(
            "SELECT * FROM wallets WHERE user_id = ?" . (Database::inTransaction() ? ' FOR UPDATE' : ''),
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
     * Referral commissions and approved mission, contest and social rewards are withdrawable.
     * Registration fees and admin adjustments are NOT earnings or withdrawable.
     */
    public static function credit(
        int $userId,
        float $amount,
        string $type,
        string $description = '',
        ?int $referenceId = null,
        ?string $referenceType = null
    ): int {
        $isNested = Database::inTransaction();
        if (!$isNested) {
            Database::beginTransaction();
        }
        
        try {
            $wallet = self::getWallet($userId);
            $balanceBefore = (float) $wallet['balance'];
            $balanceAfter = $balanceBefore + $amount;
            $isWithdrawable = in_array($type, self::EARNING_TYPES, true);
            $withdrawableBefore = (float) ($wallet['withdrawable_balance'] ?? 0);
            $withdrawableAfter = $isWithdrawable ? $withdrawableBefore + $amount : $withdrawableBefore;
            $updateData = [
                'balance'      => $balanceAfter,
                'total_earned' => self::earnedTotal($userId) + ($isWithdrawable ? $amount : 0),
            ];
            if ($isWithdrawable) {
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
            
            if (!$isNested) {
                Database::commit();
            }
            return $transactionId;
            
        } catch (Exception $e) {
            if (!$isNested) {
                Database::rollback();
            }
            error_log("Wallet credit error: " . $e->getMessage());
            ErrorLogger::logException($e, 'wallet', $userId, 'Wallet::credit');
            throw $e;
        }
    }
    
    /**
     * Reverse a previous credit (e.g. admin marking a user as unpaid).
     * Removes a non-earning credit from balance WITHOUT touching
     * withdrawable_balance or total_withdrawn.
     * Used for reversing non-withdrawable credits like registration bonuses.
     */
    public static function reverseCredit(
        int $userId,
        float $amount,
        string $type,
        string $description = '',
        ?int $referenceId = null,
        ?string $referenceType = null
    ): int {
        $wallet = self::getWallet($userId);
        $balanceBefore = (float) $wallet['balance'];

        if ($balanceBefore < $amount) {
            ErrorLogger::log('wallet', 'Wallet credit reversal failed: insufficient balance', [
                'amount'          => $amount,
                'balance_before'  => $balanceBefore,
                'transaction_type'=> $type,
            ], $userId, 'warning', 'Wallet::reverseCredit');
            throw new Exception("Insufficient balance to reverse credit");
        }

        $balanceAfter = $balanceBefore - $amount;
        $totalEarnedAfter = self::earnedTotal($userId);

        $isNested = Database::inTransaction();
        if (!$isNested) {
            Database::beginTransaction();
        }

        try {
            Database::update('wallets', [
                'balance'      => $balanceAfter,
                'total_earned' => $totalEarnedAfter,
            ], 'id = ?', [$wallet['id']]);

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
                'status'         => 'completed',
            ]);

            if (!$isNested) {
                Database::commit();
            }
            return $transactionId;

        } catch (Exception $e) {
            if (!$isNested) {
                Database::rollback();
            }
            error_log("Wallet credit reversal error: " . $e->getMessage());
            ErrorLogger::logException($e, 'wallet', $userId, 'Wallet::reverseCredit');
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
        
        $isNested = Database::inTransaction();
        if (!$isNested) {
            Database::beginTransaction();
        }
        
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
            
            if (!$isNested) {
                Database::commit();
            }
            return $transactionId;
            
        } catch (Exception $e) {
            if (!$isNested) {
                Database::rollback();
            }
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
    
    /** Reserve earnings and send a verified Abliner withdrawal. */
    public static function requestWithdrawal(int $userId, float $amount, string $phone): array {
        require_once __DIR__ . '/AblinerPayment.php';
        try {
            $verified = $_SESSION['verified_recipient'] ?? [];
            $normalized = AblinerClient::normalizePhone($phone);
            if (($verified['phone'] ?? '') !== $normalized || ($verified['expires'] ?? 0) < time()
                || ($_POST['confirm_recipient'] ?? '') !== '1' || ($_POST['confirm_withdrawal_terms'] ?? '') !== '1') {
                return ['success' => false, 'errors' => ['Verify and confirm the recipient name before withdrawing.']];
            }
            $requestKey = (string) ($_POST['withdrawal_request_key'] ?? '');
            if (!isset($_SESSION['withdrawal_request_keys'][$requestKey])) return ['success' => false, 'errors' => ['Reload the withdrawal form before submitting.']];
            require_once __DIR__.'/SnippePayout.php';
            $gateway=$_POST['withdrawal_gateway']??'abliner';
            if (!is_string($gateway) || !in_array($gateway,['abliner','snippe'],true)) throw new InvalidArgumentException('Choose a valid withdrawal provider.');
            $service=$gateway==='snippe'?new SnippePayout():new AblinerPayment();
            return $service->requestWithdrawal($userId, $amount, $normalized, $verified['name'], WithdrawalCharge::validate($_POST['withdrawal_charge_quote'] ?? ''), (string) ($_POST['withdrawal_request_key'] ?? ''));
        } catch (InvalidArgumentException | RuntimeException $e) {
            return ['success' => false, 'errors' => [$e->getMessage()]];
        } catch (Throwable $e) {
            if (Database::inTransaction()) Database::rollback();
            error_log('Abliner withdrawal error: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Withdrawal could not be confirmed. Check its status before another request.']];
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
        
        if (in_array($withdrawal['gateway'] ?? '', ['abliner','snippe'], true)) {
            return ['success' => false, 'errors' => ['Use provider status verification to settle this withdrawal.']];
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
     * Kept for existing page callers. Time alone cannot establish payout failure.
     * Pending transfers retain their hold until provider verification or a signed webhook.
     */
    public static function autoExpirePending(int $userId): int {
        return 0;
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
