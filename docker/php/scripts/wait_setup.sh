#!/bin/bash
set -euo pipefail

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Waiting for the database to be ready..."
cd /var/www/html
for attempt in $(seq 1 60); do
    if php artisan migrate:status >/dev/null 2>&1; then
        echo "[$(date +'%Y-%m-%d %H:%M:%S')] Database is ready."
        exit 0
    fi
    sleep 2
done

echo "Timed out waiting for the database after 120 seconds." >&2
exit 1
