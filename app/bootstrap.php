<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');
require_once __DIR__.'/Core.php';
require_once __DIR__.'/OrbitcardUpstream.php';
use Recharge\DemoApp;
use Recharge\Fault;

function esc(mixed $value):string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function label(string $state):string {return ['unused'=>'未使用','locked'=>'已锁定','used'=>'已核销','disabled'=>'已禁用','expired'=>'已过期','pending'=>'待提交','processing'=>'处理中','awaiting_verification'=>'待验证','succeeded'=>'已成功','failed'=>'明确失败','unknown'=>'待核实'][$state]??$state;}
function local_config():array {
    if(PHP_VERSION_ID<80512)throw new Fault('请使用项目内 PHP 8.5.12 或更新稳定版本',503);
    $path=getenv('REDEEM_CONFIG')?:dirname(__DIR__).'/config.local.php';
    if(!is_file($path))throw new Fault('请先运行 scripts/setup.php 初始化本地配置',503);
    $c=require $path;
    if(!in_array($c['provider']??'',['mock','orbitcard'],true))throw new Fault('充值通道配置无效',503);
    return $c;
}
function database_config(array $c):array|string {return $c['database']??$c['data_dir'].'/app.sqlite';}
function orbitcard_config(array $c):array {
    $o=$c['orbitcard']??[];
    $o['enabled']=getenv('ORBITCARD_ENABLED')==='1';$o['allow_create']=getenv('ORBITCARD_ALLOW_CREATE')==='1';
    $o['api_key']=getenv('ORBITCARD_API_KEY')?:'';$o['secret']=getenv('ORBITCARD_API_SECRET')?:'';
    $o['card_id']=getenv('ORBITCARD_CARD_ID')?:null;$o['max_service_fee']=getenv('ORBITCARD_MAX_SERVICE_FEE')?:'';
    return $o;
}
function live_ready(array $c):bool {$o=orbitcard_config($c);return $c['provider']==='orbitcard'&&$o['enabled']&&$o['allow_create']&&$o['api_key']!==''&&$o['secret']!==''&&filter_var($o['card_id'],FILTER_VALIDATE_INT)>0&&preg_match('/^\d+(?:\.\d+)?$/D',$o['max_service_fee'])===1;}
function app():DemoApp {static $app=null;$c=local_config();$o=orbitcard_config($c);return $app??=new DemoApp(database_config($c),null,$c['delay']??6,new Recharge\OrbitcardUpstream(new Recharge\OrbitcardClient($o),$o),['provider'=>$c['provider']]);}
function request_origin(array $config,array $server):string {
    $host=$server['HTTP_HOST']??'';$secure=isset($server['HTTPS'])&&$server['HTTPS']!==''&&$server['HTTPS']!=='off';
    $actual=($secure?'https':'http').'://'.$host;$expected=$config['app_url']??'';
    if($expected===''){
        if(!preg_match('/^(127\.0\.0\.1|localhost)(:\d{1,5})?$/D',$host))throw new Fault('请先配置服务器域名 app_url',403);
    }else{
        if(!is_string($expected)||!preg_match('~^https?://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$~D',$expected))throw new Fault('app_url须为不带路径的完整站点地址',503);
        if($expected!==$actual)throw new Fault('请求地址与配置的站点地址不符',403);
    }
    return $actual;
}
function security_headers():void {
    header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; media-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('Cache-Control: no-store');
    $origin=request_origin(local_config(),$_SERVER);
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){
        if(($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site'||isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$origin)throw new Fault('拒绝跨站写请求',403);
        if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)throw new Fault('请求内容过大，请重新获取登录资料',413);
    }
}
function admin_session():void {
    $c=local_config();$dir=$c['data_dir'].'/sessions';if(!is_dir($dir))mkdir($dir,0700,true);
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');session_save_path($dir);session_name('php_redeem_admin');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>str_starts_with(request_origin($c,$_SERVER),'https://'),'httponly'=>true,'samesite'=>'Strict']);session_start();
    $_SESSION['csrf']??=bin2hex(random_bytes(32));
    $fingerprint=hash('sha256',$c['admin_hash']);
    if(($_SESSION['auth_fingerprint']??'')!==$fingerprint||($_SESSION['expires']??0)<=time())unset($_SESSION['authenticated']);
}
function csrf_check():void {if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf']))throw new Fault('表单已失效，请刷新页面重试',403);}
