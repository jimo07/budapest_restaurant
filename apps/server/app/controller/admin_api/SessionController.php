<?php
declare(strict_types=1);
namespace app\controller\admin_api;
use app\support\ApiResponse;
use think\Request;
final class SessionController extends ResourceController
{
    protected string $resource = 'sessions';
    public function products(Request $request, int $id) { return ApiResponse::success($this->service->syncSessionProducts($id, (array)$request->post('items', []))); }
}
