#!/bin/sh
# Review tests for the origin web server's cache and policy headers: fallbacks, errors, conditional and
# partial requests. They read the running dev web container on 127.0.0.1:8095 (rebuild it first).
# Run from the repo root: TIER=task sh tests/p0/run.sh tests/p6/T22-origin-headers-review.sh
. tests/p0/lib.sh

BASE=http://127.0.0.1:8095
TMP=.gates/tmp/t22r
mkdir -p "$TMP"
trap 'rm -rf "$TMP"' EXIT

get() { n=$1; p=$2; shift 2; curl -sS -D "$TMP/$n.h" -o "$TMP/$n.b" "$@" "$BASE$p" >/dev/null 2>&1; }
hcount() { tr -d '\r' < "$TMP/$1.h" | grep -ic "^$2:"; }
hval() { tr -d '\r' < "$TMP/$1.h" | sed -n "s/^$2:[[:space:]]*//Ip" | head -n 1; }
one_is() { [ "$(hcount "$1" "$2")" = 1 ] && [ "$(hval "$1" "$2")" = "$3" ]; }
status() { head -n 1 "$TMP/$1.h" | tr -d '\r' | cut -d' ' -f2; }
# sec_ok NAME: the six security headers are on the answer exactly once each.
sec_ok() {
  for h in X-Content-Type-Options Strict-Transport-Security X-Frame-Options Content-Security-Policy Referrer-Policy Permissions-Policy; do
    [ "$(hcount "$1" "$h")" = 1 ] || return 1
  done
  [ "$(hval "$1" Content-Security-Policy)" = "frame-ancestors 'none'; base-uri 'self'; object-src 'none'" ]
}
# not_immutable NAME: no Cache-Control line says immutable.
not_immutable() { ! tr -d '\r' < "$TMP/$1.h" | grep -i '^cache-control:' | grep -iq immutable; }

get root /
js=$(grep -oE '/assets/[^"]+\.js' "$TMP/root.b" | head -n 1)
check "the served page names a hashed script" test -n "$js"

# 1. A conditional request to the page: the 304 still says no-cache and still carries the headers.
etag=$(tr -d '\r' < "$TMP/root.h" | sed -n 's/^ETag:[[:space:]]*//Ip' | head -n 1)
get cond / -H 'Accept-Encoding: identity'
etag=$(hval cond ETag)
get c304 / -H "If-None-Match: $etag" -H 'Accept-Encoding: identity'
check "1 If-None-Match on / answers 304" test "$(status c304)" = 304
check "1 the 304 on / still says Cache-Control no-cache, once" one_is c304 Cache-Control no-cache
check "1 the 304 on / keeps the six security headers once" sec_ok c304
check "1 the 304 on / names no server version" sh -c "tr -d '\r' < $TMP/c304.h | grep -i '^server:' | grep -Eqv '[0-9]'"
lm=$(hval cond Last-Modified)
get c304m / -H "If-Modified-Since: $lm" -H 'Accept-Encoding: identity'
check "1 If-Modified-Since on / answers 304 with no-cache" sh -c "[ '$(status c304m)' = 304 ] && [ \"\$(tr -d '\r' < $TMP/c304m.h | grep -ic '^cache-control: no-cache\$')\" = 1 ]"

# 2. A hashed asset: HEAD, a range and a conditional request all keep immutable and carry no page policy.
get hhead "$js" -I
check "2 HEAD on a hashed script is immutable for a year, once" one_is hhead Cache-Control 'public, max-age=31536000, immutable'
get hrange "$js" -r 0-99
check "2 a range on a hashed script answers 206" test "$(status hrange)" = 206
check "2 the 206 keeps the immutable year, once" one_is hrange Cache-Control 'public, max-age=31536000, immutable'
check "2 the 206 keeps the six security headers once" sec_ok hrange
check "2 the 206 body is exactly the 100 bytes asked for" test "$(wc -c < "$TMP/hrange.b" | tr -d ' ')" = 100
get hplain "$js"
jetag=$(hval hplain ETag)
get h304 "$js" -H "If-None-Match: $jetag"
check "2 If-None-Match on a hashed script answers 304" test "$(status h304)" = 304
check "2 the 304 on a hashed script keeps immutable, once" one_is h304 Cache-Control 'public, max-age=31536000, immutable'
check "2 the hashed script carries no page policy header" test "$(hcount hplain Content-Security-Policy-Report-Only)" = 0

