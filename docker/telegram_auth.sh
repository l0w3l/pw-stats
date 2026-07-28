#!/bin/sh
set -eu

cd "$(dirname "$0")"

./dc run --rm --user 0 access-token chown -R 10001:10001 /app/session_data
./dc run --rm access-token python auth_session.py
