<?php

declare(strict_types=1);

namespace app\support;

use think\response\Json;

final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'ok', int $status = 200): Json
    {
        return json(['code' => 0, 'message' => $message, 'data' => $data], $status);
    }

    public static function error(string $message, int $code = 40000, int $status = 400, mixed $data = null): Json
    {
        return json(['code' => $code, 'message' => $message, 'data' => $data], $status);
    }
}
