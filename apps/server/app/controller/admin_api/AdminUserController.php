<?php

declare(strict_types=1);

namespace app\controller\admin_api;

use app\support\ApiResponse;
use think\facade\Db;
use think\Request;

final class AdminUserController
{
    private const ROLES = ['super_admin', 'order_clerk', 'kitchen', 'fulfillment'];

    public function index(Request $request)
    {
        $query = Db::name('admin_users')->field('id,username,role_code,status,last_login_at,created_at,updated_at')->order('id');
        $keyword = trim((string)$request->get('keyword', ''));
        if ($keyword !== '') $query->whereLike('username', '%' . $keyword . '%');
        return ApiResponse::success($query->paginate(['list_rows' => min(100, max(1, (int)$request->get('page_size', 50))), 'page' => max(1, (int)$request->get('page', 1))])->toArray());
    }

    public function save(Request $request)
    {
        $username = trim((string)$request->post('username'));
        $password = (string)$request->post('password');
        $role = (string)$request->post('role_code');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) return ApiResponse::error('用户名需为3–60位字母、数字或 ._-', 40030, 422);
        if (strlen($password) < 8) return ApiResponse::error('密码至少8位', 40031, 422);
        if (!in_array($role, self::ROLES, true)) return ApiResponse::error('角色无效', 40032, 422);
        if (Db::name('admin_users')->where('username', $username)->find()) return ApiResponse::error('用户名已存在', 40930, 409);
        $id = Db::name('admin_users')->insertGetId(['username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role_code' => $role, 'status' => 'active']);
        return ApiResponse::success(Db::name('admin_users')->field('id,username,role_code,status,last_login_at,created_at')->where('id', $id)->find());
    }

    public function update(Request $request, int $id)
    {
        $user = Db::name('admin_users')->where('id', $id)->find();
        if (!$user) return ApiResponse::error('管理员不存在', 40430, 404);
        $role = (string)$request->post('role_code', $user['role_code']);
        $status = (string)$request->post('status', $user['status']);
        if (!in_array($role, self::ROLES, true) || !in_array($status, ['active', 'inactive'], true)) return ApiResponse::error('角色或状态无效', 40032, 422);
        if ($id === (int)$request->admin['id'] && ($status !== 'active' || $role !== 'super_admin')) return ApiResponse::error('不能停用当前账号或移除自己的超级管理员权限', 40931, 409);
        $changes = ['role_code' => $role, 'status' => $status];
        $password = (string)$request->post('password', '');
        if ($password !== '') {
            if (strlen($password) < 8) return ApiResponse::error('密码至少8位', 40031, 422);
            $changes['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        Db::transaction(function () use ($id, $changes, $password): void {
            Db::name('admin_users')->where('id', $id)->update($changes);
            if ($password !== '') Db::name('admin_tokens')->where('user_id', $id)->delete();
        });
        return ApiResponse::success(Db::name('admin_users')->field('id,username,role_code,status,last_login_at,created_at')->where('id', $id)->find());
    }
}
