<?php
declare(strict_types=1);
namespace Recharge;
use PDO;
use Throwable;

final class SqliteMigration {
    private const TABLES=['cards'=>'id','orders'=>'id','mock_tasks'=>'id','events'=>'id','limits'=>'bucket'];
    private static function digest(PDO $pdo,string $table,string $key):string {
        $hash=hash_init('sha256');$rows=$pdo->query("SELECT * FROM `$table` ORDER BY `$key`");
        while($row=$rows->fetch(PDO::FETCH_ASSOC)){
            foreach(Database::UPGRADES[$table]??[]as$name=>[$type,$default])if(!array_key_exists($name,$row))$row[$name]=$default;
            ksort($row);foreach($row as&$value)if($value!==null)$value=(string)$value;unset($value);
            hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n");
        }return hash_final($hash);
    }
    // Run with both applications stopped. The source is read-only; all target inserts commit together.
    public static function run(string $source,PDO $target):array {
        if(!is_file($source)||!Database::isMysql($target))throw new Fault('迁移需要已有SQLite文件和MySQL目标库');
        $old=new PDO('sqlite:'.$source,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,\Pdo\Sqlite::ATTR_OPEN_FLAGS=>\Pdo\Sqlite::OPEN_READONLY]);
        $old->exec('PRAGMA query_only=ON');$old->beginTransaction();$target->beginTransaction();
        try{
            $counts=[];$empty=true;$equal=true;
            foreach(self::TABLES as$table=>$key){
                $counts[$table]=(int)$old->query("SELECT count(*) FROM `$table`")->fetchColumn();
                $empty=$empty&&(int)$target->query("SELECT count(*) FROM `$table`")->fetchColumn()===0;
                $equal=$equal&&self::digest($old,$table,$key)===self::digest($target,$table,$key);
            }
            if(!$empty&&!$equal)throw new Fault('目标MySQL已有不同数据，拒绝覆盖；请使用空数据库',409);
            if($empty){
                foreach(self::TABLES as$table=>$key){
                    $rows=$old->query("SELECT * FROM `$table` ORDER BY `$key`");$insert=null;
                    while($row=$rows->fetch()){
                        if($insert===null){$columns=array_map(fn($v)=>'`'.str_replace('`','``',$v).'`',array_keys($row));$insert=$target->prepare("INSERT INTO `$table` (".implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');}
                        $insert->execute(array_values($row));
                    }
                    if(self::digest($old,$table,$key)!==self::digest($target,$table,$key))throw new Fault('迁移校验失败：'.$table,409);
                }
            }
            $target->commit();$old->rollBack();return $counts;
        }catch(Throwable $e){if($target->inTransaction())$target->rollBack();if($old->inTransaction())$old->rollBack();throw $e;}
    }
}
