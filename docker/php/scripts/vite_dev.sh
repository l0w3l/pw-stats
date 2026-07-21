#!/bin/bash
set -euo pipefail

export HOME=/var/www
export NVM_DIR="$HOME/.nvm"
# shellcheck disable=SC1091
source "$NVM_DIR/nvm.sh"
cd /var/www/html
exec npm run dev -- --host 0.0.0.0
