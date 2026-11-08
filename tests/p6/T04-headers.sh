#!/bin/sh
# P6-DEPLOY-i1-T04: the frozen nginx conf sends six security headers, all with `always`, at server level and
# inside both named locations (a location that declares its own add_header drops every inherited one).
# Static checks on the conf text plus a build-only `nginx -t` probe layer; no container is run. Manual tier,
# not in FAST. Run from the repo root: TIER=task sh tests/p0/run.sh tests/p6/T04-headers.sh
# LIVE=1 also curls the running dev web container on 127.0.0.1:8095 (only meaningful after it was rebuilt
# from this conf).
. tests/p0/lib.sh

CONF=docker/nginx/pokeprices.conf
DF=docker/web/Dockerfile
TAG=pokeprices-web:p6-check
PROBE=pokeprices-web:p6-probe
TMP=.gates/tmp
LIST=$TMP/p6-T04-headers.txt
PLACED=$TMP/p6-T04-placed.txt
mkdir -p "$TMP"
cleanup() { docker rmi "$PROBE" >/dev/null 2>&1; docker rmi "$TAG" >/dev/null 2>&1; rm -f "$LIST" "$PLACED" "$TMP"/p6-T04-live-*.txt; }
trap cleanup EXIT

# The six headers and their exact values, one "Name|value" line each.
cat > "$LIST" <<'HDR'
X-Content-Type-Options|nosniff
Strict-Transport-Security|"max-age=31536000"
X-Frame-Options|DENY
Content-Security-Policy|"frame-ancestors 'none'; base-uri 'self'; object-src 'none'"
Referrer-Policy|same-origin
Permissions-Policy|"camera=(), microphone=(), geolocation=(), payment=(), usb=()"
HDR

