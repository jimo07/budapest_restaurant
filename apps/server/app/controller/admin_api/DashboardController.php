<?php

declare(strict_types=1);

namespace app\controller\admin_api;

use app\service\DashboardService;
use app\support\ApiResponse;
use think\Request;

final class DashboardController
{
    public function __construct(private readonly DashboardService $service) {}
    public function index(Request $request) { return ApiResponse::success($this->service->summary((string)$request->get('date', date('Y-m-d')), (string)$request->get('lang', 'zh'))); }
    public function workbench(Request $request, string $type) { return ApiResponse::success($this->service->workbench($type, (string)$request->get('date', date('Y-m-d')), (string)$request->get('lang', 'zh'))); }
}
