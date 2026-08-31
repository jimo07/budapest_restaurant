<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;

final class Cors
{
    public function handle(Request $request, Closure $next)
    {
        $origin = (string)$request->header('Origin');
        $configured = array_values(array_filter(array_map('trim', explode(',', (string)env('CORS_ORIGIN', '')))));
        $allowedOrigin = in_array('*', $configured, true) ? '*' : (in_array($origin, $configured, true) ? $origin : '');
        $headers = [
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Request-Id',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Vary' => 'Origin',
        ];
        if ($allowedOrigin !== '') $headers['Access-Control-Allow-Origin'] = $allowedOrigin;
        if ($request->method() === 'OPTIONS') return response('', 204, $headers);
        return $next($request)->header($headers);
    }
}
