<?php
declare(strict_types=1);
require __DIR__.'/../app/Core.php';
use Recharge\DemoApp;
$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/php-http-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$config=['provider'=>'mock','admin_user'=>'admin','admin_hash'=>password_hash('local-test-password',PASSWORD_DEFAULT),'data_dir'=>$dir,'delay'=>1];
$mysqlAdmin=null;$mysqlName=null;
if(getenv('TEST_MYSQL_CONFIG')){
    $m=require getenv('TEST_MYSQL_CONFIG');$mysqlName='redeem_test_'.bin2hex(random_bytes(6));
    $mysqlAdmin=new PDO('mysql:host='.$m['host'].';port='.$m['port'],$m['user'],$m['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $mysqlAdmin->exec("CREATE DATABASE `$mysqlName` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
    $config['database']=$m+['driver'=>'mysql','name'=>$mysqlName];
    $p=Recharge\Database::connect($config['database']);Recharge\Database::initialize($p);unset($p);
}
file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');
$socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,'-t',$root.'/public',$root.'/router.php'],[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,$root,array_merge(getenv(),['REDEEM_CONFIG'=>$dir.'/config.php']));
$base='http://'.$address;$curl=curl_init();curl_setopt($curl,CURLOPT_COOKIEFILE,'');
function req(string $path,?array $body=null,array $headers=[],bool $form=false):array {
    global $base,$curl;
    curl_setopt_array($curl,[CURLOPT_URL=>$base.$path,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>array_merge($form?['Content-Type: application/x-www-form-urlencoded']:['Content-Type: application/json','X-Local-Request: 1'],$headers),CURLOPT_TIMEOUT=>3,CURLOPT_POST=>$body!==null]);
    if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$form?http_build_query($body):json_encode($body));
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);
    return ['status'=>curl_getinfo($curl,CURLINFO_HTTP_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
}
function expect(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$exit=0;
try {
    for($i=0;$i<60;$i++){try{$r=req('/health');break;}catch(Throwable){usleep(50000);}}
    expect(($r['status']??0)===200,'PHP服务健康检查');
    expect(str_contains($r['body'],'8.5.12'),'运行时为PHP8.5.12');
    expect(json_decode($r['body'],true)['database']===($mysqlName?'mysql':'sqlite'),'实际使用配置的数据库引擎');
    $video=req('/media/tutorial.mp4',null,['Range: bytes=0-31']);expect($video['status']===206&&strlen($video['body'])===32&&str_contains(strtolower($video['headers']),'video/mp4'),'教程视频支持Range分段播放');
    expect(req('/media/tutorial.mp4',null,['Range: bytes=999999999-'])['status']===416,'无效视频Range正确拒绝');
    $admin=req('/admin.php');expect(str_contains($admin['body'],'登录后台'),'后台未登录不显示库存');
    expect(str_contains(strtolower($admin['headers']),'referrer-policy: same-origin'),'同源表单保留Origin，跨源不发送Referer');
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$admin['body'],$matches);$csrf=$matches[1]??'';
    expect(req('/admin.php',['action'=>'generate','csrf'=>'bad'],[],true)['status']===403,'后台写入需要有效CSRF');
    expect(req('/admin.php',['action'=>'login','username'=>'admin','password'=>'wrong','csrf'=>$csrf],[],true)['status']===303,'错误登录安全返回');
    req('/admin.php',['action'=>'login','username'=>'admin','password'=>'local-test-password','csrf'=>$csrf],[],true);
    $admin=req('/admin.php');expect(str_contains($admin['body'],'生成卡密'),'后台登录成功');
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$admin['body'],$matches);$csrf=$matches[1]??'';
    req('/admin.php',['action'=>'generate','plan'=>'plus','quantity'=>'1','scenario'=>'success','days'=>'30','csrf'=>$csrf],[],true);
    $admin=req('/admin.php');preg_match('/DEMO-(?:[A-F0-9]{8}-){5}[A-F0-9]{8}/',$admin['body'],$matches);$code=$matches[0]??'';
    expect($code!=='','后台发码仅当次显示完整码');
    expect(req('/api.php',['action'=>'redeem','code'=>$code,'email'=>'demo@example.com','session_json'=>'PRIVATE-CREDENTIAL-MARKER'])['status']===400,'拒绝额外真实凭证字段');
    expect(req('/api.php',['action'=>'inspect','code'=>$code],['Origin: https://evil.example'])['status']===403,'拒绝跨站调用');
    expect(req('/api.php',['action'=>'inspect','code'=>$code],['Origin: null'])['status']===403,'不放行不透明Origin');
    expect(req('/health',null,['Host: evil.example'])['status']===403,'拒绝伪造Host');
    $r=req('/api.php',['action'=>'redeem','code'=>$code,'email'=>'demo@example.com']);expect($r['status']===202,'通过HTTP提交模拟订单');
    $order=json_decode($r['body'],true)['order'];
    expect(req('/api.php',['action'=>'redeem','code'=>$code,'email'=>'demo@example.com'])['status']===409,'重复HTTP提交不能重复建单');
    sleep(2);$result=req('/api.php',['action'=>'lookup','reference'=>$order['id']]);
    expect(json_decode($result['body'],true)['order']['status']==='succeeded','查询原单得到模拟成功');
    expect(!str_contains($result['body'],'idempotency_key')&&!str_contains($result['body'],'demo@example.com'),'响应不透传内部身份或完整邮箱');
    foreach(['/config.local.php','/app/Core.php','/data/app.sqlite','/%2e%2e/config.local.php']as$path)expect(req($path)['status']===404,'公开目录不暴露 '.$path);
    $db=new DemoApp($config['database']??$dir.'/app.sqlite');expect(!str_contains(json_encode($db->db->query('SELECT * FROM orders')->fetchAll()),'PRIVATE-CREDENTIAL-MARKER'),'凭证标记未入库');unset($db);
}catch(Throwable $e){$exit=1;echo 'FAIL '.$e->getMessage()."\n";}finally{
    proc_terminate($process);proc_close($process);
    if($mysqlAdmin)$mysqlAdmin->exec("DROP DATABASE `$mysqlName`");
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as$f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($dir);
}
exit($exit);
