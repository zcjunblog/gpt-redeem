<?php
declare(strict_types=1);
require __DIR__.'/../app/Core.php';
use Recharge\DemoApp;
use Recharge\Database;
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/SqliteMigration.php';

// Requires a local test administrator; every database is created here with a random name.
// A SQLite-only constructor must fail this test: the whole flow must use a real MySQL connection.
$configFile=getenv('TEST_MYSQL_CONFIG')?:'';
if(!is_file($configFile))exit("请设置 TEST_MYSQL_CONFIG 为专用测试MySQL配置文件\n");
$config=require $configFile;$name='redeem_test_'.bin2hex(random_bytes(6));
$admin=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4',$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
$dbConfig=['driver'=>'mysql','host'=>$config['host'],'port'=>$config['port'],'name'=>$name,'user'=>$config['user'],'password'=>$config['password']];
$passed=0;$failed=0;
function verifyMysql(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function mysqlCase(string $name,callable $test):void {global $passed,$failed;try{$test();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: {$e->getMessage()}\n";}}
try{
    // Setup is separate from web traffic; a runtime user need not have CREATE privileges.
    $app=new DemoApp($dbConfig,fn()=>1800000000,0);
    Database::initialize($app->db);
    mysqlCase('InnoDB建表可重复运行，只有哈希入库',function()use($app){
        Database::initialize($app->db);$code=$app->generate('plus',1)[0]['code'];
        verifyMysql($app->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql','实际连接必须为MySQL');
        $r=$app->db->query('SELECT * FROM cards')->fetchAll();verifyMysql(!str_contains(json_encode($r),$code),'不保存明文卡密');
        verifyMysql($app->inspect($code)['status']==='unused','验证不占用卡密');
        verifyMysql($app->db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND engine='InnoDB'")->fetchColumn()===5,'业务表必须使用InnoDB');
    });
    mysqlCase('MySQL七种场景与原单恢复',function()use($app,$dbConfig){
        foreach(['success'=>'succeeded','failed'=>'failed','timeout'=>'unknown','verification'=>'awaiting_verification','unknown'=>'unknown','wrong_account'=>'unknown','wrong_plan'=>'unknown']as$scenario=>$want){
            $code=$app->generate('plus',1,$scenario)[0]['code'];$o=$app->redeem($code,'demo@example.com');
            verifyMysql($o['status']===$want,$scenario.' 首次状态错误');
            $restart=new DemoApp($dbConfig,fn()=>1800000001,0);$result=$restart->lookup($o['id'])['order'];
            if($scenario==='timeout')verifyMysql($result['status']==='succeeded','受理超时后须查回原单');
            if($scenario==='verification'){$restart->verify($o['id']);verifyMysql($restart->lookup($code)['order']['status']==='succeeded','模拟验证后查原单');}
            if($scenario==='unknown'){$restart->resolve($o['id'],'succeeded');verifyMysql($restart->inspect($code)['status']==='used','未知确认后核销原卡');}
            if($scenario==='failed'){$restart->restore($o['id']);verifyMysql($restart->inspect($code)['status']==='unused','明确无扣款才恢复');}
            if(in_array($scenario,['wrong_account','wrong_plan'],true))verifyMysql($restart->inspect($code)['status']==='locked','错归属必须保持锁卡');
        }
        verifyMysql(count($app->cards())===8&&count($app->orders())===7,'列表查询须支持MySQL');
    });
    mysqlCase('多进程同时兑换一张卡仅有一单一次核销',function()use($app,$dbConfig){
        $code=$app->generate('plus',1)[0]['code'];$child= <<<'PHP'
require $argv[1].'/app/Core.php';$c=json_decode(stream_get_contents(STDIN),true);$a=new Recharge\DemoApp($c['db'],null,0);
try{echo json_encode(['order'=>$a->redeem($c['code'],'demo@example.com')]);}catch(Recharge\Fault $e){echo json_encode(['rejected'=>$e->status]);}
PHP;
        $children=[];for($i=0;$i<4;$i++){$p=proc_open([PHP_BINARY,'-r',$child,dirname(__DIR__)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['db'=>$dbConfig,'code'=>$code]));fclose($pipes[0]);$children[]=[$p,$pipes];}
        $ok=0;$rejected=0;foreach($children as[$p,$pipes]){$r=json_decode(stream_get_contents($pipes[1]),true);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);verifyMysql(proc_close($p)===0,'子进程异常 '.$errors);if(isset($r['order']))$ok++;if(($r['rejected']??null)===409)$rejected++;}
        verifyMysql($ok===1&&$rejected===3,'必须一成功三拒绝');verifyMysql($app->inspect($code)['status']==='used','核销应已完成');
        verifyMysql((int)$app->db->query('SELECT count(*) FROM orders')->fetchColumn()===8,'重复请求不能建新单');
        verifyMysql((int)$app->db->query('SELECT count(*) FROM mock_tasks')->fetchColumn()===8,'重复请求不能创建新上游任务');
    });
    mysqlCase('MySQL限流与事务回滚',function()use($app){
        $app->limit('test-limit',1);$blocked=false;try{$app->limit('test-limit',1);}catch(Recharge\Fault $e){$blocked=$e->status===429;}
        verifyMysql($blocked,'超限应拒绝');verifyMysql((int)$app->db->query("SELECT count FROM limits WHERE bucket='test-limit'")->fetchColumn()===1,'失败操作回滚');
    });
    mysqlCase('SQLite迁移保留卡密原单，重复执行安全，拒绝覆盖已有数据',function()use($admin,$dbConfig,$name){
        $target=$name.'_import';$admin->exec("CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
        $dir=sys_get_temp_dir().'/mysql-import-'.bin2hex(random_bytes(6));mkdir($dir,0700);
        try{
            $old=new DemoApp($dir.'/old.sqlite',fn()=>1800000000,0);$code=$old->generate('plus',1,'timeout')[0]['code'];$order=$old->redeem($code,'demo@example.com');
            $old->db->exec('PRAGMA wal_checkpoint(TRUNCATE)');unset($old);$before=hash_file('sha256',$dir.'/old.sqlite');
            $c=$dbConfig;$c['name']=$target;$pdo=Database::connect($c);Database::initialize($pdo);
            $result=Recharge\SqliteMigration::run($dir.'/old.sqlite',$pdo);
            verifyMysql($result['cards']===1&&$result['orders']===1&&$result['mock_tasks']===1,'必须迁移卡、原单、幂等任务');
            verifyMysql(Recharge\SqliteMigration::run($dir.'/old.sqlite',$pdo)===$result,'重复执行不能重复插入');
            verifyMysql(hash_file('sha256',$dir.'/old.sqlite')===$before,'不能修改源库');
            $new=new DemoApp($c,fn()=>1800000001,0);$recovered=$new->lookup($code)['order'];
            verifyMysql($recovered['id']===$order['id']&&$recovered['status']==='succeeded','迁移后继续查询原单并完成核销');
            $blocked=false;try{Recharge\SqliteMigration::run($dir.'/old.sqlite',$pdo);}catch(Throwable){$blocked=true;}
            verifyMysql($blocked&&$new->inspect($code)['status']==='used','目标已变化时拒绝覆盖');
        }finally{$admin->exec("DROP DATABASE `$target`");foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
    });
    mysqlCase('带未合并WAL的SQLite源可以迁移，主文件和WAL不被改写',function()use($admin,$dbConfig,$name){
        $target=$name.'_wal';$admin->exec("CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
        $dir=sys_get_temp_dir().'/mysql-wal-'.bin2hex(random_bytes(6));mkdir($dir,0700);
        try{
            $child= <<<'PHP'
require $argv[1].'/app/Core.php';$a=new Recharge\DemoApp($argv[2]);$a->db->exec('PRAGMA wal_autocheckpoint=0');
echo $a->generate('plus',1)[0]['code']."\n";flush();fgets(STDIN);
PHP;
            $p=proc_open([PHP_BINARY,'-r',$child,dirname(__DIR__),$dir.'/source.sqlite'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $code=trim(fgets($pipes[1]));proc_terminate($p,9);foreach($pipes as$pipe)fclose($pipe);proc_close($p);
            verifyMysql(str_starts_with($code,'DEMO-')&&is_file($dir.'/source.sqlite-wal'),'子进程必须留下已提交WAL');
            $before=[hash_file('sha256',$dir.'/source.sqlite'),hash_file('sha256',$dir.'/source.sqlite-wal')];
            $c=$dbConfig;$c['name']=$target;$pdo=Database::connect($c);Database::initialize($pdo);
            Recharge\SqliteMigration::run($dir.'/source.sqlite',$pdo);
            verifyMysql((new DemoApp($c))->inspect($code)['status']==='unused','必须包含WAL中已提交的卡密');
            verifyMysql(is_file($dir.'/source.sqlite-wal')&&[hash_file('sha256',$dir.'/source.sqlite'),hash_file('sha256',$dir.'/source.sqlite-wal')]===$before,'迁移不得checkpoint或改写源数据文件');
        }finally{$admin->exec("DROP DATABASE `$target`");foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
    });
    mysqlCase('服务器域名与HTTPS必须匹配，旧本机配置仍可用',function(){
        $c=['app_url'=>'https://redeem.example.test'];
        verifyMysql(request_origin($c,['HTTP_HOST'=>'redeem.example.test','HTTPS'=>'on','SERVER_PORT'=>'443'])==='https://redeem.example.test','配置服务器HTTPS域名应可用');
        foreach([['HTTP_HOST'=>'evil.example','HTTPS'=>'on','SERVER_PORT'=>'443'],['HTTP_HOST'=>'redeem.example.test','SERVER_PORT'=>'80']]as$s){$blocked=false;try{request_origin($c,$s);}catch(Recharge\Fault){$blocked=true;}verifyMysql($blocked,'不同Host或HTTPS降级应拒绝');}
        verifyMysql(request_origin([],['HTTP_HOST'=>'127.0.0.1:3100','SERVER_PORT'=>'3100'])==='http://127.0.0.1:3100','本地旧配置保持可用');
    });
}catch(Throwable $e){$failed++;echo 'FAIL MySQL初始化: '.$e->getMessage()."\n";}finally{$admin->exec("DROP DATABASE `$name`");}
echo "$passed passed, $failed failed\n";exit($failed?1:0);
