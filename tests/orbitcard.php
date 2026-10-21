<?php
declare(strict_types=1);
require __DIR__.'/../app/Core.php';
foreach(['OrbitcardClient','OrbitcardUpstream']as$f)if(is_file(__DIR__.'/../app/'.$f.'.php'))require_once __DIR__.'/../app/'.$f.'.php';
use Recharge\OrbitcardClient;
use Recharge\OrbitcardUpstream;
use Recharge\DemoApp;
$passed=0;$failed=0;$cleanup=[];
function ok(bool $v,string $why='断言失败'):void{if(!$v)throw new RuntimeException($why);}
function denied(callable $f):void{try{$f();}catch(Throwable){return;}throw new RuntimeException('应拒绝');}
function scenario(string $name,callable $f):void{global $passed,$failed;try{$f();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: {$e->getMessage()}\n";}}
function ocConfig():array{return ['enabled'=>true,'allow_create'=>true,'api_key'=>'test-api-key','secret'=>'test-secret','card_id'=>123,'max_service_fee'=>'0.20','plans'=>['plus'=>['type'=>'gpt-plus','codes'=>['chatgptplusplan']]],'base_url'=>'https://orbitcard.cc'];}
function targetSession():array{return ['user'=>['id'=>'user-test','email'=>'target@example.test'],'account'=>['id'=>'account-test'],'accessToken'=>'SENSITIVE-ACCESS-MARKER','sessionToken'=>'SENSITIVE-SESSION-MARKER','expires'=>'2032-11-01T09:34:59Z','extra'=>['preserve'=>true]];}
function fixtureGateway(array &$calls,?callable $detail=null,?array $config=null,bool $timeout=false):OrbitcardUpstream{
    $transport=function($url,$headers,$body,$requestTimeout)use(&$calls,$detail,$timeout){
        $payload=json_decode($body,true);$calls[]=['url'=>$url,'headers'=>$headers,'body'=>$body,'payload'=>$payload];$path=parse_url($url,PHP_URL_PATH);
        if($timeout&&$path==='/api/open/v1/createSubscription')throw new RuntimeException('SENSITIVE-TIMEOUT-MARKER');
        $data=match($path){
            '/api/open/v1/getSubscriptionPlans'=>['enabled'=>true,'plans'=>[['type'=>'gpt-plus','service_fee'=>'0.15','fee_configured'=>true]]],
            '/api/open/v1/getSubscriptionPaymentRegions'=>['plan_type'=>'gpt-plus','payment_regions'=>[['code'=>'PH','default'=>true]]],
            '/api/open/v1/createSubscription'=>['task_no'=>'SUB-TEST','client_order_no'=>$payload['client_order_no'],'plan_type'=>'gpt-plus','status'=>'succeeded','subscription_succeeded'=>true],
            '/api/open/v1/getSubscription'=>$detail?$detail($payload):['orderId'=>'SUB-TEST','task_no'=>'SUB-TEST','client_order_no'=>$payload['client_order_no'],'status'=>'succeeded','upstream_status'=>'succeeded','subscription_succeeded'=>true,'targetEmail'=>'target@example.test','plan_type'=>'gpt-plus','planCode'=>'chatgptplusplan','query_source'=>'upstream','subscriptionCancelled'=>null,'session'=>targetSession()],
            default=>throw new RuntimeException('意外路径'),
        };
        return ['status'=>200,'headers'=>[],'body'=>json_encode(['code'=>0,'data'=>$data])];
    };
    return new OrbitcardUpstream(new OrbitcardClient($config??ocConfig(),$transport,fn()=>1800000000,fn()=>'0123456789abcdef0123456789abcdef'),$config??ocConfig());
}
function appFixture(OrbitcardUpstream $gateway):array{
    global $cleanup;$dir=sys_get_temp_dir().'/orbitcard-'.bin2hex(random_bytes(6));mkdir($dir,0700);$cleanup[]=$dir;
    $time=(object)['now'=>1800000000];return [new DemoApp($dir.'/app.sqlite',fn()=>$time->now,0,$gateway),$time,$dir];
}
scenario('十行签名独立向量与实际发送字节一致',function(){
    $calls=[];$client=new OrbitcardClient(ocConfig(),function($url,$headers,$body,$timeout)use(&$calls){$calls=[$url,$headers,$body];return ['status'=>200,'headers'=>[],'body'=>'{"code":0,"data":{}}'];},fn()=>1800000000,fn()=>'0123456789abcdef0123456789abcdef');
    $client->request('createSubscription',['client_order_no'=>'ORD-TEST','authorized'=>true],'idem-order-test-123');
    ok($calls[2]==='{"client_order_no":"ORD-TEST","authorized":true}');ok($calls[1]['X-Signature']==='84766df10de089edaf8d61e7bce317011aa607766b20ea3ab24f0387d5d6f1c2');
    ok($calls[1]['Idempotency-Key']==='idem-order-test-123');
});
scenario('真实调用双开关和缺失密钥在传输前拦截',function(){
    foreach([['enabled'=>false],['allow_create'=>false],['secret'=>'']]as$override){$calls=[];$g=fixtureGateway($calls,null,array_replace(ocConfig(),$override));denied(fn()=>$g->prepare('plus'));ok(count($calls)===0,'禁用时不能发起任何网络');}
});
scenario('目录地区按套餐读取，完整Session只随单次提交发送，成功摘要不核销',function(){
    $calls=[];$g=fixtureGateway($calls);[$a,$time,$dir]=appFixture($g);$code=$a->generate('plus',1)[0]['code'];
    $o=$a->redeem($code,'target@example.test',targetSession(),true);ok($o['status']==='processing','创建摘要成功不能当最终成功');
    $creates=array_values(array_filter($calls,fn($c)=>str_ends_with($c['url'],'createSubscription')));ok(count($creates)===1);$p=$creates[0]['payload'];
    ok($p['payment_region']==='PH'&&$p['card_id']===123&&$p['session_json']['extra']['preserve']===true&&$p['authorized']===true);
    ok(!str_contains(json_encode($a->db->query('SELECT * FROM orders')->fetchAll()),'SENSITIVE-'),'Session不能入库');
    $time->now+=6;$result=$a->lookup($o['id'])['order'];ok($result['status']==='succeeded'&&$a->inspect($code)['status']==='used');
    ok(!str_contains(json_encode($result),'SENSITIVE-')&&!array_key_exists('session',$result));ok($result['subscriptionCancelled']===null);
    denied(fn()=>$a->redeem($code,'target@example.test',targetSession(),true));
});
scenario('超时和重启只查询原标识，绝不再次POST或释放卡密',function(){
    $calls=[];$g=fixtureGateway($calls,fn($p)=>['orderId'=>'SUB-TEST','client_order_no'=>$p['client_order_no'],'status'=>'failed','task_status'=>'failed','subscription_succeeded'=>null,'query_source'=>'snapshot']);
    [$a,$time,$dir]=appFixture($g);$code=$a->generate('plus',1)[0]['code'];$o=$a->redeem($code,'target@example.test',targetSession(),true);$time->now+=6;
    $a->lookup($o['id']);$a=new DemoApp($dir.'/app.sqlite',fn()=>$time->now,0,$g);$time->now+=6;
    ok($a->lookup($o['id'])['order']['status']==='unknown');ok($a->inspect($code)['status']==='locked');denied(fn()=>$a->restore($o['id']));
    ok(count(array_filter($calls,fn($c)=>str_ends_with($c['url'],'createSubscription')))===1);
});
scenario('真实创建传输超时后只查询原单，不二次发送完整Session',function(){
    $calls=[];$g=fixtureGateway($calls,null,null,true);[$a,$t,$dir]=appFixture($g);$code=$a->generate('plus',1)[0]['code'];
    $o=$a->redeem($code,'target@example.test',targetSession(),true);ok($o['status']==='unknown'&&$a->inspect($code)['status']==='locked');
    $a=new DemoApp($dir.'/app.sqlite',fn()=>$t->now,0,$g);$t->now+=6;ok($a->lookup($o['id'])['order']['status']==='succeeded');
    ok(count(array_filter($calls,fn($r)=>str_ends_with($r['url'],'createSubscription')))===1);ok(!str_contains(json_encode($a->orders()),'SENSITIVE-'));
});
scenario('真实通道关闭时卡密保持未使用，已有测试卡不能转为真实充值',function(){
    $calls=[];$g=fixtureGateway($calls,null,array_replace(ocConfig(),['enabled'=>false]));[$a]=appFixture($g);$code=$a->generate('plus',1)[0]['code'];denied(fn()=>$a->redeem($code,'target@example.test',targetSession(),true));ok($a->inspect($code)['status']==='unused'&&count($a->orders())===0&&count($calls)===0);
});
scenario('错误账号、套餐或客户单号不能误判成功',function(){
    foreach(['targetEmail'=>'other@example.test','planCode'=>'wrong-plan','client_order_no'=>'OTHER']as$field=>$bad){$calls=[];$g=fixtureGateway($calls,fn($p)=>array_replace(['orderId'=>'SUB-TEST','client_order_no'=>$p['client_order_no'],'status'=>'succeeded','subscription_succeeded'=>true,'targetEmail'=>'target@example.test','plan_type'=>'gpt-plus','planCode'=>'chatgptplusplan','query_source'=>'upstream'],[$field=>$bad]));[$a,$t]=appFixture($g);$code=$a->generate('plus',1)[0]['code'];$o=$a->redeem($code,'target@example.test',targetSession(),true);$t->now+=6;ok($a->lookup($o['id'])['order']['status']==='unknown');ok($a->inspect($code)['status']==='locked');}
});
scenario('业务错误与Retry-After安全映射，不透传凭证错误原文',function(){
    $c=new OrbitcardClient(ocConfig(),fn()=>['status'=>429,'headers'=>['retry-after'=>'12'],'body'=>'{"code":59018,"msg":"SENSITIVE-MARKER","data":{"request_error":{"retry_after_seconds":20}}}']);
    try{$c->request('getSubscription',['client_order_no'=>'ORD-TEST']);throw new RuntimeException('应抛协议错误');}catch(Recharge\ProviderFailure $e){ok($e->retryAfter===20);ok(!str_contains($e->getMessage(),'SENSITIVE'));}
});
scenario('已确认成功后的查单失败明确展示历史结果，并持续遵守600秒退避',function(){
    $calls=[];$fail=false;$detail=function($p)use(&$fail){if($fail)throw new RuntimeException('private-network-error');return ['orderId'=>'SUB-TEST','client_order_no'=>$p['client_order_no'],'status'=>'succeeded','subscription_succeeded'=>true,'targetEmail'=>'target@example.test','plan_type'=>'gpt-plus','planCode'=>'chatgptplusplan','query_source'=>'upstream'];};
    $g=fixtureGateway($calls,$detail);[$a,$t]=appFixture($g);$code=$a->generate('plus',1)[0]['code'];$o=$a->redeem($code,'target@example.test',targetSession(),true);$t->now+=6;ok($a->lookup($o['id'])['order']['status']==='succeeded');
    $fail=true;$t->now+=6;$o=$a->lookup($o['id'])['order'];ok($o['status']==='succeeded'&&!empty($o['queryError']),'保留历史成功但须标明本次查询失败');
    $t->now+=1;ok(!empty($a->lookup($o['id'])['order']['queryError']),'退避期间不能把旧结果包装成新查询成功');
    $client=new OrbitcardClient(ocConfig(),fn()=>['status'=>429,'headers'=>['retry-after'=>'600'],'body'=>'{"code":59018,"data":{}}']);
    try{$client->request('getSubscription',['client_order_no'=>'ORD-TEST']);throw new RuntimeException('应拒绝');}catch(Recharge\ProviderFailure $e){ok($e->retryAfter===600,'不得提前截断服务端退避时间');}
});
scenario('完整Session可不含示例中的sessionToken，扩展字段仍原样发送',function(){
    $calls=[];$g=fixtureGateway($calls);[$a]=appFixture($g);$code=$a->generate('plus',1)[0]['code'];$s=targetSession();unset($s['sessionToken']);
    $o=$a->redeem($code,'target@example.test',$s,true);ok($o['status']==='processing');$c=array_values(array_filter($calls,fn($r)=>str_ends_with($r['url'],'createSubscription')))[0]['payload']['session_json'];ok(!array_key_exists('sessionToken',$c)&&$c['extra']['preserve']===true);
});
scenario('同一原单查单未结束时其他请求不能重复查询',function(){
    $calls=[];$nested=null;$a=null;$t=null;$entered=false;
    $g=fixtureGateway($calls,function($p)use(&$a,&$t,&$entered,&$nested){if(!$entered){$entered=true;$t->now+=6;$nested=$a->lookup($p['client_order_no']);}return ['orderId'=>'SUB-TEST','client_order_no'=>$p['client_order_no'],'status'=>'succeeded','subscription_succeeded'=>true,'targetEmail'=>'target@example.test','plan_type'=>'gpt-plus','planCode'=>'chatgptplusplan','query_source'=>'upstream'];});
    [$a,$t]=appFixture($g);$code=$a->generate('plus',1)[0]['code'];$o=$a->redeem($code,'target@example.test',targetSession(),true);$t->now+=6;
    ok($a->lookup($o['id'])['order']['status']==='succeeded');ok($nested['order']['status']==='processing');ok(count(array_filter($calls,fn($c)=>str_ends_with($c['url'],'getSubscription')))===1);
});
scenario('人机验证链接保留片段、只临时返回、不写库，非法协议清除',function(){
    foreach(['https://verify.example.com/verify/a#access=TICKET-MARKER','javascript:alert(1)']as$url){$calls=[];$g=fixtureGateway($calls,fn($p)=>['orderId'=>'SUB-TEST','client_order_no'=>$p['client_order_no'],'status'=>'processing','subscription_succeeded'=>null,'targetEmail'=>'target@example.test','plan_type'=>'gpt-plus','planCode'=>'chatgptplusplan','query_source'=>'upstream','stage'=>'awaiting_captcha','captcha'=>['id'=>'cap-1','status'=>'pending','url'=>$url,'expires_at'=>'2032-11-01T09:34:59Z']]);[$a,$t]=appFixture($g);$code=$a->generate('plus',1)[0]['code'];$o=$a->redeem($code,'target@example.test',targetSession(),true);$t->now+=6;$o=$a->lookup($o['id'])['order'];ok($o['status']==='awaiting_verification');ok(($o['captcha']['url']??null)===(str_starts_with($url,'https:')?$url:null));ok(!str_contains(json_encode($a->db->query('SELECT * FROM orders')->fetchAll()),'TICKET-MARKER'));}
});
scenario('旧Mock单在真实Provider配置下仍走Mock',function(){
    $calls=[];$g=fixtureGateway($calls);global $cleanup;$dir=sys_get_temp_dir().'/orbitcard-old-'.bin2hex(random_bytes(6));mkdir($dir,0700);$cleanup[]=$dir;
    $a=new DemoApp($dir.'/old.sqlite',fn()=>1800000000,0);$code=$a->generate('plus',1)[0]['code'];$o=$a->redeem($code,'demo@example.com');$a=new DemoApp($dir.'/old.sqlite',fn()=>1800000001,0,$g);ok($a->lookup($o['id'])['order']['status']==='succeeded');ok(count($calls)===0);
});
foreach($cleanup as$dir){foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}echo "$passed passed, $failed failed\n";exit($failed?1:0);
