<?php

declare(strict_types=1);

namespace app\controller\admin_api;

use app\service\AuthService;
use app\support\ApiResponse;
use think\Request;

final class AuthController
{
    public function __construct(private readonly AuthService $service) {}
    public function login(Request $request) { return ApiResponse::success($this->service->login((string)$request->post('username'), (string)$request->post('password'), $request->ip())); }
}
