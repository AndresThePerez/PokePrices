#!/bin/sh
# P0-i1-T28 edge cases: O1 fails closed and restores redis-cache, X1 names the exact offender,
# O5 stays on the gate database, S5 images are non-root, dns_opt is scoped to api.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
mkdir -p .gates/tmp; T=$(mktemp -d "$(pwd)/.gates/tmp/T28E-XXXXXX"); T=${T#"$(pwd)/"}
cache_healthy() { test "$($DC ps --format json redis-cache | jq -r .Health)" = healthy; }

# O1 against a port with nothing listening: (b), (c), (d) fail, 7 checks counted, redis-cache restored.
PP_HTTP_PORT=18099 GATES_OUT="$T" sh scripts/gates/o1.sh >"$T/o1-dead.log" 2>&1
rc=$?
cp "$T/O1.json" "$T/dead.json"; cp "$T/O1.details.jsonl" "$T/dead.details.jsonl"
check "O1 exits non-zero when the web port is dead" test "$rc" -ne 0
check "O1 dead-port run counted 7 checks" jq -e '.denominator == 7' "$T/dead.json"
check "O1 dead-port run failed b, c, d and e (nothing answers)" sh -c 'test "$(jq -rs "[.[] | select(.ok == false) | .check] | sort | join(\",\")" "$0/dead.details.jsonl")" = "b_up,c_ready_ok,d_ready_503,e_ready_restored"' "$T"
check "redis-cache healthy after the dead-port O1" sh -c "$DC up -d --wait redis-cache >/dev/null 2>&1; test \"\$($DC ps --format json redis-cache | jq -r .Health)\" = healthy"

# O1 interrupted with INT while redis-cache is stopped still restores it.
GATES_OUT="$T" sh scripts/gates/o1.sh >"$T/o1-int.log" 2>&1 &
pid=$!
i=0
while [ "$i" -lt 120 ]; do
  code=$(curl -sS -o /dev/null --max-time 2 -w '%{http_code}' --resolve pokeprices.localhost:8095:127.0.0.1 http://pokeprices.localhost:8095/v1/ready || true)
  [ "$code" = 503 ] && break
  timeout 1 tail -f /dev/null
  i=$((i + 1))
done
kill -INT "$pid" 2>/dev/null
wait "$pid" 2>/dev/null
check "INT landed while redis-cache was stopped" test "$i" -lt 120
check "redis-cache healthy after O1 got INT" sh -c "$DC up -d --wait redis-cache >/dev/null 2>&1; test \"\$($DC ps --format json redis-cache | jq -r .Health)\" = healthy"
check "/v1/ready is 200 after the INT" curl -fsS -o /dev/null --max-time 5 --retry 30 --retry-all-errors --retry-delay 1 --resolve pokeprices.localhost:8095:127.0.0.1 http://pokeprices.localhost:8095/v1/ready

# X1: one wrong value fails exactly that service; a table service that is not running is a mismatch.
printf '{"postgres":1073741824,"redis-queue":67108864,"redis-cache":134217728,"api":536870912,"worker":402653184,"scheduler":134217728,"web":1,"tools":536870912,"node":2147483648}\n' > "$T/w.json"
X1_TABLE="$T/w.json" GATES_OUT="$T" sh scripts/gates/x1.sh >/dev/null 2>&1
check "X1 wrong web cap fails" test $? -ne 0
check "X1 mismatches are only web (mem_limit and host_memory)" sh -c 'test "$(jq -s "[.[] | select(.ok == false) | .service] | unique | join(\",\")" -r "$0/X1.details.jsonl")" = web' "$T"
printf '{"postgres":1073741824,"redis-queue":67108864,"redis-cache":134217728,"api":536870912,"worker":402653184,"scheduler":134217728,"web":67108864,"tools":536870912,"node":2147483648,"ghost":1048576}\n' > "$T/g.json"
X1_TABLE="$T/g.json" GATES_OUT="$T" sh scripts/gates/x1.sh >/dev/null 2>&1
check "X1 table naming a service that is not running fails" test $? -ne 0
check "X1 ghost row reports not running" jq -se 'any(.[]; .service == "ghost" and .check == "host_memory" and .got == "not running")' "$T/X1.details.jsonl"
check "X1 real table passes" env GATES_OUT="$T" sh scripts/gates/x1.sh

# O5: dev and test databases keep their migration rows; only the gate database is migrated.
before=$($DC exec -T postgres psql -U pokeprices -d pokeprices -tAc "SELECT count(*) FROM migrations")
check "O5 passes" env GATES_OUT="$T" sh scripts/gates/o5.sh
after=$($DC exec -T postgres psql -U pokeprices -d pokeprices -tAc "SELECT count(*) FROM migrations")
check "O5 left the dev migrations table untouched" test "$before" = "$after" -a -n "$before"
check "O5 pins DB_DATABASE=pokeprices_gate on every migrate" sh -c 'test "$(grep -c "DB_DATABASE=pokeprices_gate" scripts/gates/o5.sh)" -ge 2 && ! grep -q "DB_DATABASE=pokeprices " scripts/gates/o5.sh'
check "O5 value is zero over a positive denominator" jq -e '.value == 0 and .denominator > 0' "$T/O5.json"

# S5: both gate images run as non-root.
check "S5 passes" env GATES_OUT="$T" sh scripts/gates/s5.sh
check "api gate image runs as non-root" sh -c 'u=$(docker inspect --format "{{.Config.User}}" pokeprices-api:gate); test -n "$u" -a "$u" != 0 -a "$u" != root'
check "web gate image runs as non-root" sh -c 'u=$(docker inspect --format "{{.Config.User}}" pokeprices-web:gate); test -n "$u" -a "$u" != 0 -a "$u" != root'

# dns_opt is only on api.
check "dns_opt appears on api only" sh -c '$0 config --format json | jq -e "[.services | to_entries[] | select(.value.dns_opt != null) | .key] == [\"api\"]"' "$DC"
check "api mem_limit is still 512m" sh -c '$0 config --format json | jq -e ".services.api.mem_limit == \"536870912\" or .services.api.mem_limit == 536870912"' "$DC"
finish
