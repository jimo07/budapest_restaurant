<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;

final class RequestId
{
    public function handle(Request $request, Closure $next)
    {
        $requestId = $request->header('X-Request-Id') ?: bin2hex(random_bytes(12));
        $request->request_id = $requestId;
        return $next($request)->header(['X-Request-Id' => $requestId]);
    }
}
