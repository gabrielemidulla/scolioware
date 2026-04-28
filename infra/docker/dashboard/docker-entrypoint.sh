#!/bin/sh
set -e
php /var/www/html/bin/seed_admin.php --if-empty
exec docker-php-entrypoint "$@"
