<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/staff_fixture.php';require_once __DIR__.'/../includes/helpers.php';
function flashCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function footerHtml():string{ob_start();include __DIR__.'/../admin/admin_footer.php';return ob_get_clean();}
$_SESSION['flash']=[];flashCheck(!str_contains(footerHtml(),'const adminFlashMessages ='),'Empty state generated popup');
setFlash('success','Reply sent to user');ob_start();displayFlash();$inline=ob_get_clean();
flashCheck(str_contains($inline,'Reply sent to user')&&!isset($_SESSION['flash']),'Inline message not consumed completely');
flashCheck(!str_contains(footerHtml(),'const adminFlashMessages ='),'Consumed inline message generated duplicate empty popup');
setFlash('success','Saved successfully');setFlash('warning','Please review');$html=footerHtml();
preg_match('/const adminFlashMessages = (.*);/',$html,$match);$items=json_decode($match[1]??'',true);
flashCheck(count($items)===2&&$items[0]['message']==='Saved successfully'&&$items[1]['type']==='warning','Keyed messages lost');
$payload='</script><img src=x onerror=alert(1)>';$_SESSION['flash']=['type'=>'info','message'=>$payload];$html=footerHtml();
flashCheck(!str_contains($html,$payload)&&str_contains($html,'\\u003C'),'Legacy message script breakout');
flashCheck(str_contains($html,'titleText: flash.message')&&!str_contains($html,'title: flash.message'),'Message rendered as HTML title');
$_SESSION['flash']=['type'=>'<script>','message'=>'Visible message'];$html=footerHtml();
preg_match('/const adminFlashMessages = (.*);/',$html,$match);flashCheck(json_decode($match[1],true)[0]['type']==='info','Invalid icon type not normalized');
$_SESSION['flash']=['success'=>'   ','message'=>[]];flashCheck(!str_contains(footerHtml(),'const adminFlashMessages ='),'Blank/malformed messages generated popup');
echo "Admin flash: inline consumption, no empty/duplicate popup, keyed and legacy formats, text-only title and script escaping passed.\n";
