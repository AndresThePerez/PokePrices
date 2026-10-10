#!/bin/sh
# P0-i1-T03: two Redis roles with real maxmemory, test and gate databases present.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
J() { docker compose -f compose.dev.yml config --format json | jq -e "$1"; }
check "redis-queue capped at 64m" J '(.services["redis-queue"].mem_limit|tonumber) == 67108864'
check "redis-cache capped at 128m" J '(.services["redis-cache"].mem_limit|tonumber) == 134217728'
check "no data service publishes a port" J '[.services.postgres, .services["redis-queue"], .services["redis-cache"]] | all((.ports // []) | length == 0)'
check "redis images digest-pinned" J '[.services["redis-queue"].image, .services["redis-cache"].image] | all(test("@sha256:[0-9a-f]{64}$"))'
check "data tier healthy" $DC up -d --wait postgres redis-queue redis-cache
rq() { $DC exec -T redis-queue redis-cli CONFIG GET "$1" | sed -n 2p; }
rc() { $DC exec -T redis-cache redis-cli CONFIG GET "$1" | sed -n 2p; }
check_out "queue policy noeviction" noeviction rq maxmemory-policy
check_out "queue maxmemory 48mb" 50331648 rq maxmemory
check_out "queue appendonly yes" yes rq appendonly
check_out "cache policy allkeys-lru" allkeys-lru rc maxmemory-policy
check_out "cache maxmemory 96mb" 100663296 rc maxmemory
check_out "cache appendonly no" no rc appendonly
check "ensure-databases runs" scripts/dev/ensure-databases.sh
check "ensure-databases is idempotent" scripts/dev/ensure-databases.sh
for db in pokeprices pokeprices_test pokeprices_gate; do
  check_out "database $db exists" 1 $DC exec -T postgres psql -U pokeprices -d pokeprices -tAc "SELECT count(*) FROM pg_database WHERE datname = '$db'"
done
check_out "postgres container memory limit enforced" 1073741824 docker inspect --format '{{.HostConfig.Memory}}' "$($DC ps -q postgres)"
check_out "redis-cache container memory limit enforced" 134217728 docker inspect --format '{{.HostConfig.Memory}}' "$($DC ps -q redis-cache)"
finish
