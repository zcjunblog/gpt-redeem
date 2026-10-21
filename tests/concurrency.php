<?php
declare(strict_types=1);
require __DIR__.'/../app/Core.php';
use Recharge\DemoApp;

$passed=0;$failed=0;
function assertConcurrent(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function concurrentCase(string $name,callable $test):void {
    global $passed,$failed;
    $dir=sys_get_temp_dir().'/php-concurrency-'.bin2hex(random_bytes(6));mkdir($dir,0700);
    try{$test($dir);$passed++;echo "PASS $name\n";}
    catch(Throwable $e){$failed++;echo "FAIL $name: {$e->getMessage()}\n";}
    finally{foreach(glob($dir.'/*')as$file)unlink($file);rmdir($dir);}
}
function pendingFixture(string $dir,bool $taskExists):array {
    $app=new DemoApp($dir.'/test.sqlite',null,0);
    $code=$app->generate('plus',1)[0]['code'];$order=$app->redeem($code,'demo@example.com');
    // Reconstruct durable state immediately before/after supplier creation,
    // before the site's processing transition has been persisted.
    $app->db->exec("UPDATE cards SET status='locked'; UPDATE orders SET status='pending',task_id=NULL,charged=NULL,subscription_succeeded=0,reason='';");
    if(!$taskExists)$app->db->exec('DELETE FROM mock_tasks');
    return [$app,$code,$order['id']];
}

foreach([false,true]as$taskExists)concurrentCase('pending崩溃恢复：'.($taskExists?'Mock已持久化':'Mock尚未创建'),function(string $dir)use($taskExists){
    [$app,$code,$id]=pendingFixture($dir,$taskExists);
    $key=$app->db->query('SELECT idempotency_key FROM orders')->fetchColumn();unset($app);
    $app=new DemoApp($dir.'/test.sqlite',null,0);
    $result=$app->lookup($code)['order'];
    assertConcurrent($result['id']===$id&&$result['status']==='succeeded','必须恢复并完成原订单');
    assertConcurrent($app->inspect($code)['status']==='used','恢复成功必须核销原卡');
    assertConcurrent((int)$app->db->query('SELECT count(*) FROM orders')->fetchColumn()===1,'不得创建第二个本站单');
    assertConcurrent((int)$app->db->query('SELECT count(*) FROM mock_tasks')->fetchColumn()===1,'不得创建第二个Mock任务');
    assertConcurrent($app->db->query('SELECT idempotency_key FROM mock_tasks')->fetchColumn()===$key,'恢复必须沿用原幂等身份');
});

concurrentCase('并发恢复不能将另一请求已完成的成功终态回退',function(string $dir){
    [$app,$code,$id]=pendingFixture($dir,false);
    $child= <<<'PHP'
require $argv[1].'/app/Core.php';
$paused=false;
$clock=function()use(&$paused,$argv){
    if(!$paused){
        $paused=true;file_put_contents($argv[3].'/ready','ready');
        $deadline=microtime(true)+8;
        while(!is_file($argv[3].'/release')){if(microtime(true)>$deadline)throw new RuntimeException('barrier timeout');usleep(10000);}
    }
    return time();
};
$app=new Recharge\DemoApp($argv[2],$clock,0);
try{echo json_encode(['order'=>$app->sync($argv[4])],JSON_THROW_ON_ERROR);}
catch(Throwable $e){echo json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR);}
PHP;
    $process=proc_open([PHP_BINARY,'-r',$child,dirname(__DIR__),$dir.'/test.sqlite',$dir,$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    assertConcurrent(is_resource($process),'子进程启动失败');
    try{
        // The injected clock pauses the late request after reading pending,
        // immediately before its supplier INSERT; no SQLite write lock is held.
        $deadline=microtime(true)+5;
        while(!is_file($dir.'/ready')&&microtime(true)<$deadline)usleep(10000);
        assertConcurrent(is_file($dir.'/ready'),'迟到请求未到达同步屏障');
        assertConcurrent($app->sync($id)['status']==='succeeded','先完成请求必须成功');
        file_put_contents($dir.'/release','go');
        $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
        $late=json_decode($output,true,16,JSON_THROW_ON_ERROR);
        $state=$app->db->query('SELECT status FROM orders')->fetchColumn();
        assertConcurrent(($late['order']['status']??null)==='succeeded'&&$state==='succeeded',
            '迟到请求应返回成功且保留成功终态；实际 '.json_encode($late,JSON_UNESCAPED_UNICODE).', persisted='.$state.', stderr='.$errors);
        assertConcurrent($app->lookup($code)['order']['status']==='succeeded','后续原单查询必须保持可用');
        assertConcurrent($app->inspect($code)['status']==='used','原卡必须保持核销');
        assertConcurrent((int)$app->db->query('SELECT count(*) FROM mock_tasks')->fetchColumn()===1,'并发恢复只能创建一个Mock任务');
    }finally{
        file_put_contents($dir.'/release','go');
        foreach($pipes as$pipe)if(is_resource($pipe))fclose($pipe);
        if(proc_get_status($process)['running'])proc_terminate($process);
        proc_close($process);
    }
});
echo "\n$passed passed, $failed failed\n";exit($failed?1:0);
