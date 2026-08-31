<?php

declare(strict_types=1);

namespace app\controller\admin_api;

use app\support\ApiResponse;
use think\facade\Db;
use think\Request;

final class SettingController
{
    public const DEFAULTS = [
        'restaurant_name' => '布达佩斯餐厅', 'restaurant_subtitle' => 'HUNGARIAN KITCHEN',
        'restaurant_name_en' => 'Budapest Restaurant', 'restaurant_name_hu' => 'Budapest Étterem',
        'restaurant_subtitle_en' => 'HUNGARIAN KITCHEN', 'restaurant_subtitle_hu' => 'MAGYAR KONYHA',
        'restaurant_phone' => '', 'restaurant_address' => '', 'logo_url' => '',
        'restaurant_address_en' => '', 'restaurant_address_hu' => '',
        'receipt_footer' => '请按履约码核对订单',
        'receipt_footer_en' => 'Verify the order using the fulfillment code',
        'receipt_footer_hu' => 'Ellenőrizze a rendelést a teljesítési kóddal',
        'cancellation_rule' => '餐厅开始制作前可随时取消；开始制作后请联系餐厅处理。',
        'cancellation_rule_en' => 'Orders may be cancelled before preparation starts; contact the restaurant afterwards.',
        'cancellation_rule_hu' => 'A rendelés az elkészítés megkezdéséig lemondható; utána lépjen kapcsolatba az étteremmel.',
        'alert_pending_minutes' => '10', 'alert_preparing_minutes' => '30', 'alert_ready_minutes' => '20',
    ];

    public function index() { return ApiResponse::success($this->values()); }

    public function update(Request $request)
    {
        $payload = (array)$request->post();
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $payload)) continue;
            $value = trim((string)$payload[$key]);
            if (str_starts_with($key, 'alert_') && ((int)$value < 1 || (int)$value > 1440)) return ApiResponse::error('提醒时间需在1–1440分钟之间', 40040, 422);
            if (mb_strlen($value) > 500) return ApiResponse::error('配置内容不能超过500字', 40041, 422);
            Db::name('system_settings')->replace()->insert(['setting_key' => $key, 'setting_value' => $value]);
        }
        return ApiResponse::success($this->values());
    }

    public static function values(): array
    {
        $rows = Db::name('system_settings')->column('setting_value', 'setting_key');
        return array_replace(self::DEFAULTS, $rows);
    }
}
