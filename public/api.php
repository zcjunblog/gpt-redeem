<?php
declare(strict_types=1);
use Recharge\Fault;
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')throw new Fault('只接受POST请求',405);
if(($_SERVER['HTTP_X_LOCAL_REQUEST']??'')!=='1')throw new Fault('拒绝非同源请求',403);
if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))throw new Fault('只接受JSON',415);
$body=file_get_contents('php://input');
try{$data=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(JsonException){throw new Fault('JSON格式不正确');}
if(!is_array($data)||array_is_list($data))throw new Fault('请求格式不正确');
$action=$data['action']??'';
$real=local_config()['provider']==='orbitcard';
$fields=['inspect'=>['code'],'redeem'=>$real?['code','email','session_json','authorized']:['code','email'],'lookup'=>['reference'],'verify'=>['id']];
if(!is_string($action)||!isset($fields[$action]))throw new Fault('操作不存在');
if(array_diff(array_keys($data),array_merge(['action'],$fields[$action])))throw new Fault('提交字段无效，请检查后重试');
foreach(array_diff($fields[$action],['session_json','authorized'])as$key)if(!isset($data[$key])||!is_string($data[$key])||strlen($data[$key])>160)throw new Fault('请完整填写订单信息');
if($real&&$action==='redeem'){
    if(!live_ready(local_config()))throw new Fault('充值服务尚未开放，请稍后再试或联系卡密销售方',503);
    if(!is_array($data['session_json']??null)||array_is_list($data['session_json'])||($data['authorized']??null)!==true)throw new Fault('请提交完整登录资料并确认授权');
}
$app=app();$app->limit($action.':'.($_SERVER['REMOTE_ADDR']??'local'),$action==='redeem'?10:120);
$result=match($action){
    'inspect'=>$app->inspect($data['code']),
    'lookup'=>$app->lookup($data['reference']),
    'redeem'=>['order'=>$app->redeem($data['code'],$data['email'],$real?$data['session_json']:null,$real&&$data['authorized']===true)],
    'verify'=>(function()use($app,$data){$app->verify($data['id']);return ['ok'=>true];})(),
};
if($action==='redeem')http_response_code(202);
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
