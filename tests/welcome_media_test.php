<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/abliner_payment_test.php';
require __DIR__ . '/../classes/WelcomeMedia.php';
$db->exec('ALTER TABLE settings ADD COLUMN description TEXT');
foreach (['https://youtu.be/dQw4w9WgXcQ','https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=3','https://m.youtube.com/shorts/dQw4w9WgXcQ','https://youtube.com/embed/dQw4w9WgXcQ'] as $url) expect(WelcomeMedia::youtubeId($url)==='dQw4w9WgXcQ','YouTube format rejected');
foreach (['javascript:alert(1)','https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ','https://example.com/video','https://youtube.com/watch?v[]=abc','https://youtube.com/watch?v=bad','https://user:pass@youtube.com/watch?v=dQw4w9WgXcQ'] as $url) {
    try { WelcomeMedia::youtubeId($url); throw new RuntimeException('Unsafe YouTube URL accepted'); } catch (InvalidArgumentException $e) {}
}
expect(WelcomeMedia::audioPath('uploads/welcome/'.str_repeat('a',32).'.mp3'),'Generated audio path rejected');
foreach (['uploads/welcome/../../config.php','https://evil.test/audio.mp3','uploads/welcome/test.php','uploads/welcome/'.str_repeat('a',32).'.mp3.php'] as $path) expect(!WelcomeMedia::audioPath($path),'Unsafe audio path accepted');
WelcomeMedia::save(['type'=>'youtube','title'=>'Karibu <script>alert(1)</script>','youtube'=>'https://youtu.be/dQw4w9WgXcQ'],null);
expect(Database::fetchOne("SELECT setting_value FROM settings WHERE setting_key='welcome_media_type'")['setting_value']==='youtube','Media settings not saved');
$user=['id'=>1]; ob_start(); include __DIR__.'/../includes/welcome_media.php'; $html=ob_get_clean();
expect(str_contains($html,'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'),'Video player missing');
expect(!str_contains($html,'<script>alert(1)</script>'),'Media title allowed HTML injection');
try { WelcomeMedia::save(['type'=>'audio'],null); throw new RuntimeException('Missing audio accepted'); } catch (InvalidArgumentException $e) {}
try { WelcomeMedia::save(['type'=>'audio'],['error'=>UPLOAD_ERR_OK,'tmp_name'=>__FILE__,'name'=>'audio.mp3']); throw new RuntimeException('Non-uploaded file accepted'); } catch (InvalidArgumentException $e) {}
WelcomeMedia::save(['type'=>'off'],null);
expect(Database::fetchOne("SELECT setting_value FROM settings WHERE setting_key='welcome_media_type'")['setting_value']==='off','Disable setting failed');
echo "Welcome media: YouTube validation, escaped player, settings save, hidden mode and unsafe upload rejection passed. No external requests sent.\n";

