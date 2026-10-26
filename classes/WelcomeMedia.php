<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

class WelcomeMedia {
    public static function youtubeId(string $url): string {
        $parts = parse_url(trim($url));
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true) || isset($parts['user']) || isset($parts['pass'])) throw new InvalidArgumentException('Enter a valid YouTube video link.');
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $id = '';
        if (in_array($host, ['youtu.be','www.youtu.be'], true)) $id = trim($path, '/');
        elseif (in_array($host, ['youtube.com','www.youtube.com','m.youtube.com'], true)) {
            parse_str($parts['query'] ?? '', $query);
            if ($path === '/watch') $id = $query['v'] ?? '';
            elseif (preg_match('~^/(?:shorts|embed|live)/([^/]+)/?$~D', $path, $match)) $id = $match[1];
        }
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{11}$/D', $id)) throw new InvalidArgumentException('Enter a valid YouTube video link.');
        return $id;
    }

    public static function audioPath(string $path): bool {
        return (bool) preg_match('~^uploads/welcome/[a-f0-9]{32}\.(?:mp3|wav|ogg|m4a)$~D', $path);
    }

    public static function save(array $input, ?array $file): void {
        $type = $input['type'] ?? 'off';
        if (!in_array($type, ['off','audio','youtube'], true)) throw new InvalidArgumentException('Choose audio, YouTube or hidden.');
        $title = trim((string) ($input['title'] ?? 'Karibu EarnSphere'));
        if (strlen($title) > 150) throw new InvalidArgumentException('Title must be 150 characters or less.');
        $url = trim((string) ($input['youtube'] ?? ''));
        if ($type === 'youtube') self::youtubeId($url);
        $audio = (string) app_setting('welcome_media_audio', '');
        $newFile = null;
        if ($type === 'audio' && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($file['error'] ?? -1) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Audio upload failed. Check your hosting upload limit and try again.');
            if (!is_uploaded_file($file['tmp_name'] ?? '') || filesize($file['tmp_name']) > 20 * 1024 * 1024 || filesize($file['tmp_name']) === 0) throw new InvalidArgumentException('Upload an audio file of up to 20 MB.');
            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            $allowed = ['mp3'=>['audio/mpeg','audio/mp3'], 'wav'=>['audio/wav','audio/x-wav','audio/vnd.wave'], 'ogg'=>['audio/ogg','application/ogg'], 'm4a'=>['audio/mp4','audio/x-m4a','video/mp4']];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!isset($allowed[$ext]) || !in_array($mime, $allowed[$ext], true)) throw new InvalidArgumentException('Choose a valid MP3, WAV, OGG or M4A audio file.');
            $directory = dirname(__DIR__) . '/uploads/welcome';
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Audio upload folder could not be created.');
            $audio = 'uploads/welcome/' . bin2hex(random_bytes(16)) . '.' . $ext;
            $newFile = dirname(__DIR__) . '/' . $audio;
            if (!move_uploaded_file($file['tmp_name'], $newFile)) throw new RuntimeException('Audio could not be saved.');
        }
        if ($type === 'audio' && (!self::audioPath($audio) || !is_file(dirname(__DIR__) . '/' . $audio))) throw new InvalidArgumentException('Upload an audio file first.');
        try {
            Database::beginTransaction();
            foreach (['welcome_media_type'=>$type, 'welcome_media_title'=>$title ?: 'Karibu EarnSphere', 'welcome_media_youtube'=>$url, 'welcome_media_audio'=>$audio] as $key=>$value) {
                if (Database::fetchOne('SELECT id FROM settings WHERE setting_key = ?', [$key])) Database::update('settings', ['setting_value'=>$value], 'setting_key = ?', [$key]);
                else Database::insert('settings', ['setting_key'=>$key, 'setting_value'=>$value, 'description'=>$key]);
            }
            Database::commit();
        } catch (Throwable $e) {
            if (Database::inTransaction()) Database::rollback();
            if ($newFile && is_file($newFile)) unlink($newFile);
            throw $e;
        }
    }
}
