#!/bin/sh
# P0-i2-T02 edge cases (reviewer): every Ti2-02.sh assertion must bite on a mutated README copy,
# a missing README must not be masked as "no match", and the README's claims must hold against
# the Makefile, the manifest, the gate script and the running stack.
# Mutants live in the git-ignored .gates/tmp/; the README itself is never edited.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
R=api/README.md
OUT=.gates/tmp/Ti2-02-edge
RUN=.gates/tmp/Ti2-02-edge-run.sh
mkdir -p "$OUT"
# The real acceptance script with only the README path swapped (it sits two levels under the
# root, as .gates/tmp/ does, so its own cd lands on the repository root).
sed 's|^F=api/README.md$|F=$MUT|' tests/p0/Ti2-02.sh > "$RUN"
check "mutant runner swaps exactly the README path" $GREP -qxF 'F=$MUT' "$RUN"
check "mutant runner still calls the real nohits and grep" sh -c '/usr/bin/grep -qF "NOHITS=scripts/lib/nohits" "$0" && /usr/bin/grep -qF "GREP=/usr/bin/grep" "$0"' "$RUN"

app() { cp "$R" "$OUT/$1.md"; printf '\n%s\n' "$2" >> "$OUT/$1.md"; }
sub() { sed "$2" "$R" > "$OUT/$1.md"; }
run() { MUT="$OUT/$1.md" sh "$RUN" > "$OUT/$1.out" 2>&1; }
D_UNACC='no unaccented PokePrices'
D_STOCK="none of Laravel's stock README text"
D_RT='no real-time, live-price or broadcasting claim'
D_PKG='names no not-built package'
D_READY='names the readiness route GET /v1/ready'
D_UP='names the liveness route GET /up'
D_LINK='links the root README (../README.md)'
D_SHORT='short: at most 60 lines'
D_HY='no home path, server name, LAN or Tailscale address (hygiene patterns)'
D_ART='every php artisan command the README names exists (and it names at least one)'

# --- 0. control: an untouched copy fails only "tracked" (the copy is git-ignored) ------------
cp "$R" "$OUT/control.md"; run control
check "control copy: exactly one check fails (tracked)" sh -c '[ "$(/usr/bin/grep -c "^not ok" "$0")" -eq 1 ] && /usr/bin/grep -q "^not ok - tracked: " "$0"' "$OUT/control.out"
check "control copy: the artisan check ran green (docker reachable)" $GREP -qxF "ok - $D_ART" "$OUT/control.out"

