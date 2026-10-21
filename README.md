# GPT 卡密兑换站

轻量的 ChatGPT 卡密兑换网站，使用 **PHP + MySQL + 原生 HTML/CSS/JS**，支持卡密发放、充值、进度查询和后台管理，已包含使用教程视频。运行无需 Composer、Node、Redis 或队列服务。

**默认使用模拟接口，不会发起真实充值。** Orbitcard 接入代码已完成，真实密钥联调与支付尚未验证，启用方式见下文。

## 部署环境

- PHP **8.5.12+**（CLI 和 PHP-FPM），扩展：`pdo_mysql`、`session`、`filter`、`curl`、`openssl`。
- MySQL **8.4**，使用 InnoDB。
- Nginx、域名及 HTTPS 证书。

## 服务器部署

以下以 `/var/www/gpt-redeem` 为例；使用宝塔等面板时，按实际目录、PHP-FPM 用户和 socket 修改。

### 1. 下载源码

```sh
git clone https://github.com/zcjunblog/gpt-redeem.git /var/www/gpt-redeem
cd /var/www/gpt-redeem
```

私有仓库需要先登录 GitHub，也可下载 ZIP 后上传解压。`public/media/` 内的视频和封面需一起上传。

### 2. 创建 MySQL 数据库

在 MySQL 管理终端执行，替换示例密码：

```sql
CREATE DATABASE gpt_redeem CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE USER 'gpt_redeem'@'localhost' IDENTIFIED BY '替换为随机数据库密码';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, REFERENCES, ALTER
ON gpt_redeem.* TO 'gpt_redeem'@'localhost';
```

### 3. 填写配置

```sh
cp config.example.php config.local.php
```

编辑 `config.local.php`，填写以下内容：

| 配置 | 填写内容 |
| --- | --- |
| `app_url` | 网站完整地址，如 `https://recharge.example.com`，末尾不加 `/` |
| `database` | MySQL 地址、端口、库名、用户名和密码 |
| `admin_user` | 后台用户名 |
| `admin_hash` | 下方命令生成的密码哈希 |
| `provider` | 先保持 `mock`，用于模拟测试 |

在 **Bash** 中输入至少 16 个字符的后台密码，并把输出哈希填入 `admin_hash`：

```bash
read -rs -p '管理员密码（至少16字符）: ' REDEEM_ADMIN_PASSWORD
printf '%s' "$REDEEM_ADMIN_PASSWORD" | php scripts/password.php
unset REDEEM_ADMIN_PASSWORD
```

登录时使用自己输入的原始密码。配置文件放在 `public/` 外，不提交到 GitHub。

### 4. 初始化与目录权限

```sh
php scripts/setup.php
```

脚本会自动建表；模拟模式首次空库生成的测试卡密保存在 `data/demo-codes.txt`。重复执行不会覆盖已有配置或数据。

让 PHP-FPM 可以读取配置、写入 `data/`。以下假设其用户和组为 `www-data`，**请按服务器实际情况替换**：

```sh
sudo chgrp www-data config.local.php
sudo chmod 640 config.local.php
sudo chown -R www-data:www-data data
sudo chmod 700 data
```

### 5. 配置 Nginx 和 HTTPS

使用仓库中的 [Nginx 配置示例](deploy/nginx.conf.example)，替换域名、证书路径、项目路径和 PHP-FPM socket，然后执行 `nginx -t` 并重载 Nginx。

**网站根目录设为 `/var/www/gpt-redeem/public`，动态请求交给项目根目录的 `router.php`。** 请使用上述示例，不能仅设置根目录后套用普通 PHP 配置。`app_url` 必须与访问域名及 HTTPS 协议一致。

PHP-FPM 建议设置 `display_errors=Off`、`log_errors=On`、`zend.exception_ignore_args=On`、`expose_php=Off`。不要记录充值接口的请求体和响应体。

### 6. 检查部署

- 首页：`https://你的域名/`
- 后台：`https://你的域名/admin.php`
- 健康检查：`https://你的域名/health`，应显示 `database: mysql`、`provider: mock`、`live_enabled: false`。

登录后台，生成模拟卡密，用虚构邮箱 `demo@example.com` 跑通兑换、查询和卡密核销，再检查教程视频能否播放。

## 以后接通 Orbitcard

在 `config.local.php` 中将 `provider` 改为 `orbitcard`，确认 `orbitcard.plans` 的套餐映射，并在 **PHP-FPM 环境**中配置：

| 环境变量 | 说明 |
| --- | --- |
| `ORBITCARD_API_KEY` / `ORBITCARD_API_SECRET` | Orbitcard API 凭证 |
| `ORBITCARD_CARD_ID` | Orbitcard 有效支付卡 ID |
| `ORBITCARD_MAX_SERVICE_FEE` | 每单可接受的最高平台服务费，USDT 十进制数 |
| `ORBITCARD_ENABLED` | `1` 允许访问真实接口，默认 `0` |
| `ORBITCARD_ALLOW_CREATE` | `1` 允许真实充值，默认 `0` |

**两个开关都为 `1` 才允许真实充值，当前保持关闭。** 密钥只从环境变量读取；`.env.example` 是变量清单，程序不会自动加载 `.env`。PHP-FPM 可用 `env[变量名] = 值` 配置并重载，终端 `export` 不会自动传给 FPM。Pro 套餐映射需确认后再填写。

执行 `php scripts/orbitcard-check.php` 可离线检查配置，不发起网络请求。支付卡、权限、只读目录检查及正式启用步骤见 [Orbitcard 接入说明](docs/orbitcard-deploy.md)。

## 本地运行与后续维护

已配置 MySQL 后，可用 PHP 内置服务器进行本地测试（不用于公网）：

```sh
php scripts/setup.php
php -S 127.0.0.1:3100 -t public router.php
```

本地将 `app_url` 设为 `http://127.0.0.1:3100`，保留 `provider: mock`。现有 Mac 开发环境也可直接运行 `sh start.sh`。

更新前备份 MySQL、`config.local.php` 和 `data/`，更新代码后运行 `php scripts/setup.php` 补充数据库字段。服务器私有配置和数据不在 Git 仓库中，本机 `.runtime/` 也不需要上传。

从旧 SQLite 迁移时，**不要先初始化或生成测试卡**，请按 [MySQL 部署与迁移说明](docs/mysql-deploy.md) 操作。代码测试和离线接口验证记录见 [验证说明](docs/orbitcard-verification.md)。
