#!/bin/bash
set -e

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Waiting for setup to complete..."

while [ ! -f /var/www/html/.setup_done ]; do
  sleep 2
done

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Setup finished! Proceeding..."
