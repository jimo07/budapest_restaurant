<?php

declare(strict_types=1);

namespace app\enum;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Fulfilling = 'fulfilling';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
