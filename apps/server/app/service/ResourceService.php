<?php

declare(strict_types=1);

namespace app\service;

use app\exception\BusinessException;
use think\facade\Db;

final class ResourceService
{
    private const DEFINITIONS = [
        'categories' => ['table' => 'categories', 'required' => ['name'], 'fields' => ['name', 'name_en', 'name_hu', 'sort_order', 'status']],
        'products' => ['table' => 'products', 'required' => ['type', 'name', 'base_price'], 'fields' => ['type', 'category_id', 'name', 'name_en', 'name_hu', 'description', 'description_en', 'description_hu', 'base_price', 'image_url', 'sort_order', 'status']],
        'sessions' => ['table' => 'service_sessions', 'required' => ['service_date', 'meal_type', 'order_start_at', 'cutoff_at'], 'fields' => ['service_date', 'meal_type', 'order_start_at', 'cutoff_at', 'enabled_delivery', 'enabled_takeaway', 'enabled_dine_in', 'status']],
        'time-slots' => ['table' => 'time_slots', 'required' => ['session_id', 'fulfillment_type', 'start_time', 'end_time', 'capacity'], 'fields' => ['session_id', 'fulfillment_type', 'start_time', 'end_time', 'capacity', 'status']],
        'delivery-zones' => ['table' => 'delivery_zones', 'required' => ['name', 'delivery_fee', 'min_order_amount'], 'fields' => ['name', 'name_en', 'name_hu', 'delivery_fee', 'min_order_amount', 'status']],
        'tables' => ['table' => 'dining_tables', 'required' => ['table_no', 'capacity'], 'fields' => ['table_no', 'capacity', 'status']],
    ];

    public function list(string $resource, array $filters): array
    {
        $def = $this->definition($resource);
        $query = Db::name($def['table'])->order('id', 'desc');
        foreach (['status', 'session_id', 'service_date', 'meal_type', 'fulfillment_type', 'category_id', 'type'] as $field) {
            if (in_array($field, $def['fields'], true) && isset($filters[$field]) && $filters[$field] !== '') $query->where($field, $filters[$field]);
        }
        if (!empty($filters['keyword'])) {
            $searchField = in_array('name', $def['fields'], true) ? 'name' : (in_array('table_no', $def['fields'], true) ? 'table_no' : null);
            if ($searchField) $query->whereLike($searchField, '%' . trim((string)$filters['keyword']) . '%');
        }
        return $query->paginate(['list_rows' => min(100, max(1, (int)($filters['page_size'] ?? 20))), 'page' => max(1, (int)($filters['page'] ?? 1))])->toArray();
    }

    public function show(string $resource, int $id): array
    {
        $def = $this->definition($resource);
        $row = Db::name($def['table'])->where('id', $id)->find();
        if (!$row) throw new BusinessException('记录不存在', 40430, 404);
        if ($resource === 'products' && $row['type'] === 'package') {
            $row['package_items'] = Db::name('package_items')->alias('pi')->join('products p', 'p.id=pi.product_id')
                ->where('pi.package_id', $id)->field('pi.product_id,pi.quantity,p.name')->select()->toArray();
        }
        if ($resource === 'sessions') {
            $row['products'] = Db::name('session_products')->alias('sp')->join('products p', 'p.id=sp.product_id')
                ->where('sp.session_id', $id)->field('sp.*,p.name,p.type')->select()->toArray();
        }
        return $row;
    }

    public function create(string $resource, array $payload): array
    {
        $def = $this->definition($resource);
        $data = $this->sanitize($def, $payload, true);
        $this->validateBusinessRules($resource, $data);
        $id = (int)Db::transaction(function () use ($resource, $def, $data, $payload): int {
            $id = (int)Db::name($def['table'])->insertGetId($data);
            $this->syncRelations($resource, $id, $payload);
            return $id;
        });
        return $this->show($resource, $id);
    }

    public function update(string $resource, int $id, array $payload): array
    {
        $def = $this->definition($resource);
        if (!Db::name($def['table'])->where('id', $id)->find()) throw new BusinessException('记录不存在', 40430, 404);
        $data = $this->sanitize($def, $payload, false);
        $this->validateBusinessRules($resource, $data);
        Db::transaction(function () use ($resource, $def, $id, $data, $payload): void {
            if ($data !== []) Db::name($def['table'])->where('id', $id)->update($data);
            $this->syncRelations($resource, $id, $payload);
        });
        return $this->show($resource, $id);
    }

