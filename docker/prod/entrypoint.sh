#!/bin/sh
# Роли: web (API + фронтенд, применяет миграции), queue, backups, scheduler; любая другая команда выполняется как есть.
set -e
cd /var/www/html

# Пустой том storage — разворачиваем структуру каталогов
cp -rn /var/www/storage-skel/. storage/
chown -R www-data:www-data storage

as_app() { exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }

role="${1:-web}"
export SK_ROLE="$role"

case "$role" in
    web)
        until php artisan migrate --force --no-interaction; do
            echo "Жду базу данных…"; sleep 3
        done
        setpriv --reuid=www-data --regid=www-data --init-groups php artisan optimize
        exec supervisord -c /etc/supervisor/supervisord.conf
        ;;
    queue)
        setpriv --reuid=www-data --regid=www-data --init-groups php artisan optimize
        as_app php artisan queue:work --tries=3 --sleep=3
        ;;
    backups)
        setpriv --reuid=www-data --regid=www-data --init-groups php artisan optimize
        as_app php artisan queue:work --queue=backups --tries=1 --timeout=14400 --sleep=3
        ;;
    scheduler)
        setpriv --reuid=www-data --regid=www-data --init-groups php artisan optimize
        as_app php artisan schedule:work
        ;;
    *)
        as_app "$@"
        ;;
esac