# 3. Fallback shapes: a dot in the route, a trailing slash, a query string, a file-looking name outside /assets/,
# and an /assets/ name that does not exist. Every one is the app's page, revalidated, never immutable.
i=0
for p in /card/base1-4.5 "/search/?q=a.b" /card/base1-4/ /vendor/app.js /assets/missing-abcdefgh.js /assets/ "/about?x=1&y=2" /index.html; do
  i=$((i + 1))
  get "fb$i" "$p"
  check "3 $p: Cache-Control no-cache, once" one_is "fb$i" Cache-Control no-cache
  check "3 $p: never immutable" not_immutable "fb$i"
  check "3 $p: carries the page policy once" test "$(hcount "fb$i" Content-Security-Policy-Report-Only)" = 1
  check "3 $p: is the app's page, not a script body" cmp -s "$TMP/root.b" "$TMP/fb$i.b"
done

# 4. Unhashed files directly under the root, with and without a query string: modest hour, never immutable.
i=0
for p in /favicon.svg "/robots.txt?v=2" /og-image.svg; do
  i=$((i + 1))
  get "un$i" "$p"
  check "4 $p: public, max-age=3600, once" one_is "un$i" Cache-Control 'public, max-age=3600'
done

# 5. /v1 answers: a 404 and a non-GET keep the API's own cache header only; nginx adds no page headers.
get v404 /v1/this-route-does-not-exist-abcdefgh.js
check "5 a /v1 404 carries no page policy" test "$(hcount v404 Content-Security-Policy-Report-Only)" = 0
check "5 a /v1 404 is never immutable" not_immutable v404
check "5 a /v1 404 carries at most one Cache-Control" sh -c "[ \$(tr -d '\r' < $TMP/v404.h | grep -ic '^cache-control:') -le 1 ]"
check "5 a /v1 404 keeps the six security headers once" sec_ok v404
get vfresh /v1/prices/freshness -H 'Accept-Encoding: gzip'
check "5 /v1/prices/freshness carries one Cache-Control and it is not nginx's" sh -c "[ \$(tr -d '\r' < $TMP/vfresh.h | grep -ic '^cache-control:') = 1 ] && ! tr -d '\r' < $TMP/vfresh.h | grep -i '^cache-control:' | grep -Eiq 'immutable|31536000|^cache-control: no-cache\$'"
get vpost /v1/ready -X POST
check "5 a POST to /v1 is not given the page's no-cache by nginx" sh -c "! tr -d '\r' < $TMP/vpost.h | grep -i '^cache-control:' | grep -iq 'immutable'"

# 6. An oversize body is refused by nginx itself: the error answer keeps the six security headers once, no version.
head -c 1500000 /dev/zero > "$TMP/big.bin"
curl -sS -D "$TMP/big.h" -o "$TMP/big.b" -X POST -H 'Content-Type: application/octet-stream' --data-binary "@$TMP/big.bin" "$BASE/v1/ready" >/dev/null 2>&1
check "6 a 1.5 MB body to /v1 answers 413" test "$(status big)" = 413
check "6 the 413 keeps the six security headers once" sec_ok big
check "6 the 413 names no server version" sh -c "tr -d '\r' < $TMP/big.h | grep -i '^server:' | grep -Eqv '[0-9]' && ! grep -Eq 'nginx/[0-9]' $TMP/big.b"
check "6 the 413 carries no page policy and is never immutable" sh -c "[ \$(tr -d '\r' < $TMP/big.h | grep -ic '^content-security-policy-report-only:') = 0 ] && ! tr -d '\r' < $TMP/big.h | grep -iq 'immutable'"

# 7. The pinned inline-script hash follows the script: the page's script hashes to the conf's pin, and a changed
# script would not.
hash_of() { awk 'BEGIN { RS = "\001" } { s = index($0, "<script>") + 8; e = index($0, "</script>"); printf "%s", substr($0, s, e - s) }' "$1" | openssl dgst -sha256 -binary | base64; }
pin=$(grep -o "'sha256-[^']*'" docker/nginx/pokeprices.conf | head -n 1 | tr -d "'")
check "7 the served page's inline script hashes to the pin in the conf" test "sha256-$(hash_of "$TMP/root.b")" = "$pin"
sed 's/<script>/<script>\/* edited *\//' "$TMP/root.b" > "$TMP/edited.html"
check "7 an edited inline script no longer matches the pin" test "sha256-$(hash_of "$TMP/edited.html")" != "$pin"

# 8. The policy names every picture host the API's card answers really carry (newer sets come from the second one).
for host in https://images.pokemontcg.io https://images.scrydex.com; do
  check "8 the page policy's img-src names $host" sh -c "tr -d '\\r' < $TMP/root.h | grep -i '^content-security-policy-report-only:' | grep -Fq \"$host\""
done

finish
