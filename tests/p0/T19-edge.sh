#!/bin/sh
# P0-i1-T19 edge cases (reviewer): routing prefix bypasses, request-id gate, 413 body integrity, SPA fallback.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
U=http://pokeprices.localhost:8095
cu() { curl -sS --resolve pokeprices.localhost:8095:127.0.0.1 "$@"; }
origin() { cu -o /dev/null -D - --path-as-is "$U$1" | tr -d '\r' | awk 'tolower($0)~/^x-pokeprices-origin:/{print $2}'; }
status() { cu -o /dev/null -w '%{http_code}' --path-as-is "$U$1"; }

for p in /v1x /login2 /LOGIN /reset-passwordx /sanctum /up/ /loginx/..; do
  check_out "near-miss path $p is the SPA, not the API" "" origin "$p"
done
for p in /v1/ready "/up?x=1" /%76%31/ready //v1/ready /reset-password/abc /two-factor-challenge; do
  check_out "path $p reaches php-fpm" api origin "$p"
done
check_out "unknown host header on the loopback port is still served" 200 curl -sS -o /dev/null -w '%{http_code}' -H 'Host: other.invalid' http://127.0.0.1:8095/v1/ready
check_out "missing asset falls back to index.html with 200" 200 status /assets/nope.js
check_out "HEAD /v1/ready is 200" 200 cu -I -o /dev/null -w '%{http_code}' "$U/v1/ready"

rid() { cu -o /dev/null -D - -H "X-Request-Id: $1" "$U/v1/ready" | tr -d '\r' | awk 'tolower($0)~/^x-request-id:/{print $2}'; }
i128=$(printf 'a%.0s' $(seq 128))
check_out "a 128-char valid request id is forwarded" "$i128" rid "$i128"
check "a 129-char request id is replaced" sh -c '. tests/p0/lib.sh; :; [ "$(curl -sS -o /dev/null -D - --resolve pokeprices.localhost:8095:127.0.0.1 -H "X-Request-Id: '"${i128}"'a" '"$U"'/v1/ready | tr -d "\r" | awk "tolower(\$0)~/^x-request-id:/{print \$2}")" != "'"${i128}"'a" ]'
check "a 7-char request id is replaced" sh -c '! curl -sS -o /dev/null -D - --resolve pokeprices.localhost:8095:127.0.0.1 -H "X-Request-Id: abcdefg" '"$U"'/v1/ready | /usr/bin/grep -qi "x-request-id: abcdefg"'

check "413 body stays valid JSON under a hostile request id" sh -c "$(printf '%s' 'head -c 2000000 /dev/zero | curl -sS --resolve pokeprices.localhost:8095:127.0.0.1 -X POST --data-binary @- -H '"'"'X-Request-Id: abcdefgh"},{"x":"'"'"' http://pokeprices.localhost:8095/v1/ready | jq -e ".status == 413 and (.request_id|test(\"^[0-9a-f]{32}$\"))"')"
check "a client route serves the SPA without the API header" sh -c "[ -z \"\$(curl -sS -D - -o /dev/null --resolve pokeprices.localhost:8095:127.0.0.1 $U/some/client/route | /usr/bin/grep -i x-pokeprices-origin)\" ]"
check "nosniff is present on a php-fpm response" sh -c "curl -sS -D - -o /dev/null --resolve pokeprices.localhost:8095:127.0.0.1 $U/v1/ready | tr -d '\r' | /usr/bin/grep -qi '^x-content-type-options: nosniff'"
check "EC-routing fails closed with value 19 when nginx is unreachable" sh -c 'mkdir -p .gates/tmp/t19e; ! PP_HTTP_PORT=18099 GATES_OUT=.gates/tmp/t19e sh scripts/gates/ec-routing.sh && [ "$(jq -r .value .gates/tmp/t19e/EC-routing.json)" = 19 ]'
finish
