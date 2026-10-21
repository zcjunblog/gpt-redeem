<?php
declare(strict_types=1);
namespace Recharge;
use PDO;

final class Database {
    public const UPGRADES=[
        'cards'=>['provider'=>["VARCHAR(16) NOT NULL DEFAULT 'mock'",'mock']],
        'orders'=>['provider'=>["VARCHAR(16) NOT NULL DEFAULT 'mock'",'mock'],'request_snapshot'=>['TEXT NULL',null],'last_query_at'=>['BIGINT NOT NULL DEFAULT 0',0],'retry_after'=>['BIGINT NOT NULL DEFAULT 0',0],'subscription_cancelled'=>['TINYINT NULL',null],'query_until'=>['BIGINT NOT NULL DEFAULT 0',0],'query_token'=>['VARCHAR(32) NULL',null],'query_error'=>['TINYINT NOT NULL DEFAULT 0',0]],
    ];
    public static function connect(array|string $config):PDO {
        if(is_string($config))$config=['driver'=>'sqlite','path'=>$config];
        $driver=$config['driver']??'';
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>false];
        if($driver==='mysql'){
            foreach(['host','name','user','password']as$key)if(!is_string($config[$key]??null))throw new Fault('MySQL配置缺少 '.$key,503);
            if(!preg_match('/^[a-zA-Z0-9_.-]+$/D',$config['host'])||!preg_match('/^[a-zA-Z0-9_]+$/D',$config['name']))throw new Fault('MySQL主机或数据库名称无效',503);
            $port=filter_var($config['port']??3306,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
            if($port===false)throw new Fault('MySQL端口无效',503);
            $pdo=new PDO('mysql:host='.$config['host'].';port='.$port.';dbname='.$config['name'].';charset=utf8mb4',$config['user'],$config['password'],$options+[PDO::ATTR_EMULATE_PREPARES=>false]);
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
            return $pdo;
        }
        if($driver!=='sqlite'||!is_string($config['path']??null))throw new Fault('数据库配置仅支持 mysql 或 sqlite',503);
        $path=$config['path'];if($path!==':memory:'&&!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
        $pdo=new PDO('sqlite:'.$path,null,null,$options);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
        return $pdo;
    }
    public static function isMysql(PDO $pdo):bool {return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
    public static function initialize(PDO $pdo):void {
        $file=dirname(__DIR__).'/database/'.(self::isMysql($pdo)?'mysql':'sqlite').'.sql';
        foreach(explode(';',file_get_contents($file))as$sql)if(trim($sql)!=='')$pdo->exec($sql);
        foreach(self::UPGRADES as$table=>$fields){
            $rows=self::isMysql($pdo)?$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll():$pdo->query('PRAGMA table_info('.$table.')')->fetchAll();
            $columns=array_column($rows,self::isMysql($pdo)?'Field':'name');
            foreach($fields as$name=>[$type,$default])if(!in_array($name,$columns,true))$pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` $type");
        }
    }
}
