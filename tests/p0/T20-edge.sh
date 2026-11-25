#!/bin/sh
# PD-i1-T20 review: gate X1 expects dev's 512m for api and still refuses a wrong memory limit.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
mkdir -p .gates/tmp; T=$(mktemp -d "$(pwd)/.gates/tmp/T20-XXXXXX"); T=${T#"$(pwd)/"}
trap 'rm -rf "$T"' EXIT
# builtin_table [REV]: the table x1.sh carries in its own text (at REV, or the work tree).
builtin_table() {
  if [ -n "${1:-}" ]; then git show "$1:scripts/gates/x1.sh"; else cat scripts/gates/x1.sh; fi |
    sed -n "s/^table='\(.*\)'\$/\1/p" | head -n 1
}
base() { # a copy of the built-in table with one service's bytes replaced (or removed with "del")
  if [ "$2" = del ]; then builtin_table | jq -c --arg s "$1" 'del(.[$s])'
  else builtin_table | jq -c --arg s "$1" --argjson v "$2" '.[$s] = $v'; fi
}
# only SERVICES: the failing rows of the last X1 run name exactly this service list.
only() { test "$(jq -rs '[.[] | select(.ok == false) | .service] | unique | join(",")' "$T/X1.details.jsonl")" = "$1"; }
run_x1() { X1_TABLE="$1" GATES_OUT="$T" sh scripts/gates/x1.sh >/dev/null 2>&1; }

check_out "the built-in table holds api at 512m" 536870912 sh -c 'sed -n "s/^table=\x27\(.*\)\x27\$/\1/p" scripts/gates/x1.sh | jq -r .api'
check "the built-in table differs from the one before only in api" sh -c '
  a=$(git show 5e086bf:scripts/gates/x1.sh | sed -n "s/^table=\x27\(.*\)\x27\$/\1/p" | jq -S "del(.api)")
  b=$(sed -n "s/^table=\x27\(.*\)\x27\$/\1/p" scripts/gates/x1.sh | jq -S "del(.api)")
  test -n "$a" -a "$a" = "$b"'
check "dev compose declares the api cap the built-in table expects" sh -c '
  want=$(sed -n "s/^table=\x27\(.*\)\x27\$/\1/p" scripts/gates/x1.sh | jq -r .api)
  got=$(docker compose -f compose.dev.yml config --format json | jq -r ".services.api.mem_limit | tostring")
  test -n "$want" -a "$want" = "$got"'

# A wrong api limit is still refused, whichever side of 512m it falls on, and only api is blamed.
base api 402653184 > "$T/api384.json"
run_x1 "$T/api384.json"; rc=$?
check "api expected at 384m is refused against dev's 512m" test "$rc" -ne 0
check "api at 384m blames api alone" only api
base api 1073741824 > "$T/api1g.json"
run_x1 "$T/api1g.json"; rc=$?
check "api expected at 1g is refused" test "$rc" -ne 0
check "api at 1g blames api alone" only api
# Another service's limit moved: refused, and only that service is blamed.
base worker 536870912 > "$T/worker512.json"
run_x1 "$T/worker512.json"; rc=$?
check "worker expected at 512m is refused (the raise stayed on api)" test "$rc" -ne 0
check "worker at 512m blames worker alone" only worker
# An empty table is a mismatch, never a pass.
printf '{}\n' > "$T/empty.json"
run_x1 "$T/empty.json"; rc=$?
check "an empty table is refused" test "$rc" -ne 0
# The built-in table passes on the running stack.
check "the real table passes" env GATES_OUT="$T" sh scripts/gates/x1.sh
finish
