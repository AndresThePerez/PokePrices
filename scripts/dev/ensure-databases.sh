#!/bin/sh
# Create the PokéPrices test and gate databases when absent (idempotent).
set -eu
DC="docker compose -f compose.dev.yml"
for db in pokeprices_test pokeprices_gate; do
  present=$($DC exec -T postgres psql -U pokeprices -d pokeprices -tAc "SELECT 1 FROM pg_database WHERE datname = '$db'")
  if [ "$present" != "1" ]; then
    $DC exec -T postgres psql -U pokeprices -d pokeprices -v ON_ERROR_STOP=1 -c "CREATE DATABASE $db OWNER pokeprices"
  fi
done
