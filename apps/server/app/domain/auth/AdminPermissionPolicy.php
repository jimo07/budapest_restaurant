<?php

declare(strict_types=1);

namespace app\domain\auth;

final class AdminPermissionPolicy
{
    public function allows(string $role, string $method, string $path, ?string $targetStatus = null): bool
    {
        if ($role === 'super_admin') return true;
        $path = strtolower($path);
        $method = strtoupper($method);
        if ($method === 'GET' && str_ends_with($path, '/admin/notifications')) return true;
        if ($role === 'order_clerk') {
            return (bool)preg_match('#/admin/(dashboard|orders(?:/.*)?|workbench/.*|reports/orders\.csv)$#', $path);
        }
        if ($role === 'kitchen') {
            if ($method === 'GET' && preg_match('#/admin/workbench/kitchen(?:_orders)?$#', $path)) return true;
            return $method === 'PATCH'
                && (bool)preg_match('#/admin/orders/\d+/status$#', $path)
                && in_array($targetStatus, ['preparing', 'ready'], true);
        }
        if ($role === 'fulfillment') {
            if ($method === 'GET' && preg_match('#/admin/workbench/(delivery|takeaway|dine[_-]in)$#', $path)) return true;
            if ($method === 'GET' && preg_match('#/admin/orders/\d+$#', $path)) return true;
            return $method === 'PATCH' && (bool)preg_match('#/admin/orders/\d+/fulfillment$#', $path);
        }
        return false;
    }
}
