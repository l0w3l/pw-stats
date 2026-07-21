#!/bin/bash
set -euo pipefail

export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
# shellcheck disable=SC1091
source "$NVM_DIR/nvm.sh"

cd /var/www/html

npm ci

if [ "${1:-}" != "--install-only" ]; then
    npm run build
fi
