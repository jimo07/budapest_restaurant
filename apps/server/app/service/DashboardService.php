<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class DashboardService
{
    public function summary(string $date, string $language = 'zh'): array
    {
        $language = in_array($language, ['en', 'hu'], true) ? $language : 'zh';
        $productName = $language === 'zh' ? 'p.name' : "COALESCE(NULLIF(p.name_{$language},''),p.name)";
        $thresholds = Db::name('system_settings')->whereIn('setting_key', ['alert_pending_minutes', 'alert_preparing_minutes', 'alert_ready_minutes'])->column('setting_value', 'setting_key');
        $pendingSeconds = max(60, (int)($thresholds['alert_pending_minutes'] ?? 10) * 60);
        $preparingSeconds = max(60, (int)($thresholds['alert_preparing_minutes'] ?? 30) * 60);
        $readySeconds = max(60, (int)($thresholds['alert_ready_minutes'] ?? 20) * 60);
        $base = fn () => Db::name('orders')->where('service_date', $date);
        $valid = fn () => $base()->where('status', '<>', 'cancelled');
        $statusRows = $base()->field('status,COUNT(*) count')->group('status')->select()->toArray();
        $statusDistribution = [];
        $statusGroups = ['pending' => 'pending', 'confirmed' => 'pending', 'preparing' => 'in_progress', 'ready' => 'in_progress', 'fulfilling' => 'in_progress', 'completed' => 'completed', 'cancelled' => 'cancelled'];
        foreach ($statusRows as $row) {
            $group = $statusGroups[$row['status']] ?? $row['status'];
            $statusDistribution[$group] = ($statusDistribution[$group] ?? 0) + (int)$row['count'];
        }
        $fulfillmentRows = $valid()->field('fulfillment_type,COUNT(*) count')->group('fulfillment_type')->select()->toArray();
        $amounts = $valid()->field('COUNT(*) order_count,COALESCE(SUM(people_count),0) people_count,COALESCE(SUM(subtotal_amount),0) subtotal_amount,COALESCE(SUM(delivery_fee),0) delivery_fee,COALESCE(SUM(payable_amount),0) payable_amount')->find();
        $paid = $valid()->where('payment_status', 'paid')->sum('payable_amount');
        $ranking = Db::name('order_items')->alias('oi')->join('orders o', 'o.id=oi.order_id')->leftJoin('products p', 'p.id=oi.product_id')
            ->where('o.service_date', $date)->where('o.status', '<>', 'cancelled')
            ->field("oi.product_id,{$productName} product_name,SUM(oi.quantity) quantity,SUM(oi.total_amount) amount")
            ->group("oi.product_id,{$productName}")->order('quantity', 'desc')->limit(10)->select()->toArray();
        $capacity = Db::name('time_slots')->alias('ts')->join('service_sessions ss', 'ss.id=ts.session_id')
            ->where('ss.service_date', $date)->field('ts.id,ss.meal_type,ts.fulfillment_type,ts.start_time,ts.end_time,ts.capacity,ts.used_capacity')
            ->order('ts.start_time')->select()->toArray();
        $alertOrders = $base()->where(function ($query) use ($pendingSeconds, $preparingSeconds, $readySeconds): void {
            $query->whereOr(function ($q) use ($pendingSeconds): void { $q->where('status', 'pending')->whereTime('updated_at', '<=', date('Y-m-d H:i:s', time() - $pendingSeconds)); })
                ->whereOr(function ($q) use ($preparingSeconds): void { $q->where('status', 'preparing')->whereTime('updated_at', '<=', date('Y-m-d H:i:s', time() - $preparingSeconds)); })
                ->whereOr(function ($q) use ($readySeconds): void { $q->where('status', 'ready')->whereTime('updated_at', '<=', date('Y-m-d H:i:s', time() - $readySeconds)); })
                ->whereOr(function ($q): void { $q->where('status', 'completed')->where('payment_status', 'unpaid'); });
        })->field('id,order_no,fulfillment_code,fulfillment_type,status,payment_status,customer_name,payable_amount,updated_at')->order('updated_at')->limit(20)->select()->toArray();
        foreach ($alertOrders as &$alert) {
            if ($alert['status'] === 'pending') [$alert['level'], $alert['reason']] = ['urgent', '超过' . (int)($pendingSeconds / 60) . '分钟未确认'];
            elseif ($alert['status'] === 'preparing') [$alert['level'], $alert['reason']] = ['warning', '制作已超过' . (int)($preparingSeconds / 60) . '分钟'];
            elseif ($alert['status'] === 'ready') [$alert['level'], $alert['reason']] = ['warning', '备好后超过' . (int)($readySeconds / 60) . '分钟未履约'];
            else [$alert['level'], $alert['reason']] = ['warning', '订单已完成但尚未收款'];
        }
        return [
            'date' => $date, 'metrics' => $amounts + ['paid_amount' => number_format((float)$paid, 2, '.', '')],
            'status_distribution' => $statusDistribution,
            'fulfillment_distribution' => array_column($fulfillmentRows, 'count', 'fulfillment_type'),
            'product_ranking' => $ranking, 'slot_capacity' => $capacity,
            'alerts' => $alertOrders,
            'scope' => ['amounts_exclude_cancelled' => true, 'paid_amount_only_includes_paid' => true],
        ];
    }

    public function workbench(string $type, string $date, string $language = 'zh'): array
    {
        $language = in_array($language, ['en', 'hu'], true) ? $language : 'zh';
        $productName = $language === 'zh' ? 'p.name' : "COALESCE(NULLIF(p.name_{$language},''),p.name)";
        if ($type === 'kitchen_orders') {
            $orders = Db::name('orders')->alias('o')->leftJoin('time_slots ts', 'ts.id=o.time_slot_id')
                ->where('o.service_date', $date)->whereIn('o.status', ['confirmed', 'preparing'])
                ->field('o.id,o.order_no,o.fulfillment_code,o.fulfillment_type,o.status,o.remark,ts.start_time,ts.end_time')
                ->orderRaw("FIELD(o.status,'preparing','confirmed')")->order('ts.start_time')->order('o.id')->select()->toArray();
            if ($orders === []) return [];
            $itemRows = Db::name('order_items')->alias('oi')->join('products p', 'p.id=oi.product_id')->whereIn('oi.order_id', array_column($orders, 'id'))->field("oi.*,{$productName} localized_name")->order('oi.id')->select()->toArray();
            $items = [];
            foreach ($itemRows as $item) $items[$item['order_id']][] = $item['localized_name'] . ' × ' . $item['quantity'];
            foreach ($orders as &$order) $order['items_text'] = implode('；', $items[$order['id']] ?? []);
            return $orders;
        }
        if ($type === 'kitchen') {
            return Db::name('order_items')->alias('oi')->join('orders o', 'o.id=oi.order_id')->join('products p', 'p.id=oi.product_id')
                ->where('o.service_date', $date)->whereIn('o.status', ['confirmed', 'preparing', 'ready'])
                ->field("oi.product_id,{$productName} product_name,SUM(oi.quantity) quantity,COUNT(DISTINCT o.id) order_count")
                ->group("oi.product_id,{$productName}")->order('quantity', 'desc')->select()->toArray();
        }
        $fulfillment = ['delivery' => 'delivery', 'takeaway' => 'takeaway', 'dine-in' => 'dine_in', 'dine_in' => 'dine_in'][$type] ?? null;
        if (!$fulfillment) return [];
        $orders = Db::name('orders')->alias('o')->leftJoin('time_slots ts', 'ts.id=o.time_slot_id')->leftJoin('dining_tables dt', 'dt.id=o.table_id')
            ->where('o.service_date', $date)->where('o.fulfillment_type', $fulfillment)
            ->whereIn('o.status', $fulfillment === 'dine_in' ? ['confirmed', 'preparing', 'ready', 'fulfilling'] : ['ready', 'fulfilling'])
            ->field('o.id,o.order_no,o.fulfillment_code,o.status,o.fulfillment_type,o.fulfillment_status,o.customer_name,o.customer_phone,o.address,o.people_count,o.remark,o.table_id,dt.table_no,ts.start_time,ts.end_time')
            ->order('ts.start_time')->order('o.id')->select()->toArray();
        foreach ($orders as &$order) $order['customer_phone_masked'] = preg_replace('/(?<=\d{3})\d+(?=\d{3})/', '****', $order['customer_phone']);
        return $orders;
    }
}
