<?php

declare(strict_types=1);

namespace app\domain\order;

use app\exception\BusinessException;

final class FulfillmentPolicy
{
    private const INITIAL = [
        'delivery' => 'waiting_delivery',
        'takeaway' => 'waiting_pickup',
        'dine_in' => 'waiting_arrival',
    ];

    private const TRANSITIONS = [
        'delivery' => ['waiting_delivery' => 'delivering', 'delivering' => 'delivered'],
        'takeaway' => ['waiting_pickup' => 'picked_up'],
        'dine_in' => ['waiting_arrival' => 'seated', 'seated' => 'served', 'served' => 'dine_completed'],
    ];

    public function initialStatus(string $type): string
    {
        return self::INITIAL[$type] ?? throw new BusinessException('履约方式无效', 40002);
    }

    public function assertCanTransition(string $type, string $from, string $to, string $orderStatus): void
    {
        if ((self::TRANSITIONS[$type][$from] ?? null) !== $to) {
            throw new BusinessException('履约状态流转不合法', 40924, 409);
        }
        if (in_array($type, ['delivery', 'takeaway'], true) && !in_array($orderStatus, ['ready', 'fulfilling'], true)) {
            throw new BusinessException('餐点尚未备好，不能开始履约', 40929, 409);
        }
        if ($type !== 'dine_in') return;
        if ($to === 'seated' && !in_array($orderStatus, ['confirmed', 'preparing', 'ready', 'fulfilling'], true)) {
            throw new BusinessException('订单尚未确认，不能安排入座', 40929, 409);
        }
        if ($to === 'served' && !in_array($orderStatus, ['ready', 'fulfilling'], true)) {
            throw new BusinessException('餐点尚未备好，不能确认上菜', 40929, 409);
        }
        if ($to === 'dine_completed' && $orderStatus !== 'fulfilling') {
            throw new BusinessException('订单尚未上菜，不能完成堂食', 40929, 409);
        }
    }

    public function resultingOrderStatus(string $currentStatus, string $target): string
    {
        if (in_array($target, ['delivered', 'picked_up', 'dine_completed'], true)) return 'completed';
        if ($currentStatus === 'ready' && in_array($target, ['delivering', 'seated', 'served'], true)) return 'fulfilling';
        return $currentStatus;
    }
}
