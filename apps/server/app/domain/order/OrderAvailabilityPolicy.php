<?php

declare(strict_types=1);

namespace app\domain\order;

use app\exception\BusinessException;

final class OrderAvailabilityPolicy
{
    public function assertCapacity(int $used, int $capacity, int $units): void
    {
        if ($units < 1 || $units > 50 || $used + $units > $capacity) {
            throw new BusinessException('预约时段容量不足', 40914, 409);
        }
    }

    public function assertStock(?int $stock, int $sold, int $quantity, string $name): void
    {
        if ($quantity < 1 || $quantity > 99) {
            throw new BusinessException('商品数量必须为 1-99', 40011);
        }
        if ($stock !== null && $sold + $quantity > $stock) {
            throw new BusinessException("商品 {$name} 库存不足", 40916, 409);
        }
    }
}
