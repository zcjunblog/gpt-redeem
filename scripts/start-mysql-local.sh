#!/bin/sh
# Starts only this project's existing development database, never a system service.
set -eu
cd "$(dirname "$0")/.."
MYSQL_PREFIX="$(brew --prefix mysql@8.4)"
if [ ! -f .runtime/mysql-data/auto.cnf ] || [ ! -f .runtime/mysql-client.cnf ]; then
  echo '本机MySQL实例尚未初始化；服务器使用自己的MySQL，无需此脚本。' >&2
  exit 1
fi
if "$MYSQL_PREFIX/bin/mysqladmin" --defaults-extra-file="$PWD/.runtime/mysql-client.cnf" ping --silent >/dev/null 2>&1; then exit 0; fi
nohup "$MYSQL_PREFIX/bin/mysqld" --no-defaults --basedir="$MYSQL_PREFIX" --datadir="$PWD/.runtime/mysql-data" --bind-address=127.0.0.1 --port=3307 --socket="$PWD/.runtime/mysql.sock" --pid-file="$PWD/.runtime/mysql.pid" --mysqlx=OFF --log-error="$PWD/.runtime/mysql.log" > .runtime/mysql-console.log 2>&1 &
for attempt in 1 2 3 4 5 6 7 8 9 10; do
  if "$MYSQL_PREFIX/bin/mysqladmin" --defaults-extra-file="$PWD/.runtime/mysql-client.cnf" ping --silent >/dev/null 2>&1; then exit 0; fi
  sleep 1
done
echo 'MySQL未启动，请查看 .runtime/mysql.log。' >&2
exit 1
