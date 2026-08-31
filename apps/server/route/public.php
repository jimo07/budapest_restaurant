<?php

declare(strict_types=1);

use app\controller\public_api\MenuController;
use app\controller\public_api\OrderController;
use app\controller\public_api\StoreController;
use app\controller\public_api\SessionController;
use think\facade\Route;

Route::group('api/v1', function (): void {
    Route::get('store', [StoreController::class, 'show']);
    Route::get('sessions', [SessionController::class, 'index']);
    Route::get('sessions/:sessionId/menu', [MenuController::class, 'show']);
    Route::post('orders/preview', [OrderController::class, 'preview']);
    Route::post('orders', [OrderController::class, 'create']);
    Route::get('orders/:orderNo', [OrderController::class, 'show']);
    Route::post('orders/:orderNo/cancel', [OrderController::class, 'cancel']);
});
