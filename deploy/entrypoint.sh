#!/bin/sh
set -eu
cd /var/www/html
if [ "$1" = "apache2-foreground" ]; then
  mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
  chown -R www-data:www-data storage bootstrap/cache
  if [ "${MIGRATE_ON_START:-false}" = "true" ]; then
    echo '[DupIssue] Applying pending Laravel migrations (not migrate:fresh)'
    php artisan migrate --force --no-interaction
  fi
  php artisan storage:link --force || true
  php artisan config:cache
  php artisan view:cache
  echo '[DupIssue] Apache starting'
fi
exec "$@"
