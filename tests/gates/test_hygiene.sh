#!/bin/sh
# P0-i1-T29: hygiene and trailer gates are clean on the repo and catch planted leaks; DEN enforces denominators.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp; OUT=$(mktemp -d "$ROOT/.gates/tmp/hy-XXXXXX")
check "HY-hygiene clean on the repo (primary, local patterns present)" env GATES_OUT="$OUT" PP_RUN_CONTEXT=primary sh scripts/gates/hy-hygiene.sh
check "HY-trailers clean on the repo" env GATES_OUT="$OUT" sh scripts/gates/hy-trailers.sh
check "the local pattern file exists in the primary checkout" test -s .git/info/hygiene.local
check "no machine-specific line of the local file is in the tracked file" sh -c '! /usr/bin/grep -qxFf .git/info/hygiene.local gates/hygiene.patterns'
scratch() {
  d=$(mktemp -d "$ROOT/.gates/tmp/hys-XXXXXX")
  ( cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t
    mkdir -p gates && cp "$ROOT/gates/hygiene.patterns" gates/ && printf 'ok\n' > README.md
    git add -A && git commit -qm "Start" )
  printf '%s\n' "$d"
}
d=$(scratch); printf 'see /%s/someone/x\n' home > "$d/a.txt"; ( cd "$d" && git add -A && git commit -qm "Add a" )
check "catches a home path at HEAD" sh -c 'cd "$0" && ! env GATES_OUT="$1" PP_RUN_CONTEXT=nested sh "$2/scripts/gates/hy-hygiene.sh"' "$d" "$OUT" "$ROOT"
d=$(scratch); printf 'host 192.%s.1.20\n' 168 > "$d/b.txt"; ( cd "$d" && git add -A && git commit -qm "Add b" && git rm -q b.txt && git commit -qm "Remove b" )
check "catches a LAN address that exists only in history" sh -c 'cd "$0" && ! env GATES_OUT="$1" PP_RUN_CONTEXT=nested sh "$2/scripts/gates/hy-hygiene.sh"' "$d" "$OUT" "$ROOT"
d=$(scratch); printf 'ts 100.%s.2.3\n' 101 > "$d/c.txt"; ( cd "$d" && git add -A && git commit -qm "Add c" )
check "catches a Tailscale address" sh -c 'cd "$0" && ! env GATES_OUT="$1" PP_RUN_CONTEXT=nested sh "$2/scripts/gates/hy-hygiene.sh"' "$d" "$OUT" "$ROOT"
d=$(scratch); printf 'ok\n' > "$d/e.txt"; ( cd "$d" && git add -A && git commit -qm "Add e" )
check "primary context without the local pattern file fails" sh -c 'cd "$0" && ! env GATES_OUT="$1" PP_RUN_CONTEXT=primary sh "$2/scripts/gates/hy-hygiene.sh"' "$d" "$OUT" "$ROOT"
d=$(scratch); ( cd "$d" && printf 'x\n' > f.txt && git add -A && git commit -qm "Add f" -m "Co-Authored-By: someone <s@example.invalid>" )
check "catches a Co-Authored-By trailer" sh -c 'cd "$0" && ! env GATES_OUT="$1" sh "$2/scripts/gates/hy-trailers.sh"' "$d" "$OUT" "$ROOT"
d=$(scratch); ( cd "$d" && printf 'x\n' > g.txt && git add -A && git commit -qm "Add g" -m "Written with Claude" )
check "catches a Claude reference in a body" sh -c 'cd "$0" && ! env GATES_OUT="$1" sh "$2/scripts/gates/hy-trailers.sh"' "$d" "$OUT" "$ROOT"
d=$(scratch); ( cd "$d" && printf 'x\n' > h.txt && git add -A && git commit -qm "Add h" -m "Note: parsed as a trailer" )
check "catches any parsable trailer" sh -c 'cd "$0" && ! env GATES_OUT="$1" sh "$2/scripts/gates/hy-trailers.sh"' "$d" "$OUT" "$ROOT"
R=$(mktemp -d "$ROOT/.gates/tmp/den-XXXXXX"); mkdir -p "$R/records"
printf '{"gates":[{"id":"A","denominator_min":1},{"id":"B","denominator_min":10},{"id":"DEN","denominator_min":1}]}\n' > "$R/m.json"
printf '{"id":"A","status":"pass","denominator":3,"denominator_source":"echo 3"}\n' > "$R/records/A.json"
printf '{"id":"B","status":"pass","denominator":12,"denominator_source":"echo 12"}\n' > "$R/records/B.json"
check "DEN passes when every record has a denominator at its minimum" env GATES_OUT="${R#"$ROOT/"}" MANIFEST="$R/m.json" python3 -I scripts/gates/den.py
printf '{"id":"B","status":"pass","denominator":4,"denominator_source":"echo 4"}\n' > "$R/records/B.json"
check "DEN fails below a minimum" sh -c '! env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py' "${R#"$ROOT/"}" "$R/m.json"
rm "$R/records/B.json"
check "DEN fails when a gate has no record" sh -c '! env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py' "${R#"$ROOT/"}" "$R/m.json"
finish
