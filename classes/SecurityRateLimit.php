<?php
/** Shared across sessions; private files and flock prevent parallel-attempt bypasses. */
final class SecurityRateLimit {
    public static function consume(string $key, int $limit, int $seconds): bool {
        $directory = APP_ROOT . '/logs/security-limits';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            error_log('Security rate limit storage unavailable');
            return false;
        }
        $file = @fopen($directory . '/' . hash('sha256', $key) . '.json', 'c+');
        if (!$file) return false;
        try {
            if (!flock($file, LOCK_EX)) return false;
            $state = json_decode(stream_get_contents($file), true);
            $now = time();
            if (!is_array($state) || ($state['until'] ?? 0) <= $now) {
                $state = ['until' => $now + $seconds, 'count' => 0];
            }
            if ($state['count'] >= $limit) return false;
            $state['count']++;
            $encoded = json_encode($state);
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) return false;
            return true;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
