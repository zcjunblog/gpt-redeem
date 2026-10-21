# Orbitcard 接入 Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan inline; one fresh reviewer at the end. Steps use checkboxes.

**Goal:** 真实Orbitcard适配代码与生产用户说明/教程视频可交付，默认关闭真实充值。
**Architecture:** PDO订单状态机保留，增加Upstream接口、OrbitcardClient及OrbitcardUpstream；既有订单按持久化provider路由。真实请求只提交一次、按原单查单，Session不落盘。
**Tech Stack:** PHP8.5.12、MySQL8.4/InnoDB、原生HTML/CSS/JS、cURL。
**Spec:** docs/orbitcard-design.md

## Global Constraints

- 禁止本轮真实充值或使用真实API密钥；默认mock。
- 密钥只从服务端环境变量读取；Session、验证URL不写数据库或日志。
- 已有订单不改变归属；超时和未知只查原单，不重建。
- 页面按aisub风格，说明生产化；源码包带教程视频且无私有文件。

## Review Focus

- API返回HTTP成功但业务code非零、身份不一致或仅本地任务成功时不得核销。
- 一次POST超时、并发提交、重启恢复不得重复真实POST。
- 关闭开关/缺失配置在卡密锁定和网络前拒绝。
- 验证URL临时性、过期/多轮更新、Session隐私和错误映射。
- 本机旧Mock订单在生产配置下仍走Mock；媒体Range与源码包无敏感文件。

### Task 1: Provider与协议

Files: app/Upstream.php, app/OrbitcardClient.php, app/OrbitcardUpstream.php, tests/orbitcard.php。
- [x] 独立已知向量验证十行签名、请求原始字节及业务信封；先失败。
- [x] 实现网络双开关、catalog/regions查询、创建、原单查询、结果归属与captcha安全映射。
- [x] 测试非零code、超时、错误归属、误判成功、Retry-After、无网络禁用状态，转绿。

### Task 2: 状态机与配置

Files: app/Core.php, app/Database.php, bootstrap.php, schemas, API/admin/setup/config, tests。
- [x] 测试真实提交仅一次、凭证不落盘、未知恢复原单、旧单provider隔离，先失败。
- [x] 加列迁移与provider路由；原子领取提交、Session内存传递，查询节流，真实失败禁恢复。
- [x] 配置示例和后台区分真实/模拟，所有业务/HTTP/MySQL回归通过。

### Task 3: 用户页面与视频

Files: public/index.php, recharge.js, style.css, media/tutorial.mp4, router.php, nginx示例。
- [x] Chrome查看参考使用说明与用户视频，确认素材。
- [x] 正式说明、Session表单、授权确认、验证按钮与安全清理；Mock独立测试提示。
- [x] 视频播放器、正确MIME和Range；Chrome桌面/移动/教程播放与模拟流程通过。

### Task 4: 交付

- [x] 独立代码审查并修复重要问题；完整测试与无真实请求证明。
- [x] 更新接入部署说明，打包无凭证/无数据的源码+视频，校验清单和本地入口。

无Git仓库，本轮不虚构commit；过程结果追加docs/orbitcard-verification.md。
