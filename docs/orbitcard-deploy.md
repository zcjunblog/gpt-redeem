# Orbitcard 接入与上线配置

已实现真实 API 适配代码，**默认仍为模拟模式，未使用真实密钥联调、未发起真实充值**。本轮完成签名、请求/响应处理、订单状态机、生产表单及教程视频，外部协议通过注入假运输测试验证。填写密钥后仍需按下方步骤配置和主动启用。

## 一、上传源码与视频

使用提供的源码 ZIP，解压到服务器项目目录，例如 `/var/www/gpt-redeem`。包内已包含 `public/media/tutorial.mp4` 与封面，不依赖你本机的 Downloads 路径。网站公开目录必须设为 `public/`。Nginx 示例在 `deploy/nginx.conf.example`，包含视频 MIME/Range、64KB 请求上限与动态路由。

PHP 需要 8.5.12 或更新稳定版，开启 PDO MySQL、session、filter、cURL、OpenSSL；数据库使用 MySQL/InnoDB。数据库、后台密码与HTTPS配置见 `mysql-deploy.md`。PHP-FPM 设置 `display_errors=Off`、`log_errors=On`、`zend.exception_ignore_args=On`。不要在反向代理、WAF、错误上报系统里采集 `/api.php` 请求体或响应体。

## 二、配置站点与通道

复制 `config.example.php` 为 `config.local.php`，填写 MySQL、后台密码哈希、准确的 HTTPS `app_url` 和可写的私有 `data_dir`。已有配置请保留数据库与 `admin_hash`，合并新增 `orbitcard` 配置。

`provider` 支持：

- `mock`：仅模拟接口，DEMO卡，不接收真实Session。
- `orbitcard`：真实接口通道，但仍受两个环境开关限制；关闭时显示服务未开放，拒绝提交且不锁卡。

API Key 和 Secret **只从环境变量读取**，不写入PHP配置、前端或日志。`.env.example` 仅为变量清单，程序不会自动读取 `.env` 文件。

| 环境变量 | 作用 |
| --- | --- |
| `ORBITCARD_ENABLED` | `1` 允许真实网络调用；默认关闭 |
| `ORBITCARD_ALLOW_CREATE` | `1` 允许创建真实订阅；默认关闭 |
| `ORBITCARD_API_KEY` | Orbitcard完整API Key |
| `ORBITCARD_API_SECRET` | 创建Key时显示一次的Secret |
| `ORBITCARD_CARD_ID` | 你在Orbitcard的有效支付卡ID，不是银行卡号或本站CDK |
| `ORBITCARD_MAX_SERVICE_FEE` | 每单可接受的最高平台服务费，USDT十进制字符串，例如按你实际费率填写；不是订阅支付金额 |

在 PHP-FPM pool 中用 `env[变量名] = 值` 注入上述变量，配置文件仅由管理员读取；重载 FPM 生效。CLI和FPM环境独立，在终端export变量不代表FPM能够读取。不要把密钥填进Nginx公开配置、URL参数或命令行参数。

## 三、确认支付卡、套餐与权限

在Orbitcard控制台创建具有 `subscriptions:read` 和 `subscriptions:create` 权限的Key，确认支付卡可用。支付卡ID在服务端配置；消费者只提交本人目标账号的完整Session与充值授权。

`config.local.php` 中 `orbitcard.plans` 将本站 Plus/Pro 映射到你可用的套餐：

- `type` 必须与 `getSubscriptionPlans` 目录返回的实际类型一致。
- `codes` 是允许的上游 `planCode`，用于最终结果核对。Plus提供文档中的 `chatgptplusplan` 示例映射；Pro保持空白，需确认实际产品后填写，不能猜测不同档位等价。
- 可选 `payment_region` 必须是该套餐地区接口返回的代码；未配置时采用当前推荐地区，没有推荐则由上游选择。不会固定复用别的套餐地区。

下单前自动读取已启用目录、核对费用上限、再读取该套餐地区。配置或目录不可用时在锁卡前拒绝，不会创建订阅。

只检查配置、不访问网络：

```sh
php scripts/orbitcard-check.php
```

以后你填好密钥并主动开启 `ORBITCARD_ENABLED=1`，保持 `ORBITCARD_ALLOW_CREATE=0`，可执行只读目录查询：

```sh
php scripts/orbitcard-check.php --catalog
```

这条命令会访问Orbitcard目录接口，但不会创建订阅。本轮未运行真实目录查询。

## 四、初始化与正式启用

先备份现有数据库、配置。执行 `php scripts/setup.php` 建表/补充字段，可重复执行；已有卡与订单的provider默认保持mock，不会转为真实充值。已有SQLite迁移流程继续支持，见MySQL文档。初始化后可撤销运行账号的DDL权限。

当你确认支付卡、费用上限、套餐映射和域名正确，并准备好进行实际充值时，才将 `provider` 改为 `orbitcard`、两个环境开关都设为 `1`。**这一步会允许真实支付，本轮保持关闭。** 在真实通道后台新生成的卡密以 `CDK-` 开头；旧 `DEMO-` 卡不会转为真实卡。后台旧模拟订单仍查询原Mock。

正式表单要求复制完整Session JSON，自动识别邮箱，再核对套餐并勾选授权。不要求手工补造 `sessionToken`；所有原有扩展字段随单次请求原样转发。本站不接收账号密码，不在数据库、日志、浏览器持久存储中保存Session；提交后清空页面资料。Orbitcard作为处理方按其订阅流程处理账号资料。

## 五、订单、超时与人机验证

- 创建响应、HTTP成功或平台 `code=0` 都不直接核销；只在原订单、邮箱、套餐一致，且详情明确成功和 `subscription_succeeded=true` 时核销。
- 本站原子创建订单并锁卡，真实POST领取一次。任何超时或重启只用原 `client_order_no` 查单，不自动重放创建，也不自动换幂等身份。
- Session不落盘，因此提交前进程崩溃时可能需要人工核实；如果平台查不到原单，本站保持待核实和锁卡，禁止靠新建订单猜测恢复。
- 同单查询间隔至少5秒、单次租约20秒，尊重上游完整 `Retry-After`。自动查询最多15分钟，之后仍可手动查原单。查询失败会标明本次失败；历史终态不会冒充最新查询结果。
- 人机验证只使用当前详情的临时HTTPS链接，保留 `#` 片段，在新窗口打开；缺失、过期、切换订单即清除，不存数据库或日志。验证完成或窗口关闭不能当作充值成功。
- 自动续费状态分别显示“已取消 / 未取消 / 暂无确认结果”，不会把缺失信息当作已取消。
- 真实失败订单不自动恢复卡密。Orbitcard服务费状态、HTTP失败或平台本地failed都不能证明银行卡没有扣款；请线下核实，不要重复充值。

## 六、部署验证与当前边界

部署后先看 `/health` 的 `provider` 与 `live_enabled`；它不返回任何密钥。浏览器应为HTTPS、域名与app_url一致，视频可播放。真实充值启用后需要你另行做一次有授权的小范围联调，确认实际套餐/地区/支付卡、上游验证域名及实际响应。

目前验证包含十三项Orbitcard离线协议/状态机测试、Mock与MySQL回归、HTTP关闭状态检查、前端凭证清理和验证多轮测试、Chrome视频播放及手机布局。没有访问真实充值API，也没有测试真实支付成功。

协议参考：[Orbitcard API文档](https://orbitcard.cc/orbitcard-subscription-api-v1.md)。