# --- 1. each assertion bites on the mutation it claims to catch ------------------------------
app unacc 'Prices come from PokePrices.';                    run unacc
check "bites: unaccented PokePrices" $GREP -qxF "not ok - $D_UNACC" "$OUT/unacc.out"
app stock 'Laravel Sponsors thank you.';                     run stock
check "bites: stock Laravel text" $GREP -qxF "not ok - $D_STOCK" "$OUT/stock.out"
app rt 'Prices update in real time.';                        run rt
check "bites: real-time claim" $GREP -qxF "not ok - $D_RT" "$OUT/rt.out"
app intraday 'Intraday quotes stream in.';                   run intraday
check "bites: intraday claim" $GREP -qxF "not ok - $D_RT" "$OUT/intraday.out"
app pkg 'Queues run on Horizon.';                            run pkg
check "bites: not-built package (Horizon)" $GREP -qxF "not ok - $D_PKG" "$OUT/pkg.out"
app mysql 'Data lives in MySQL.';                            run mysql
check "bites: not-built datastore (MySQL)" $GREP -qxF "not ok - $D_PKG" "$OUT/mysql.out"
sub noready 's|GET /v1/ready|GET /v1/health|'
run noready
check "bites: readiness route renamed" $GREP -qxF "not ok - $D_READY" "$OUT/noready.out"
sub upload 's|GET /up|GET /upload|'
run upload
check "bites: liveness route /up turned into /upload" $GREP -qxF "not ok - $D_UP" "$OUT/upload.out"
sub nolink 's|(\.\./README\.md)|(README.md)|'
run nolink
check "bites: root README link without ../" $GREP -qxF "not ok - $D_LINK" "$OUT/nolink.out"
app bridge "$(printf 'Reach it at %s.0.1 from a container.' 172.17)";                  run bridge
check "bites: Docker bridge address" $GREP -qxF "not ok - $D_HY" "$OUT/bridge.out"
app tailnet "$(printf 'Reach it at 100.%s.102.103 over the tailnet.' 101)";            run tailnet
check "bites: Tailscale address" $GREP -qxF "not ok - $D_HY" "$OUT/tailnet.out"
app lan "$(printf 'Reach it at 192.%s.1.20.' 168)";                                    run lan
check "bites: LAN address" $GREP -qxF "not ok - $D_HY" "$OUT/lan.out"
app homepath "$(printf 'Edit /%s/someone/work/x.' home)";                              run homepath
check "bites: home path" $GREP -qxF "not ok - $D_HY" "$OUT/homepath.out"
app tilde "$(printf 'See ~/%s/pokeprices.' Repositories)";                            run tilde
check "bites: tilde repository path" $GREP -qxF "not ok - $D_HY" "$OUT/tilde.out"
app badart 'Run `php artisan nosuch:command`.';              run badart
check "bites: unknown artisan command" $GREP -qxF "not ok - $D_ART" "$OUT/badart.out"
sub noart 's|php artisan|php tool|g'
run noart
check "bites: README naming no artisan command at all" $GREP -qxF "not ok - $D_ART" "$OUT/noart.out"
# A command name the checker's pattern cannot read whole must not be truncated into a real one.
app digit 'Run `php artisan pokeprices:queue-probe2`.';      run digit
check "bites: artisan name ending in a digit is not truncated into a real command" $GREP -qxF "not ok - $D_ART" "$OUT/digit.out"
# A route that merely starts with the named one is not the named route.
sub readyz 's|GET /v1/ready|GET /v1/readyz|'
run readyz
check "bites: GET /v1/readyz is not GET /v1/ready" $GREP -qxF "not ok - $D_READY" "$OUT/readyz.out"

# --- 2. length boundary: exactly 60 lines pass, 61 fail -------------------------------------
n=$(wc -l < "$R")
cp "$R" "$OUT/len60.md"; i=$n; while [ "$i" -lt 60 ]; do echo >> "$OUT/len60.md"; i=$((i + 1)); done
cp "$OUT/len60.md" "$OUT/len61.md"; echo >> "$OUT/len61.md"
check "len60 copy has exactly 60 lines" test "$(wc -l < "$OUT/len60.md")" -eq 60
check "len61 copy has exactly 61 lines" test "$(wc -l < "$OUT/len61.md")" -eq 61
run len60; run len61
check "60 lines pass the length check" $GREP -qxF "ok - $D_SHORT" "$OUT/len60.out"
check "61 lines fail the length check" $GREP -qxF "not ok - $D_SHORT" "$OUT/len61.out"

# --- 3. a missing or empty README must not turn the negative checks into passes (rc-2 masking) --
check "the absent path really is absent" test ! -e "$OUT/absent.md"
MUT="$OUT/absent.md" sh "$RUN" > "$OUT/absent.out" 2>&1
for d in "$D_UNACC" "$D_STOCK" "$D_RT" "$D_PKG" "$D_HY" "$D_ART"; do
  check "missing README fails: $d" $GREP -qxF "not ok - $d" "$OUT/absent.out"
done
: > "$OUT/empty.md"; run empty
check "empty README fails the PokéPrices-name check" $GREP -qxF 'not ok - names PokéPrices with the accent' "$OUT/empty.out"
check "empty README fails the readiness-route check" $GREP -qxF "not ok - $D_READY" "$OUT/empty.out"
check "empty README fails the artisan check" $GREP -qxF "not ok - $D_ART" "$OUT/empty.out"

