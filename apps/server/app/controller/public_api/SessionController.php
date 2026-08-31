<?php

declare(strict_types=1);

namespace app\controller\public_api;

use app\service\MenuService;
use app\support\ApiResponse;
use think\Request;

final class SessionController
{
    public function __construct(private readonly MenuService $service) {}
    public function index(Request $request) { return ApiResponse::success($this->service->sessions($request->get('date'))); }
}
