<?php

declare(strict_types=1);

namespace app\domain\order;

use app\enum\OrderStatus;
use app\exception\BusinessException;

final class OrderStatusMachine
{
    private const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['fulfilling', 'cancelled'],
        'fulfilling' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function assertCanTransition(string $from, string $to): void
    {
        if (OrderStatus::tryFrom($from) === null || OrderStatus::tryFrom($to) === null) {
            throw new BusinessException('未知订单状态', 40021);
        }
        if (!in_array($to, self::TRANSITIONS[$from], true)) {
            throw new BusinessException("订单不能从 {$from} 流转到 {$to}", 40921, 409);
        }
    }
}
