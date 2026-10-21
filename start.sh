#!/bin/sh
set -eu
cd "$(dirname "$0")"
PHP="${REDEEM_PHP:-$PWD/.runtime/php/bin/php}"
if [ ! -x "$PHP" ]; then
  echo '请先运行 sh scripts/install-php.sh，或用 REDEEM_PHP 指定 PHP 8.5.12+。' >&2
  exit 1
fi
if [ -f .runtime/mysql-client.cnf ] && [ "$("$PHP" -r '$c=require (getenv("REDEEM_CONFIG")?:"config.local.php"); echo (($c["database"]["driver"]??"")==="mysql"&&($c["database"]["host"]??"")==="127.0.0.1"&&($c["database"]["port"]??0)===3307)?"local":"external";')" = local ]; then
  sh scripts/start-mysql-local.sh
fi
"$PHP" scripts/setup.php
echo '充值页：http://127.0.0.1:3100/'
echo '后台：http://127.0.0.1:3100/admin.php'
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec "$PHP" -S 127.0.0.1:3100 -t public router.php
