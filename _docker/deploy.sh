#!/bin/bash
set -e

# Установить зависимости Composer
composer install --ignore-platform-reqs

# Пересоздать кэш
php artisan optimize
php artisan queue:restart
cp _docker/supervisor/supervisord.conf  /etc/supervisor/supervisord.conf
supervisorctl update


npm install --legacy-peer-deps
npm run build
./vendor/bin/openapi app -o resources/swagger/openapi.json
