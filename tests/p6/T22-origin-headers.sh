#!/bin/sh
# The origin web server's version, compression, caching and content policy, read with curl from the running
# dev web container on 127.0.0.1:8095 (rebuild it first: docker compose -f compose.dev.yml up -d --wait --build web).
# Manual tier, not in FAST. Run from the repo root: TIER=task sh tests/p0/run.sh tests/p6/T22-origin-headers.sh
. tests/p0/lib.sh

BASE=http://127.0.0.1:8095
TMP=.gates/tmp/t22
mkdir -p "$TMP"
trap 'rm -rf "$TMP"' EXIT

# get NAME PATH [curl args]: response headers of GET PATH into $TMP/NAME.h, the body into $TMP/NAME.b.
get() { n=$1; p=$2; shift 2; curl -sS -D "$TMP/$n.h" -o "$TMP/$n.b" "$@" "$BASE$p" >/dev/null 2>&1; }
# hcount NAME HEADER: how many times the response carries HEADER (any case).
hcount() { tr -d '\r' < "$TMP/$1.h" | grep -ic "^$2:"; }
# hval NAME HEADER: the value of the first HEADER line.
hval() { tr -d '\r' < "$TMP/$1.h" | sed -n "s/^$2:[[:space:]]*//Ip" | head -n 1; }
# one_is NAME HEADER VALUE: HEADER is sent exactly once, with exactly VALUE.
one_is() { [ "$(hcount "$1" "$2")" = 1 ] && [ "$(hval "$1" "$2")" = "$3" ]; }
# one_has NAME HEADER TEXT: HEADER is sent exactly once and its value contains TEXT.
one_has() { [ "$(hcount "$1" "$2")" = 1 ] && hval "$1" "$2" | grep -Fq -- "$3"; }

get root /
get about /about
get card /card/base1-4
get deep /some/deep/client/route
get index /index.html
get missing /assets/missing-abcdefgh.js
get api /v1/ready
get fav /favicon.svg
get robots /robots.txt
get og /og-image.png
js=$(grep -oE '/assets/[^"]+\.js' "$TMP/root.b" | head -n 1)
get js "$js"
css=$(grep -oE '/assets/[^"]+\.css' "$TMP/root.b" | head -n 1)
[ -n "$css" ] || css=$(grep -oE '/assets/[^"]+\.css' "$TMP/js.b" | head -n 1)
check "the served page names a hashed script" test -n "$js"

# 1. The version is not shown.
for n in root js api fav; do
  check "$n: Server header carries no version" sh -c "tr -d '\r' < $TMP/$n.h | grep -i '^server:' | grep -Eqv '[0-9]'"
done
curl -sS --path-as-is -o "$TMP/bad.b" "$BASE/%" >/dev/null 2>&1
check "an nginx error page names no version" sh -c "test -s $TMP/bad.b && ! grep -Eq 'nginx/[0-9]' $TMP/bad.b"

# 2. Text assets are compressed, with Vary: Accept-Encoding.
for p in / "$js" /favicon.svg; do
  get gz "$p" -H 'Accept-Encoding: gzip'
  check "GET $p with gzip: Content-Encoding gzip" one_is gz Content-Encoding gzip
  check "GET $p with gzip: Vary names Accept-Encoding" sh -c "tr -d '\r' < $TMP/gz.h | grep -i '^vary:' | grep -iq accept-encoding"
done
get gzcss "$css" -H 'Accept-Encoding: gzip'
check "the stylesheet is compressed" one_is gzcss Content-Encoding gzip
get plain / -H 'Accept-Encoding: identity'
check "a client that does not ask gets no Content-Encoding" test "$(hcount plain Content-Encoding)" = 0
get png /og-image.png -H 'Accept-Encoding: gzip'
check "the png picture is not compressed" test "$(hcount png Content-Encoding)" = 0

# 3. Hashed assets are immutable for a year.
check "hashed script: public, max-age=31536000, immutable" one_is js Cache-Control 'public, max-age=31536000, immutable'
check "hashed stylesheet: public, max-age=31536000, immutable" one_is gzcss Cache-Control 'public, max-age=31536000, immutable'

# 4. Every path that serves the app's page revalidates.
for n in root about card deep index missing; do
  check "$n: the app's page answers Cache-Control no-cache" one_is "$n" Cache-Control no-cache
done
check "/about answers the app's page" cmp -s "$TMP/root.b" "$TMP/about.b"

# Unhashed files are never immutable.
for n in fav robots og; do
  check "$n: Cache-Control present once and never immutable" sh -c "[ \$(tr -d '\r' < $TMP/$n.h | grep -ic '^cache-control:') = 1 ] && ! tr -d '\r' < $TMP/$n.h | grep -i '^cache-control:' | grep -iq immutable"
done

# 5. The content policy on the app's page lists what the app really uses.
SCRIPT_HASH=$(awk 'BEGIN { RS = "\001" } { s = index($0, "<script>") + 8; e = index($0, "</script>"); printf "%s", substr($0, s, e - s) }' "$TMP/root.b" | openssl dgst -sha256 -binary | base64)
for n in root about card deep index; do
  check "$n: Content-Security-Policy-Report-Only names the picture host" one_has "$n" Content-Security-Policy-Report-Only 'img-src '"'self'"' data: https://images.pokemontcg.io'
  check "$n: the policy allows the page's inline script by hash" one_has "$n" Content-Security-Policy-Report-Only "'sha256-$SCRIPT_HASH'"
  check "$n: the policy limits connections to the origin" one_has "$n" Content-Security-Policy-Report-Only "connect-src 'self'"
done
check "the inline script hash is not empty" test -n "$SCRIPT_HASH"
check "the API answer carries no page policy" test "$(hcount api Content-Security-Policy-Report-Only)" = 0
check "an asset carries no page policy" test "$(hcount js Content-Security-Policy-Report-Only)" = 0

# 6. The API keeps its own cache header; no second one is added.
check "/v1/ready keeps the API's Cache-Control exactly once" one_is api Cache-Control 'no-store, private'

# 7. add_header inheritance: the six security headers are on every kind of answer.
cat > "$TMP/sec.txt" <<'HDR'
X-Content-Type-Options|nosniff
Strict-Transport-Security|max-age=31536000
X-Frame-Options|DENY
Content-Security-Policy|frame-ancestors 'none'; base-uri 'self'; object-src 'none'
Referrer-Policy|same-origin
Permissions-Policy|camera=(), microphone=(), geolocation=(), payment=(), usb=()
HDR
for n in root card missing js fav api; do
  while IFS='|' read -r name value; do
    check "$n: $name sent once with its exact value" one_is "$n" "$name" "$value"
  done < "$TMP/sec.txt"
done

# 8. The conf is valid inside the web container.
check "nginx -t passes in the web container" sh -c "$DC exec -T web nginx -t"

finish
