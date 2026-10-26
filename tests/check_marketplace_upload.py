"""Real multipart uploads to a temporary local PHP probe; test files are cleaned."""
import base64,json,os,secrets,socket,subprocess,sys,tempfile,time,urllib.error,urllib.request
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
token=secrets.token_hex(24);env=os.environ.copy();env['EARNSPHERE_UPLOAD_TEST_TOKEN']=token
flags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0
photo=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==')
with tempfile.TemporaryDirectory(prefix='earnsphere-upload-probe-') as directory:
    probe=Path(directory).resolve()/'router.php'
    assert probe.parent==Path(directory).resolve()
    code="""<?php
$token=getenv('EARNSPHERE_UPLOAD_TEST_TOKEN');
if(PHP_SAPI!=='cli-server'||!$token||!hash_equals($token,(string)($_SERVER['HTTP_X_UPLOAD_TEST']??''))){http_response_code(404);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
"""
    code+='require_once '+json.dumps((root/'classes/MarketplaceImages.php').as_posix())+';'
    code+="""
header('Content-Type: application/json');$paths=[];
try{$paths=(new MarketplaceImages())->store($_FILES['photos']??[]);echo json_encode(['success'=>true,'paths'=>$paths]);}
catch(Throwable $e){http_response_code($e instanceof InvalidArgumentException?422:500);echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}
finally{MarketplaceImages::cleanup($paths);}
"""
    probe.write_text(code)
    process=subprocess.Popen([php,'-d','upload_tmp_dir='+directory,'-d','sys_temp_dir='+directory,'-d','upload_max_filesize=6M','-d','post_max_size=25M','-S',f'127.0.0.1:{port}',str(probe)],cwd=root,env=env,stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,creationflags=flags)
    url=f'http://127.0.0.1:{port}/'
    def upload(files):
        assert probe.is_file(),'Temporary upload probe disappeared'
        boundary='----EarnSphere'+secrets.token_hex(10);parts=[]
        for filename,content in files:
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="photos[]"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+b'\r\n')
        body=b''.join(parts)+f'--{boundary}--\r\n'.encode()
        req=urllib.request.Request(url,data=body,headers={'X-Upload-Test':token,'Content-Type':f'multipart/form-data; boundary={boundary}'})
        try:
            with urllib.request.urlopen(req,timeout=15) as response:return response.status,json.loads(response.read())
        except urllib.error.HTTPError as e:return e.code,json.loads(e.read())
    try:
        for _ in range(50):
            try:urllib.request.urlopen(urllib.request.Request(url,headers={'X-Upload-Test':token}),timeout=.3)
            except urllib.error.HTTPError:break
            except (urllib.error.URLError,TimeoutError):time.sleep(.1)
        status,result=upload([('product.png',photo)])
        assert status==200 and result['success'],result
        for path in result['paths']:assert path.endswith('.png') and not (root/path).exists(),'Photo cleanup failed'
        status,result=upload([('image.php',photo)])
        assert status==200 and result['paths'][0].endswith('.png'),result
        status,result=upload([('fake.jpg',b'This is plain text, not an image.')])
        assert status==422 and not result['success'],'Non-image payload accepted as photo'
        status,result=upload([(f'photo-{i}.png',photo) for i in range(5)])
        assert status==422,'More than four photos accepted'
        before=set((root/'uploads/marketplace').glob('*'))
        status,result=upload([('good.png',photo),('bad.png',b'<html>not a photo</html>')])
        assert status==422 and set((root/'uploads/marketplace').glob('*'))==before,'Partial upload left an orphan image'
        print('Multipart photos: validated bytes, safe filenames, limits and failure cleanup passed. No stored test photos remain.')
    finally:
        process.terminate()
        try:process.wait(timeout=5)
        except subprocess.TimeoutExpired:process.kill();process.wait(timeout=5)
        logs=process.stderr.read().decode(errors='replace')
        if sys.exc_info()[0]:print(logs,file=sys.stderr)
