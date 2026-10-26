<?php
class MarketplaceImages {
    public static function validPath(string $path): bool {
        return (bool)preg_match('~^uploads/marketplace/[a-f0-9]{32}\.(?:jpg|png|webp)$~D',$path);
    }
    public static function inspect(string $file): string {
        $size=is_file($file)?filesize($file):0;
        if (!$size || $size>5*1024*1024) throw new InvalidArgumentException('Each product photo must be between 1 byte and 5 MB.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
        $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $dimensions=@getimagesize($file);
        if (!isset($extensions[$mime]) || !$dimensions || ($dimensions['mime']??'')!==$mime || $dimensions[0]<1 || $dimensions[1]<1 || $dimensions[0]>6000 || $dimensions[1]>6000) throw new InvalidArgumentException('Use valid JPG, PNG or WebP photos, up to 6000 × 6000 pixels.');
        return $extensions[$mime];
    }
    public function store(array $files): array {
        if (!is_array($files['error']??null) || !is_array($files['tmp_name']??null) || count($files['error'])<1 || count($files['error'])>4) throw new InvalidArgumentException('Upload 1–4 product photos.');
        $paths=[];
        try {
            foreach ($files['error'] as $index=>$error) {
                if ($error!==UPLOAD_ERR_OK || !is_string($files['tmp_name'][$index]??null) || !is_uploaded_file($files['tmp_name'][$index])) throw new InvalidArgumentException('Photo upload failed. Upload 1–4 photos and check your hosting upload limit.');
                $extension=self::inspect($files['tmp_name'][$index]);
                $directory=dirname(__DIR__).'/uploads/marketplace';
                if (!is_dir($directory) && !mkdir($directory,0755,true) && !is_dir($directory)) throw new RuntimeException('Product photo folder could not be created.');
                $relative='uploads/marketplace/'.bin2hex(random_bytes(16)).'.'.$extension;
                if (!move_uploaded_file($files['tmp_name'][$index],dirname(__DIR__).'/'.$relative)) throw new RuntimeException('Product photo could not be saved.');
                $paths[]=$relative;
            }
            return $paths;
        }catch(Throwable $e){self::cleanup($paths);throw $e;}
    }
    public static function cleanup(array $paths): void {
        foreach ($paths as $path) if (is_string($path) && self::validPath($path) && is_file(dirname(__DIR__).'/'.$path)) unlink(dirname(__DIR__).'/'.$path);
    }
}
