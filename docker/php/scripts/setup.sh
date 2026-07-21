#!/bin/bash
set -e

export HOME=/var/www
export NVM_DIR="$HOME/.nvm"

log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $1"
}

rm -f /var/www/html/.setup_done

scripts_folder=/usr/local/bin/scripts

log "Starting setup..."

# Composer
if [ -f "composer.json" ]; then
    log "Installing composer dependencies..."
    bash "$scripts_folder"/setups/composer.sh
fi

# NPM / Build (only if package.json exists)
if [ -f "package.json" ]; then
    if [ "$APP_VITE_DEV" = "true" ]; then
        log "Dev mode detected, skipping heavy npm build (vite will handle it)"
    else
        log "Installing npm dependencies and building assets..."
        bash "$scripts_folder"/setups/npm.sh
    fi
fi

# Laravel specific
log "Running Laravel optimizations..."
cd /var/www/html
php artisan migrate --force
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

touch /var/www/html/.setup_done
log "Setup completed successfully."
