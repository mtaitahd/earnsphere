"""Exercise genuine multipart handling with MP4 container header fixtures (not playback tests)."""
import json,os,secrets,socket,subprocess,sys,tempfile,time,urllib.error,urllib.request
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
with socket.socket() as sock:
 sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
token=secrets.token_hex(24);env=os.environ.copy();env['EARNSPHERE_GUIDE_TEST']=token
flags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0
# Fileinfo identifies this ISO base-media container header as video/mp4.
mp4=bytes.fromhex('000000186674797069736f6d0000020069736f6d69736f32')
with tempfile.TemporaryDirectory(prefix='earnsphere-guide-upload-') as directory:
 router=Path(directory)/'router.php'
 fixture=Path(directory)/'fixture.php'
 source=(root/'tests/abliner_payment_test.php').read_text(encoding='utf-8').replace("if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }",'').replace('__DIR__',json.dumps((root/'tests').as_posix()))
 fixture.write_text(source,encoding='utf-8')
 code="<?php if(PHP_SAPI!=='cli-server'||!hash_equals(getenv('EARNSPHERE_GUIDE_TEST'),(string)($_SERVER['HTTP_X_GUIDE_TEST']??''))){http_response_code(404);exit;} if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;} ob_start();require "+json.dumps(fixture.as_posix())+";ob_end_clean();$db->exec('ALTER TABLE settings ADD COLUMN description TEXT'); require_once "+json.dumps((root/'classes/WelcomeMedia.php').as_posix())+";"
 code+="""
$before=glob(dirname(__FILE__).'/unused')?:[];$paths=[];
try{WelcomeMedia::saveLibrary(['enabled'=>'1','title'=>'Upload test'],$_FILES['media_files']??null);$items=WelcomeMedia::items(true);foreach($items as $item)if(WelcomeMedia::mediaPath($item['source']))$paths[]=dirname("""+json.dumps((root/'classes').as_posix())+""").'/'.$item['source'];echo json_encode(['success'=>true,'items'=>$items]);}
catch(Throwable $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
finally{foreach($paths as $path)if(is_file($path))unlink($path);}
"""
 router.write_text(code)
 process=subprocess.Popen([php,'-d','upload_tmp_dir='+directory,'-d','sys_temp_dir='+directory,'-d','upload_max_filesize=6M','-d','post_max_size=25M','-S',f'127.0.0.1:{port}',str(router)],cwd=root,env=env,stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,creationflags=flags)
 url=f'http://127.0.0.1:{port}/'
 def upload(files):
  boundary='----Guide'+secrets.token_hex(10);parts=[]
  for name,content in files:parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="media_files[]"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+b'\r\n')
  body=b''.join(parts)+f'--{boundary}--\r\n'.encode()
  request=urllib.request.Request(url,data=body,headers={'X-Guide-Test':token,'Content-Type':f'multipart/form-data; boundary={boundary}'})
  try:
   with urllib.request.urlopen(request,timeout=15) as response:return response.status,json.loads(response.read())
  except urllib.error.HTTPError as error:return error.code,json.loads(error.read())
 try:
  for _ in range(50):
   try:urllib.request.urlopen(urllib.request.Request(url,headers={'X-Guide-Test':token}),timeout=.3)
   except urllib.error.HTTPError:break
   except (urllib.error.URLError,TimeoutError):time.sleep(.1)
  status,result=upload([('welcome.mp4',mp4),('wallet.mp4',mp4)])
  assert status==200 and len(result['items'])==2,result
  assert all(item['type']=='video' and not (root/item['source']).exists() for item in result['items']),result
  before=set((root/'uploads/welcome').glob('*'))
  status,result=upload([('first.mp4',mp4),('bad.mp4',b'<?php echo 1; ?>')])
  assert status==422 and not result['success'],result
  assert set((root/'uploads/welcome').glob('*'))==before,'Failed batch left uploaded files'
  status,result=upload([('bad.php',mp4)])
  assert status==422 and not result['success'],result
 finally:
  process.terminate();process.wait(timeout=5)
print('Welcome multipart: two video uploads, script/MIME rejection and failed-batch cleanup passed (container fixtures; no playback claim).')
