<?php

declare(strict_types=1);

namespace app\domain\order;

use app\exception\BusinessException;

final class OrderPriceCalculator
{
    /** @param array<int,array{unit_price:string|int|float,quantity:int}> $items */
    public function calculate(array $items, string|int|float $deliveryFee = 0, string|int|float $discount = 0): array
    {
        if ($items === []) throw new BusinessException('购物车不能为空', 40010);
        $subtotal = 0;
        foreach ($items as $item) {
            $quantity = (int)($item['quantity'] ?? 0);
            if ($quantity < 1 || $quantity > 99) throw new BusinessException('商品数量必须为 1-99', 40011);
            $subtotal += (int)round((float)$item['unit_price'] * 100) * $quantity;
        }
        $fee = (int)round((float)$deliveryFee * 100);
        $off = (int)round((float)$discount * 100);
        if ($fee < 0 || $off < 0 || $off > $subtotal + $fee) throw new BusinessException('金额参数不合法', 40012);
        return [
            'subtotal_amount' => number_format($subtotal / 100, 2, '.', ''),
            'delivery_fee' => number_format($fee / 100, 2, '.', ''),
            'discount_amount' => number_format($off / 100, 2, '.', ''),
            'payable_amount' => number_format(($subtotal + $fee - $off) / 100, 2, '.', ''),
        ];
    }
}
