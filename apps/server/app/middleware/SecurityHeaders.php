<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request)->header([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(self)',
            'Cache-Control' => 'no-store',
        ]);
    }
}
