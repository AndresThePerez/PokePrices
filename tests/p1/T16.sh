#!/bin/sh
# P1-i1-T16: api, worker and scheduler reach PokéSearch on the workstation through
# host.docker.internal:host-gateway with POKESEARCH_URL in the &api-env anchor (R134; the external-network
# join is rejected), the api image has the zstd extension and decodes a real collected snapshot, and the
# compose project stays self-contained: nine services, one loopback port, nothing outside the project, so
# the api starts with PokéSearch absent. Needs the running pokeprices stack; the reachability check also
# needs PokéSearch dev answering on the host's :8081. Proven from compose config, never by stopping PokéSearch.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
T=.gates/tmp/T16; mkdir -p "$T/gates"
API="$DC exec -T -w /workspace/api api"
SEAM=host.docker.internal:host-gateway
URL_DEFAULT='${POKESEARCH_URL:-http://host.docker.internal:8081}'
NINE='["api","node","postgres","redis-cache","redis-queue","scheduler","tools","web","worker"]'
SNAP=.data/snapshots/2026-10-11/products/604.json.zst

# Resolved with the dev defaults (no PP_HTTP_PORT / POKESEARCH_URL override), every profile included;
# and the raw file without interpolation, to see the ${POKESEARCH_URL:-...} default as written.
env -u PP_HTTP_PORT -u POKESEARCH_URL $DC --profile '*' config --format json > "$T/config.json" 2> "$T/config.err"
$DC config --no-interpolate --format json > "$T/raw.json" 2> "$T/raw.err"
check "docker compose config renders compose.dev.yml (resolved and uninterpolated)" sh -c 'jq -e .services "$0" && jq -e .services "$1"' "$T/config.json" "$T/raw.json"

# ---- criterion 1: the seam -----------------------------------------------------------------------------
for s in api worker scheduler; do
  check "$s has extra_hosts exactly [$SEAM]" jq -e --arg s "$s" --arg h "$SEAM" \
    '.services[$s].extra_hosts | type == "array" and (map(sub("="; ":")) == [$h])' "$T/config.json"
  check "$s has POKESEARCH_URL written as $URL_DEFAULT" jq -e --arg s "$s" --arg u "$URL_DEFAULT" \
    '.services[$s].environment.POKESEARCH_URL == $u' "$T/raw.json"
  check "$s resolves POKESEARCH_URL to http://host.docker.internal:8081 by default" jq -e --arg s "$s" \
    '.services[$s].environment.POKESEARCH_URL == "http://host.docker.internal:8081"' "$T/config.json"
done
check_out "POKESEARCH_URL is defined once in compose.dev.yml (the &api-env anchor), worker and scheduler alias it" "1 2" \
  sh -c 'printf "%s %s" "$(/usr/bin/grep -c "^[[:space:]]*POKESEARCH_URL:" compose.dev.yml)" "$(/usr/bin/grep -c "^[[:space:]]*environment: \*api-env$" compose.dev.yml)"'
check "the &api-env anchor block holds POKESEARCH_URL" sh -c '
  awk "/environment: &api-env\$/ { a = 1; next } a && /^    [a-z_]+:/ { a = 0 } a && /^      POKESEARCH_URL: / { f = 1 } END { exit !f }" compose.dev.yml'
check "no service has extra_hosts other than api, worker and scheduler" jq -e \
  '[.services | to_entries[] | select(.value | has("extra_hosts")) | .key] | sort == ["api","scheduler","worker"]' "$T/config.json"
check "compose.dev.yml and docker/api/Dockerfile hold no IPv4 literal other than loopback" sh -c '
  ! /usr/bin/grep -ohE "([0-9]{1,3}\.){3}[0-9]{1,3}" compose.dev.yml docker/api/Dockerfile | /usr/bin/grep -vqx "127\.0\.0\.1"'
check "no external network is declared" jq -e '[(.networks // {})[] | select(.external // false)] == []' "$T/config.json"
check "every service joins the project default network only" jq -e \
  '[.services[] | (.networks // {}) | keys[]] | unique == ["default"]' "$T/config.json"
check "compose.dev.yml never names pokesearch_internal" sh -c '! /usr/bin/grep -q pokesearch_internal compose.dev.yml'

