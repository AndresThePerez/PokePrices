#!/bin/sh
# P0-i1-T03 edge cases (reviewer): live Redis behaviour under load and restart, persistence
# files, runtime port exposure, service-to-service reachability, bootstrap idempotence.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
$DC up -d --wait postgres redis-queue redis-cache >/dev/null 2>&1
cleanup() {
  $DC exec -T redis-queue redis-cli FLUSHALL >/dev/null 2>&1
  $DC exec -T redis-cache redis-cli FLUSHALL >/dev/null 2>&1
}
trap cleanup EXIT
cleanup
rq() { $DC exec -T redis-queue redis-cli "$@"; }
rc() { $DC exec -T redis-cache redis-cli "$@"; }
# Separate 100 KB SET commands (a single EVAL is not re-checked against maxmemory per call).
fill() { # $1 service, $2 key count; prints redis-cli replies
  $DC exec -T "$1" sh -c 'v=$(head -c 100000 /dev/zero | tr "\0" x); i=0; while [ "$i" -lt '"$2"' ]; do echo "SET fill:$i $v"; i=$((i+1)); done | redis-cli'
}

# --- 1. configuration the shipped script does not assert -----------------------------------
check_out "queue RDB snapshots are off (save is empty)" "" sh -c "$DC exec -T redis-queue redis-cli CONFIG GET save | sed -n 2p"
check_out "cache RDB snapshots are off (save is empty)" "" sh -c "$DC exec -T redis-cache redis-cli CONFIG GET save | sed -n 2p"
check_out "queue container memory limit enforced (64m)" 67108864 docker inspect --format '{{.HostConfig.Memory}}' "$($DC ps -q redis-queue)"
check "maxmemory sits below the container cap for both roles" sh -c '
  j=$(docker compose -f compose.dev.yml config --format json)
  printf "%s" "$j" | jq -e "(.services[\"redis-queue\"].mem_limit|tonumber) > 50331648 and (.services[\"redis-cache\"].mem_limit|tonumber) > 100663296"'
check "queue and cache run the same image digest as compose pins" sh -c '
  want=3811787313eba226a2ef38658c6ccb91cd5e110edc89c37767de373120a0e5a0
  for s in redis-queue redis-cache; do
    docker inspect --format "{{.Config.Image}}" "$(docker compose -f compose.dev.yml ps -q $s)" | /usr/bin/grep -q "@sha256:$want\$" || exit 1
  done'
check "queue AOF is on in the redisqueue volume and has no RDB file" sh -c '
  docker compose -f compose.dev.yml exec -T redis-queue sh -c "test -d /data/appendonlydir && ! test -e /data/dump.rdb"'
check "cache has no AOF directory and no RDB file" sh -c '
  docker compose -f compose.dev.yml exec -T redis-cache sh -c "! test -e /data/appendonlydir && ! test -e /data/dump.rdb"'
check "queue /data is backed by the named redisqueue volume" sh -c '
  docker inspect --format "{{range .Mounts}}{{.Name}}:{{.Destination}} {{end}}" "$(docker compose -f compose.dev.yml ps -q redis-queue)" | /usr/bin/grep -q "redisqueue:/data"'

# --- 2. nothing is published on the host at runtime ------------------------------------------
for s in postgres redis-queue redis-cache; do
  check_out "$s has no published host port at runtime" "" sh -c "docker port \"\$($DC ps -q $s)\" 2>/dev/null"
done

# --- 3. services reach each other by service name (what the api will use) ---------------------
check_out "redis-queue answers PONG to a peer by service name" PONG sh -c "$DC exec -T redis-cache redis-cli -h redis-queue ping"
check_out "redis-cache answers PONG to a peer by service name" PONG sh -c "$DC exec -T redis-queue redis-cli -h redis-cache ping"
check "postgres accepts connections on its service name" sh -c "$DC exec -T postgres pg_isready -h postgres -U pokeprices -d pokeprices"

# --- 4. behaviour at the cap: queue refuses, cache evicts -------------------------------------
queue_fill=$(fill redis-queue 1500 2>&1)
check "queue (noeviction) rejects writes past maxmemory with OOM" sh -c 'printf "%s" "$1" | /usr/bin/grep -q "OOM command not allowed"' _ "$queue_fill"
check "queue keeps its already-written keys (nothing was evicted)" sh -c '
  n=$(docker compose -f compose.dev.yml exec -T redis-queue redis-cli DBSIZE | tr -dc 0-9); [ "$n" -ge 30 ] && [ "$n" -lt 1500 ]'
