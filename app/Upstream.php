<?php
declare(strict_types=1);
namespace Recharge;

interface Upstream {
    public function name():string;
    public function prepare(string $plan):array;
    public function create(array $order,?array $session=null):?string;
    public function query(array $order):array;
    public function verify(string $id):void;
}
final class ProviderFailure extends \RuntimeException {
    public function __construct(string $message='充值平台暂时无法确认结果，请稍后查询原订单',public readonly int $retryAfter=5){parent::__construct($message);}
}
