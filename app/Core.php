<?php
declare(strict_types=1);
namespace Recharge;
use PDO;
use Closure;
use Throwable;
require_once __DIR__.'/Database.php';
require_once __DIR__.'/Upstream.php';

final class Fault extends \RuntimeException {
    public function __construct(string $message, public readonly int $status=400) { parent::__construct($message); }
}
final class DemoApp {
    public readonly PDO $db;
    private Closure $clock;
    private Upstream $upstream;
    private MockUpstream $mock;
    private string $activeProvider;
    public const PLANS=['plus'=>'ChatGPT Plus · 月度订阅','pro'=>'ChatGPT Pro · 月度订阅'];
    public const SCENARIOS=['success'=>'正常成功','failed'=>'明确失败（未扣款）','timeout'=>'提交已受理但超时','verification'=>'等待模拟验证','unknown'=>'结果待核实','wrong_account'=>'账号不匹配','wrong_plan'=>'套餐不匹配'];
    public function __construct(array|string $path, ?callable $now=null, int $delay=6,?Upstream $upstream=null,array $options=[]) {
        $this->clock=$now ? Closure::fromCallable($now) : fn()=>time();
        $this->db=Database::connect($path);
        if(!Database::isMysql($this->db))Database::initialize($this->db);
        $this->mock=new MockUpstream($this->db,$this->clock,$delay);$this->upstream=$upstream??$this->mock;
        $this->activeProvider=$options['provider']??$this->upstream->name();
    }
    private function sql(string $sql,array $values=[]): \PDOStatement { $s=$this->db->prepare($sql);$s->execute($values);return $s; }
    private function tx(callable $fn): mixed {
        $mysql=Database::isMysql($this->db);
        if($mysql)$this->db->beginTransaction();else $this->db->exec('BEGIN IMMEDIATE');
        try {$r=$fn();if($mysql)$this->db->commit();else $this->db->exec('COMMIT');return $r;}
        catch(Throwable $e){if($mysql){if($this->db->inTransaction())$this->db->rollBack();}else $this->db->exec('ROLLBACK');throw $e;}
    }
    private function event(string $action,string $id): void {$this->sql('INSERT INTO events(action,object_id,created_at) VALUES (?,?,?)',[$action,$id,($this->clock)()]);}
    private function card(string $code): array {
        if(strlen($code)>160||trim($code)==='')throw new Fault('请输入完整卡密');
        $c=$this->sql('SELECT * FROM cards WHERE code_hash=?',[hash('sha256',strtoupper(trim($code)))])->fetch();
        return $c?:throw new Fault('卡密不存在，请检查后重试',404);
    }
    private function order(string $id): array {$lock=Database::isMysql($this->db)&&$this->db->inTransaction()?' FOR UPDATE':'';return $this->sql('SELECT * FROM orders WHERE id=?'.$lock,[$id])->fetch()?:throw new Fault('订单不存在',404);}
    private function view(array $r): array {
        [$name,$domain]=explode('@',$r['email']);
        return ['id'=>$r['id'],'plan'=>$r['plan'],'planName'=>self::PLANS[$r['plan']],'email'=>substr($name,0,1).'***@'.$domain,'status'=>$r['status'],'reason'=>$r['reason'],'subscriptionSucceeded'=>(bool)$r['subscription_succeeded'],'createdAt'=>(int)$r['created_at'],'updatedAt'=>(int)$r['updated_at'],'restoredAt'=>$r['restored_at'],'mock'=>$r['provider']==='mock','subscriptionCancelled'=>$r['subscription_cancelled']===null?null:(bool)$r['subscription_cancelled'],'nextPollSeconds'=>max(5,(int)$r['retry_after']-($this->clock)()),'queryError'=>$r['query_error']?'本次提交或查询暂未得到确认，请稍后查询原订单。':null];
    }
    public function generate(string $plan,int $quantity,string $scenario='success',int $days=30): array {
        if(!isset(self::PLANS[$plan]))throw new Fault('套餐不存在');
        if($quantity<1||$quantity>100)throw new Fault('生成数量须为1–100张');
        if($days<1||$days>365)throw new Fault('有效期须为1–365天');
        if(!isset(self::SCENARIOS[$scenario]))throw new Fault('模拟场景不存在');
        return $this->tx(function()use($plan,$quantity,$scenario,$days){
            $rows=[];$now=($this->clock)();
            for($i=0;$i<$quantity;$i++){
                $code=($this->activeProvider==='mock'?'DEMO-':'CDK-').implode('-',str_split(strtoupper(bin2hex(random_bytes(24))),8));$id=bin2hex(random_bytes(16));
                $this->sql('INSERT INTO cards(id,code_hash,prefix,plan,scenario,expires_at,created_at,provider) VALUES (?,?,?,?,?,?,?,?)',[$id,hash('sha256',$code),substr($code,0,13),$plan,$scenario,$now+$days*86400,$now,$this->activeProvider]);
                $rows[]=['id'=>$id,'code'=>$code,'scenario'=>$scenario];
            }$this->event('cards.generated',$plan);return $rows;
        });
    }
    public function inspect(string $code): array {
        $c=$this->card($code);
        return ['id'=>$c['id'],'prefix'=>$c['prefix'],'plan'=>$c['plan'],'planName'=>self::PLANS[$c['plan']],'status'=>$c['status']==='unused'&&$c['expires_at']<=($this->clock)()?'expired':$c['status'],'expiresAt'=>(int)$c['expires_at'],'mock'=>$c['provider']==='mock','order'=>$c['order_id']?$this->view($this->order($c['order_id'])):null];
    }
    public function redeem(string $code,string $email,?array $session=null,bool $authorized=false): array {
        $email=strtolower(trim($email));
        $c=$this->card($code);$provider=$this->activeProvider;
        if($c['provider']!==$provider)throw new Fault('当前卡密不适用于此充值通道，请联系卡密销售方');
        if($c['status']!=='unused'||$c['expires_at']<=($this->clock)())throw new Fault('卡密已使用、已锁定、已禁用或过期；已提交请查询原单',409);
        if($provider==='mock'){
            if($session!==null)throw new Fault('测试模式请使用虚构邮箱，不提交真实登录资料');
            if(strlen($email)>120||!preg_match('/^[a-z0-9._+\-]+@(example\.(com|org|net)|[a-z0-9.-]+\.(test|invalid))$/D',$email))throw new Fault('测试模式只接受虚构邮箱，如 demo@example.com');
            $snapshot=[];
        }else{
            if(!$authorized)throw new Fault('请先确认账号、套餐并授权本次充值');
            self::validateSession($session,$email,($this->clock)());
            $snapshot=$this->upstream->prepare($c['plan']);
        }
        $id=($provider==='mock'?'SIM-':'ORD-').strtoupper(bin2hex(random_bytes(16)));
        $this->tx(function()use($c,$id,$email,$provider,$snapshot){
            $now=($this->clock)();$change=$this->sql("UPDATE cards SET status='locked',order_id=? WHERE id=? AND status='unused' AND expires_at>?",[$id,$c['id'],$now]);
            if($change->rowCount()!==1)throw new Fault('卡密已使用、已锁定、已禁用或过期；已提交请查询原单',409);
            $this->sql('INSERT INTO orders(id,card_id,email,plan,scenario,idempotency_key,created_at,updated_at,provider,request_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,$c['id'],$email,$c['plan'],$c['scenario'],bin2hex(random_bytes(24)),$now,$now,$provider,json_encode($snapshot,JSON_THROW_ON_ERROR)]);$this->event('order.created',$id);
        });
        if($provider==='mock')return $this->sync($id);
        // Claim BEFORE the external POST. Crashes and every lookup only query this client_order_no.
        $claim=$this->sql("UPDATE orders SET status='processing',last_query_at=?,reason='订单已提交，正在等待充值结果' WHERE id=? AND status='pending'",[($this->clock)(),$id]);
        if($claim->rowCount()===1){
            try{$task=$this->upstream->create($this->order($id),$session);$this->sql('UPDATE orders SET task_id=COALESCE(task_id,?) WHERE id=?',[$task,$id]);}
            catch(Throwable $e){$this->markUnknown($id,$e);}
        }
        unset($session);return $this->view($this->order($id));
    }
    private static function validateSession(?array $session,string $email,int $now):void {
        if(!$session||strlen(json_encode($session,JSON_THROW_ON_ERROR))>61440||strlen($email)>120||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new Fault('请粘贴完整有效的Session JSON');
        foreach([['user','id'],['account','id']]as[$a,$b])if(!is_string($session[$a][$b]??null)||$session[$a][$b]==='')throw new Fault('登录资料不完整，请重新获取Session JSON');
        if(!is_string($session['user']['email']??null)||strtolower($session['user']['email'])!==$email)throw new Fault('确认邮箱与登录资料不一致');
        foreach(['accessToken','expires']as$key)if(!is_string($session[$key]??null)||$session[$key]==='')throw new Fault('登录资料不完整，请重新获取Session JSON');
        if((strtotime($session['expires'])?:0)<=$now)throw new Fault('登录资料已过期，请重新登录后获取');
    }
    private function markUnknown(string $id,Throwable $error,?string $token=null):void {
        $retry=$error instanceof ProviderFailure?$error->retryAfter:5;
        $values=[($this->clock)(),($this->clock)()+$retry,$id];if($token!==null)$values[]=$token;
        $waitValues=[($this->clock)()+$retry,$id];if($token!==null)$waitValues[]=$token;
        $this->sql('UPDATE orders SET retry_after=?,query_error=1 WHERE id=?'.($token!==null?' AND query_token=?':''),$waitValues);
        $this->sql("UPDATE orders SET status='unknown',reason='提交结果待核实，请继续查询原订单',updated_at=?,retry_after=? WHERE id=? AND status NOT IN ('succeeded','failed')".($token!==null?' AND query_token=?':''),$values);
    }
    public function sync(string $id): array {
        $row=$this->order($id);$real=$row['provider']==='orbitcard';$gateway=$real?$this->upstream:$this->mock;
        if($real&&$gateway->name()!=='orbitcard')return $this->view($row);
        if(!$real&&in_array($row['status'],['succeeded','failed'],true))return $this->view($row);
        $token=null;if($real){
            $now=($this->clock)();
            $token=bin2hex(random_bytes(16));
            if($this->sql('UPDATE orders SET last_query_at=?,query_until=?,query_token=? WHERE id=? AND last_query_at<=? AND retry_after<=? AND query_until<=?',[$now,$now+20,$token,$id,$now-5,$now,$now])->rowCount()!==1)return $this->view($row);
        }
        try {
            if(!$real&&$row['status']==='pending'){$gateway->create($row);$this->sql("UPDATE orders SET status='processing' WHERE id=? AND status='pending'",[$id]);}
            $result=$gateway->query($row);
        }catch(Throwable $e){
            $this->markUnknown($id,$e,$token);if($real)$this->sql('UPDATE orders SET query_until=0,query_token=NULL WHERE id=? AND query_token=?',[$id,$token]);return $this->view($this->order($id));
        }
        try{return $this->tx(function()use($id,$result,$real,$token){
            $r=$this->order($id);if(!$real&&in_array($r['status'],['succeeded','failed'],true))return $this->view($r);
            if($real&&$r['query_token']!==$token)return $this->view($r);
            $match=$result['order_id']===$id&&$result['email']===$r['email']&&$result['plan']===$r['plan'];$status=$match?$result['status']:'unknown';
            if($status==='succeeded'&&$result['subscription_succeeded']!==true)$status='unknown';
            $reasons=['processing'=>'正在处理充值，请耐心等待','unknown'=>'结果尚未确认，请稍后查询或联系卡密销售方','failed'=>$real?'充值未完成，请联系卡密销售方处理':'模拟接口确认失败，未扣款','awaiting_verification'=>$real?'请完成本轮人机验证后继续查询原单':'请点击下方按钮完成本地模拟验证','succeeded'=>$real?'充值已完成，卡密已核销':'模拟充值已完成，卡密已核销'];
            $reason=$match?$reasons[$status]:'订单、账号或套餐不匹配，已转入待核实';
            $this->sql('UPDATE orders SET status=?,reason=?,task_id=?,charged=?,subscription_succeeded=?,updated_at=?,query_error=0 WHERE id=?',[$status,$reason,$result['id'],$result['charged']===null?null:(int)$result['charged'],(int)($status==='succeeded'),($this->clock)(),$id]);
            if($real)$this->sql('UPDATE orders SET subscription_cancelled=? WHERE id=?',[$result['subscription_cancelled']===null?null:(int)$result['subscription_cancelled'],$id]);
            if($status==='succeeded'&&!$this->cardUsedBy($r['card_id'],$id)&&$this->sql("UPDATE cards SET status='used' WHERE id=? AND order_id=? AND status='locked'",[$r['card_id'],$id])->rowCount()!==1)throw new Fault('卡密订单归属异常',409);
            if($r['status']!==$status)$this->event('order.'.$status,$id);$view=$this->view($this->order($id));
            if($real&&$status==='awaiting_verification'&&($result['captcha']['expiresAt']??0)>($this->clock)())$view['captcha']=$result['captcha'];
            return $view;
        });}finally{if($real)$this->sql('UPDATE orders SET query_until=0,query_token=NULL WHERE id=? AND query_token=?',[$id,$token]);}
    }
    private function cardUsedBy(string $cardId,string $orderId):bool{$card=$this->sql('SELECT status,order_id FROM cards WHERE id=?',[$cardId])->fetch();return $card['status']==='used'&&$card['order_id']===$orderId;}
    public function lookup(string $reference): array {
        $reference=strtoupper(trim($reference));if(strlen($reference)>160)throw new Fault('查询内容过长');
        if(str_starts_with($reference,'SIM-')||str_starts_with($reference,'ORD-'))return ['order'=>$this->sync($reference)];
        $c=$this->card($reference);$view=$this->inspect($reference);if($c['order_id'])$view['order']=$this->sync($c['order_id']);return $view;
    }
    public function verify(string $id): void {$r=$this->order($id);if($r['provider']!=='mock'||$r['status']!=='awaiting_verification')throw new Fault('当前没有可提交的模拟验证任务',409);$this->mock->verify($id);}
    public function resolve(string $id,string $result): void {$r=$this->order($id);if($r['provider']!=='mock'||$r['status']!=='unknown'||$r['scenario']!=='unknown')throw new Fault('仅可处理未知结果模拟场景',409);$this->mock->resolve($id,$result);$this->sync($id);}
    public function restore(string $id): void {
        $this->tx(function()use($id){
            $r=$this->order($id);if($r['provider']!=='mock')throw new Fault('真实充值订单须线下核实支付结果，不支持自动恢复卡密',409);$result=$this->mock->query($r);
            if($r['status']!=='failed'||$r['restored_at']||$result['status']!=='failed'||$result['charged']!==false||$result['email']!==$r['email']||$result['plan']!==$r['plan'])throw new Fault('只有明确失败且确认无扣款的原单才能恢复',409);
            if($this->sql("UPDATE cards SET status='unused',order_id=NULL WHERE id=? AND order_id=? AND status='locked'",[$r['card_id'],$id])->rowCount()!==1)throw new Fault('卡密归属变化，不能恢复',409);
            $this->sql('UPDATE orders SET restored_at=? WHERE id=?',[($this->clock)(),$id]);$this->event('card.restored',$id);
        });
    }
    public function disable(string $id): void {if($this->sql("UPDATE cards SET status='disabled' WHERE id=? AND status='unused'",[$id])->rowCount()!==1)throw new Fault('只能禁用未使用卡密',409);}
    public function cards(): array {return $this->sql('SELECT id,prefix,plan,scenario,status,expires_at,created_at,provider FROM cards ORDER BY created_at DESC,id DESC LIMIT 300')->fetchAll();}
    public function orders(): array {return array_map(fn($o)=>array_merge($this->view($o),['scenario'=>$o['scenario'],'taskId'=>$o['task_id'],'charged'=>$o['charged']===null?null:(bool)$o['charged']]),$this->sql('SELECT * FROM orders ORDER BY created_at DESC,id DESC LIMIT 300')->fetchAll());}
    public function limit(string $bucket,int $maximum): void {
        $this->tx(function()use($bucket,$maximum){$now=($this->clock)();$this->sql('DELETE FROM limits WHERE reset_at<=?',[$now]);$conflict=Database::isMysql($this->db)?'ON DUPLICATE KEY UPDATE count=count+1':'ON CONFLICT(bucket) DO UPDATE SET count=count+1';$this->sql('INSERT INTO limits(bucket,count,reset_at) VALUES (?,1,?) '.$conflict,[$bucket,$now+60]);if($this->sql('SELECT count FROM limits WHERE bucket=?',[$bucket])->fetchColumn()>$maximum)throw new Fault('操作频繁，请一分钟后重试',429);});
    }
}

// Persistent local fake supplier; no HTTP client or payment credentials.
final class MockUpstream implements Upstream {
    public function __construct(private PDO $db,private Closure $clock,private int $delay){}
    private function task(string $id): array {$q=$this->db->prepare('SELECT * FROM mock_tasks WHERE order_id=?');$q->execute([$id]);return $q->fetch()?:throw new Fault('模拟原单不存在',404);}
    public function name():string{return 'mock';}
    public function prepare(string $plan):array{return [];}
    public function create(array $o,?array $session=null):?string {
        $insert=Database::isMysql($this->db)?'INSERT INTO':'INSERT OR IGNORE INTO';$conflict=Database::isMysql($this->db)?' ON DUPLICATE KEY UPDATE id=mock_tasks.id':'';
        $q=$this->db->prepare($insert.' mock_tasks(id,order_id,idempotency_key,email,plan,scenario,ready_at) VALUES (?,?,?,?,?,?,?)'.$conflict);$q->execute(['MOCK-'.bin2hex(random_bytes(16)),$o['id'],$o['idempotency_key'],$o['email'],$o['plan'],$o['scenario'],($this->clock)()+$this->delay]);
        $t=$this->task($o['id']);if($t['idempotency_key']!==$o['idempotency_key']||$t['email']!==$o['email']||$t['plan']!==$o['plan'])throw new Fault('幂等身份冲突',409);
        if($o['scenario']==='timeout')throw new Fault('模拟受理后超时',504);
        return null;
    }
    public function query(array $o): array {
        $t=$this->task($o['id']);$s=($this->clock)()>=$t['ready_at']?($t['scenario']==='failed'?'failed':'succeeded'):'processing';
        if($t['scenario']==='verification'&&!$t['verified'])$s='awaiting_verification';if($t['scenario']==='unknown')$s=$t['resolution']??'unknown';
        return ['id'=>$t['id'],'order_id'=>$t['order_id'],'email'=>$s==='succeeded'&&$t['scenario']==='wrong_account'?'other@example.com':$t['email'],'plan'=>$s==='succeeded'&&$t['scenario']==='wrong_plan'?'bad-plan':$t['plan'],'status'=>$s,'subscription_succeeded'=>$s==='succeeded','charged'=>$s==='succeeded'?true:($s==='failed'?false:null)];
    }
    public function verify(string $id): void {$q=$this->db->prepare("UPDATE mock_tasks SET verified=1,ready_at=? WHERE order_id=? AND scenario='verification' AND verified=0");$q->execute([($this->clock)()+$this->delay,$id]);if($q->rowCount()!==1)throw new Fault('无需重复验证',409);}
    public function resolve(string $id,string $s): void {if(!in_array($s,['succeeded','failed'],true))throw new Fault('结果无效');$q=$this->db->prepare("UPDATE mock_tasks SET resolution=? WHERE order_id=? AND scenario='unknown' AND resolution IS NULL");$q->execute([$s,$id]);if($q->rowCount()!==1)throw new Fault('模拟结果已确认',409);}
}
