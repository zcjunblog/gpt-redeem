<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';security_headers();admin_session();
use Recharge\DemoApp;
use Recharge\Fault;
$config=local_config();$app=app();
if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$action=$_POST['action']??'';
    try{
        if($action==='login'){
            $app->limit('login:'.($_SERVER['REMOTE_ADDR']??''),5);
            if(!is_string($_POST['password']??null)||strlen($_POST['password'])>128||($_POST['username']??'')!==$config['admin_user']||!password_verify($_POST['password'],$config['admin_hash']))throw new Fault('用户名或密码不正确');
            session_regenerate_id(true);$_SESSION['authenticated']=true;$_SESSION['expires']=time()+28800;$_SESSION['auth_fingerprint']=hash('sha256',$config['admin_hash']);
        }elseif(!($_SESSION['authenticated']??false)){throw new Fault('请先登录',401);}
        elseif($action==='logout'){$_SESSION=[];session_regenerate_id(true);}
        elseif($action==='generate'){
            $q=filter_var($_POST['quantity']??null,FILTER_VALIDATE_INT);$days=filter_var($_POST['days']??null,FILTER_VALIDATE_INT);
            if($q===false||$days===false||!is_string($_POST['plan']??null)||!is_string($_POST['scenario']??null))throw new Fault('请正确填写生成参数');
            $_SESSION['new_codes']=$app->generate($_POST['plan'],$q,$_POST['scenario'],$days);$_SESSION['flash']='已生成卡密，请复制或导出，完整码只显示一次。';
        }elseif($action==='disable'){$app->disable((string)($_POST['id']??''));$_SESSION['flash']='卡密已禁用。';}
        elseif($action==='sync'){$app->sync((string)($_POST['id']??''));$_SESSION['flash']='已查询原订单。';}
        elseif($action==='resolve'){$app->resolve((string)($_POST['id']??''),(string)($_POST['result']??''));$_SESSION['flash']='已模拟上游最终确认并同步原单。';}
        elseif($action==='restore'){$app->restore((string)($_POST['id']??''));$_SESSION['flash']='已确认原单无扣款并恢复卡密，历史记录保留。';}
        else throw new Fault('操作不存在');
    }catch(Fault $e){$_SESSION['flash']=$e->getMessage();}
    header('Location: /admin.php'.(in_array($action,['sync','resolve','restore'],true)?'?view=orders':''),true,303);exit;
}
$logged=(bool)($_SESSION['authenticated']??false);$flash=$_SESSION['flash']??'';$codes=$_SESSION['new_codes']??[];unset($_SESSION['flash'],$_SESSION['new_codes']);
$view=($_GET['view']??'')==='orders'?'orders':'cards';
function csrf_input():void {echo '<input type="hidden" name="csrf" value="'.esc($_SESSION['csrf']).'">';}
function action_form(string $action,string $id,string $text,string $extra=''):void {echo '<form method="post" class="inline-form">';csrf_input();echo '<input type="hidden" name="action" value="'.esc($action).'"><input type="hidden" name="id" value="'.esc($id).'">'.$extra.'<button class="link-button">'.esc($text).'</button></form>';}
?><!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>卡密与订单 · 管理后台</title><link rel="stylesheet" href="/assets/style.css"><script src="/assets/theme.js" defer></script><script src="/assets/admin.js" defer></script></head><body>
<main class="admin-wrap"><header class="admin-heading"><div><a href="/" class="back-link">← 返回充值页</a><h1>卡密与订单</h1><p><?=esc($config['provider']==='mock'?'当前为测试通道，不会发起实际充值。':(live_ready($config)?'Orbitcard充值通道已开放。':'Orbitcard已配置为目标通道，真实充值尚未开放。'))?></p></div><?php if($logged):?><form method="post"><?php csrf_input();?><input type="hidden" name="action" value="logout"><button class="secondary small">退出登录</button></form><?php endif;?></header>
<?php if($flash):?><p class="notice" role="status"><?=esc($flash)?></p><?php endif;?>
<?php if(!$logged):?>
<section class="panel login-panel"><h2>登录后台</h2><p class="muted">请输入管理员账号管理卡密与订单。</p><form method="post" class="stack"><?php csrf_input();?><input type="hidden" name="action" value="login"><label for="username">用户名</label><input id="username" name="username" value="admin" autocomplete="username" required><label for="password">管理员密码</label><input id="password" name="password" type="password" autocomplete="current-password" maxlength="128" required><button class="primary">登录</button></form></section>
<?php else:?>
<nav class="admin-tabs"><a href="/admin.php" class="<?=$view==='cards'?'selected':''?>">发放卡密</a><a href="/admin.php?view=orders" class="<?=$view==='orders'?'selected':''?>">兑换订单</a></nav>
<?php if($view==='cards'):?>
<section class="panel"><h2>生成卡密</h2><p class="muted">完整卡密仅生成时显示，请及时复制或下载保存。</p><form method="post" class="generate-form"><?php csrf_input();?><input type="hidden" name="action" value="generate"><div><label for="plan">套餐</label><select id="plan" name="plan"><?php foreach(DemoApp::PLANS as$key=>$value):?><option value="<?=esc($key)?>"><?=esc($value)?></option><?php endforeach;?></select></div><?php if($config['provider']==='mock'):?><div><label for="scenario">模拟场景</label><select id="scenario" name="scenario"><?php foreach(DemoApp::SCENARIOS as$key=>$value):?><option value="<?=esc($key)?>"><?=esc($value)?></option><?php endforeach;?></select></div><?php else:?><input type="hidden" name="scenario" value="success"><?php endif;?><div><label for="quantity">数量</label><input id="quantity" name="quantity" type="number" min="1" max="100" value="1" required></div><div><label for="days">有效天数</label><input id="days" name="days" type="number" min="1" max="365" value="30" required></div><button class="primary">生成卡密</button></form>
<?php if($codes):?><section class="new-codes"><div class="row"><label for="new-codes">新生成的卡密</label><button type="button" class="link-button" id="download-codes">下载TXT</button></div><textarea readonly id="new-codes" rows="4"><?=esc(implode("\n",array_column($codes,'code')))?></textarea><p class="muted">刷新后不能再次查看，请及时保存。</p></section><?php endif;?></section>
<section class="panel table-panel"><h2>卡密列表 <small>最近300张</small></h2><div class="table-scroll"><table><thead><tr><th>卡密前缀</th><th>套餐 / 场景</th><th>状态</th><th>有效期至</th><th>操作</th></tr></thead><tbody><?php foreach($app->cards()as$c):?><tr><td class="mono"><?=esc($c['prefix'])?>…</td><td><?=esc(DemoApp::PLANS[$c['plan']])?><small><?=esc($c['provider']==='mock'?'测试 · '.DemoApp::SCENARIOS[$c['scenario']]:'Orbitcard')?></small></td><td><span class="badge <?=esc($c['status'])?>"><?=esc($c['status']==='unused'&&$c['expires_at']<=time()?'已过期':label($c['status']))?></span></td><td><?=esc(date('m-d H:i',(int)$c['expires_at']))?></td><td><?php if($c['status']==='unused')action_form('disable',$c['id'],'禁用');?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php else:?>
<section class="panel table-panel"><h2>兑换订单 <small>最近300笔</small></h2><p class="muted">查询原单更新状态；结果不明时保持锁定。</p><div class="table-scroll"><table><thead><tr><th>订单 / 账号</th><th>场景</th><th>结果</th><th>操作</th></tr></thead><tbody><?php $orders=$app->orders();foreach($orders as$o):?><tr><td><details><summary class="mono"><?=esc(substr($o['id'],0,16))?>…</summary><p class="mono break"><?=esc($o['id'])?></p></details><small><?=esc($o['email'])?></small></td><td><?=esc($o['mock']?'测试 · '.DemoApp::SCENARIOS[$o['scenario']]:'Orbitcard')?></td><td><span class="badge <?=esc($o['status'])?>"><?=esc(label($o['status']))?></span><small><?=esc($o['reason'])?></small><?php if($o['restoredAt']):?><small>卡密资格已恢复</small><?php endif;?></td><td class="actions"><?php action_form('sync',$o['id'],'查原单');if($o['mock']&&$o['status']==='unknown'&&$o['scenario']==='unknown'){action_form('resolve',$o['id'],'模拟成功','<input type="hidden" name="result" value="succeeded">');action_form('resolve',$o['id'],'模拟失败','<input type="hidden" name="result" value="failed">');}if($o['mock']&&$o['status']==='failed'&&!$o['restoredAt']&&$o['charged']===false)action_form('restore',$o['id'],'核实无扣款并恢复');?></td></tr><?php endforeach;if(!$orders):?><tr><td colspan="4" class="empty">暂无订单，使用卡密提交一次模拟充值即可。</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;endif;?>
<footer>PHP <?=esc(PHP_VERSION)?> · <?=esc(Recharge\Database::isMysql($app->db)?'MySQL':'SQLite')?> · <?=esc($config['provider']==='mock'?'测试通道':'Orbitcard')?></footer></main></body></html>
