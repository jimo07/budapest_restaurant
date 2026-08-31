<?php

declare(strict_types=1);

namespace app\service;

use app\domain\order\OrderNumberGenerator;
use app\domain\order\OrderAvailabilityPolicy;
use app\domain\order\OrderCancellationPolicy;
use app\domain\order\OrderPriceCalculator;
use app\domain\order\OrderStatusMachine;
use app\domain\order\FulfillmentPolicy;
use app\domain\order\PaymentPolicy;
use app\enum\FulfillmentType;
use app\exception\BusinessException;
use app\support\QueryToken;
use think\facade\Db;

final class OrderService
{
    public function __construct(
        private readonly OrderPriceCalculator $calculator = new OrderPriceCalculator(),
        private readonly OrderNumberGenerator $numberGenerator = new OrderNumberGenerator(),
        private readonly OrderStatusMachine $statusMachine = new OrderStatusMachine(),
        private readonly OrderCancellationPolicy $cancellationPolicy = new OrderCancellationPolicy(),
        private readonly FulfillmentPolicy $fulfillmentPolicy = new FulfillmentPolicy(),
        private readonly OrderAvailabilityPolicy $availabilityPolicy = new OrderAvailabilityPolicy(),
        private readonly PaymentPolicy $paymentPolicy = new PaymentPolicy(),
    ) {}

    public function preview(array $payload): array
    {
        return Db::transaction(fn () => $this->prepare($payload, false));
    }

    public function create(array $payload): array
    {
        $key = trim((string)($payload['idempotency_key'] ?? ''));
        if ($key === '' || strlen($key) > 80) throw new BusinessException('idempotency_key 必填且不能超过80字符', 40013);
        $existing = Db::name('orders')->where('idempotency_key', $key)->find();
        if ($existing) return $this->detail((int)$existing['id'], null, true);

        return Db::transaction(function () use ($payload, $key): array {
            $prepared = $this->prepare($payload, true);
            $token = QueryToken::generate();
            $order = [
                'order_no' => $this->numberGenerator->generate(), 'idempotency_key' => $key,
                'query_token_hash' => QueryToken::hash($token), 'session_id' => $prepared['session']['id'],
                'fulfillment_code' => $this->numberGenerator->fulfillmentCode(),
                'service_date' => $prepared['session']['service_date'], 'meal_type' => $prepared['session']['meal_type'],
                'fulfillment_type' => $prepared['fulfillment_type'], 'fulfillment_status' => $this->fulfillmentPolicy->initialStatus($prepared['fulfillment_type']),
                'status' => 'pending', 'payment_status' => 'unpaid', 'customer_name' => trim((string)$payload['customer_name']),
                'customer_phone' => trim((string)$payload['customer_phone']), 'address' => $payload['address'] ?? null,
                'delivery_lat' => $payload['delivery_lat'] ?? null, 'delivery_lng' => $payload['delivery_lng'] ?? null,
                'time_slot_id' => (int)$payload['time_slot_id'], 'delivery_zone_id' => $prepared['delivery_zone']['id'] ?? null,
                'people_count' => $prepared['capacity_units'], 'remark' => $payload['remark'] ?? null,
            ] + $prepared['amounts'];
            $orderId = (int)Db::name('orders')->insertGetId($order);
            foreach ($prepared['items'] as $item) {
                Db::name('order_items')->insert([
                    'order_id' => $orderId, 'product_id' => $item['product_id'], 'product_name' => $item['name'],
                    'product_type' => $item['type'], 'unit_price' => $item['unit_price'], 'quantity' => $item['quantity'],
                    'total_amount' => number_format((float)$item['unit_price'] * $item['quantity'], 2, '.', ''),
                    'package_snapshot' => $item['package_snapshot'] ?? null,
                ]);
            }
            Db::name('order_status_logs')->insert(['order_id' => $orderId, 'from_status' => null, 'to_status' => 'pending', 'operator_type' => 'customer']);
            $result = $this->detail($orderId, null, true);
            $result['query_token'] = $token;
            return $result;
        });
    }

    public function findByNumber(string $orderNo, string $token): array
    {
        $order = Db::name('orders')->where('order_no', $orderNo)->find();
        if (!$order || !QueryToken::verify($token, $order['query_token_hash'])) throw new BusinessException('订单不存在或查询凭证无效', 40420, 404);
        return $this->detail((int)$order['id']);
    }

