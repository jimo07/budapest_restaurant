<?php

declare(strict_types=1);

namespace app\controller\public_api;

use app\service\OrderService;
use app\support\ApiResponse;
use think\Request;
use think\facade\Db;

final class OrderController
{
    public function __construct(private readonly OrderService $service) {}
    public function preview(Request $request) { return ApiResponse::success($this->service->preview($request->post())); }
    public function create(Request $request) { return ApiResponse::success($this->localize($this->service->create($request->post()), (string)$request->get('lang', 'zh')), '订单创建成功', 201); }
    public function show(Request $request, string $orderNo) { return ApiResponse::success($this->localize($this->service->findByNumber($orderNo, (string)$request->get('token')), (string)$request->get('lang', 'zh'))); }
    public function cancel(Request $request, string $orderNo) { return ApiResponse::success($this->localize($this->service->cancel($orderNo, (string)$request->post('token'), (string)$request->post('reason', '顾客取消')), (string)$request->get('lang', 'zh'))); }

    private function localize(array $order, string $language): array
    {
        if (!in_array($language, ['en', 'hu'], true) || empty($order['items'])) return $order;
        $field = 'name_' . $language;
        $names = Db::name('products')->whereIn('id', array_column($order['items'], 'product_id'))->column($field, 'id');
        foreach ($order['items'] as &$item) if (!empty($names[$item['product_id']])) $item['product_name'] = $names[$item['product_id']];
        return $order;
    }
}
