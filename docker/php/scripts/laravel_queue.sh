#!/bin/bash
set -e

scripts_folder=/usr/local/bin/scripts
bash "$scripts_folder"/wait_setup.sh

cd /var/www/html
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Starting Laravel Queue Worker..."
php artisan queue:work --verbose --tries=3 --timeout=0