check "queue used_memory stays under the 64m container cap" sh -c '
  m=$(docker compose -f compose.dev.yml exec -T redis-queue redis-cli INFO memory | tr -d "\r" | sed -n "s/^used_memory://p"); [ "$m" -lt 67108864 ]'
cache_fill=$(fill redis-cache 1500 2>&1)
check "cache (allkeys-lru) accepts writes past maxmemory without error" sh -c '! printf "%s" "$1" | /usr/bin/grep -qi "error\|OOM"' _ "$cache_fill"
check "cache evicted keys instead of growing past maxmemory" sh -c '
  n=$(docker compose -f compose.dev.yml exec -T redis-cache redis-cli DBSIZE | tr -dc 0-9)
  e=$(docker compose -f compose.dev.yml exec -T redis-cache redis-cli INFO stats | tr -d "\r" | sed -n "s/^evicted_keys://p")
  m=$(docker compose -f compose.dev.yml exec -T redis-cache redis-cli INFO memory | tr -d "\r" | sed -n "s/^used_memory://p")
  [ "$e" -gt 0 ] && [ "$n" -lt 1500 ] && [ "$m" -le 100663296 ]'
check "cache container is still running and healthy after the fill" sh -c '
  [ "$(docker inspect --format "{{.State.Health.Status}}" "$(docker compose -f compose.dev.yml ps -q redis-cache)")" = healthy ]'
cleanup

# --- 5. persistence across restart: queue keeps data, cache loses it --------------------------
rq SET edge:queue survives >/dev/null
rc SET edge:cache gone >/dev/null
$DC restart redis-queue redis-cache >/dev/null 2>&1
$DC up -d --wait redis-queue redis-cache >/dev/null 2>&1
check_out "queue key survives a restart (AOF)" survives rq GET edge:queue
check_out "cache key does not survive a restart (no persistence)" "" rc GET edge:cache

# --- 6. bootstrap script ----------------------------------------------------------------------
psql_pp() { $DC exec -T postgres psql -U pokeprices -d "$1" -tAc "$2"; }
psql_pp pokeprices_test "CREATE TABLE IF NOT EXISTS edge_marker(i int); INSERT INTO edge_marker VALUES (42)" >/dev/null 2>&1
check "ensure-databases leaves existing databases and their data intact" sh -c '
  scripts/dev/ensure-databases.sh >/dev/null 2>&1 && scripts/dev/ensure-databases.sh >/dev/null 2>&1 &&
  [ "$(docker compose -f compose.dev.yml exec -T postgres psql -U pokeprices -d pokeprices_test -tAc "SELECT i FROM edge_marker")" = 42 ]'
psql_pp pokeprices_test "DROP TABLE edge_marker" >/dev/null 2>&1
check "ensure-databases recreates a missing gate database" sh -c '
  docker compose -f compose.dev.yml exec -T postgres psql -U pokeprices -d pokeprices -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS pokeprices_gate" >/dev/null &&
  scripts/dev/ensure-databases.sh >/dev/null 2>&1 &&
  [ "$(docker compose -f compose.dev.yml exec -T postgres psql -U pokeprices -d pokeprices -tAc "SELECT count(*) FROM pg_database WHERE datname = '"'"'pokeprices_gate'"'"'")" = 1 ]'
check_out "test and gate databases are owned by pokeprices" "pokeprices|pokeprices" sh -c "$DC exec -T postgres psql -U pokeprices -d pokeprices -tAc \"SELECT string_agg(pg_get_userbyid(datdba), '|' ORDER BY datname) FROM pg_database WHERE datname IN ('pokeprices_gate','pokeprices_test')\""
# pest --parallel (T07) leaves Laravel's per-worker databases pokeprices_test_test_N behind; they are not created by ensure-databases.
check_out "ensure-databases only creates the two names (no stray databases)" "pokeprices,pokeprices_gate,pokeprices_test,postgres" sh -c "$DC exec -T postgres psql -U pokeprices -d pokeprices -tAc \"SELECT string_agg(datname, ',' ORDER BY datname) FROM pg_database WHERE NOT datistemplate AND datname !~ '^pokeprices_test_test_[0-9]+\\$'\""
check "ensure-databases exits non-zero when it cannot reach the compose project (no masking)" sh -c '
  cd "$(mktemp -d)" && ! /bin/sh "$OLDPWD/scripts/dev/ensure-databases.sh" >/dev/null 2>&1'
finish
