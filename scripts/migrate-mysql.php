<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/SqliteMigration.php';
if(PHP_SAPI!=='cli')exit(1);
try{
    $c=local_config();$target=Recharge\Database::connect(database_config($c));
    if(!Recharge\Database::isMysql($target))throw new RuntimeException('目标配置必须为MySQL');
    Recharge\Database::initialize($target);
    $result=Recharge\SqliteMigration::run($argv[1]??$c['data_dir'].'/app.sqlite',$target);
    echo '迁移及逐行SHA256校验完成：'.json_encode($result,JSON_UNESCAPED_UNICODE)."\n旧SQLite文件保持原样。\n";
}catch(Throwable $e){fwrite(STDERR,'迁移失败：'.$e->getMessage()."\n");exit(1);}
