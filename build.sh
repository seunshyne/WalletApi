#!/usr/bin/env bash
# exit on error
set -o errexit

composer install --no-dev --working-dir=/opt/render/project/src

php artisan config:clear
php artisan cache:clear
php artisan migrate --force