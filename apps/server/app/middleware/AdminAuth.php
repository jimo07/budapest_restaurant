<?php

declare(strict_types=1);

namespace app\middleware;

use app\support\ApiResponse;
use Closure;
use think\facade\Db;
use think\Request;

final class AdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        $authorization = (string)$request->header('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) return ApiResponse::error('请先登录', 40100, 401);
        $auth = Db::name('admin_tokens')->alias('t')->join('admin_users u', 'u.id=t.user_id')
            ->where('t.token_hash', hash('sha256', $matches[1]))->where('t.expires_at', '>', date('Y-m-d H:i:s'))->where('u.status', 'active')
            ->field('u.id,u.username,u.role_code')->find();
        if (!$auth) return ApiResponse::error('登录已失效', 40100, 401);
        $request->admin = $auth;
        if (!$this->allowed((string)$auth['role_code'], $request)) {
            return ApiResponse::error('没有执行此操作的权限', 40300, 403);
        }
        return $next($request);
    }

    private function allowed(string $role, Request $request): bool
    {
        if ($role === 'super_admin') return true;
        $path = strtolower($request->pathinfo());
        $method = strtoupper($request->method());
        if ($method === 'GET' && str_ends_with($path, '/admin/notifications')) return true;
        if ($role === 'order_clerk') {
            return (bool)preg_match('#/admin/(dashboard|orders(?:/.*)?|workbench/.*|reports/orders\.csv)$#', $path);
        }
        if ($role === 'kitchen') {
            if ($method === 'GET' && preg_match('#/admin/workbench/kitchen(?:_orders)?$#', $path)) return true;
            if ($method === 'PATCH' && preg_match('#/admin/orders/\d+/status$#', $path)) {
                return in_array((string)$request->post('status'), ['preparing', 'ready'], true);
            }
            return false;
        }
        if ($role === 'fulfillment') {
            if ($method === 'GET' && preg_match('#/admin/workbench/(delivery|takeaway|dine[_-]in)$#', $path)) return true;
            if ($method === 'GET' && preg_match('#/admin/orders/\d+$#', $path)) return true;
            return $method === 'PATCH' && (bool)preg_match('#/admin/orders/\d+/fulfillment$#', $path);
        }
        return false;
    }
}