    public function disable(string $resource, int $id): void
    {
        $def = $this->definition($resource);
        $status = $resource === 'sessions' ? 'closed' : 'inactive';
        if (!Db::name($def['table'])->where('id', $id)->update(['status' => $status])) throw new BusinessException('记录不存在或已经停用', 40430, 404);
    }

    public function syncSessionProducts(int $sessionId, array $items): array
    {
        if (!Db::name('service_sessions')->where('id', $sessionId)->find()) throw new BusinessException('餐次不存在', 40410, 404);
        Db::transaction(function () use ($sessionId, $items): void {
            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $price = (float)($item['sale_price'] ?? -1);
                $stock = array_key_exists('stock', $item) && $item['stock'] !== null ? (int)$item['stock'] : null;
                if ($productId < 1 || $price < 0 || ($stock !== null && $stock < 0)) throw new BusinessException('餐次商品参数不合法', 40031);
                $existing = Db::name('session_products')->where('session_id', $sessionId)->where('product_id', $productId)->find();
                $data = ['sale_price' => number_format($price, 2, '.', ''), 'stock' => $stock, 'status' => $item['status'] ?? 'active'];
                if ($existing) {
                    if ($stock !== null && $stock < (int)$existing['sold_qty']) throw new BusinessException('库存不能小于已售数量', 40931, 409);
                    Db::name('session_products')->where('id', $existing['id'])->update($data);
                } else {
                    Db::name('session_products')->insert($data + ['session_id' => $sessionId, 'product_id' => $productId, 'sold_qty' => 0]);
                }
            }
        });
        return $this->show('sessions', $sessionId);
    }

    private function definition(string $resource): array
    {
        if (!isset(self::DEFINITIONS[$resource])) throw new BusinessException('不支持的资源类型', 40431, 404);
        return self::DEFINITIONS[$resource];
    }

    private function sanitize(array $def, array $payload, bool $creating): array
    {
        if ($creating) foreach ($def['required'] as $field) if (!array_key_exists($field, $payload) || $payload[$field] === '') throw new BusinessException("{$field} 必填", 40030);
        return array_intersect_key($payload, array_flip($def['fields']));
    }

    private function validateBusinessRules(string $resource, array $data): void
    {
        if (isset($data['status']) && !in_array($data['status'], ['active', 'inactive', 'open', 'closed'], true)) throw new BusinessException('状态值无效', 40032);
        if (isset($data['base_price']) && (float)$data['base_price'] < 0) throw new BusinessException('价格不能为负数', 40032);
        if (isset($data['capacity']) && (int)$data['capacity'] < 1) throw new BusinessException('容量必须大于0', 40032);
        if ($resource === 'products' && isset($data['type']) && !in_array($data['type'], ['dish', 'package'], true)) throw new BusinessException('商品类型无效', 40032);
        if ($resource === 'sessions' && isset($data['meal_type']) && !in_array($data['meal_type'], ['lunch', 'dinner'], true)) throw new BusinessException('餐次类型无效', 40032);
        if ($resource === 'sessions' && isset($data['order_start_at'], $data['cutoff_at']) && strtotime($data['cutoff_at']) <= strtotime($data['order_start_at'])) throw new BusinessException('截止时间必须晚于开始时间', 40032);
        if ($resource === 'time-slots' && isset($data['fulfillment_type']) && !in_array($data['fulfillment_type'], ['delivery', 'takeaway', 'dine_in'], true)) throw new BusinessException('履约方式无效', 40032);
        if ($resource === 'time-slots' && isset($data['start_time'], $data['end_time']) && $data['end_time'] <= $data['start_time']) throw new BusinessException('时段结束时间必须晚于开始时间', 40032);
    }

    private function syncRelations(string $resource, int $id, array $payload): void
    {
        if ($resource !== 'products' || !array_key_exists('package_items', $payload)) return;
        Db::name('package_items')->where('package_id', $id)->delete();
        foreach ((array)$payload['package_items'] as $item) {
            $productId = (int)($item['product_id'] ?? 0); $quantity = (int)($item['quantity'] ?? 0);
            if ($productId === $id || $productId < 1 || $quantity < 1) throw new BusinessException('套餐组成参数不合法', 40033);
            Db::name('package_items')->insert(['package_id' => $id, 'product_id' => $productId, 'quantity' => $quantity]);
        }
    }
}
