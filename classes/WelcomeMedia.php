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

    public static function embedUrl(string $url, bool $privacy = false): string {
        $id = self::youtubeId($url);
        $site = parse_url(SITE_URL);
        $origin = ($site['scheme'] ?? 'https').'://'.($site['host'] ?? '').(isset($site['port']) ? ':'.$site['port'] : '');
        return 'https://www.'.($privacy ? 'youtube-nocookie.com' : 'youtube.com').'/embed/'.$id.'?'.http_build_query(['playsinline'=>1,'controls'=>1,'rel'=>0,'origin'=>$origin], '', '&', PHP_QUERY_RFC3986);
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

    public static function mediaPath(string $path): bool {
        return (bool) preg_match('~^uploads/welcome/[a-f0-9]{32}\.(?:mp3|wav|ogg|m4a|mp4|webm)$~D', $path);
    }

    public static function guideUrl(string $url): string {
        $url = trim($url);
        $parts = parse_url($url);
        if (strlen($url)>1000 || !$parts || ($parts['scheme']??'') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port']!==443) || preg_match('/[\x00-\x20\x7f]/', $url)) throw new InvalidArgumentException('Use a valid HTTPS link without login details.');
        return $url;
    }

    public static function items(bool $includeHidden = false): array {
        $typeRow = Database::fetchOne('SELECT setting_value FROM settings WHERE setting_key = ?', ['welcome_media_type']);
        $type = (string) ($typeRow['setting_value'] ?? 'off');
        if (!$includeHidden && $type === 'off') return [];
        $row = Database::fetchOne('SELECT setting_value FROM settings WHERE setting_key = ?', ['welcome_media_items']);
        if ($row) {
            $decoded = json_decode($row['setting_value'], true);
            $items = [];
            foreach (is_array($decoded) ? $decoded : [] as $item) {
                if (!is_array($item) || !preg_match('/^[a-f0-9]{32}$/D', $item['id']??'') || !is_string($item['title']??null) || !is_string($item['source']??null)) continue;
                try {
                    if (($item['type']??'') === 'youtube') self::youtubeId($item['source']);
                    elseif (($item['type']??'') === 'link') self::guideUrl($item['source']);
                    elseif (in_array($item['type']??'', ['audio','video'], true)) {
                        if (!self::mediaPath($item['source']) || !is_file(dirname(__DIR__).'/'.$item['source'])) continue;
                    } else continue;
                    $items[] = $item;
                } catch (InvalidArgumentException $e) { continue; }
            }
            return array_slice($items, 0, 30);
        }
        if ($type === 'youtube') {
            $url = (string) app_setting('welcome_media_youtube', '');
            try { self::youtubeId($url); } catch (InvalidArgumentException $e) { return []; }
            return [['id'=>md5('legacy-youtube'), 'type'=>'youtube', 'source'=>$url, 'title'=>(string)app_setting('welcome_media_title','Karibu EarnSphere')]];
        }
        if ($type === 'audio') {
            $path = (string) app_setting('welcome_media_audio','');
            if (self::audioPath($path) && is_file(dirname(__DIR__).'/'.$path)) return [['id'=>md5('legacy-audio'), 'type'=>'audio', 'source'=>$path, 'title'=>(string)app_setting('welcome_media_title','Karibu EarnSphere')]];
        }
        return [];
    }

    public static function saveLibrary(array $input, ?array $files): void {
        $title = trim(is_string($input['title']??null) ? $input['title'] : 'Know Your Account');
        if (mb_strlen($title)>150) throw new InvalidArgumentException('Title must be 150 characters or less.');
        $items = self::items(true);
        $remove = is_array($input['remove_items']??null) ? $input['remove_items'] : [];
        $titles = is_array($input['item_titles']??null) ? $input['item_titles'] : [];
        $items = array_values(array_filter($items, fn($item)=>!in_array($item['id'],$remove,true)));
        foreach ($items as &$item) {
            if (isset($titles[$item['id']])) {
                if (!is_string($titles[$item['id']]) || trim($titles[$item['id']])==='' || mb_strlen(trim($titles[$item['id']]))>150) throw new InvalidArgumentException('Each item needs a title of up to 150 characters.');
                $item['title'] = trim($titles[$item['id']]);
            }
        }
        unset($item);
        $links = $input['links']??'';
        if (!is_string($links) || strlen($links)>40000) throw new InvalidArgumentException('Too many links.');
        foreach (preg_split('/\R/',trim($links)) as $line) {
            if (!trim($line)) continue;
            $parts = explode('|', $line, 2);
            $label = count($parts)===2 ? trim($parts[0]) : '';
            $url = self::guideUrl(trim(count($parts)===2 ? $parts[1] : $parts[0]));
            $host = strtolower(parse_url($url, PHP_URL_HOST));
            $youtube = in_array($host,['youtube.com','www.youtube.com','m.youtube.com','youtu.be','www.youtu.be'],true);
            if ($youtube) self::youtubeId($url);
            if (mb_strlen($label)>150) throw new InvalidArgumentException('Link title must be 150 characters or less.');
            $items[] = ['id'=>bin2hex(random_bytes(16)), 'type'=>$youtube?'youtube':'link', 'title'=>$label ?: ($youtube?'Video '.(count($items)+1):$host), 'source'=>$url];
        }
        $newFiles = [];
        try {
            if ($files && isset($files['error'])) {
                if (!is_array($files['error']) || count($files['error'])>10) throw new InvalidArgumentException('Choose up to 10 media files per save.');
                foreach ($files['error'] as $i=>$error) {
                    if ($error===UPLOAD_ERR_NO_FILE) continue;
                    if ($error!==UPLOAD_ERR_OK) throw new InvalidArgumentException('Media upload failed. Check your hosting upload and request limits.');
                    $tmp=$files['tmp_name'][$i]??''; $name=$files['name'][$i]??'';
                    if (!is_string($tmp) || !is_string($name) || !is_uploaded_file($tmp)) throw new InvalidArgumentException('Choose a valid uploaded media file.');
                    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
                    $allowed=['mp4'=>['video/mp4'],'webm'=>['video/webm'],'mp3'=>['audio/mpeg','audio/mp3'],'wav'=>['audio/wav','audio/x-wav','audio/vnd.wave'],'ogg'=>['audio/ogg','application/ogg'],'m4a'=>['audio/mp4','audio/x-m4a','video/mp4']];
                    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                    if (!isset($allowed[$ext]) || !in_array($mime,$allowed[$ext],true)) throw new InvalidArgumentException('Use MP4/WebM video or MP3/WAV/OGG/M4A audio.');
                    $video=in_array($ext,['mp4','webm'],true);$limit=($video?50:20)*1024*1024;
                    if (filesize($tmp)<=0 || filesize($tmp)>$limit) throw new InvalidArgumentException($video?'Videos must be 50 MB or less.':'Audio must be 20 MB or less.');
                    $dir=dirname(__DIR__).'/uploads/welcome';
                    if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Media folder unavailable.');
                    $path='uploads/welcome/'.bin2hex(random_bytes(16)).'.'.$ext;
                    if (!move_uploaded_file($tmp,dirname(__DIR__).'/'.$path)) throw new RuntimeException('Media upload could not be saved.');
                    $newFiles[]=dirname(__DIR__).'/'.$path;
                    $items[]=['id'=>bin2hex(random_bytes(16)),'type'=>$video?'video':'audio','source'=>$path,'title'=>mb_substr(pathinfo(basename($name),PATHINFO_FILENAME),0,150) ?: 'Maelekezo'];
                }
            }
            if (count($items)>30) throw new InvalidArgumentException('Keep up to 30 media items and links. Remove an item before adding another.');
            Database::beginTransaction();
            $values=['welcome_media_items'=>json_encode($items,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'welcome_media_type'=>($input['enabled']??'')==='1'?'library':'off', 'welcome_media_title'=>$title ?: 'Know Your Account'];
            foreach ($values as $key=>$value) {
                if (Database::fetchOne('SELECT id FROM settings WHERE setting_key=?',[$key])) Database::update('settings',['setting_value'=>$value],'setting_key=?',[$key]);
                else Database::insert('settings',['setting_key'=>$key,'setting_value'=>$value,'description'=>$key]);
            }
            Database::commit();
        } catch (Throwable $e) {
            if (Database::inTransaction()) Database::rollback();
            foreach ($newFiles as $path) if (is_file($path)) unlink($path);
            throw $e;
        }
    }
}
