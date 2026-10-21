<?php
declare(strict_types=1);
namespace Recharge;
use Closure;
require_once __DIR__.'/Upstream.php';

final class OrbitcardClient {
    private Closure $transport;
    private Closure $clock;
    private Closure $nonce;
    public function __construct(private array $config,?callable $transport=null,?callable $clock=null,?callable $nonce=null){
        $this->transport=$transport?Closure::fromCallable($transport):Closure::fromCallable([$this,'send']);
        $this->clock=$clock?Closure::fromCallable($clock):fn()=>time();
        $this->nonce=$nonce?Closure::fromCallable($nonce):fn()=>bin2hex(random_bytes(24));
    }
    public function assertReady(bool $create=false):void {
        if(($this->config['enabled']??false)!==true||($create&&($this->config['allow_create']??false)!==true))throw new Fault('充值服务尚未开放，请稍后再试或联系卡密销售方',503);
        foreach(['api_key','secret']as$key)if(!is_string($this->config[$key]??null)||trim($this->config[$key])==='')throw new Fault('充值服务暂未配置完成，请联系卡密销售方',503);
    }
    public function request(string $method,array $payload=[],string $idempotency=''):array {
        $create=$method==='createSubscription';$this->assertReady($create);
        if(!in_array($method,['getSubscriptionPlans','getSubscriptionPaymentRegions','createSubscription','getSubscription'],true))throw new Fault('接口不受支持');
        if($create&&!preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$idempotency))throw new Fault('缺少固定幂等标识');
        $base=rtrim($this->config['base_url']??'https://orbitcard.cc','/');$url=parse_url($base);
        if(($url['scheme']??'')!=='https'||!isset($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||($url['path']??'')!=='')throw new Fault('充值平台地址配置无效',503);
        $host=strtolower($url['host']).(isset($url['port'])?':'.$url['port']:'');$path='/api/open/v1/'.$method;
        $body=json_encode($payload?:new \stdClass(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $timestamp=(string)($this->clock)();$nonce=($this->nonce)();
        if(!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$nonce))throw new Fault('随机签名参数无效',503);
        $canonical=implode("\n",['ORBITCARD-HMAC-SHA256-V1',$this->config['api_key'],$timestamp,$nonce,'POST',$host,$path,'',hash('sha256',$body),$idempotency]);
        $headers=['Content-Type'=>'application/json','Accept'=>'application/json','X-API-Key'=>$this->config['api_key'],'X-Timestamp'=>$timestamp,'X-Nonce'=>$nonce,'X-Signature'=>hash_hmac('sha256',$canonical,$this->config['secret'])];
        if($idempotency!=='')$headers['Idempotency-Key']=$idempotency;
        try{$response=($this->transport)($base.$path,$headers,$body,$create?45:15);}catch(\Throwable){throw new ProviderFailure();}
        $retry=5;$h=array_change_key_case($response['headers']??[],CASE_LOWER);$after=$h['retry-after']??null;
        if(is_string($after))$retry=max($retry,ctype_digit($after)?(int)$after:max(0,(strtotime($after)?:0)-($this->clock)()));
        try{$json=json_decode($response['body']??'',true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new ProviderFailure('充值平台返回异常，请查询原订单',$retry);}
        $error=$json['data']['request_error']??$json['request_error']??[];
        $retry=max($retry,is_numeric($error['retry_after_seconds']??null)?(int)$error['retry_after_seconds']:5);
        $retry=min(PHP_INT_MAX-($this->clock)()-1,$retry);
        if(($response['status']??0)<200||$response['status']>=300||($json['code']??null)!==0||!is_array($json['data']??null)||!empty($error))throw new ProviderFailure('充值平台暂时无法确认结果，请稍后查询原订单',$retry);
        return $json['data'];
    }
    private function send(string $url,array $headers,string $body,int $timeout):array {
        if(!function_exists('curl_init'))throw new ProviderFailure('服务器缺少cURL扩展');
        $ch=curl_init($url);$responseHeaders=[];$responseBody='';
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>array_map(fn($k,$v)=>$k.': '.$v,array_keys($headers),array_values($headers)),CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$timeout,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$responseHeaders){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$responseHeaders[strtolower(trim($k))]=trim($v);}return strlen($line);},
            CURLOPT_WRITEFUNCTION=>function($c,$chunk)use(&$responseBody){if(strlen($responseBody)+strlen($chunk)>1048576)return 0;$responseBody.=$chunk;return strlen($chunk);}
        ]);
        if(curl_exec($ch)===false)throw new ProviderFailure();
        return ['status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'headers'=>$responseHeaders,'body'=>$responseBody];
    }
}
