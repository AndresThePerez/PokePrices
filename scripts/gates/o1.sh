#!/bin/sh
# O1: healthchecks are real, /up and /v1/ready answer from Laravel, ready is 503 within 2 s when
# redis-cache stops, the worker consumes a queued job and the probe is scheduled (F-P0-041, R12).
# A trap restarts redis-cache on any exit once it has been stopped (Review Focus 3).
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
top=$(git rev-parse --show-toplevel 2>/dev/null) && cd "$top"
port=${PP_HTTP_PORT:-8095}
host=pokeprices.localhost
details="$GATES_OUT/O1.details.jsonl"
: > "$details"
fail=0
n=0
record() { # CHECK OK(true|false) NOTE
  n=$((n + 1))
  [ "$2" = true ] || fail=$((fail + 1))
  jq -nc --arg c "$1" --argjson ok "$2" --arg note "$3" '{check: $c, ok: $ok, note: $note}' >> "$details"
}
hdr_value() { tr -d '\r' < "$1" | awk -v k="$2" -F': *' 'tolower($1) == k { v = $2 } END { print v }'; }
get() { # NAME PATH [CURL OPTIONS...]: writes $GATES_OUT/O1.NAME.{hdr,body}, prints the status code
  name=$1; path=$2; shift 2
  : > "$GATES_OUT/O1.$name.hdr"
  : > "$GATES_OUT/O1.$name.body"
  c=$(curl -sS -o "$GATES_OUT/O1.$name.body" -D "$GATES_OUT/O1.$name.hdr" -w '%{http_code}' \
    --resolve "$host:$port:127.0.0.1" "$@" "http://$host:$port$path" 2>>"$GATES_OUT/O1.curl.err")
  printf '%s' "${c:-000}"
}

# (a) every service has a real healthcheck and every default service is healthy.
cfg="$GATES_OUT/O1.compose.json"
ps="$GATES_OUT/O1.ps.json"
if docker compose -f compose.dev.yml config --format json > "$cfg" 2>"$GATES_OUT/O1.compose.err" &&
  docker compose -f compose.dev.yml ps -a --format json > "$ps" 2>"$GATES_OUT/O1.ps.err"; then
  bad=$(jq -r --slurpfile ps "$ps" '
    ($ps | flatten | map({key: .Service, value: (.Health // "")}) | from_entries) as $health
    | .services | to_entries[]
    | (.value.healthcheck.test // null) as $raw
    | ($raw | if type == "string" then ["CMD-SHELL", .] else . end) as $t
    | select($raw == null or (.value.healthcheck.disable // false) or $t == ["NONE"]
        or $t == ["CMD", "true"] or $t == ["CMD-SHELL", "true"]
        or ($t[0] == "CMD-SHELL" and (($t[1] // "") | test("^\\s*(echo\\b|exit\\s+0\\s*$)")))
        or ($health[.key] // "") != "healthy")
    | "\(.key):\($health[.key] // "absent")"' "$cfg" | tr '\n' ' ')
  total=$(jq '.services | length' "$cfg")
  if [ -z "$bad" ] && [ "$total" -gt 0 ]; then record a_healthchecks true "$total services healthy"
  else record a_healthchecks false "services=$total bad: $bad"; fi
else
  record a_healthchecks false "docker compose config or ps failed"
fi
rm -f "$cfg" "$ps"

# (b) /up answers from Laravel.
code=$(get up /up --max-time 5)
origin=$(hdr_value "$GATES_OUT/O1.up.hdr" x-pokeprices-origin)
rid=$(hdr_value "$GATES_OUT/O1.up.hdr" x-request-id)
if [ "$code" = 200 ] && [ "$origin" = api ] && [ -n "$rid" ]; then record b_up true "200 origin=api"
else record b_up false "code=$code origin=$origin request_id=$rid"; fi

# (c) /v1/ready is 200 JSON with every check ok.
code=$(get ready /v1/ready --max-time 5)
ctype=$(hdr_value "$GATES_OUT/O1.ready.hdr" content-type)
if [ "$code" = 200 ] && case "$ctype" in application/json*) : ;; *) false ;; esac &&
  jq -e '.status == "ok" and .checks.database == "ok" and .checks.redis_queue == "ok" and .checks.redis_cache == "ok"' \
    "$GATES_OUT/O1.ready.body" >/dev/null 2>&1; then
  record c_ready_ok true "200 $ctype"
else
  record c_ready_ok false "code=$code content_type=$ctype"
fi

# (d) with redis-cache stopped, /v1/ready is 503 problem+json within 2 s.
trap 'docker compose -f compose.dev.yml start redis-cache >/dev/null 2>&1; docker compose -f compose.dev.yml up -d --wait redis-cache >/dev/null 2>&1' EXIT INT TERM
if docker compose -f compose.dev.yml stop redis-cache >"$GATES_OUT/O1.stop.log" 2>&1; then
  code=$(get down /v1/ready --max-time 2)
  ctype=$(hdr_value "$GATES_OUT/O1.down.hdr" content-type)
  if [ "$code" = 503 ] && case "$ctype" in application/problem+json*) : ;; *) false ;; esac &&
    jq -e '(.request_id | type == "string" and length > 0) and .checks.redis_cache == "fail"' \
      "$GATES_OUT/O1.down.body" >/dev/null 2>&1; then
    record d_ready_503 true "503 $ctype"
  else
    record d_ready_503 false "code=$code content_type=$ctype"
  fi
else
  record d_ready_503 false "docker compose stop redis-cache failed"
fi

# (e) redis-cache comes back and /v1/ready is 200 again.
if docker compose -f compose.dev.yml start redis-cache >"$GATES_OUT/O1.start.log" 2>&1 &&
  docker compose -f compose.dev.yml up -d --wait redis-cache >>"$GATES_OUT/O1.start.log" 2>&1; then
  code=$(get back /v1/ready --max-time 5 --retry 30 --retry-all-errors --retry-delay 1)
  if [ "$code" = 200 ]; then record e_ready_restored true "200"
  else record e_ready_restored false "code=$code"; fi
else
  record e_ready_restored false "docker compose start or up --wait redis-cache failed"
fi

# (f) the worker consumes a job from redis-queue.
if docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:queue-probe --timeout=10 \
  >"$GATES_OUT/O1.queue-probe.log" 2>&1; then
  record f_queue_probe true "queue-probe succeeded"
else
  record f_queue_probe false "queue-probe exited $?"
fi

# (g) schedule:list names the probe entry (R75: the entry is named pokeprices-probe).
if docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan schedule:list \
  >"$GATES_OUT/O1.schedule.log" 2>&1 && /usr/bin/grep -q 'pokeprices-probe' "$GATES_OUT/O1.schedule.log"; then
  record g_schedule true "pokeprices-probe listed"
else
  record g_schedule false "pokeprices-probe not listed"
fi

gate_result O1 "$fail" "$n" "$details"; gr=$?
printf 'O1: %s failed of %s checks\n' "$fail" "$n"
[ "$gr" -eq 0 ] && [ "$fail" -eq 0 ]
