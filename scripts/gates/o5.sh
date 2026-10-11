#!/bin/sh
# O5: migrations round-trip (fresh, reset, migrate, status) on the gate database only (F-P0-061, F-P0-086).
# Every migrate command runs with DB_DATABASE=pokeprices_gate; the dev and test databases are never touched.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
top=$(git rev-parse --show-toplevel 2>/dev/null) && cd "$top"
details="$GATES_OUT/O5.details.jsonl"
: > "$details"
out="$GATES_OUT/O5.txt"
: > "$out"
sh scripts/dev/ensure-databases.sh >"$GATES_OUT/O5.ensure.log" 2>&1
ensure=$?
if [ "$ensure" -eq 0 ]; then
  docker compose -f compose.dev.yml exec -T -e DB_DATABASE=pokeprices_gate -w /workspace/api api \
    sh -c 'export DB_DATABASE=pokeprices_gate; php artisan migrate:fresh --force --no-interaction && php artisan migrate:reset --force --no-interaction && php artisan migrate --force --no-interaction && php artisan migrate:status --no-interaction' \
    >"$out" 2>&1
  rc=$?
else
  rc=$ensure
fi
pending=$(/usr/bin/grep -c 'Pending' "$out")
[ -n "$pending" ] || pending=0
value=$pending
[ "$rc" -eq 0 ] || value=$((value + 1))
denominator=$(ls api/database/migrations/*.php 2>/dev/null | wc -l)
jq -nc --argjson ensure "$ensure" --argjson rc "$rc" --argjson pending "$pending" --argjson files "$denominator" \
  '{database: "pokeprices_gate", ensure_databases_exit: $ensure, migrate_exit: $rc, pending_lines: $pending, migration_files: $files}' >> "$details"
gate_result O5 "$value" "$denominator" "$details"; gr=$?
printf 'O5: %s failures (exit %s, %s pending) over %s migrations on pokeprices_gate\n' "$value" "$rc" "$pending" "$denominator"
[ "$gr" -eq 0 ] && [ "$value" -eq 0 ]
