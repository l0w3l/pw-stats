#!/bin/bash
set -e

export HOME=/var/www
export NVM_DIR="$HOME/.nvm"

log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $1"
}

scripts_folder=/usr/local/bin/scripts

log "Starting setup..."

# Composer
if [ -f "composer.json" ]; then
    log "Installing composer dependencies..."
    bash "$scripts_folder"/setups/composer.sh
fi

# NPM dependencies are always installed. Development skips only the production
# build because the dev Compose overlay starts a real Vite process.
if [ -f "package.json" ]; then
    if [ "$APP_VITE_DEV" = "true" ]; then
        log "Installing npm dependencies for Vite development..."
        bash "$scripts_folder"/setups/npm.sh --install-only
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

log "Setup completed successfully."
