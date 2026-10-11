#!/bin/sh
# X1: compose mem_limit and the running container's cgroup cap match the server table, postgres has its
# shm and both Redis instances enforce maxmemory with their policy (F-P0-046, F-P0-068, F-P0-091).
# X1_TABLE (a JSON file of service -> bytes) replaces the built-in table; tests only.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
table_file=${X1_TABLE:-}
case $table_file in ''|/*) ;; *) table_file=$(pwd)/$table_file ;; esac
top=$(git rev-parse --show-toplevel 2>/dev/null) && cd "$top"
details="$GATES_OUT/X1.details.jsonl"
: > "$details"
table='{"postgres":1073741824,"redis-queue":67108864,"redis-cache":134217728,"api":402653184,"worker":402653184,"scheduler":134217728,"web":67108864,"tools":536870912,"node":1073741824}'
if [ -n "$table_file" ]; then
  table=$(jq -c . "$table_file") || table='{}'
fi
mismatch=0
n=0
note() { # KIND SUBJECT WANT GOT OK
  [ "$5" = true ] || mismatch=$((mismatch + 1))
  jq -nc --arg k "$1" --arg s "$2" --arg w "$3" --arg g "$4" --argjson ok "$5" \
    '{check: $k, service: $s, want: $w, got: $g, ok: $ok}' >> "$details"
}
cid_of() { docker compose -f compose.dev.yml ps -q "$1" 2>/dev/null | head -n 1; }
cfg="$GATES_OUT/X1.compose.json"
if ! docker compose -f compose.dev.yml config --format json > "$cfg" 2>"$GATES_OUT/X1.compose.err"; then
  printf '{}' > "$cfg"
fi
entries=$(printf '%s' "$table" | jq -r 'to_entries[] | "\(.key) \(.value)"')
[ -n "$entries" ] || note table "" "nine services" "empty table" false
for pair in $(printf '%s\n' "$entries" | tr ' ' '='); do
  s=${pair%%=*}; want=${pair#*=}
  n=$((n + 1))
  conf=$(jq -r --arg s "$s" '.services[$s].mem_limit // "missing" | tostring' "$cfg" 2>/dev/null)
  [ -n "$conf" ] || conf=missing
  if [ "$conf" = "$want" ]; then note mem_limit "$s" "$want" "$conf" true
  else note mem_limit "$s" "$want" "$conf" false; fi
  cid=$(cid_of "$s")
  if [ -n "$cid" ]; then
    live=$(docker inspect --format '{{.HostConfig.Memory}}' "$cid" 2>/dev/null)
  else
    live="not running"
  fi
  if [ "$live" = "$want" ]; then note host_memory "$s" "$want" "$live" true
  else note host_memory "$s" "$want" "$live" false; fi
done
rm -f "$cfg"
cid=$(cid_of postgres)
shm="not running"
[ -z "$cid" ] || shm=$(docker inspect --format '{{.HostConfig.ShmSize}}' "$cid" 2>/dev/null)
if [ "$shm" = 268435456 ]; then note shm_size postgres 268435456 "$shm" true
else note shm_size postgres 268435456 "$shm" false; fi
redis_get() { # SERVICE KEY: prints the value of CONFIG GET KEY
  docker compose -f compose.dev.yml exec -T "$1" redis-cli CONFIG GET "$2" 2>/dev/null | tr -d '\r' | sed -n 2p
}
for spec in redis-queue=50331648=noeviction redis-cache=100663296=allkeys-lru; do
  s=${spec%%=*}; rest=${spec#*=}; mm=${rest%%=*}; pol=${rest#*=}
  got=$(redis_get "$s" maxmemory)
  if [ "$got" = "$mm" ]; then note maxmemory "$s" "$mm" "$got" true
  else note maxmemory "$s" "$mm" "$got" false; fi
  got=$(redis_get "$s" maxmemory-policy)
  if [ "$got" = "$pol" ]; then note maxmemory_policy "$s" "$pol" "$got" true
  else note maxmemory_policy "$s" "$pol" "$got" false; fi
done
gate_result X1 "$mismatch" "$n" "$details"; gr=$?
printf 'X1: %s mismatches across %s services\n' "$mismatch" "$n"
[ "$gr" -eq 0 ] && [ "$mismatch" -eq 0 ]
