<?php

declare(strict_types=1);

namespace app\middleware;

use app\domain\auth\AdminPermissionPolicy;
use app\support\ApiResponse;
use Closure;
use think\facade\Db;
use think\Request;

final class AdminAuth
{
    public function __construct(private readonly AdminPermissionPolicy $permission = new AdminPermissionPolicy()) {}

    public function handle(Request $request, Closure $next)
    {
        $authorization = (string)$request->header('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) return ApiResponse::error('请先登录', 40100, 401);
        $auth = Db::name('admin_tokens')->alias('t')->join('admin_users u', 'u.id=t.user_id')
            ->where('t.token_hash', hash('sha256', $matches[1]))->where('t.expires_at', '>', date('Y-m-d H:i:s'))->where('u.status', 'active')
            ->field('u.id,u.username,u.role_code')->find();
        if (!$auth) return ApiResponse::error('登录已失效', 40100, 401);
        $request->admin = $auth;
        if (!$this->permission->allows(
            (string)$auth['role_code'],
            $request->method(),
            $request->pathinfo(),
            (string)$request->post('status', ''),
        )) {
            return ApiResponse::error('没有执行此操作的权限', 40300, 403);
        }
        return $next($request);
    }

}
