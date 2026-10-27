<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/abliner_payment_test.php';
require __DIR__.'/../classes/WelcomeMedia.php';
$db->exec('ALTER TABLE settings ADD COLUMN description TEXT');
WelcomeMedia::save(['type'=>'youtube','title'=>'Legacy welcome','youtube'=>'https://youtu.be/dQw4w9WgXcQ'],null);
WelcomeMedia::saveLibrary(['enabled'=>'1','title'=>'Know Your Account','links'=>"Wallet | https://youtu.be/aqz-KE-bpKQ\nHelp | https://example.com/help"],null);
$items=WelcomeMedia::items();
expect(count($items)===3,'Legacy video or new links lost');
expect(count(array_filter($items,fn($i)=>$i['type']==='youtube'))===2,'Two videos not saved');
expect($items[2]['type']==='link','External link must not become an iframe');
$user=['id'=>1];ob_start();include __DIR__.'/../includes/welcome_media.php';$html=ob_get_clean();
expect(substr_count($html,'data-welcome-src=')===2,'Multiple video players missing');
expect(substr_count($html,'Open on YouTube')===2,'YouTube fallback missing');
expect(str_contains($html,'strict-origin-when-cross-origin'),'YouTube referrer suppressed');
expect(!str_contains($html,'iframe src='),'YouTube players loaded before popup opens');
WelcomeMedia::saveLibrary(['enabled'=>'1','title'=>'Instructions','item_titles'=>[$items[0]['id']=>'<script>alert(2)</script>'],'remove_items'=>[$items[1]['id']]],null);
expect(count(WelcomeMedia::items())===2,'Item removal failed');
ob_start();include __DIR__.'/../includes/welcome_media.php';$html=ob_get_clean();expect(!str_contains($html,'<script>alert(2)</script>'),'Item title not escaped');
$before=json_encode(WelcomeMedia::items());
foreach (['javascript:alert(1)','https://user:password@example.com/help','http://example.com','https://youtu.be/invalid'] as $url) {
 try { WelcomeMedia::saveLibrary(['enabled'=>'1','links'=>$url],null);throw new RuntimeException('Unsafe link accepted'); } catch(InvalidArgumentException $e) {}
 expect(json_encode(WelcomeMedia::items())===$before,'Invalid input changed library');
}
try { WelcomeMedia::saveLibrary(['enabled'=>'1','links'=>implode("\n",array_fill(0,31,'https://example.com/help'))],null);throw new RuntimeException('Library overflow accepted'); } catch(InvalidArgumentException $e) {}
expect(json_encode(WelcomeMedia::items())===$before,'Overflow changed library');
try { WelcomeMedia::saveLibrary(['enabled'=>'1'],['error'=>[UPLOAD_ERR_OK],'tmp_name'=>[__FILE__],'name'=>['test.mp4']]);throw new RuntimeException('Non-uploaded video accepted'); } catch(InvalidArgumentException $e) {}
WelcomeMedia::saveLibrary(['title'=>'Instructions'],null);
expect(WelcomeMedia::items()===[],'Hidden library visible');
expect(count(WelcomeMedia::items(true))===2,'Hidden items deleted');
expect(WelcomeMedia::mediaPath('uploads/welcome/'.str_repeat('a',32).'.mp4'),'MP4 path rejected');
expect(!WelcomeMedia::mediaPath('uploads/welcome/../../config.php'),'Traversal accepted');
echo "Welcome library: two videos, external links, legacy preservation, fallback, escaping, editing, hide/remove, limits and unsafe uploads passed. No live requests.\n";
