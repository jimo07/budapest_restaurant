<?php

declare(strict_types=1);

use app\controller\HealthController;
use think\facade\Route;

Route::get('api/v1/health', [HealthController::class, 'index']);