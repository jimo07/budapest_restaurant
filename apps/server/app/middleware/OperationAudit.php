<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\facade\Db;
use think\Request;

final class OperationAudit
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $segments = explode('/', trim($request->pathinfo(), '/'));
            Db::name('operation_logs')->insert([
                'user_id' => $request->admin['id'] ?? null,
                'action' => strtolower($request->method()) . ':' . $request->pathinfo(),
                'target_type' => $segments[3] ?? null,
                'target_id' => isset($segments[4]) && ctype_digit($segments[4]) ? $segments[4] : null,
                'ip' => $request->ip(),
                'detail' => json_encode(['request_id' => $request->request_id ?? null], JSON_UNESCAPED_UNICODE),
            ]);
        }
        return $response;
    }
}
