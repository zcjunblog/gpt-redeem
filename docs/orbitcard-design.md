# Orbitcard 接入与正式页面

用户要求：完成真实接口接入代码，密钥稍后填写，本轮禁止真实充值；页面说明改为生产用户说明，附带用户提供的video.mp4。既有PHP8.5.12/MySQL和卡密渠道销售方式保留。

方案：抽离Upstream契约，Mock和Orbitcard并存。Orbitcard客户端实现十行HMAC签名、严格JSON信封、HTTPS固定地址、超时和限流。新订单记录provider与不含凭证的请求快照；完整Session只活在当前请求内，不进数据库、日志、浏览器存储或查单响应。真实POST通过原子领取仅执行一次，任何超时/崩溃只按原client_order_no查单，绝不自动重建。成功必须核对订单号、邮箱、套餐、上游成功与subscription_succeeded；真实失败不自动恢复卡密，服务费状态不作为银行卡未扣款证据。

安全开关：默认provider=mock；真实网络需ORBITCARD_ENABLED=1，真实创建另需ORBITCARD_ALLOW_CREATE=1。API Key、Secret、支付card_id与费用上限从服务端环境变量读取。仅填写密钥不启用充值。未配置或关闭时，真实请求在锁卡和网络前失败；本站测试不使用真实密钥，不向Orbitcard API发起请求。

页面：沿用参考站浅灰/渐变/白卡片/蓝按钮。说明面向用户：验证CDK→登录本人ChatGPT→获取完整Session→核对邮箱套餐并授权→等待原单；视频独立public/media/tutorial.mp4。测试模式仅有清晰小提示，不能把模拟成功冒充真实成功。真实验证链接临时显示，按任务id更新、缺失/过期清除，新窗口noopener/noreferrer，不保存；所有查询间隔至少5秒并处理Retry-After。

迁移：已有订单provider默认mock，始终用Mock查询，不因配置切换而误发真实请求。独立、可重复的加列迁移，保留原卡密订单数据。源码上传包排除密钥、数据、运行时、测试产物，包含教程视频。无公网发布。
