<?php
// 全局中间件定义文件
return [
    \app\middleware\RequestId::class,
    \app\middleware\Cors::class,
    \app\middleware\SecurityHeaders::class,
];
