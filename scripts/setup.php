<?php
declare(strict_types=1);
require __DIR__.'/../app/Core.php';
if(PHP_VERSION_ID<80512)exit("请使用 PHP 8.5.12 或更新稳定版本\n");
umask(0077);$root=dirname(__DIR__);$dir=$root.'/data';if(!is_dir($dir))mkdir($dir,0700,true);
$configPath=getenv('REDEEM_CONFIG')?:$root.'/config.local.php';
if(!is_file($configPath)){
    $password=bin2hex(random_bytes(12));$config=['provider'=>'mock','admin_user'=>'admin','admin_hash'=>password_hash($password,PASSWORD_DEFAULT),'data_dir'=>$dir,'delay'=>6];
    file_put_contents($configPath,'<?php return '.var_export($config,true).";\n",LOCK_EX);
    file_put_contents($dir.'/local-login.txt',"本地后台：http://127.0.0.1:3100/admin.php\n用户名：admin\n密码：$password\n");
}
$config=require $configPath;$dir=$config['data_dir'];if(!is_dir($dir))mkdir($dir,0700,true);
if(!in_array($config['provider'],['mock','orbitcard'],true))exit("充值通道配置无效\n");
$app=new \Recharge\DemoApp($config['database']??$config['data_dir'].'/app.sqlite');
\Recharge\Database::initialize($app->db);
if($config['provider']==='mock'&&!is_file($dir.'/demo-codes.txt')&&!$app->db->query('SELECT count(*) FROM cards')->fetchColumn()){
    $lines=["# 本地模拟卡密，不产生真实订阅或扣款。虚构邮箱 demo@example.com",''];
    foreach(\Recharge\DemoApp::SCENARIOS as$key=>$label){$lines[]='## '.$label;foreach($app->generate('plus',2,$key)as$c)$lines[]=$c['code'];$lines[]='';}
    file_put_contents($dir.'/demo-codes.txt',implode("\n",$lines));
}
echo "初始化完成（不会覆盖已有配置或数据）。\n登录信息：$dir/local-login.txt\n演示卡密：$dir/demo-codes.txt\n";
