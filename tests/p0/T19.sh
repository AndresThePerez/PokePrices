#!/bin/sh
# P0-i1-T19: one nginx, loopback-only, routes Laravel paths and serves the SPA build.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
J() { docker compose -f compose.dev.yml config --format json | jq -e "$1"; }
check "exactly one published port, loopback, 8095 inside" J '[.services[] | (.ports // [])[]] | length == 1 and .[0].host_ip == "127.0.0.1" and .[0].target == 8095'
check "web capped at 64m" J '(.services.web.mem_limit|tonumber) == 67108864'
check "web carries the pokeprices.localhost alias" J '.services.web.networks.default.aliases | index("pokeprices.localhost") != null'
check "stack healthy" $DC up -d --wait --build
check "web image runs as a non-root user" sh -c 'u=$(docker inspect --format "{{.Config.User}}" "$(docker compose -f compose.dev.yml ps -q web)"); case "${u%%:*}" in ""|0|root) false ;; *) true ;; esac'
T=.gates/tmp/T19; mkdir -p "$T"
check "EC-routing passes" env GATES_OUT="$T" sh scripts/gates/ec-routing.sh
check_out "EC-routing counted 19 requests" 19 jq -r .denominator "$T/EC-routing.json"
check "an oversized body gets a JSON 413 from nginx" sh -c 'head -c 2000000 /dev/zero | curl -sS -o /dev/null -w "%{http_code} %{content_type}" --resolve pokeprices.localhost:8095:127.0.0.1 -X POST --data-binary @- http://pokeprices.localhost:8095/v1/ready | /usr/bin/grep -q "^413 application/problem+json"'
check "a static asset is served by nginx without the API header" sh -c 'f=$(cd web/dist && ls assets/*.js | head -1); curl -sS -D - -o /dev/null --resolve pokeprices.localhost:8095:127.0.0.1 "http://pokeprices.localhost:8095/$f" | tr -d "\r" | /usr/bin/grep -qi "^content-type: .*javascript" && ! curl -sS -D - -o /dev/null --resolve pokeprices.localhost:8095:127.0.0.1 "http://pokeprices.localhost:8095/$f" | /usr/bin/grep -qi x-pokeprices-origin'
check "nosniff on every response" sh -c 'curl -sS -D - -o /dev/null --resolve pokeprices.localhost:8095:127.0.0.1 http://pokeprices.localhost:8095/ | tr -d "\r" | /usr/bin/grep -qi "^x-content-type-options: nosniff"'
finish
