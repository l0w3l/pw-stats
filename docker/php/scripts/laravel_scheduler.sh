#!/bin/bash
set -e

cd /var/www/html
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Starting Laravel Scheduler..."
# In some environments schedule:work is preferred for simplicity in containers
exec php artisan schedule:work
