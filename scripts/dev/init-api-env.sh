#!/bin/sh
# Create api/.env from api/.env.example only when absent, then generate APP_KEY once (R15).
# Never reads or prints api/.env.
set -eu
if [ ! -e api/.env ]; then
  cp api/.env.example api/.env
  docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan key:generate --force --no-interaction
fi
