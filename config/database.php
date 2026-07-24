<?php
/**
 * EarnSphere - Database Connection
 * PDO-based database handler with prepared statements
 */

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;
    
    /**
     * Get singleton PDO instance
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];
            
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                error_log("Database connection failed: " . $e->getMessage());
                die("Database connection failed. Please try again later.");
            }
        }
        
        return self::$instance;
    }
    
    /**
     * Shorthand query with prepare + execute
     */
    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Fetch single row
     */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    /**
     * Fetch all rows
     */
    public static function fetchAll(string $sql, array $params = []): array {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Insert and return last insert ID
     */
    public static function insert(string $table, array $data): int {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";
        self::query($sql, array_values($data));
        
        return (int) self::getConnection()->lastInsertId();
    }
    
    /**
     * Update rows
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set = implode(', ', array_map(fn($col) => "`{$col}` = ?", array_keys($data)));
        $sql = "UPDATE `{$table}` SET {$set} WHERE {$where}";
        
        $stmt = self::query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }
    
    /**
     * Delete rows
     */
    public static function delete(string $table, string $where, array $params = []): int {
        $sql = "DELETE FROM `{$table}` WHERE {$where}";
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Count rows
     */
    public static function count(string $table, string $where = '1=1', array $params = []): int {
        $parts = preg_split('/\s+/', trim($table), 2);
        $fromClause = '`' . $parts[0] . '`' . (isset($parts[1]) ? ' ' . $parts[1] : '');
        $sql = "SELECT COUNT(*) as cnt FROM {$fromClause} WHERE {$where}";
        $result = self::fetchOne($sql, $params);
        return (int) ($result['cnt'] ?? 0);
    }
    
    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool {
        return self::getConnection()->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public static function commit(): bool {
        return self::getConnection()->commit();
    }
    
    /**
     * Rollback transaction
     */
    public static function rollback(): bool {
        return self::getConnection()->rollBack();
    }
    
    /**
     * Get raw PDO for complex operations
     */
    public static function getPdo(): PDO {
        return self::getConnection();
    }
}

/**
 * Get a setting from DB, falling back to a default value.
 * Replaces hardcoded constants so admin can change values from dashboard.
 */
function app_setting(string $key, $default = null) {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    
    try {
        $row = Database::fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        $value = ($row && $row['setting_value'] !== null && $row['setting_value'] !== '') ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        $value = $default;
    }
    
    $cache[$key] = $value;
    return $value;
}