# Where each add_header sits: one "<context> <name>" line, context = server (directly in the server block),
# the named location (@api_unavailable, @payload_too_large) or "other" (any other location).
awk '
  { line = $0; sub(/#.*/, "", line) }
  !inserver && line ~ /^[[:space:]]*server[[:space:]]*\{/ { inserver = 1; depth = 0 }
  inserver && depth == 1 && line ~ /^[[:space:]]*location[[:space:]]/ { split(line, w, /[[:space:]]+/); loc = (w[1] == "") ? w[3] : w[2]; ctx = (loc ~ /^@/) ? loc : "other" }
  inserver && line ~ /^[[:space:]]*add_header[[:space:]]/ { split(line, w, /[[:space:]]+/); n = (w[1] == "") ? w[3] : w[2]; print ((depth == 1) ? "server" : ctx) " " n }
  inserver { o = gsub(/\{/, "{", line); c = gsub(/\}/, "}", line); depth += o - c; if (depth <= 0 && c > 0) inserver = 0 }
' "$CONF" > "$PLACED"

# exact_count WANT: how many conf lines read exactly WANT once leading whitespace is dropped.
exact_count() { sed 's/^[[:space:]]*//' "$CONF" | grep -cFx -- "$1"; }

while IFS='|' read -r name value; do
  check_out "$name: exactly 3 add_header lines (server + both named locations)" 3 sh -c "grep -cE '^[[:space:]]*add_header[[:space:]]+$name[[:space:]]' $CONF"
  check_out "$name: each of the 3 ends with always;" 3 sh -c "grep -E '^[[:space:]]*add_header[[:space:]]+$name[[:space:]]' $CONF | grep -cE '[[:space:]]always;[[:space:]]*\$'"
  for ctx in server @api_unavailable @payload_too_large; do
    check "$name: sent from $ctx" grep -Fxq "$ctx $name" "$PLACED"
  done
  check_out "$name: all 3 read exactly [add_header $name $value always;]" 3 exact_count "add_header $name $value always;"
done < "$LIST"

check "every add_header in the conf ends with always;" sh -c "! grep -E '^[[:space:]]*add_header[[:space:]]' $CONF | grep -vqE '[[:space:]]always;[[:space:]]*\$'"
check "no add_header sits in an ordinary location (it would drop the server-level ones there)" sh -c "! grep -q '^other ' $PLACED"
check "HSTS max-age is one year (31536000)" grep -Eq 'add_header[[:space:]]+Strict-Transport-Security[[:space:]]+"max-age=31536000"' "$CONF"
check "HSTS has no includeSubDomains (sibling subdomains are other apps)" sh -c "! grep -E '^[[:space:]]*add_header[[:space:]]' $CONF | grep -qi includeSubDomains"
check "HSTS has no preload" sh -c "! grep -E '^[[:space:]]*add_header[[:space:]]' $CONF | grep -qi preload"
check "Referrer-Policy is never no-referrer or strict-origin (Sanctum reads the same-origin Referer)" sh -c "! grep -Ei 'add_header[[:space:]]+Referrer-Policy' $CONF | grep -Eqi 'no-referrer|strict-origin'"
check "CSP carries frame-ancestors 'none'" grep -Fq "frame-ancestors 'none'" "$CONF"
check "CSP sets no script-src or style-src (deferred)" sh -c "! grep -Eq 'script-src|style-src' $CONF"

# The rest of the conf is unchanged: the two static JSON bodies, the request-id lines, routing basics.
check "503 problem body is byte-identical" grep -Fxq "        return 503 '{\"type\":\"https://pokeprices.andrestheperez.com/problems/api-unavailable\",\"title\":\"API Unavailable\",\"status\":503,\"request_id\":\"\$pp_request_id\"}';" "$CONF"
check "413 problem body is byte-identical" grep -Fxq "        return 413 '{\"type\":\"https://pokeprices.andrestheperez.com/problems/payload-too-large\",\"title\":\"Payload Too Large\",\"status\":413,\"request_id\":\"\$pp_request_id\"}';" "$CONF"
check_out "both named locations keep their X-Request-Id line" 2 exact_count 'add_header X-Request-Id $pp_request_id always;'
check "listen, server_name, body limit and SPA fallback unchanged" sh -c "grep -Fxq '    listen 8095 default_server;' $CONF && grep -Fxq '    server_name pokeprices.localhost;' $CONF && grep -Fxq '    client_max_body_size 1m;' $CONF && grep -Fxq '        try_files \$uri /index.html;' $CONF"
check "no merge_slashes directive" sh -c "! grep -q merge_slashes $CONF"

# nginx -t inside a build-only probe layer on the web runtime image (no container is run). The conf names
# the upstream host `api`, which only exists on the compose network, so the probe maps it to a TEST-NET address.
rm -f "$TMP/p6-T04-build.log" "$TMP/p6-T04-probe.log"
docker build -t "$TAG" --target runtime -f "$DF" . > "$TMP/p6-T04-build.log" 2>&1
check "web runtime image builds" docker image inspect "$TAG"
check "nginx -t passes on the built conf" sh -c "printf 'FROM %s\nRUN nginx -t\n' '$TAG' | docker build --progress=plain --no-cache --add-host api:192.0.2.10 -t '$PROBE' - > '$TMP/p6-T04-probe.log' 2>&1 && grep -q 'test is successful' '$TMP/p6-T04-probe.log'"

if [ "${LIVE:-}" = 1 ]; then
  BASE=http://127.0.0.1:8095
  # live_has FILE NAME VALUE: the response headers in FILE carry NAME (any case) exactly once, with VALUE.
  live_has() { [ "$(tr -d '\r' < "$1" | grep -ic "^$2:")" = 1 ] && tr -d '\r' < "$1" | grep -iFxq "$2: $3"; }
  n=0
  for path in / /some/client/route /v1/ready; do
    n=$((n + 1)); f=$TMP/p6-T04-live-$n.txt
    curl -sS -D - -o /dev/null "$BASE$path" > "$f" 2>/dev/null
    while IFS='|' read -r name value; do
      v=$(printf '%s' "$value" | sed 's/^"//; s/"$//')
      check "LIVE GET $path sends $name: $v" live_has "$f" "$name" "$v"
    done < "$LIST"
  done
  f=$TMP/p6-T04-live-413.txt
  head -c 1100000 /dev/zero | curl -sS -D - -o /dev/null -X POST --data-binary @- "$BASE/v1/ready" > "$f" 2>/dev/null
  check "LIVE >1 MB POST /v1/ready answers 413" sh -c "head -n 1 $f | grep -q ' 413'"
  while IFS='|' read -r name value; do
    v=$(printf '%s' "$value" | sed 's/^"//; s/"$//')
    check "LIVE 413 from @payload_too_large sends $name: $v" live_has "$f" "$name" "$v"
  done < "$LIST"
else
  printf '# LIVE section skipped: set LIVE=1 after the dev web container is rebuilt from this conf\n'
fi

finish
