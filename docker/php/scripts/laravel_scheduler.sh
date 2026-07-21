#!/bin/bash
set -e

scripts_folder=/usr/local/bin/scripts
bash "$scripts_folder"/wait_setup.sh

cd /var/www/html
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Starting Laravel Scheduler..."
# In some environments schedule:work is preferred for simplicity in containers
php artisan schedule:work
