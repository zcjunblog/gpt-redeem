# Orbitcard接入验证 · 2026-10-22

用户授权真实适配代码、正式用户说明与教程视频，明确禁止本轮实际充值。

## 结果

- PHP8.5.12 / MySQL8.4.13 保持运行。默认provider=mock，ORBITCARD_ENABLED和ALLOW_CREATE均关闭；本机未配置Key、Secret、支付卡或费用上限。
- 最新协议从Orbitcard公开Markdown文档读取；参考站aisub.online已用Chrome DevTools MCP打开，并查看使用教程页。
- 实现十行Canonical HMAC-SHA256、真实HTTPS传输、目录/费用上限/按套餐地区、单次创建、按原client_order_no查询、归属和最终成功校验、临时captcha、多轮验证和续费三态。
- 订单/卡密持久化provider，新加字段迁移可重复，旧单默认Mock。完整Session不写数据库/日志/浏览器存储，验证URL不持久化。真实失败不能自动恢复卡密。
- 本机没有向Orbitcard开放API发起真实请求；协议测试使用伪造凭证和注入假HTTP运输。尚未进行真实密钥、支付卡或实际充值联调。

## 自动验证

- tests/orbitcard.php：13项通过，包括独立签名向量、关闭状态零传输、目录地区、创建摘要不核销、实际传输超时后只查原单、重启恢复、错误归属、600秒退避、历史结果查询错误标注、Session兼容、查询租约、captcha不落库、旧单隔离。
- 既有业务11项、SQLite恢复并发3项、真实MySQL集成7项通过。
- HTTP测试在SQLite与MySQL下各24项通过，包括视频Range与416、后台认证、CSRF/Origin/Host、重复订单及敏感路径保护。
- tests/live-http.php：正式通道关闭时503、无锁卡/新订单/凭证反射、旧Mock仍可查，通过。
- tests/frontend.cjs与frontend-live.cjs通过：验证卡密快照、真实Session扩展保留/清理、captcha多轮与缺失清理、600秒等待。
- PHP语法、JS与启动脚本检查通过。

## 审查修复

独立审查发现并复现两项P2：查询失败被历史终态掩盖；Retry-After被截短。均补充失败测试后修复并转绿，完整回归通过。Session示例并未逐字段声明sessionToken必填，前后端已放宽并测试，无需用户手工伪造该字段。

## 页面与视频

- 文案面向正式用户，保留清晰测试模式小提示，不将模拟结果冒充真实充值。
- public/media/tutorial.mp4与用户原始video.mp4字节一致，9,969,605字节；原生播放器、封面和文字说明已接入。
- Chrome实际播放：50.875秒、1994×1080、paused=false。390px手机页面scrollWidth=innerWidth=390，无横向溢出；桌面教程截图已检查。
- 本地PHP开发服务器增加并发处理，避免视频加载占用其他请求；服务器Nginx示例直接提供静态视频与Range。

## 交付

源码包由scripts/package-source.py显式白名单生成，包含43个源码/文档/媒体文件和校验清单，约9.35MiB。排除config.local.php、.env、data、会话、数据库、.runtime、artifacts。ZIP完整性、逐文件SHA256和视频原始字节校验通过。

入口仍为http://127.0.0.1:3100/。上线配置与后续有授权的真实联调步骤见docs/orbitcard-deploy.md。本轮没有操作用户服务器或发布公网。
