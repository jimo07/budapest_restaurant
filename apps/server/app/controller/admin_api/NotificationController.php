<?php

declare(strict_types=1);

namespace app\controller\admin_api;

use app\support\ApiResponse;
use think\facade\Db;
use think\Request;

final class NotificationController
{
    public function index(Request $request)
    {
        $since = (string)$request->get('since', date('Y-m-d H:i:s', time() - 60));
        if (!strtotime($since)) $since = date('Y-m-d H:i:s', time() - 60);
        $role = (string)($request->admin['role_code'] ?? '');
        $query = Db::name('orders')->where('updated_at', '>', $since)->where('status', '<>', 'cancelled')->order('updated_at')->limit(50);
        if ($role === 'order_clerk') $query->where('status', 'pending');
        elseif ($role === 'kitchen') $query->whereIn('status', ['confirmed', 'preparing']);
        elseif ($role === 'fulfillment') $query->where(function ($q): void {
            $q->whereIn('status', ['ready', 'fulfilling'])->whereOr(function ($dine): void {
                $dine->where('fulfillment_type', 'dine_in')->whereIn('status', ['confirmed', 'preparing']);
            });
        });
        $rows = $query->field('id,order_no,fulfillment_code,fulfillment_type,status,fulfillment_status,updated_at')->select()->toArray();
        return ApiResponse::success($rows);
    }
}
