<?php

declare(strict_types=1);

namespace app\controller\admin_api;

use app\service\OrderService;
use app\support\ApiResponse;
use think\facade\Db;
use think\Request;

final class OrderController
{
    public function __construct(private readonly OrderService $service) {}
    public function index(Request $request)
    {
        $query = Db::name('orders')->order('id', 'desc');
        foreach (['service_date', 'meal_type', 'fulfillment_type'] as $field) if ($request->get($field) !== null && $request->get($field) !== '') $query->where($field, $request->get($field));
        $status = trim((string)$request->get('status', ''));
        if ($status !== '') $query->whereIn('status', array_filter(explode(',', $status)));
        $keyword = trim((string)$request->get('keyword', ''));
        if ($keyword !== '') $query->whereLike('order_no|fulfillment_code|customer_name|customer_phone', '%' . $keyword . '%');
        return ApiResponse::success($query->paginate(['list_rows' => min(100, max(1, (int)$request->get('page_size', 20))), 'page' => max(1, (int)$request->get('page', 1))])->toArray());
    }
    public function status(Request $request, int $id)
    {
        return ApiResponse::success($this->service->updateStatus($id, (string)$request->post('status'), (int)$request->admin['id'], $request->post('reason')));
    }

    public function show(Request $request, int $id)
    {
        $order = Db::name('orders')->where('id', $id)->find();
        if (!$order) return ApiResponse::error('订单不存在', 40420, 404);
        unset($order['query_token_hash'], $order['idempotency_key']);
        $order['items'] = Db::name('order_items')->where('order_id', $id)->select()->toArray();
        $language = (string)$request->get('lang', 'zh');
        if (in_array($language, ['en', 'hu'], true)) {
            $names = Db::name('products')->whereIn('id', array_column($order['items'], 'product_id'))->column('name_' . $language, 'id');
            foreach ($order['items'] as &$item) if (!empty($names[$item['product_id']])) $item['product_name'] = $names[$item['product_id']];
        }
        $order['status_logs'] = Db::name('order_status_logs')->where('order_id', $id)->order('created_at')->select()->toArray();
        $order['table_no'] = !empty($order['table_id']) ? Db::name('dining_tables')->where('id', $order['table_id'])->value('table_no') : null;
        return ApiResponse::success($order);
    }

    public function fulfillment(Request $request, int $id)
    {
        return ApiResponse::success($this->service->updateFulfillment($id, (string)$request->post('fulfillment_status'), (int)$request->admin['id'], $request->post('table_id') !== null ? (int)$request->post('table_id') : null));
    }

    public function payment(Request $request, int $id)
    {
        return ApiResponse::success($this->service->updatePayment($id, (string)$request->post('payment_status'), (int)$request->admin['id']));
    }

    public function reschedule(Request $request, int $id)
    {
        return ApiResponse::success($this->service->reschedule($id, (int)$request->post('time_slot_id'), (int)$request->admin['id']));
    }

    public function batchStatus(Request $request)
    {
        return ApiResponse::success($this->service->batchUpdateStatus((array)$request->post('ids', []), (string)$request->post('status'), (int)$request->admin['id'], $request->post('reason')));
    }
}
