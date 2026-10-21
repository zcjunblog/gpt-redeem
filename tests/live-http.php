<?php
declare(strict_types=1);
require __DIR__.'/../app/Core.php';require __DIR__.'/../app/OrbitcardUpstream.php';
$dir=sys_get_temp_dir().'/live-http-'.bin2hex(random_bytes(6));mkdir($dir,0700);$root=dirname(__DIR__);
$c=['provider'=>'orbitcard','admin_user'=>'admin','admin_hash'=>password_hash('offline-test-password',PASSWORD_DEFAULT),'data_dir'=>$dir];file_put_contents($dir.'/config.php','<?php return '.var_export($c,true).';');
$old=new Recharge\DemoApp($dir.'/app.sqlite',null,0);$oldCode=$old->generate('plus',1)[0]['code'];$oldOrder=$old->redeem($oldCode,'demo@example.com');
$disabled=new Recharge\OrbitcardUpstream(new Recharge\OrbitcardClient(['enabled'=>false]),[]);$app=new Recharge\DemoApp($dir.'/app.sqlite',null,0,$disabled);$code=$app->generate('plus',1)[0]['code'];
$socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);
$env=array_merge(getenv(),['REDEEM_CONFIG'=>$dir.'/config.php','ORBITCARD_ENABLED'=>'0','ORBITCARD_ALLOW_CREATE'=>'0']);
$p=proc_open([PHP_BINARY,'-S',$address,'-t',$root.'/public',$root.'/router.php'],[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,$root,$env);
function callLive(string $path,?array $body=null):array{global $address;$h=curl_init('http://'.$address.$path);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>$body!==null,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Local-Request: 1'],CURLOPT_TIMEOUT=>3]);if($body!==null)curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body));$out=curl_exec($h);if($out===false)throw new RuntimeException('临时服务尚未就绪');return [curl_getinfo($h,CURLINFO_HTTP_CODE),$out];}
$exit=0;try{
    for($i=0;$i<60;$i++){try{[$status,$out]=callLive('/health');break;}catch(Throwable){usleep(50000);}}
    if($status!==200||json_decode($out,true)['live_enabled']!==false)throw new RuntimeException('未保持关闭');
    [$status,$page]=callLive('/');if($status!==200||!str_contains($page,'完整 Session JSON')||!str_contains($page,'充值服务暂未开放'))throw new RuntimeException('正式表单和关闭提示缺失');
    [$status,$out]=callLive('/api.php',['action'=>'redeem','code'=>$code,'email'=>'target@example.test','session_json'=>['accessToken'=>'SENSITIVE-HTTP-MARKER'],'authorized'=>true]);
    if($status!==503||$app->inspect($code)['status']!=='unused'||count($app->orders())!==1||str_contains($out,'SENSITIVE-HTTP-MARKER'))throw new RuntimeException('关闭时不应锁卡、记录凭证或发起充值');
    [$status,$out]=callLive('/api.php',['action'=>'lookup','reference'=>$oldOrder['id']]);if($status!==200||json_decode($out,true)['order']['mock']!==true)throw new RuntimeException('旧模拟单隔离失败');
    echo "PASS 正式页面/Session表单存在；关闭状态503且不锁卡不创建原单；旧Mock单仍可查\n";
}catch(Throwable $e){$exit=1;echo 'FAIL '.$e->getMessage()."\n";}finally{proc_terminate($p);proc_close($p);unset($old,$app);$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($dir);}exit($exit);
