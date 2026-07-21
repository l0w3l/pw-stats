#!/bin/bash
set -e

cd /var/www/html
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Starting Laravel Queue Worker..."
exec php artisan queue:work --verbose --tries=3 --timeout=0