# ---- criterion 2: zstd ---------------------------------------------------------------------------------
check "the base stage's install-php-extensions line installs zstd" sh -c '
  awk "/^FROM .* AS base\$/ { b = 1; next } /^FROM / { b = 0 } b && /^RUN install-php-extensions / { print }" docker/api/Dockerfile |
    /usr/bin/grep -qw zstd'
check "the api container's php -m lists zstd" sh -c "$API php -m | /usr/bin/grep -qx zstd"
if [ -d .data ]; then
  sum0=$(sha256sum < "$SNAP" 2>/dev/null); mt0=$(stat -c %Y "$SNAP" 2>/dev/null)
  check_out "zstd_uncompress decodes $SNAP to JSON with a non-empty results list" nonempty \
    $DC exec -T -w /workspace api php -r '
      $r = json_decode(zstd_uncompress(file_get_contents($argv[1])), true, 512, JSON_THROW_ON_ERROR);
      echo is_array($r["results"] ?? null) && array_is_list($r["results"]) && count($r["results"]) > 0 ? "nonempty" : "empty";' \
    "/workspace/$SNAP"
  check "the snapshot file is unchanged after the decode (content and mtime)" sh -c '
    test -n "$1" && test "$(sha256sum < "$0")" = "$1" && test "$(stat -c %Y "$0")" = "$2"' "$SNAP" "$sum0" "$mt0"
else
  printf 'ok - (skipped: .data/ is absent, nested or ci context) zstd decode of %s\n' "$SNAP"
fi

# ---- criterion 3: the running containers reach the host gateway ------------------------------------------
for s in api worker scheduler; do
  check "$s resolves host.docker.internal (getent hosts)" $DC exec -T "$s" getent hosts host.docker.internal
done
want=$($DC config --format json 2>/dev/null | jq -r '.services.api.environment.POKESEARCH_URL')
check_out "the running api container has POKESEARCH_URL as compose resolves it" "$want" $API printenv POKESEARCH_URL
check "the running api container's POKESEARCH_URL names host.docker.internal, not an address" sh -c '
  case "$0" in http://host.docker.internal:*) exit 0 ;; *) exit 1 ;; esac' "$($API printenv POKESEARCH_URL 2>/dev/null)"
if [ "$(curl -s -m 3 -o /dev/null -w '%{http_code}' http://localhost:8081/healthz 2>/dev/null)" = 200 ]; then
  check_out "the api container gets 200 from http://host.docker.internal:8081/healthz" 200 \
    $API curl -sS -m 3 -o /dev/null -w '%{http_code}' http://host.docker.internal:8081/healthz
else
  printf 'ok - (skipped: PokéSearch dev is not answering on the host :8081) api -> host.docker.internal:8081/healthz\n'
fi
check "with PokéSearch absent the api still starts: every depends_on names a service of this project" jq -e \
  '(.services | keys) as $k | ([.services[] | (.depends_on // {}) | keys[]] - $k) == []' "$T/config.json"
check "no service links to, shares the network of, or extends anything outside the project" jq -e \
  '[.services[] | select(has("external_links") or has("links") or has("network_mode") or has("extends"))] == []' "$T/config.json"
check "the api depends only on postgres and the two Redis instances" jq -e \
  '.services.api.depends_on | keys == ["postgres","redis-cache","redis-queue"]' "$T/config.json"

# ---- criterion 4: X1, S6, EC-P1-04 stay green ----------------------------------------------------------
check "X1 passes (compose mem_limit and live cgroup caps, postgres shm, Redis maxmemory)" env GATES_OUT="$T/gates" sh scripts/gates/x1.sh
check "S6 passes (every base image digest-pinned)" env GATES_OUT="$T/gates" sh scripts/gates/s6.sh
check "EC-P1-04: the project has exactly the nine services" jq -e --argjson n "$NINE" '.services | keys == $n' "$T/config.json"
check "EC-P1-04: the only published port is 127.0.0.1:8095 on web" jq -e \
  '[.services | to_entries[] | .key as $s | (.value.ports // [])[] | {s: $s, ip: .host_ip, pub: (.published | tostring), t: .target}]
   == [{s: "web", ip: "127.0.0.1", pub: "8095", t: 8095}]' "$T/config.json"
check "no service exposes host networking or host ports beyond that" jq -e \
  '[.services[] | select((.network_mode // "") == "host" or has("expose"))] == []' "$T/config.json"
finish
