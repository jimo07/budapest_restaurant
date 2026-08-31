<?php

declare(strict_types=1);

namespace app\controller\admin_api;

use app\support\ApiResponse;
use think\facade\Db;
use think\Request;

final class LogController
{
    public function index(Request $request)
    {
        $query = Db::name('operation_logs')->alias('l')->leftJoin('admin_users u', 'u.id=l.user_id')
            ->field('l.id,l.user_id,u.username,l.action,l.target_type,l.target_id,l.ip,l.detail,l.created_at')->order('l.id', 'desc');
        $keyword = trim((string)$request->get('keyword', ''));
        if ($keyword !== '') $query->whereLike('u.username|l.action|l.target_id|l.ip', '%' . $keyword . '%');
        return ApiResponse::success($query->paginate(['list_rows' => min(100, max(1, (int)$request->get('page_size', 50))), 'page' => max(1, (int)$request->get('page', 1))])->toArray());
    }
}
