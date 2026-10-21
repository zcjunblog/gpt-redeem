#!/bin/sh
# Reproduce the project-local macOS runtime without replacing system PHP.
set -eu
cd "$(dirname "$0")/.."
TASK_ROOT="$PWD"
PHP_VERSION=8.5.12
PHP_SHA256=08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209
command -v brew >/dev/null || { echo '此脚本需要 macOS Homebrew 和 Xcode Command Line Tools。' >&2; exit 1; }
brew install pkgconf openssl@4 curl sqlite
mkdir -p .runtime
cd .runtime
if [ ! -f "php-$PHP_VERSION.tar.xz" ]; then
  curl --fail --location --output "php-$PHP_VERSION.tar.xz" "https://www.php.net/distributions/php-$PHP_VERSION.tar.xz"
fi
echo "$PHP_SHA256  php-$PHP_VERSION.tar.xz" | shasum -a 256 -c -
if [ ! -d "php-$PHP_VERSION" ]; then tar -xf "php-$PHP_VERSION.tar.xz"; fi
export PKG_CONFIG_PATH="$(brew --prefix openssl@4)/lib/pkgconfig:$(brew --prefix curl)/lib/pkgconfig:$(brew --prefix sqlite)/lib/pkgconfig"
"./php-$PHP_VERSION/configure" --prefix="$TASK_ROOT/.runtime/php" --disable-all --enable-cli --disable-cgi --disable-phpdbg --enable-session --enable-filter --enable-ctype --enable-pdo --with-pdo-sqlite --with-sqlite3 --enable-mysqlnd --with-pdo-mysql=mysqlnd --with-openssl --with-curl
make -j6
make install
"$TASK_ROOT/.runtime/php/bin/php" -v
