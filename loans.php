<?php
// Loans have been removed from the member interface. Old links return to the dashboard.
require_once __DIR__.'/classes/Auth.php';
Auth::initSession();
Auth::requireAuth();
header('Location: '.SITE_URL.'/dashboard');
exit;
