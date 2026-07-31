<?php
/**
 * EarnSphere - Live Proof of Payment API
 * Public endpoint returning recent completed payouts.
 * Names are masked for privacy, amounts are shown as social proof.
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Contest.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$enabled = app_setting('live_proof_enabled', '1');
if ($enabled === '0') {
    jsonResponse(['success' => true, 'enabled' => false, 'data' => []]);
}

$limit = min(max((int) ($_GET['limit'] ?? 10), 1), 20);

$rows = Database::fetchAll(
    "SELECT w.id, w.amount, w.processed_at, w.created_at, u.full_name
     FROM withdrawals w
     INNER JOIN users u ON u.id = w.user_id
     WHERE w.status = 'completed'
     ORDER BY COALESCE(w.processed_at, w.created_at) DESC
     LIMIT ?",
    [$limit]
);

$data = [];
foreach ($rows as $row) {
    $data[] = [
        'id'     => (int) $row['id'],
        'name'   => Contest::maskName($row['full_name']),
        'amount' => (float) $row['amount'],
        'time'   => timeAgo($row['processed_at'] ?: $row['created_at']),
    ];
}

jsonResponse(['success' => true, 'enabled' => true, 'data' => $data]);
