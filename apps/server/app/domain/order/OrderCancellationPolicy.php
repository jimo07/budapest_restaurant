<?php

declare(strict_types=1);

namespace app\domain\order;

use app\exception\BusinessException;

final class OrderCancellationPolicy
{
    public function assertCustomerCanCancel(string $status): void
    {
        if (!in_array($status, ['pending', 'confirmed'], true)) {
            throw new BusinessException('餐厅已开始制作，订单不能由顾客取消', 40922, 409);
        }
    }
}
