<?php

declare(strict_types=1);

namespace app\enum;

enum MealType: string
{
    case Lunch = 'lunch';
    case Dinner = 'dinner';
}
