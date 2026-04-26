#!/bin/sh
set -e
# Create first admin from env when none exists (see seed_admin.php --if-empty).
php /var/www/html/bin/seed_admin.php --if-empty
exec docker-php-entrypoint "$@"
