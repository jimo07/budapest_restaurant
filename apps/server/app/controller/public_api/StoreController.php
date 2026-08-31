<?php

declare(strict_types=1);

namespace app\controller\public_api;

use app\controller\admin_api\SettingController;
use app\support\ApiResponse;
use think\Request;

final class StoreController
{
    public function show(Request $request)
    {
        $settings = SettingController::values();
        $language = in_array($request->get('lang'), ['en', 'hu'], true) ? (string)$request->get('lang') : 'zh';
        $result = array_intersect_key($settings, array_flip(['restaurant_name', 'restaurant_subtitle', 'restaurant_phone', 'restaurant_address', 'logo_url', 'cancellation_rule']));
        if ($language !== 'zh') {
            foreach (['restaurant_name', 'restaurant_subtitle', 'restaurant_address', 'cancellation_rule'] as $key) {
                $result[$key] = $settings["{$key}_{$language}"] ?: $settings[$key];
            }
        }
        return ApiResponse::success($result);
    }
}
