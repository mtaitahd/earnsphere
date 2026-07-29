<?php
/**
 * EarnSphere - Structured Error Logger
 * Stores user-facing and system errors so admins can review them from the dashboard.
 */

require_once __DIR__ . '/../config/database.php';

class ErrorLogger {
    private static bool $tableChecked = false;
    private static bool $handlersRegistered = false;
    private static bool $isLogging = false;

    public static function ensureTable(): void {
        if (self::$tableChecked) {
            return;
        }

        try {
            $pdo = Database::getConnection();

            // Avoid implicit commits if an error is logged while app logic owns a transaction.
            if ($pdo->inTransaction()) {
                return;
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS `error_logs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED DEFAULT NULL,
                `category` VARCHAR(50) NOT NULL DEFAULT 'system',
                `severity` VARCHAR(20) NOT NULL DEFAULT 'error',
                `source` VARCHAR(150) DEFAULT NULL,
                `message` TEXT NOT NULL,
                `context` JSON DEFAULT NULL,
                `request_method` VARCHAR(10) DEFAULT NULL,
                `request_uri` VARCHAR(255) DEFAULT NULL,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_agent` TEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_error_user` (`user_id`),
                KEY `idx_error_category` (`category`),
                KEY `idx_error_severity` (`severity`),
                KEY `idx_error_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            self::$tableChecked = true;
        } catch (Throwable $e) {
            error_log('Error log table setup failed: ' . $e->getMessage());
        }
    }

    public static function registerHandlers(): void {
        if (self::$handlersRegistered) {
            return;
        }

        self::$handlersRegistered = true;

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            ErrorLogger::log('php', $message, [
                'severity' => ErrorLogger::severityName($severity),
                'file'     => ErrorLogger::shortPath($file),
                'line'     => $line,
            ], null, ErrorLogger::mapPhpSeverity($severity), ErrorLogger::shortPath($file) . ':' . $line);

            return false;
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            ErrorLogger::log('php', $error['message'], [
                'severity' => ErrorLogger::severityName($error['type']),
                'file'     => ErrorLogger::shortPath($error['file'] ?? ''),
                'line'     => $error['line'] ?? null,
            ], null, 'critical', ErrorLogger::shortPath($error['file'] ?? '') . ':' . ($error['line'] ?? 0));
        });
    }

    public static function log(
        string $category,
        string $message,
        array $context = [],
        ?int $userId = null,
        string $severity = 'error',
        ?string $source = null
    ): void {
        if (self::$isLogging) {
            return;
        }

        self::$isLogging = true;

        try {
            self::ensureTable();

            $userId = $userId ?? self::currentUserId();
            $contextJson = empty($context)
                ? null
                : json_encode(self::sanitizeContext($context), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            Database::insert('error_logs', [
                'user_id'        => $userId,
                'category'       => substr($category ?: 'system', 0, 50),
                'severity'       => substr($severity ?: 'error', 0, 20),
                'source'         => $source ? substr($source, 0, 150) : self::defaultSource(),
                'message'        => $message,
                'context'        => $contextJson,
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'request_uri'    => isset($_SERVER['REQUEST_URI']) ? substr($_SERVER['REQUEST_URI'], 0, 255) : null,
                'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('Structured error log failed: ' . $e->getMessage() . ' | Original: ' . $message);
        } finally {
            self::$isLogging = false;
        }
    }

    public static function logException(Throwable $e, string $category = 'system', ?int $userId = null, ?string $source = null): void {
        self::log($category, $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => self::shortPath($e->getFile()),
            'line'      => $e->getLine(),
            'trace'     => self::compactTrace($e->getTrace()),
        ], $userId, 'error', $source);
    }

    private static function currentUserId(): ?int {
        if (isset($_SESSION) && isset($_SESSION['user_id'])) {
            return (int) $_SESSION['user_id'];
        }

        return null;
    }

    private static function defaultSource(): ?string {
        return $_SERVER['SCRIPT_NAME'] ?? null;
    }

    private static function sanitizeContext(array $context): array {
        $sensitive = ['password', 'pass', 'token', 'csrf', 'secret', 'api_key', 'authorization', 'cookie'];

        $clean = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            foreach ($sensitive as $needle) {
                if (str_contains($lower, $needle)) {
                    $clean[$key] = '[masked]';
                    continue 2;
                }
            }

            if (is_array($value)) {
                $clean[$key] = self::sanitizeContext($value);
            } elseif (is_object($value)) {
                $clean[$key] = '[object ' . get_class($value) . ']';
            } elseif (is_string($value) && strlen($value) > 2000) {
                $clean[$key] = substr($value, 0, 2000) . '...';
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private static function compactTrace(array $trace): array {
        $items = [];
        foreach (array_slice($trace, 0, 8) as $row) {
            $items[] = [
                'file'     => isset($row['file']) ? self::shortPath($row['file']) : null,
                'line'     => $row['line'] ?? null,
                'function' => ($row['class'] ?? '') . ($row['type'] ?? '') . ($row['function'] ?? ''),
            ];
        }

        return $items;
    }

    public static function shortPath(string $path): string {
        if (defined('APP_ROOT') && str_starts_with($path, APP_ROOT)) {
            return ltrim(substr($path, strlen(APP_ROOT)), '/\\');
        }

        return $path;
    }

    private static function mapPhpSeverity(int $severity): string {
        return match ($severity) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'error',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_STRICT => 'notice',
            default => 'error',
        };
    }

    private static function severityName(int $severity): string {
        return match ($severity) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_UNKNOWN',
        };
    }
}
