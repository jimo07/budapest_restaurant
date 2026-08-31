<?php

declare(strict_types=1);

namespace app\controller;

use think\facade\Db;
use think\response\Json;

class HealthController
{
    public function index(): Json
    {
        Db::query('SELECT 1');

        return json([
            'code' => 0,
            'message' => 'ok',
            'data' => [
                'service' => 'budapest-restaurant-api',
                'database' => 'connected',
                'php_version' => PHP_VERSION,
                'time' => date('Y-m-d H:i:s'),
            ],
        ]);
    }
}