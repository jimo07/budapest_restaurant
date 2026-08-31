<?php

declare(strict_types=1);

namespace app\controller\public_api;

use app\service\MenuService;
use app\support\ApiResponse;
use think\Request;

final class MenuController
{
    public function __construct(private readonly MenuService $service) {}
    public function show(Request $request, int $sessionId) { return ApiResponse::success($this->service->menu($sessionId, (string)$request->get('lang', 'zh'))); }
}
