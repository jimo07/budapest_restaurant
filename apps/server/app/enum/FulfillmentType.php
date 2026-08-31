<?php

declare(strict_types=1);

namespace app\enum;

enum FulfillmentType: string
{
    case Delivery = 'delivery';
    case Takeaway = 'takeaway';
    case DineIn = 'dine_in';
}
