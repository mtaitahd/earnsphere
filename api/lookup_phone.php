<?php
require_once __DIR__ . '/../includes/payment_api.php';
paymentApiUser();
paymentApiPost();
if ((StaffAccess::current()['role'] ?? '') === 'agent' && !StaffAccess::can('withdrawals') && !StaffAccess::can('wallet')) StaffAccess::deny(true);
$recent = array_filter($_SESSION['lookup_times'] ?? [], fn($time) => $time > time() - 60);
if (count($recent) >= 12) paymentApiReply(['success' => false, 'error' => 'Please wait before checking another number.'], 429);
$recent[] = time();
$_SESSION['lookup_times'] = $recent;
unset($_SESSION['verified_recipient']);
try {
    $phone = AblinerClient::normalizePhone((string) ($_POST['phone'] ?? ''));
    $result = (new AblinerClient())->lookup($phone);
    if (!$result['success']) paymentApiReply(['success' => false, 'error' => $result['error'], 'code' => $result['code']], 422);
    $data = $result['data'];
    if (empty($data['name']) || empty($data['network']) || AblinerClient::normalizePhone((string) ($data['phone'] ?? '')) !== $phone) {
        paymentApiReply(['success' => false, 'error' => 'This number could not be verified.'], 422);
    }
    $_SESSION['verified_recipient'] = ['phone' => $phone, 'name' => (string) $data['name'], 'network' => (string) $data['network'], 'expires' => time() + 300];
    paymentApiReply(['success' => true, 'phone' => $phone, 'name' => $data['name'], 'network' => $data['network']]);
} catch (InvalidArgumentException $e) { paymentApiReply(['success' => false, 'error' => $e->getMessage()], 422); }
catch (Throwable $e) { error_log('Abliner lookup failed'); paymentApiReply(['success' => false, 'error' => 'Name lookup is unavailable. Try again shortly.'], 503); }
