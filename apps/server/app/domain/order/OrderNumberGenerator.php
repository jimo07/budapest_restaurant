<?php

declare(strict_types=1);

namespace app\domain\order;

final class OrderNumberGenerator
{
    public function generate(): string
    {
        return date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
    }

    public function fulfillmentCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
