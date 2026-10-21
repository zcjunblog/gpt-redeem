# MySQL 服务器部署

当前程序支持 PHP 8.5.12+、PDO MySQL、MySQL InnoDB。已使用 MySQL 8.4.13 验证，建议服务器采用 MySQL 8.4 系列。Orbitcard真实接口代码已实现但默认关闭，密钥与启用步骤另见 `orbitcard-deploy.md`；当前本机继续使用模拟充值。

## 1. 准备目录和运行环境

服务器安装 Nginx、PHP 8.5.12 或更新稳定版的 PHP-FPM/CLI、MySQL 8.4。PHP 开启 `pdo_mysql`、`session`、`filter`；从 SQLite 迁移时另外开启 `pdo_sqlite`，HTTP 开发测试需要 `curl`。CLI 和 FPM 都须开启所需扩展。

将源码上传到 `/var/www/gpt-redeem`，无需上传 `.runtime/`、`artifacts/`、`tests/` 或本机私有配置。新部署无需上传本机 `data/`；要继续已有订单时按第 5 节操作。`.runtime/` 是本机 Mac 的编译产物，服务器应使用自己的 PHP。

网站根目录设为 `/var/www/gpt-redeem/public`。PHP-FPM 运行用户需要读源码、读私有配置、写 `data/`；不要使用 777 权限。配置文件保存在 `public/` 外，通常由部署用户所有、PHP-FPM 用户组可读，权限 640；`data/` 可设 PHP-FPM 用户所有、权限 700。

## 2. 创建数据库与专用账号

在 MySQL 管理终端执行，替换示例密码：

```sql
CREATE DATABASE gpt_redeem CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE USER 'gpt_redeem'@'localhost' IDENTIFIED BY '替换为随机数据库密码';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, REFERENCES, ALTER
ON gpt_redeem.* TO 'gpt_redeem'@'localhost';
```

这里使用同机 MySQL 的 `127.0.0.1:3306`。网站使用专用账号，不使用 root。初始化完成后，可撤销该账号的 CREATE、INDEX、REFERENCES、ALTER 权限；Web 请求不执行 MySQL 建表。

## 3. 填写配置并初始化

复制 `config.example.php` 为 `config.local.php`，填写：

- `app_url`：真实站点地址，例如 `https://recharge.example.com`，结尾不要加斜杠。
- `database`：MySQL 地址、端口、库名、账号、密码。
- `admin_hash`：管理员密码哈希。
- `data_dir`：PHP-FPM 可写的私有目录。

通过标准输入生成密码哈希，避免将密码放进命令行参数。以下命令适用于 Bash：

```bash
read -rs -p '管理员密码（至少16字符）: ' REDEEM_ADMIN_PASSWORD
printf '%s' "$REDEEM_ADMIN_PASSWORD" | php scripts/password.php
unset REDEEM_ADMIN_PASSWORD
```

将输出哈希填入 `admin_hash`，妥善保存自己输入的密码。新站执行：

```sh
php scripts/setup.php
```

脚本创建五张 InnoDB 表并补充已有表缺少的字段；mock通道首次空库会生成模拟卡密到 `data/demo-codes.txt`，真实通道不自动生成测试卡。新库可用MySQL客户端导入 `database/mysql.sql`；旧库升级请运行setup脚本。重复初始化不会覆盖已有数据。

## 4. 配置 Nginx + PHP-FPM

参考 `deploy/nginx.conf.example`，替换域名、证书路径、项目路径及 PHP-FPM socket，执行 `nginx -t` 后再加载。示例让所有动态请求经过 `router.php` 的路由白名单，只有 `public/assets/` 的静态文件直接公开。

PHP-FPM 建议 `display_errors=Off`、`log_errors=On`、`expose_php=Off`。示例直接由 Nginx 终止 HTTPS，并把 HTTPS 状态传给 PHP；`app_url`、实际域名和协议必须一致。若前面还有 CDN 或其他代理，应在受信任的 Nginx 配置中传递正确 HTTPS 状态，不要直接信任任意客户端的 X-Forwarded-* 头。

验证首页、`/admin.php` 及 `/health`。健康响应应含 `"database":"mysql"`。登录后台，生成一张模拟卡，使用 `demo@example.com` 完成兑换，确认后台卡密核销；不要用 PHP 内置开发服务器作为公网服务。

## 5. 从本机旧 SQLite 迁移

迁移保留卡密哈希、本站订单、模拟上游任务及幂等身份、事件和限流记录。初始完整卡密仍在旧 `data/demo-codes.txt`，数据库本身没有完整卡密，无法重新导出。

1. 停止旧站写入，备份整个 `data/` 和旧配置；SQLite 使用 WAL 时须一并保留 `.sqlite-wal`，不要只复制一个正在写入的 `.sqlite` 文件。
2. 创建全新 MySQL 库，配置 `config.local.php` 指向它。若需保留原后台密码，把旧 `admin_hash` 一并保留。
3. **不要先运行 setup.php 发测试卡。** 对空目标执行：

```sh
php scripts/migrate-mysql.php /绝对路径/旧数据/app.sqlite
```

4. 脚本建表，在一个事务中导入全部数据，并对每张表逐行规范化后做 SHA256 校验。失败回滚全部插入；旧 SQLite 只读，不会改写或删除。
5. 成功后启动新站，查询已有原单，再进行一张新卡的模拟兑换。

重复执行时，目标与源完全相同会直接校验通过；目标已有不同数据则拒绝覆盖。迁移期间不要同时运行新旧站点。数据库变更后，新站产生的记录不会自动写回旧库；若切回旧库，先停止写入并处理切换后产生的新记录，避免遗失或重复兑换。

## 当前本机情况

本机 PHP 已加入 `pdo_mysql`。独立 MySQL 位于项目 `.runtime/mysql-data`，仅监听 `127.0.0.1:3307`，数据库 `gpt_redeem`；凭证保存在私有配置。`sh start.sh` 会按本机配置启动该实例再启动 3100 的 PHP 演示站，不注册系统开机服务。

官方参考：[PDO MySQL](https://www.php.net/manual/en/ref.pdo-mysql.php)、[InnoDB 事务与锁](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking.html)。
