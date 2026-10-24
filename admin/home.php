<?php
require_once __DIR__ . '/../classes/StaffAccess.php';
StaffAccess::requirePage('home');
$pageTitle = 'Agent Home';
include __DIR__ . '/admin_header.php';
?>
<h1 class="h3 mb-3">Welcome to your staff account</h1>
<p>Your sidebar shows the sections assigned by your administrator. Your profile and logout are always available.</p>
<?php if (count(StaffAccess::menu()) === 1): ?><div class="alert alert-info">No work permissions are assigned yet. Contact your administrator.</div><?php endif; ?>
<?php include __DIR__ . '/admin_footer.php'; ?>
