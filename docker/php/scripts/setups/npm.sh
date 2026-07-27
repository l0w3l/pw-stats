#!/bin/bash
set -e

source $HOME/.bashrc

cd /var/www/html

if [ "$APP_ENV" = "production" ]; then
    npm ci
    npm run build
else
    npm install
    npm run build
fi
