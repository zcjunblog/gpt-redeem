<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
if(PHP_SAPI!=='cli')exit(1);
$c=local_config();$o=orbitcard_config($c);
if(!in_array('--catalog',$argv,true)){
    echo json_encode(['provider'=>$c['provider'],'network_enabled'=>$o['enabled'],'create_enabled'=>$o['allow_create'],'key_present'=>$o['api_key']!=='','secret_present'=>$o['secret']!=='','card_configured'=>filter_var($o['card_id'],FILTER_VALIDATE_INT)>0,'fee_cap_configured'=>$o['max_service_fee']!=='','live_ready'=>live_ready($c)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
    echo "此检查未发送网络请求。--catalog 会在明确启用网络后查询只读目录，不创建订阅。\n";exit;
}
try{
    $client=new Recharge\OrbitcardClient($o);$data=$client->request('getSubscriptionPlans');
    foreach($data['plans']??[]as$p)echo json_encode(['type'=>$p['type']??null,'name'=>$p['name']??null,'service_fee'=>$p['service_fee']??null,'fee_configured'=>$p['fee_configured']??null],JSON_UNESCAPED_UNICODE)."\n";
}catch(Throwable){fwrite(STDERR,"目录查询未成功，请检查开关、密钥权限与网络。未创建订阅。\n");exit(1);}
