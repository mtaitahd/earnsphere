<?php
/**
 * EarnSphere - Mail Test
 * DELETE THIS FILE AFTER TESTING
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Mailer.php';

echo "<h2>EarnSphere Mail Test</h2>";

// Show SMTP config (hide password)
echo "<h3>SMTP Configuration</h3>";
echo "<pre>";
echo "SMTP_HOST: " . SMTP_HOST . "\n";
echo "SMTP_PORT: " . SMTP_PORT . "\n";
echo "SMTP_ENCRYPTION: " . SMTP_ENCRYPTION . "\n";
echo "SMTP_USER: " . SMTP_USER . "\n";
echo "SMTP_PASS: " . str_repeat('*', strlen(SMTP_PASS)) . "\n";
echo "FROM_EMAIL: " . FROM_EMAIL . "\n";
echo "</pre>";

// Test PHP mail() function
echo "<h3>Test PHP mail()</h3>";
$testTo = FROM_EMAIL;
$testSubject = "EarnSphere Mail Test - " . date('Y-m-d H:i:s');
$testBody = "<h2>Test Email</h2><p>This is a test email from EarnSphere at " . date('Y-m-d H:i:s') . "</p>";

echo "<p>Sending test to: <strong>{$testTo}</strong></p>";

$mailer = new Mailer();
$result = $mailer->send($testTo, $testSubject, $testBody);

if ($result) {
    echo "<p style='color:green;font-size:1.2rem;'>Email sent successfully!</p>";
    echo "<p>Check your inbox (and spam folder) at: <strong>{$testTo}</strong></p>";
} else {
    echo "<p style='color:red;font-size:1.2rem;'>Email FAILED to send</p>";
    
    // Try direct PHP mail
    echo "<h3>Debug: Direct PHP mail() test</h3>";
    $headers = "From: EarnSphere <" . FROM_EMAIL . ">\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $directResult = @mail($testTo, $testSubject, $testBody, $headers);
    echo "<p>Direct mail() result: " . ($directResult ? "SUCCESS" : "FAILED") . "</p>";
    
    if (!$directResult) {
        echo "<p style='color:red;'>Your hosting may block PHP mail(). Check cPanel settings.</p>";
    }
}

echo "<hr>";
echo "<p><strong>DELETE THIS FILE after testing!</strong></p>";
echo "<p><a href='index'>Back to site</a></p>";
