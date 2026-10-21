# 本地验证 · 2026-10-22

运行时：项目内官方 PHP 8.5.12 CLI，源码 SHA256 校验通过；PDO SQLite、session、filter、cURL、OpenSSL 可用。未替换系统 PHP。

## 自动验证

- `tests/run.php`：11 个业务场景全部通过，覆盖卡密哈希存储、无效/禁用/过期、重复提交、七种模拟结果、原单重启恢复、归属校验、失败后恢复。
- `tests/http.php`：21 项 HTTP 断言通过，覆盖后台登录、CSRF、Origin/Host、公开路径隔离、重复下单、结果脱敏、不接收真实凭证字段。
- `tests/concurrency.php`：3 个恢复/并发场景通过，分别覆盖 Mock 创建前崩溃、创建后崩溃、迟到查询不能回退另一请求已完成的成功终态。
- `tests/frontend.cjs`：验证请求期间修改卡密输入，实际提交仍须绑定已验证并展示确认的卡密，通过。
- PHP 文件语法检查、前端 JS 与启动脚本语法检查通过。

上述并发终态与前端竞态测试曾在修复前失败，修复后通过。并发状态变更限定 `pending`，前端提交使用验证时的卡密快照。

## Chrome DevTools MCP 实测

按用户要求打开 `https://aisub.online/`，截图对照其浅灰背景、渐变标题、白色圆角卡片、步骤条、蓝色按钮。参考图与本地页面并排见 `../artifacts/reference-comparison.png`。

- 后台真实表单登录、生成卡密；完整卡密当次显示。
- 正常卡密：验卡 → 虚构邮箱 → 确认 → 处理中 → 模拟成功；后台显示已核销。
- 验证场景：待验证 → 点击本地模拟验证 → 处理中 → 用订单号查询同一原单得到成功。
- 1200px 桌面与 390px 手机检查；手机 document.scrollWidth 与 innerWidth 同为 390，无页面横向溢出。
- 深浅主题可切换，手机深色截图保存；前台浏览器控制台无 error/warn。
- 发现 `Referrer-Policy: no-referrer` 使 Chrome 同源表单 POST 带 `Origin: null`，导致登录拒绝。改为 `same-origin` 后真实表单通过，仍拒绝 null/跨站 Origin。

## 当前入口与保留

已停止本项目 3101 验证进程，通过 `sh start.sh` 在 `http://127.0.0.1:3100/` 启动。最终 `/health` 返回 `ok=true, provider=mock, php=8.5.12`，Chrome 打开前台及后台订单页正常，两笔浏览器演示订单保持成功。

旧 `local-redeem/` 未修改，PHP 使用独立 SQLite。所有演示只走持久化 Mock，无真实支付、真实充值或真实账号凭证；本轮没有公网部署。

## 后续 MySQL 改造 · 2026-10-22

用户要求数据库改为 MySQL 便于服务器部署。当前本机已切换至 MySQL 8.4.13 / InnoDB，项目 PHP 8.5.12 已编入 mysqlnd/pdo_mysql，原 SQLite 兼容代码保留。

- 新增 MySQL 建表、配置示例、Nginx/PHP-FPM 示例和 `docs/mysql-deploy.md`；MySQL Web 请求只读写业务表，不执行 DDL。
- MySQL 7 项集成测试通过：建表/哈希存储、七种模拟状态、四进程同时兑换一张卡仅成功一单、限流事务回滚、SQLite迁移及重复执行/拒绝覆盖、未合并WAL源库迁移、服务器域名/HTTPS匹配。
- SQLite 原业务11项、恢复并发3项、前端竞态测试继续通过；HTTP测试在SQLite和真实MySQL下各22项断言通过。
- MySQL支持缺失时测试先失败；WAL只读回归测试亦确认修复前失败、增加SQLite只读打开标志后通过。独立代码审查发现该WAL问题，已修复。
- 已暂停旧PHP写入并备份旧SQLite和配置，导入17张卡、2笔原单、2个Mock任务、16条事件、1条限流记录，逐表规范化SHA256校验一致后切换配置。
- `/health` 返回 `ok=true, provider=mock, php=8.5.12, database=mysql`；Chrome 后台能够查看迁移前两笔成功原单并显示MySQL。
- Chrome另完成一笔新MySQL兑换：验卡、确认、提交、查询成功；数据库确认订单 succeeded，前台无控制台错误或警告。
- 旧SQLite主文件和备份保留；没有操作用户服务器或接入真实支付。服务器Nginx示例须按实际域名、证书及PHP-FPM路径替换后用 `nginx -t` 验证，本机没有执行服务器部署。
