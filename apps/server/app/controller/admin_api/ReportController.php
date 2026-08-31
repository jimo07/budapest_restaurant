<?php

declare(strict_types=1);

namespace app\controller\admin_api;

use think\facade\Db;
use think\Request;

final class ReportController
{
    public function orders(Request $request)
    {
        $query = Db::name('orders')->alias('o')
            ->leftJoin('time_slots ts', 'ts.id=o.time_slot_id')
            ->leftJoin('dining_tables dt', 'dt.id=o.table_id')
            ->order('o.id', 'desc');
        foreach (['service_date', 'meal_type', 'fulfillment_type'] as $field) {
            $value = $request->get($field);
            if ($value !== null && $value !== '') $query->where('o.' . $field, $value);
        }
        $status = trim((string)$request->get('status', ''));
        if ($status !== '') $query->whereIn('o.status', array_filter(explode(',', $status)));
        $keyword = trim((string)$request->get('keyword', ''));
        if ($keyword !== '') $query->whereLike('o.order_no|o.fulfillment_code|o.customer_name|o.customer_phone', '%' . $keyword . '%');

        $rows = $query->field('o.*,ts.start_time,ts.end_time,dt.table_no')->select()->toArray();
        $itemRows = $rows === [] ? [] : Db::name('order_items')->whereIn('order_id', array_column($rows, 'id'))->order('id')->select()->toArray();
        $items = [];
        foreach ($itemRows as $item) $items[$item['order_id']][] = $item['product_name'] . '×' . $item['quantity'];

        $labels = [
            'delivery' => '配送', 'takeaway' => '自取', 'dine_in' => '堂食',
            'pending' => '待确认', 'confirmed' => '已确认', 'preparing' => '制作中', 'ready' => '已备好',
            'fulfilling' => '履约中', 'completed' => '已完成', 'cancelled' => '已取消',
            'unpaid' => '未收款', 'paid' => '已收款', 'refunded' => '已退款',
        ];
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['订单号', '履约码', '营业日', '餐次', '方式', '预约时段', '桌号', '联系人', '手机号', '地址', '商品', '人数', '应付金额（HUF）', '支付状态', '订单状态', '备注', '取消原因', '下单时间'], ',', '"', '\\');
        foreach ($rows as $row) {
            $safe = fn ($value) => preg_match('/^[=+\-@]/u', (string)$value) ? "'" . $value : $value;
            fputcsv($stream, array_map($safe, [
                $row['order_no'], $row['fulfillment_code'], $row['service_date'], $row['meal_type'] === 'lunch' ? '午餐' : '晚餐',
                $labels[$row['fulfillment_type']] ?? $row['fulfillment_type'], substr((string)$row['start_time'], 0, 5) . '-' . substr((string)$row['end_time'], 0, 5),
                $row['table_no'] ?? '', $row['customer_name'], $row['customer_phone'], $row['address'] ?? '', implode('；', $items[$row['id']] ?? []),
                $row['people_count'], $row['payable_amount'], $labels[$row['payment_status']] ?? $row['payment_status'],
                $labels[$row['status']] ?? $row['status'], $row['remark'] ?? '', $row['cancel_reason'] ?? '', $row['created_at'],
            ]), ',', '"', '\\');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        Db::name('operation_logs')->insert([
            'user_id' => $request->admin['id'] ?? null, 'action' => 'export:orders', 'target_type' => 'orders',
            'ip' => $request->ip(), 'detail' => json_encode(['count' => count($rows), 'filters' => $request->get()], JSON_UNESCAPED_UNICODE),
        ]);
        $filename = 'orders-' . ((string)$request->get('service_date', date('Y-m-d'))) . '.csv';
        return response($content, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $filename . '"']);
    }
}
