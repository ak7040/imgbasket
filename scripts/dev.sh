#!/bin/sh
# Runs the site locally at http://127.0.0.1:8080 with PHP's built-in server.
# Needs app/config.php (copy app/config.sample.php) and a MySQL/MariaDB database.
cd "$(dirname "$0")/.." || exit 1
PHP_CLI_SERVER_WORKERS=4 exec php \
  -d upload_max_filesize=2048M -d post_max_size=2100M -d memory_limit=512M \
  -S 127.0.0.1:8080 -t . scripts/dev-router.php
