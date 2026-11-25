#!/bin/sh
# P0-i1-T28: runtime gates are green on the stack, catch a wrong cap, and O1 always restores redis-cache.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
mkdir -p .gates/tmp; T=$(mktemp -d "$(pwd)/.gates/tmp/T28-XXXXXX"); T=${T#"$(pwd)/"}
check "stack healthy" $DC up -d --wait
for g in o1 o5 x1 s5; do
  check "$g passes" env GATES_OUT="$T" sh "scripts/gates/$g.sh"
done
check "O1 ran at least 7 checks" jq -e '.denominator >= 7 and .value == 0' "$T/O1.json"
check "X1 checked 9 services" jq -e '.denominator >= 9 and .value == 0' "$T/X1.json"
check "O5 saw at least 3 migrations" jq -e '.denominator >= 3' "$T/O5.json"
check "S5 inspected 2 images" jq -e '.denominator == 2' "$T/S5.json"
printf '{"postgres":1073741824,"redis-queue":67108864,"redis-cache":134217728,"api":536870912,"worker":402653184,"scheduler":134217728,"web":1,"tools":536870912,"node":2147483648}\n' > "$T/x1-wrong.json"
check "X1 catches a cap that differs from the table" sh -c '! env GATES_OUT="$0" X1_TABLE="$0/x1-wrong.json" sh scripts/gates/x1.sh' "$T"
check "O5 never touched the dev database" sh -c 'test "$(docker compose -f compose.dev.yml exec -T postgres psql -U pokeprices -d pokeprices -tAc "SELECT count(*) FROM migrations")" -ge 1'
GATES_OUT="$T" sh scripts/gates/o1.sh > "$T/o1-term.log" 2>&1 &
pid=$!
i=0
while [ "$i" -lt 120 ]; do
  code=$(curl -sS -o /dev/null --max-time 2 -w '%{http_code}' --resolve pokeprices.localhost:8095:127.0.0.1 http://pokeprices.localhost:8095/v1/ready || true)
  [ "$code" = 503 ] && break
  timeout 1 tail -f /dev/null
  i=$((i + 1))
done
kill -TERM "$pid" 2>/dev/null
wait "$pid" 2>/dev/null
check "the TERM landed while redis-cache was stopped" test "$i" -lt 120
check "redis-cache is healthy again after O1 was terminated mid-run" sh -c 'docker compose -f compose.dev.yml up -d --wait redis-cache && test "$(docker compose -f compose.dev.yml ps --format json redis-cache | jq -r .Health)" = healthy'
finish
