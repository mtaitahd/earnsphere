<?php
/**
 * EarnSphere - Database Diagnostic
 * DELETE THIS FILE AFTER TESTING
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>EarnSphere Diagnostic</h2>";

// Check .env
$envFile = dirname(__FILE__) . '/.env';
echo "<h3>1. .env File</h3>";
if (file_exists($envFile)) {
    echo "<p style='color:green;'>.env file EXISTS</p>";
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        list($key, $value) = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
    }
    echo "<pre>";
    echo "DB_HOST: " . ($env['DB_HOST'] ?? 'NOT SET') . "\n";
    echo "DB_NAME: " . ($env['DB_NAME'] ?? 'NOT SET') . "\n";
    echo "DB_USER: " . ($env['DB_USER'] ?? 'NOT SET') . "\n";
    echo "DB_PASS: " . (isset($env['DB_PASS']) ? '***SET***' : 'NOT SET') . "\n";
    echo "APP_URL: " . ($env['APP_URL'] ?? 'NOT SET') . "\n";
    echo "</pre>";
} else {
    echo "<p style='color:red;'>.env file NOT FOUND at: $envFile</p>";
}

// Check database connection
echo "<h3>2. Database Connection</h3>";
require_once __DIR__ . '/config/database.php';

$host = getenv('DB_HOST') ?: 'localhost';
$name = getenv('DB_NAME') ?: 'earnsphere';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

echo "<pre>";
echo "Host: $host\n";
echo "Name: $name\n";
echo "User: $user\n";
echo "Pass: " . ($pass ? '***SET***' : 'EMPTY') . "\n";
echo "</pre>";

try {
    $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "<p style='color:green;'>Database connection SUCCESS</p>";
    
    // Check tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>Tables found: " . count($tables) . "</p>";
    echo "<pre>" . implode("\n", $tables) . "</pre>";
} catch (PDOException $e) {
    echo "<p style='color:red;'>Database FAILED: " . $e->getMessage() . "</p>";
    
    // Try without dbname
    try {
        $dsn = "mysql:host=$host;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        echo "<p style='color:orange;'>MySQL server is reachable, but database '$name' might not exist.</p>";
    } catch (PDOException $e2) {
        echo "<p style='color:red;'>Cannot connect to MySQL server: " . $e2->getMessage() . "</p>";
    }
}

// Check writable directories
echo "<h3>3. Directory Permissions</h3>";
$dirs = ['uploads', 'uploads/avatars', 'uploads/qrcodes', 'logs'];
foreach ($dirs as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) {
        echo "<p style='color:orange;'>$dir - NOT FOUND (creating...)</p>";
        @mkdir($path, 0755, true);
    }
    $writable = is_writable($path);
    echo "<p style='color:" . ($writable ? 'green' : 'red') . ";'>$dir - " . ($writable ? 'WRITABLE' : 'NOT WRITABLE') . "</p>";
}

echo "<hr><p><strong>DELETE THIS FILE after testing!</strong></p>";
echo "<p><a href='index.php'>Back to site</a></p>";
