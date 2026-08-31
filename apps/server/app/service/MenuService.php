<?php

declare(strict_types=1);

namespace app\service;

use app\exception\BusinessException;
use think\facade\Db;

final class MenuService
{
    public function sessions(?string $date = null): array
    {
        $query = Db::name('service_sessions')->where('service_date', '>=', $date ?: date('Y-m-d'))
            ->where('status', '<>', 'closed')->order('service_date')->order('meal_type');
        return $query->select()->toArray();
    }

    public function menu(int $sessionId, string $language = 'zh'): array
    {
        $session = Db::name('service_sessions')->where('id', $sessionId)->find();
        if (!$session) throw new BusinessException('餐次不存在', 40410, 404);
        $language = in_array($language, ['en', 'hu'], true) ? $language : 'zh';
        $nameField = $language === 'zh' ? 'p.name' : "COALESCE(NULLIF(p.name_{$language},''),p.name)";
        $descriptionField = $language === 'zh' ? 'p.description' : "COALESCE(NULLIF(p.description_{$language},''),p.description)";
        $categoryField = $language === 'zh' ? 'c.name' : "COALESCE(NULLIF(c.name_{$language},''),c.name)";
        $products = Db::name('session_products')->alias('sp')
            ->join('products p', 'p.id=sp.product_id')->leftJoin('categories c', 'c.id=p.category_id')
            ->where('sp.session_id', $sessionId)->where('sp.status', 'active')->where('p.status', 'active')
            ->field("p.id,p.type,{$nameField} name,{$descriptionField} description,p.image_url,p.category_id,{$categoryField} category_name,sp.sale_price,sp.stock,sp.sold_qty")
            ->order('c.sort_order')->order('p.sort_order')->select()->toArray();
        foreach ($products as &$product) {
            $product['available_stock'] = $product['stock'] === null ? null : max(0, (int)$product['stock'] - (int)$product['sold_qty']);
            $product['sold_out'] = $product['stock'] !== null && $product['available_stock'] <= 0;
        }
        $slots = Db::name('time_slots')->where('session_id', $sessionId)->where('status', 'active')
            ->order('start_time')->select()->toArray();
        return ['session' => $session, 'products' => $products, 'time_slots' => $slots];
    }
}
