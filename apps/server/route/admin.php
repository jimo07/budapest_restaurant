<?php

declare(strict_types=1);

use app\controller\admin_api\AuthController;
use app\controller\admin_api\AdminUserController;
use app\controller\admin_api\CategoryController;
use app\controller\admin_api\DashboardController;
use app\controller\admin_api\DeliveryZoneController;
use app\controller\admin_api\OrderController;
use app\controller\admin_api\ProductController;
use app\controller\admin_api\ReportController;
use app\controller\admin_api\LogController;
use app\controller\admin_api\NotificationController;
use app\controller\admin_api\SessionController;
use app\controller\admin_api\SettingController;
use app\controller\admin_api\TableController;
use app\controller\admin_api\TimeSlotController;
use app\middleware\AdminAuth;
use app\middleware\OperationAudit;
use think\facade\Route;

Route::post('api/v1/admin/auth/login', [AuthController::class, 'login']);
Route::group('api/v1/admin', function (): void {
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('workbench/:type', [DashboardController::class, 'workbench']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/:id', [OrderController::class, 'show']);
    Route::patch('orders/:id/status', [OrderController::class, 'status']);
    Route::patch('orders/:id/fulfillment', [OrderController::class, 'fulfillment']);
    Route::patch('orders/:id/payment', [OrderController::class, 'payment']);
    Route::patch('orders/:id/reschedule', [OrderController::class, 'reschedule']);
    Route::post('orders/batch-status', [OrderController::class, 'batchStatus']);
    Route::get('reports/orders.csv', [ReportController::class, 'orders']);
    Route::get('users', [AdminUserController::class, 'index']);
    Route::post('users', [AdminUserController::class, 'save']);
    Route::put('users/:id', [AdminUserController::class, 'update']);
    Route::get('logs', [LogController::class, 'index']);
    Route::get('settings', [SettingController::class, 'index']);
    Route::put('settings', [SettingController::class, 'update']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::resource('categories', CategoryController::class)->except(['create', 'edit']);
    Route::resource('products', ProductController::class)->except(['create', 'edit']);
    Route::resource('sessions', SessionController::class)->except(['create', 'edit']);
    Route::put('sessions/:id/products', [SessionController::class, 'products']);
    Route::resource('time-slots', TimeSlotController::class)->except(['create', 'edit']);
    Route::resource('delivery-zones', DeliveryZoneController::class)->except(['create', 'edit']);
    Route::resource('tables', TableController::class)->except(['create', 'edit']);
})->middleware([AdminAuth::class, OperationAudit::class]);
