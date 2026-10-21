<?php
declare(strict_types=1);
require __DIR__.'/../app/Core.php';
use Recharge\DemoApp;

$passed=0; $failed=0; $clean=[];
function check(bool $value, string $message='断言不满足'): void { if (!$value) throw new RuntimeException($message); }
function rejects(callable $fn): void { try { $fn(); } catch (Throwable) { return; } throw new RuntimeException('应拒绝此操作'); }
function fixture(): array {
    global $clean;
    $dir=sys_get_temp_dir().'/php-redeem-'.bin2hex(random_bytes(8)); mkdir($dir,0700); $clean[]=$dir;
    $clock=(object)['time'=>1800000000]; $now=fn()=>$clock->time;
    return [new DemoApp($dir.'/test.sqlite',$now,2),$clock,$dir,$now];
}
function issue(DemoApp $app,string $scenario='success'): string {
    $rows=$app->generate('plus',1,$scenario); check(count($rows)===1,'应生成一张测试卡密'); return $rows[0]['code'];
}
function run(string $name,callable $fn): void {
    global $passed,$failed;
    try { $fn(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: ".$e->getMessage()."\n"; }
}

run('随机卡密只存哈希，验证不会锁卡',function(){
    [$app,,$dir]=fixture(); $code=issue($app);
    check($app->inspect($code)['status']==='unused');
    check(!str_contains(json_encode($app->cards()),$code));
    check(!str_contains(file_get_contents($dir.'/test.sqlite'),$code));
});
run('无效、禁用、过期卡密拒绝下单',function(){
    [$app,$clock]=fixture(); rejects(fn()=>$app->redeem('bad','demo@example.com'));
    $code=issue($app); $app->disable($app->inspect($code)['id']); rejects(fn()=>$app->redeem($code,'demo@example.com'));
    $code=issue($app); $clock->time+=31*86400; rejects(fn()=>$app->redeem($code,'demo@example.com')); check(count($app->orders())===0);
});
run('重复点击只产生一单，已核销不能再次使用',function(){
    [$app,$clock]=fixture(); $code=issue($app); $order=$app->redeem($code,'demo@example.com');
    rejects(fn()=>$app->redeem($code,'other@example.com')); check(count($app->orders())===1);
    check($order['status']==='processing'); $clock->time+=3;
    check($app->lookup($code)['order']['status']==='succeeded');
    check($app->inspect($code)['status']==='used'); rejects(fn()=>$app->redeem($code,'demo@example.com'));
});
run('受理超时后查询原单，不创建第二个模拟任务',function(){
    [$app,$clock]=fixture(); $code=issue($app,'timeout'); $order=$app->redeem($code,'demo@example.com');
    check($order['status']==='unknown'); $clock->time+=3;
    $result=$app->lookup($order['id']); check($result['order']['status']==='succeeded');
    check($app->db->query('SELECT count(*) FROM mock_tasks')->fetchColumn()===1);
});
run('模拟验证后仍需查单，不直接标记成功',function(){
    [$app,$clock]=fixture(); $code=issue($app,'verification'); $order=$app->redeem($code,'demo@example.com');
    check($order['status']==='awaiting_verification'); $app->verify($order['id']);
    check($app->lookup($order['id'])['order']['status']==='processing'); $clock->time+=3;
    check($app->lookup($code)['order']['status']==='succeeded');
});
run('未知结果保持锁定，经模拟上游确认后仍查询同单',function(){
    [$app,$clock]=fixture(); $code=issue($app,'unknown'); $order=$app->redeem($code,'demo@example.com'); $clock->time+=3600;
    check($app->lookup($code)['order']['status']==='unknown'); rejects(fn()=>$app->restore($order['id']));
    $app->resolve($order['id'],'succeeded'); check($app->lookup($code)['order']['id']===$order['id']);
    check($app->inspect($code)['status']==='used');
});
foreach(['wrong_account','wrong_plan'] as $scenario) run('拒绝错误归属成功 '.$scenario,function()use($scenario){
    [$app,$clock]=fixture(); $code=issue($app,$scenario); $app->redeem($code,'demo@example.com'); $clock->time+=3;
    check($app->lookup($code)['order']['status']==='unknown'); check($app->inspect($code)['status']==='locked');
});
run('明确失败不自动释放，确认无扣款才可恢复并保留历史',function(){
    [$app,$clock]=fixture(); $code=issue($app,'failed'); $order=$app->redeem($code,'demo@example.com'); rejects(fn()=>$app->restore($order['id']));
    $clock->time+=3; check($app->lookup($code)['order']['status']==='failed'); check($app->inspect($code)['status']==='locked');
    $app->restore($order['id']); check($app->inspect($code)['status']==='unused'); check(count($app->orders())===1); rejects(fn()=>$app->restore($order['id']));
});
run('重启后原单、原上游任务与卡密状态仍存在',function(){
    [$app,$clock,$dir,$now]=fixture(); $code=issue($app,'timeout'); $order=$app->redeem($code,'demo@example.com'); unset($app);
    $clock->time+=3; $app=new DemoApp($dir.'/test.sqlite',$now,2);
    check($app->lookup($code)['order']['id']===$order['id']); check($app->inspect($code)['status']==='used');
    check($app->db->query('SELECT count(*) FROM mock_tasks')->fetchColumn()===1);
});
run('拒绝真实邮箱、非法套餐和数量，不写入订单',function(){
    [$app]=fixture(); $code=issue($app); rejects(fn()=>$app->redeem($code,'real@gmail.com'));
    rejects(fn()=>$app->generate('unknown',1)); rejects(fn()=>$app->generate('plus',0));
    check(count($app->orders())===0); check($app->inspect($code)['status']==='unused');
});

foreach($clean as $dir){foreach(glob($dir.'/*') as $file) unlink($file);rmdir($dir);}
echo "\n$passed passed, $failed failed\n"; exit($failed?1:0);
