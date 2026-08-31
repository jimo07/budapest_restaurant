<?php

declare(strict_types=1);

namespace app\domain\order;

use app\exception\BusinessException;

final class PaymentPolicy
{
    public function assertCanChange(string $orderStatus, string $currentPayment, string $targetPayment): void
    {
        if (!in_array($targetPayment, ['unpaid', 'paid', 'refunded'], true)) {
            throw new BusinessException('支付状态无效', 40025);
        }
        if ($orderStatus === 'cancelled' && $targetPayment === 'paid') {
            throw new BusinessException('已取消订单不能确认收款', 40926, 409);
        }
        if ($targetPayment === 'refunded' && $currentPayment !== 'paid') {
            throw new BusinessException('只有已支付订单可以退款', 40927, 409);
        }
    }
}
