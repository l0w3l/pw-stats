#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
args=()
while getopts 'bf' opt; do
    case "$opt" in
        b) args+=(--build) ;;
        f) args+=(--force-recreate) ;;
        *) exit 2 ;;
    esac
done

# Use dc so dotenv files are parsed by Compose, never executed by a shell.
exec "$SCRIPT_DIR/dc" prod up scheduler queue "${args[@]}"
