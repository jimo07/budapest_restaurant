# Budapest Restaurant

面向布达佩斯餐厅的预约点餐系统，包含顾客 H5、商家后台和 ThinkPHP API。系统支持中文、英语、匈牙利语，以及配送、自取、堂食、午餐和晚餐业务。

## 工程结构

```text
apps/
  customer-h5/       顾客端 Vue 3 应用
  admin-web/         商家后台 Vue 3 应用
  server/            ThinkPHP 8 API
packages/
  shared/            跨前端共享的业务常量与基础类型
  api-types/         API 响应与数据传输类型
scripts/             初始化、启动、检查、测试脚本
docs/                需求、接口、测试、部署和操作文档
deploy/              后续正式部署配置
storage/             本地持久化文件占位，不提交运行数据
```

## 本地启动

环境要求：PHP 8.1+、Composer、Node.js 22.18+、pnpm 10、MySQL。

```bash
cd /Users/jimo/Desktop/budapest-restaurant
pnpm bootstrap
pnpm seed
pnpm dev
```

默认访问地址：

- 顾客端：`http://127.0.0.1:5173`
- 商家后台：`http://127.0.0.1:5174/admin/`
- API：`http://127.0.0.1:8000`

`pnpm dev` 会同时启动三个应用，按 `Control + C` 会一起关闭。

## 常用命令

- `pnpm bootstrap`：安装前后端依赖并创建缺失的本地环境文件。
- `pnpm seed`：幂等初始化数据库、演示午晚餐和三语数据。
- `pnpm dev`：同时启动 API、顾客端和后台端。
- `pnpm type-check`：检查两个前端和公共包的 TypeScript。
- `pnpm lint`：检查 PHP 语法并运行前端代码规范检查。
- `pnpm test`：运行后端测试、类型检查和生产构建。

应用自己的配置仍放在各自目录的 `.env` 中，密钥和生产数据库密码不要提交到 Git。

## 文档

- [需求与范围](docs/01-requirements.md)
- [API 概览](docs/04-api.md)
- [测试验收](docs/06-test-cases.md)
- [部署说明](docs/07-deployment.md)
- [后台操作手册](docs/08-admin-manual.md)
- [发布检查清单](docs/10-release-checklist.md)
