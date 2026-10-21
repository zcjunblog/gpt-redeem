<?php
declare(strict_types=1);
namespace Recharge;
require_once __DIR__.'/Upstream.php';
require_once __DIR__.'/OrbitcardClient.php';

final class OrbitcardUpstream implements Upstream {
    public function __construct(private OrbitcardClient $client,private array $config){}
    public function name():string{return 'orbitcard';}
    public function prepare(string $plan):array {
        $this->client->assertReady(true);
        $mapping=$this->config['plans'][$plan]??null;$card=filter_var($this->config['card_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$cap=$this->config['max_service_fee']??'';
        if(!$card||!is_array($mapping)||!is_string($mapping['type']??null)||$mapping['type']===''||!is_array($mapping['codes']??null)||count($mapping['codes'])===0||!is_string($cap)||!preg_match('/^\d{1,8}(?:\.\d{1,8})?$/D',$cap))throw new Fault('套餐支付配置尚未完成，请联系卡密销售方',503);
        $catalog=$this->client->request('getSubscriptionPlans');$found=null;
        foreach($catalog['plans']??[]as$p)if(($p['type']??null)===$mapping['type'])$found=$p;
        if(($catalog['enabled']??false)!==true||!$found||($found['fee_configured']??false)!==true)throw new Fault('当前套餐暂不可用，请联系卡密销售方',503);
        $fee=$found['service_fee']??null;
        if(!is_string($fee)||!preg_match('/^\d{1,8}(?:\.\d{1,8})?$/D',$fee)||self::decimal($fee)>self::decimal($cap))throw new Fault('套餐服务配置已变化，请联系卡密销售方',503);
        $regions=$this->client->request('getSubscriptionPaymentRegions',['plan_type'=>$mapping['type']]);
        if(($regions['plan_type']??null)!==$mapping['type'])throw new ProviderFailure();
        $selected=null;$preferred=$mapping['payment_region']??null;
        foreach($regions['payment_regions']??[]as$r)if(is_string($r['code']??null)&&preg_match('/^[A-Z]{2}$/D',$r['code'])&&($preferred?$r['code']===$preferred:($r['default']??false)===true)){$selected=$r['code'];break;}
        // No region is hard-coded; if no default is returned, let Orbitcard choose.
        if($preferred&&$selected===null)throw new Fault('当前支付地区不可用，请联系卡密销售方',503);
        return ['plan_type'=>$mapping['type'],'plan_codes'=>array_values($mapping['codes']),'payment_region'=>$selected,'card_id'=>$card,'max_service_fee'=>$cap];
    }
    private static function decimal(string $v):int {[$whole,$fraction]=array_pad(explode('.',$v,2),2,'');return (int)$whole*100000000+(int)str_pad($fraction,8,'0');}
    public function create(array $order,?array $session=null):?string {
        if(!$session)throw new Fault('请重新准备完整登录资料');
        $s=json_decode($order['request_snapshot'],true,32,JSON_THROW_ON_ERROR);
        $payload=['client_order_no'=>$order['id'],'plan_type'=>$s['plan_type'],'card_id'=>$s['card_id'],'session_json'=>$session,'authorized'=>true,'max_service_fee'=>$s['max_service_fee']];
        if($s['payment_region']!==null)$payload['payment_region']=$s['payment_region'];
        $r=$this->client->request('createSubscription',$payload,$order['idempotency_key']);
        if(($r['client_order_no']??null)!==$order['id']||($r['plan_type']??null)!==$s['plan_type']||!is_string($r['task_no']??null)||strlen($r['task_no'])>64)throw new ProviderFailure();
        return $r['task_no'];
    }
    public function query(array $order):array {
        $r=$this->client->request('getSubscription',['client_order_no'=>$order['id']]);
        $snapshot=json_decode($order['request_snapshot'],true,32,JSON_THROW_ON_ERROR);$id=$r['orderId']??$r['task_no']??null;
        $match=is_string($id)&&$id!==''&&strlen($id)<=64&&(!isset($r['orderId'],$r['task_no'])||$r['orderId']===$r['task_no'])&&(!$order['task_id']||$order['task_id']===$id)&&($r['client_order_no']??null)===$order['id']&&strtolower($r['targetEmail']??'')===$order['email']&&($r['plan_type']??null)===$snapshot['plan_type']&&in_array($r['planCode']??null,$snapshot['plan_codes'],true)&&($r['query_source']??null)==='upstream';
        if(!$match)throw new ProviderFailure('订单信息暂未核对完成，请继续查询原单');
        $status='unknown';$raw=$r['status']??'';
        if(in_array($raw,['pending','processing'],true))$status='processing';
        if($raw==='succeeded'&&($r['subscription_succeeded']??null)===true)$status='succeeded';
        if($raw==='failed'&&array_key_exists('subscription_succeeded',$r)&&$r['subscription_succeeded']===false)$status='failed';
        if($status==='processing'&&($r['stage']??null)==='awaiting_captcha')$status='awaiting_verification';
        $captcha=null;$c=$r['captcha']??null;
        if($status==='awaiting_verification'&&is_array($c)&&is_string($c['id']??null)&&strlen($c['id'])<=128&&($c['status']??null)==='pending'){
            $expires=is_string($c['expires_at']??null)?strtotime($c['expires_at']):false;
            $captcha=['id'=>$c['id'],'url'=>self::safeCaptchaUrl($c['url']??null),'expiresAt'=>$expires?:0];
        }
        return ['id'=>$id,'order_id'=>$order['id'],'email'=>$order['email'],'plan'=>$order['plan'],'status'=>$status,'subscription_succeeded'=>$status==='succeeded','charged'=>null,'captcha'=>$captcha,'subscription_cancelled'=>is_bool($r['subscriptionCancelled']??null)?$r['subscriptionCancelled']:null];
    }
    private static function safeCaptchaUrl(mixed $url):?string {
        if(!is_string($url)||strlen($url)>4096||preg_match('/[\x00-\x20\x7f]/',$url))return null;
        $p=parse_url($url);$host=strtolower($p['host']??'');
        if(($p['scheme']??'')!=='https'||$host===''||isset($p['user'])||isset($p['pass'])||isset($p['port'])&&$p['port']!==443||$host==='localhost'||!str_contains($host,'.'))return null;
        if(filter_var($host,FILTER_VALIDATE_IP)&&!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;
        return $url;
    }
    public function verify(string $id):void {throw new Fault('请在上游验证页面完成验证，再查询原订单');}
}
