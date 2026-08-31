<?php

declare(strict_types=1);

namespace app\controller\admin_api;

use app\service\ResourceService;
use app\support\ApiResponse;
use think\Request;

abstract class ResourceController
{
    protected string $resource;
    public function __construct(protected readonly ResourceService $service) {}
    public function index(Request $request) { return ApiResponse::success($this->service->list($this->resource, $request->get())); }
    public function read(int $id) { return ApiResponse::success($this->service->show($this->resource, $id)); }
    public function save(Request $request) { return ApiResponse::success($this->service->create($this->resource, $request->post()), '创建成功', 201); }
    public function update(Request $request, int $id) { return ApiResponse::success($this->service->update($this->resource, $id, $request->post())); }
    public function delete(int $id) { $this->service->disable($this->resource, $id); return ApiResponse::success(null, '已停用'); }
}
