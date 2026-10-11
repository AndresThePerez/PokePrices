#!/bin/sh
# EC-routing: Laravel paths reach php-fpm through nginx; everything else is the SPA (R12, R14, R24).
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
. "$(dirname "$0")/../lib/gate-result.sh"
port=${PP_HTTP_PORT:-8095}
host=pokeprices.localhost
rid=gate-req-0001
details="$GATES_OUT/EC-routing.details.jsonl"
: > "$details"
fail=0
n=0
hdr_value() { tr -d '\r' < "$1" | awk -v k="$2" -F': *' 'tolower($1) == k { v = $2 } END { print v }'; }
probe() { # METHOD PATH STATUS TYPE_PREFIX ORIGIN(api|spa)
  n=$((n + 1))
  hdr="$GATES_OUT/EC-routing.$n.hdr"
  body="$GATES_OUT/EC-routing.$n.body"
  code=$(curl -sS -o "$body" -D "$hdr" -w '%{http_code}' --max-time 5 \
    --resolve "$host:$port:127.0.0.1" -X "$1" -H "X-Request-Id: $rid" "http://$host:$port$2") || code=000
  ctype=$(hdr_value "$hdr" content-type)
  origin=$(hdr_value "$hdr" x-pokeprices-origin)
  echoed=$(hdr_value "$hdr" x-request-id)
  ok=true
  [ "$code" = "$3" ] || ok=false
  case "$ctype" in "$4"*) ;; *) ok=false ;; esac
  if [ "$5" = api ]; then
    [ "$origin" = api ] && [ "$echoed" = "$rid" ] || ok=false
  else
    [ -z "$origin" ] && /usr/bin/grep -q 'id="root"' "$body" || ok=false
  fi
  [ "$ok" = true ] || fail=$((fail + 1))
  jq -nc --arg m "$1" --arg p "$2" --arg want "$3" --arg got "$code" --arg ctype "$ctype" --arg origin "$origin" --argjson ok "$ok" \
    '{method: $m, path: $p, want: $want, got: $got, content_type: $ctype, origin: $origin, ok: $ok}' >> "$details"
}
probe GET /v1/ready 200 application/json api
probe GET /v1/does-not-exist 404 application/problem+json api
probe GET /up 200 text/html api
for p in /login /logout /register /forgot-password /reset-password /reset-password/x /email/verify \
         /user/profile-information /user/two-factor-authentication /two-factor-challenge /sanctum/csrf-cookie; do
  probe GET "$p" 404 application/problem+json api
done
probe POST /login 404 application/problem+json api
probe POST /two-factor-challenge 404 application/problem+json api
for p in / /account/login /watchlist; do
  probe GET "$p" 200 text/html spa
done
gate_result EC-routing "$fail" "$n" "$details"
printf 'EC-routing: %s failed of %s requests\n' "$fail" "$n"
[ "$fail" -eq 0 ]
