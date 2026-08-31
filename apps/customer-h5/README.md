# Budapest Restaurant Customer H5

布达佩斯餐厅顾客移动端，基于 Vue 3、TypeScript、Pinia 和 Vite。

## 已实现

- 营业日和午/晚餐餐次选择
- 分类菜单、库存与售罄展示
- 主菜、汤品、饮品等吸顶分类导航和滚动定位
- 本地持久化购物车
- 购物车抽屉与截止时间倒计时
- 配送、自取、堂食动态表单
- 预约时段和剩余容量选择
- 服务端订单试算
- 幂等订单提交
- 下单成功及查询凭证保存
- 下单前最终信息确认
- 本机历史订单自动管理
- 订单查询和顾客取消
- 加载、错误和空数据状态
- 375–520px 移动端响应式布局

## 本地运行

先启动后端（默认端口 8000）：

```bash
cd ../server
php think run
```

再启动顾客端：

```bash
pnpm install
pnpm dev
```

Vite 会把 `/api` 请求代理到 `http://127.0.0.1:8000`。部署时可通过 `VITE_API_BASE_URL` 指定 API 地址。

## 检查

```bash
pnpm build
pnpm lint
```
