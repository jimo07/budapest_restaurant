# Budapest Restaurant API

布达佩斯餐厅订单预约系统后端，基于 ThinkPHP 8、PHP 8.1+ 和 MySQL 8。

## 已实现接口

所有响应均为 `{ "code": 0, "message": "ok", "data": ... }`，业务错误会返回非零 `code` 及合适的 HTTP 状态码。

| Method | Path | 用途 |
|---|---|---|
| GET | `/api/v1/health` | 服务及数据库健康检查 |
| GET | `/api/v1/sessions?date=2026-08-30` | 可预约餐次 |
| GET | `/api/v1/sessions/{id}/menu` | 餐次菜单、库存、时段 |
| POST | `/api/v1/orders/preview` | 服务端试算及可用性检查 |
| POST | `/api/v1/orders` | 创建订单（需 `idempotency_key`） |
| GET | `/api/v1/orders/{order_no}?token=...` | 查询本人订单 |
| POST | `/api/v1/orders/{order_no}/cancel` | 顾客取消订单 |
| POST | `/api/v1/admin/auth/login` | 管理员登录 |
| GET | `/api/v1/admin/orders` | 后台订单筛选（Bearer Token） |
| PATCH | `/api/v1/admin/orders/{id}/status` | 后台订单状态流转 |
| PATCH | `/api/v1/admin/orders/{id}/fulfillment` | 配送/取餐/堂食履约状态流转 |
| PATCH | `/api/v1/admin/orders/{id}/payment` | 确认收款或登记退款 |
| PATCH | `/api/v1/admin/orders/{id}/reschedule` | 管理员调整预约时段 |
| POST | `/api/v1/admin/orders/batch-status` | 批量推进订单状态 |
| GET | `/api/v1/admin/dashboard?date=YYYY-MM-DD` | 营业日统计仪表盘 |
| GET | `/api/v1/admin/workbench/{kitchen\|delivery\|takeaway\|dine-in}` | 作业看板 |

后台基础资源均支持列表、详情、创建、更新和停用：

- `/api/v1/admin/categories`
- `/api/v1/admin/products`（套餐可传 `package_items`）
- `/api/v1/admin/sessions`
- `/api/v1/admin/time-slots`
- `/api/v1/admin/delivery-zones`
- `/api/v1/admin/tables`

餐次菜单和库存通过 `PUT /api/v1/admin/sessions/{id}/products` 批量配置。

创建订单示例：

```json
{
  "idempotency_key": "web-uuid-019",
  "session_id": 1,
  "fulfillment_type": "takeaway",
  "time_slot_id": 1,
  "customer_name": "张三",
  "customer_phone": "+36 30 123 4567",
  "remark": "少辣",
  "items": [{"product_id": 1, "quantity": 2}]
}
```

配送订单还需 `delivery_zone_id` 和 `address`；堂食订单需 `people_count`。创建成功返回的 `query_token` **只在首次响应中出现**，客户端必须安全保存。

## 初始化

1. 安装 PHP 8.1+（扩展：PDO MySQL、mbstring）和 MySQL 8。
2. 复制 `.example.env` 为 `.env` 并修改数据库密码。
3. 创建数据库和业务账号后导入结构：

```bash
mysql -u root -p -e "CREATE DATABASE budapest_restaurant CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p budapest_restaurant < database/schema.sql
composer install
php think run
```

也可以直接读取当前 `.env`，以幂等方式初始化表结构和本地演示数据：

```bash
php scripts/install.php --seed
```

演示账号为 `admin / Admin@123456`，仅限本地开发，首次登录后必须更换。

首个管理员密码必须用 PHP 生成哈希，禁止存储明文：

```bash
php -r "echo password_hash('请替换成强密码', PASSWORD_DEFAULT), PHP_EOL;"
```

将输出作为 `password_hash` 插入：

```sql
INSERT INTO admin_users (username, password_hash, role_code) VALUES ('admin', '生成的哈希', 'super_admin');
```

## 一致性与安全

- 创建订单使用 MySQL 事务及 `SELECT ... FOR UPDATE` 语义锁定餐次商品和时段，避免超卖。
- 商品价格、配送费、起送额均从数据库重新读取；客户端金额不会参与结算。
- `idempotency_key` 有唯一索引；订单查询使用随机令牌的 SHA-256 摘要。
- 管理员密码使用 `password_hash/password_verify`；登录令牌数据库仅保存摘要，24 小时过期。
- 顾客只可在截止时间前取消 `pending/confirmed` 订单，取消会返还库存与容量。

生产环境请设置 `APP_DEBUG=false`、明确配置 `CORS_ORIGIN`、使用 HTTPS，并定期清理过期 `admin_tokens`。

## 测试

```bash
composer test
find app config route tests -name '*.php' -print0 | xargs -0 -n1 php -l
php think route:list
```