    public function cancel(string $orderNo, string $token, string $reason = '顾客取消'): array
    {
        return Db::transaction(function () use ($orderNo, $token, $reason): array {
            $order = Db::name('orders')->lock(true)->where('order_no', $orderNo)->find();
            if (!$order || !QueryToken::verify($token, $order['query_token_hash'])) throw new BusinessException('订单不存在或查询凭证无效', 40420, 404);
            $this->cancellationPolicy->assertCustomerCanCancel($order['status']);
            $items = Db::name('order_items')->where('order_id', $order['id'])->select()->toArray();
            foreach ($items as $item) Db::name('session_products')->where('session_id', $order['session_id'])->where('product_id', $item['product_id'])->dec('sold_qty', $item['quantity'])->update();
            Db::name('time_slots')->where('id', $order['time_slot_id'])->dec('used_capacity', $order['people_count'])->update();
            Db::name('orders')->where('id', $order['id'])->update(['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 200), 'cancelled_at' => date('Y-m-d H:i:s')]);
            Db::name('order_status_logs')->insert(['order_id' => $order['id'], 'from_status' => $order['status'], 'to_status' => 'cancelled', 'operator_type' => 'customer', 'reason' => mb_substr($reason, 0, 200)]);
            return $this->detail((int)$order['id']);
        });
    }

    public function updateStatus(int $orderId, string $to, int $operatorId, ?string $reason = null): array
    {
        return Db::transaction(function () use ($orderId, $to, $operatorId, $reason): array {
            $order = Db::name('orders')->lock(true)->where('id', $orderId)->find();
            if (!$order) throw new BusinessException('订单不存在', 40420, 404);
            $this->statusMachine->assertCanTransition($order['status'], $to);
            $changes = ['status' => $to];
            if ($to === 'cancelled') {
                $items = Db::name('order_items')->where('order_id', $orderId)->select()->toArray();
                foreach ($items as $item) {
                    Db::name('session_products')->where('session_id', $order['session_id'])
                        ->where('product_id', $item['product_id'])->dec('sold_qty', $item['quantity'])->update();
                }
                Db::name('time_slots')->where('id', $order['time_slot_id'])
                    ->dec('used_capacity', $order['people_count'])->update();
                $changes['cancel_reason'] = mb_substr((string)($reason ?: '管理员取消'), 0, 200);
                $changes['cancelled_at'] = date('Y-m-d H:i:s');
            }
            Db::name('orders')->where('id', $orderId)->update($changes);
            Db::name('order_status_logs')->insert(['order_id' => $orderId, 'from_status' => $order['status'], 'to_status' => $to, 'operator_type' => 'admin', 'operator_id' => $operatorId, 'reason' => $reason]);
            return $this->detail($orderId);
        });
    }

    public function updateFulfillment(int $orderId, string $to, int $operatorId, ?int $tableId = null): array
    {
        return Db::transaction(function () use ($orderId, $to, $operatorId, $tableId): array {
            $order = Db::name('orders')->lock(true)->where('id', $orderId)->find();
            if (!$order) throw new BusinessException('订单不存在', 40420, 404);
            $this->fulfillmentPolicy->assertCanTransition($order['fulfillment_type'], $order['fulfillment_status'], $to, $order['status']);
            $changes = ['fulfillment_status' => $to];
            if ($order['fulfillment_type'] === 'dine_in' && $to === 'seated') {
                $table = Db::name('dining_tables')->where('id', (int)$tableId)->where('status', 'active')->find();
                if (!$table || (int)$table['capacity'] < (int)$order['people_count']) throw new BusinessException('桌台无效或容量不足', 40925, 409);
                $changes['table_id'] = $table['id'];
            }
            $resultingStatus = $this->fulfillmentPolicy->resultingOrderStatus($order['status'], $to);
            if ($resultingStatus !== $order['status']) $changes['status'] = $resultingStatus;
            Db::name('orders')->where('id', $orderId)->update($changes);
            if (isset($changes['status']) && $changes['status'] !== $order['status']) {
                Db::name('order_status_logs')->insert(['order_id' => $orderId, 'from_status' => $order['status'], 'to_status' => $changes['status'], 'operator_type' => 'admin', 'operator_id' => $operatorId, 'reason' => "履约状态更新为 {$to}"]);
            }
            return $this->detail($orderId);
        });
    }

    public function updatePayment(int $orderId, string $paymentStatus, int $operatorId): array
    {
        return Db::transaction(function () use ($orderId, $paymentStatus, $operatorId): array {
            $order = Db::name('orders')->lock(true)->where('id', $orderId)->find();
            if (!$order) throw new BusinessException('订单不存在', 40420, 404);
            $this->paymentPolicy->assertCanChange($order['status'], $order['payment_status'], $paymentStatus);
            Db::name('orders')->where('id', $orderId)->update(['payment_status' => $paymentStatus]);
            Db::name('operation_logs')->insert(['user_id' => $operatorId, 'action' => 'order.payment.update', 'target_type' => 'order', 'target_id' => (string)$orderId, 'detail' => json_encode(['from' => $order['payment_status'], 'to' => $paymentStatus], JSON_UNESCAPED_UNICODE)]);
            return $this->detail($orderId);
        });
    }

    public function reschedule(int $orderId, int $timeSlotId, int $operatorId): array
    {
        return Db::transaction(function () use ($orderId, $timeSlotId, $operatorId): array {
            $order = Db::name('orders')->lock(true)->where('id', $orderId)->find();
            if (!$order) throw new BusinessException('订单不存在', 40420, 404);
            if (in_array($order['status'], ['completed', 'cancelled'], true)) throw new BusinessException('终态订单不能修改时段', 40928, 409);
            if ((int)$order['time_slot_id'] === $timeSlotId) return $this->detail($orderId);
            $slot = Db::name('time_slots')->lock(true)->where('id', $timeSlotId)->find();
            if (!$slot || (int)$slot['session_id'] !== (int)$order['session_id'] || $slot['fulfillment_type'] !== $order['fulfillment_type'] || $slot['status'] !== 'active') throw new BusinessException('目标时段无效', 40026);
            $units = (int)$order['people_count'];
            $this->availabilityPolicy->assertCapacity((int)$slot['used_capacity'], (int)$slot['capacity'], $units);
            Db::name('time_slots')->where('id', $order['time_slot_id'])->dec('used_capacity', $units)->update();
            Db::name('time_slots')->where('id', $timeSlotId)->inc('used_capacity', $units)->update();
            Db::name('orders')->where('id', $orderId)->update(['time_slot_id' => $timeSlotId]);
            Db::name('operation_logs')->insert(['user_id' => $operatorId, 'action' => 'order.reschedule', 'target_type' => 'order', 'target_id' => (string)$orderId, 'detail' => json_encode(['from' => $order['time_slot_id'], 'to' => $timeSlotId], JSON_UNESCAPED_UNICODE)]);
            return $this->detail($orderId);
        });
    }

    public function batchUpdateStatus(array $ids, string $to, int $operatorId, ?string $reason = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
        if ($ids === [] || count($ids) > 100) throw new BusinessException('请选择 1-100 张订单', 40027);
        $results = [];
        foreach ($ids as $id) {
            try { $this->updateStatus($id, $to, $operatorId, $reason); $results[] = ['id' => $id, 'success' => true]; }
            catch (BusinessException $e) { $results[] = ['id' => $id, 'success' => false, 'message' => $e->getMessage()]; }
        }
        return $results;
    }

    private function prepare(array $payload, bool $reserve): array
    {
        $this->validatePayload($payload);
        $session = Db::name('service_sessions')->where('id', (int)$payload['session_id'])->lock($reserve)->find();
        if (!$session || $session['status'] !== 'open') throw new BusinessException('餐次不可预订', 40910, 409);
        $now = time();
        if ($now < strtotime($session['order_start_at']) || $now >= strtotime($session['cutoff_at'])) throw new BusinessException('当前不在下单时间内', 40911, 409);
        $type = (string)$payload['fulfillment_type'];
        $enabledColumn = ['delivery' => 'enabled_delivery', 'takeaway' => 'enabled_takeaway', 'dine_in' => 'enabled_dine_in'][$type];
        if (!(bool)$session[$enabledColumn]) throw new BusinessException('该餐次不支持所选履约方式', 40912, 409);

        $slot = Db::name('time_slots')->where('id', (int)$payload['time_slot_id'])->lock($reserve)->find();
        if (!$slot || (int)$slot['session_id'] !== (int)$session['id'] || $slot['fulfillment_type'] !== $type || $slot['status'] !== 'active') throw new BusinessException('预约时段无效', 40014);
        $units = $type === 'dine_in' ? (int)($payload['people_count'] ?? 0) : 1;
        $this->availabilityPolicy->assertCapacity((int)$slot['used_capacity'], (int)$slot['capacity'], $units);

        $ids = array_map(fn ($i) => (int)($i['product_id'] ?? 0), $payload['items']);
        if (count($ids) !== count(array_unique($ids))) throw new BusinessException('购物车存在重复商品', 40015);
        $rows = Db::name('session_products')->alias('sp')->join('products p', 'p.id=sp.product_id')
            ->where('sp.session_id', $session['id'])->whereIn('sp.product_id', $ids)->lock($reserve)
            ->field('sp.product_id,sp.sale_price,sp.stock,sp.sold_qty,sp.status,p.name,p.type,p.status product_status')->select()->toArray();
        $byId = array_column($rows, null, 'product_id'); $items = [];
        foreach ($payload['items'] as $requested) {
            $id = (int)$requested['product_id']; $quantity = (int)$requested['quantity']; $row = $byId[$id] ?? null;
            if (!$row || $row['status'] !== 'active' || $row['product_status'] !== 'active') throw new BusinessException("商品 {$id} 不可售", 40915, 409);
            $this->availabilityPolicy->assertStock($row['stock'] === null ? null : (int)$row['stock'], (int)$row['sold_qty'], $quantity, $row['name']);
            $items[] = ['product_id' => $id, 'name' => $row['name'], 'type' => $row['type'], 'unit_price' => $row['sale_price'], 'quantity' => $quantity];
        }
        $zone = null; $fee = 0;
        if ($type === 'delivery') {
            if (trim((string)($payload['address'] ?? '')) === '') throw new BusinessException('配送地址必填', 40016);
            $zone = Db::name('delivery_zones')->where('id', (int)($payload['delivery_zone_id'] ?? 0))->where('status', 'active')->find();
            if (!$zone) throw new BusinessException('配送区域无效', 40017);
            $fee = $zone['delivery_fee'];
        }
        $amounts = $this->calculator->calculate($items, $fee);
        if ($zone && (float)$amounts['subtotal_amount'] < (float)$zone['min_order_amount']) throw new BusinessException('未达到配送起送金额', 40917, 409);
        if ($reserve) {
            foreach ($items as $item) Db::name('session_products')->where('session_id', $session['id'])->where('product_id', $item['product_id'])->inc('sold_qty', $item['quantity'])->update();
            Db::name('time_slots')->where('id', $slot['id'])->inc('used_capacity', $units)->update();
        }
        return ['session' => $session, 'items' => $items, 'amounts' => $amounts, 'fulfillment_type' => $type, 'capacity_units' => $units, 'delivery_zone' => $zone];
    }

    private function validatePayload(array $p): void
    {
        if ((int)($p['session_id'] ?? 0) < 1 || (int)($p['time_slot_id'] ?? 0) < 1) throw new BusinessException('餐次和预约时段必填', 40001);
        if (FulfillmentType::tryFrom((string)($p['fulfillment_type'] ?? '')) === null) throw new BusinessException('履约方式无效', 40002);
        if (!is_array($p['items'] ?? null) || $p['items'] === []) throw new BusinessException('购物车不能为空', 40010);
        if (trim((string)($p['customer_name'] ?? '')) === '' || !preg_match('/^[0-9+() -]{6,30}$/', (string)($p['customer_phone'] ?? ''))) throw new BusinessException('姓名或手机号格式不正确', 40003);
        if (mb_strlen((string)($p['remark'] ?? '')) > 200) throw new BusinessException('备注不能超过200字', 40004);
        if (isset($p['delivery_lat'], $p['delivery_lng'])) {
            $lat = (float)$p['delivery_lat'];
            $lng = (float)$p['delivery_lng'];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) throw new BusinessException('配送定位坐标无效', 40017);
        }
    }

    private function detail(int $id, ?string $token = null, bool $internal = false): array
    {
        $order = Db::name('orders')->where('id', $id)->find();
        if (!$order) throw new BusinessException('订单不存在', 40420, 404);
        unset($order['query_token_hash'], $order['idempotency_key']);
        $order['items'] = Db::name('order_items')->where('order_id', $id)->select()->toArray();
        $order['status_logs'] = Db::name('order_status_logs')->where('order_id', $id)->order('created_at')->select()->toArray();
        if (!empty($order['table_id'])) {
            $order['table_no'] = Db::name('dining_tables')->where('id', $order['table_id'])->value('table_no');
        } else {
            $order['table_no'] = null;
        }
        return $order;
    }

}
