<?php

declare(strict_types=1);

namespace app\service;

use app\exception\BusinessException;
use think\facade\Cache;
use think\facade\Db;

final class AuthService
{
    public function login(string $username, string $password, ?string $ip = null): array
    {
        $attemptKey = 'admin-login:' . hash('sha256', strtolower(trim($username)) . '|' . ($ip ?: 'unknown'));
        $attempts = (int)Cache::get($attemptKey, 0);
        if ($attempts >= 5) throw new BusinessException('登录尝试过多，请5分钟后再试', 42901, 429);
        $user = Db::name('admin_users')->where('username', $username)->find();
        if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
            Cache::set($attemptKey, $attempts + 1, 300);
            Db::name('operation_logs')->insert(['user_id' => $user['id'] ?? null, 'action' => 'auth:login_failed', 'target_type' => 'admin_users', 'target_id' => $user['id'] ?? null, 'ip' => $ip, 'detail' => json_encode(['username' => $username], JSON_UNESCAPED_UNICODE)]);
            throw new BusinessException('用户名或密码错误', 40101, 401);
        }
        Cache::delete($attemptKey);
        $token = bin2hex(random_bytes(32));
        Db::transaction(function () use ($user, $token, $ip): void {
            Db::name('admin_tokens')->where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
            Db::name('admin_tokens')->insert(['user_id' => $user['id'], 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + 86400)]);
            Db::name('admin_users')->where('id', $user['id'])->update(['last_login_at' => date('Y-m-d H:i:s')]);
            Db::name('operation_logs')->insert(['user_id' => $user['id'], 'action' => 'auth:login', 'target_type' => 'admin_users', 'target_id' => $user['id'], 'ip' => $ip]);
        });
        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 86400, 'user' => ['id' => $user['id'], 'username' => $user['username'], 'role_code' => $user['role_code']]];
    }
}
