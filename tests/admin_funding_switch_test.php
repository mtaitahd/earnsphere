<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/abliner_payment_test.php';
$client = new MockAbliner(); $service = new AblinerPayment($client);
$client->mode = 'pending';
$old = $service->initiateFunding(3, '0712345678', 1000);
$count = $client->postCount();
$blocked = $service->initiateFunding(3, '0687654321', 2000);
expect(!$blocked['success'] && str_contains($blocked['error'],'company deposit'), 'Admin got a registration-only error');
expect($client->postCount() === $count, 'Unconfirmed number change sent a push');
$stale = $service->initiateFunding(3, '0687654321', 2000, true, 'wrong-order');
expect(!$stale['success'] && $client->postCount() === $count, 'Stale confirmation sent a push');
$amountChange = $service->initiateFunding(3, '0712345678', 2000);
expect(!$amountChange['success'] && $client->postCount() === $count, 'Existing amount was silently reused');
$new = $service->initiateFunding(3, '0687654321', 2000, true, $old['order_id']);
expect($new['success'] && $new['order_id'] !== $old['order_id'] && $client->postCount() === $count + 1, 'Admin could not confirm a new number');
$duplicate = $service->initiateFunding(3, '0687654321', 2000, true, $old['order_id']);
expect($duplicate['order_id'] === $new['order_id'] && $client->postCount() === $count + 1, 'Repeated submit duplicated push');
$commissions = Database::count('commissions');
$service->applyTransaction('payments', (int) $new['payment_id'], transactionFor('payments', (int) $new['payment_id'], 'completed'));
expect(Database::count('commissions') === $commissions, 'Company funding paid commissions');
expect(Database::fetchOne('SELECT status FROM payments WHERE id=?',[$old['payment_id']])['status'] === 'pending', 'Company deposit was closed as a registration attempt');
echo "Admin funding number switch, amount guard, duplicate push and no-commission checks passed.\n";