# --- 4. the README's claims against the repository ------------------------------------------
check "the sh block's make targets exist in the Makefile" sh -c '
  t=$(/usr/bin/grep -oE "^make [a-z]+" "$0" | awk "{print \$2}" | sort -u); [ -n "$t" ] || exit 1
  for x in $t; do /usr/bin/grep -qE "^$x:" Makefile || exit 1; done' "$R"
check "the README names make init and make gates" sh -c '/usr/bin/grep -q "^make init" "$0" && /usr/bin/grep -q "^make gates" "$0"' "$R"
check "the pest command is the manifest's T1 command prefix" sh -c '
  c=$(/usr/bin/grep -E "^docker compose .*vendor/bin/pest" "$0" | head -n 1 | sed -E "s/[[:space:]]+\$//")
  [ -n "$c" ] && /usr/bin/grep -qF "\"command\": \"$c " gates/manifest-P0.json' "$R"
check "every ../ path the README names exists from api/" sh -c '
  p=$(/usr/bin/grep -oE "\.\./[A-Za-z0-9_./-]*[A-Za-z0-9_]" "$0" | sort -u); [ -n "$p" ] || exit 1
  for x in $p; do [ -e "api/$x" ] || exit 1; done' "$R"
check "the OpenAPI contract the README names is tracked and gate C1 compares it by cmp" sh -c '
  git ls-files --error-unmatch openapi/api.json >/dev/null && /usr/bin/grep -q "cmp -s openapi/api.json" scripts/gates/c1.sh'
check "scramble:export is a registered command (the README names it)" sh -c '
  docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan list --format=json | jq -e "[.commands[].name] | index(\"scramble:export\")"'
check "the scheduler entry is every minute in routes/console.php" $GREP -qE "everyMinute\(\)->name\('pokeprices-probe'\)" api/routes/console.php
check "/up is the health route in bootstrap/app.php" $GREP -qF "health: '/up'" api/bootstrap/app.php
# "the SPA owns every other path" is false: /up, /sanctum/*, /login... are Laravel's (root README, EC-routing).
check "no paragraph says the SPA owns every other path without naming Laravel's /up" sh -c '
  awk -v RS="" "/every other path/ && !/\/up/ {bad=1} END {exit bad}" "$0"' "$R"

# --- 5. the README's runtime claims against the running stack ---------------------------------
H='--resolve pokeprices.localhost:8095:127.0.0.1'
U=http://pokeprices.localhost:8095
check "GET /v1/ready answers 200 with the three README checks" sh -c '
  b=$(curl -fsS --max-time 5 $0 $1/v1/ready) && printf "%s" "$b" | jq -e ".checks | keys == [\"database\",\"redis_cache\",\"redis_queue\"]"' "$H" "$U"
check "an unknown /v1 path is problem+json and carries X-Request-Id" sh -c '
  h=$(curl -sS --max-time 5 -D - -o /dev/null $0 $1/v1/nope-ti2-02) && printf "%s" "$h" | /usr/bin/grep -qi "^content-type: application/problem+json" && printf "%s" "$h" | /usr/bin/grep -qi "^x-request-id: ."' "$H" "$U"
check "POST /v1/ready (405) is problem+json" sh -c '
  curl -sS --max-time 5 -X POST -D - -o /dev/null $0 $1/v1/ready | /usr/bin/grep -qi "^content-type: application/problem+json"' "$H" "$U"
check "/up echoes a valid incoming X-Request-Id" sh -c '
  curl -sS --max-time 5 -D - -o /dev/null -H "X-Request-Id: edge-ti2-02-abcdef" $0 $1/up | /usr/bin/grep -qi "^x-request-id: edge-ti2-02-abcdef"' "$H" "$U"
check "/up replaces an invalid incoming X-Request-Id (too short) with a fresh one" sh -c '
  v=$(curl -sS --max-time 5 -D - -o /dev/null -H "X-Request-Id: short" $0 $1/up | tr -d "\r" | /usr/bin/grep -i "^x-request-id:" | sed "s/^[^:]*: *//")
  [ -n "$v" ] && [ "$v" != short ]' "$H" "$U"
finish
