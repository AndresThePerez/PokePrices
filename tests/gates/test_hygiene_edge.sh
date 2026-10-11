#!/bin/sh
# P0-i1-T29 review: edge cases for the hygiene, trailer and denominator gates.
# Every planted token is assembled at run time so this tracked file stays clean under HY-hygiene.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp; OUT=$(mktemp -d "$ROOT/.gates/tmp/hye-XXXXXX")
HY="$ROOT/scripts/gates/hy-hygiene.sh"; TR="$ROOT/scripts/gates/hy-trailers.sh"
scratch() {
  d=$(mktemp -d "$ROOT/.gates/tmp/hyes-XXXXXX")
  ( cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t
    mkdir -p gates && cp "$ROOT/gates/hygiene.patterns" gates/ && printf 'ok\n' > README.md
    git add -A && git commit -qm "Start" )
  printf '%s\n' "$d"
}
# plant FILE-NAME CONTENT-FORMAT ARG: commit one planted file in a fresh scratch repo
plant() { d=$(scratch); printf "$2" "$3" > "$d/$1"; ( cd "$d" && git add -A && git commit -qm "Add" ); printf '%s\n' "$d"; }
hy() { ( cd "$1" && env GATES_OUT="$OUT" PP_RUN_CONTEXT=nested sh "$HY" ); }
tr_() { ( cd "$1" && env GATES_OUT="$OUT" sh "$TR" ); }

# Public look-alikes must stay clean.
d=$(scratch); {
  printf 'a 100.%s.1.1\n' 1; printf 'b 172.%s.0.1\n' 32; printf 'c 172.%s.0.1\n' 15
  printf 'd host:%s\n' 8095; printf 'e host:%s\n' 8080; printf 'f host:%s\n' 80840; printf 'g 100.%s.1.1\n' 128; printf 'h 1100.%s.1.1\n' 64
} > "$d/n.txt"; ( cd "$d" && git add -A && git commit -qm "Add n" )
check "public look-alikes (100.1.x, 172.32, 172.15, :8095, :8080, :80840, 100.128, 1100.64) do not fire" hy "$d"

# Each private form fires on its own.
for spec in '172.%s.4.5:16' '172.%s.4.5:31' '100.%s.4.5:64' '100.%s.4.5:127' '10.%s.0.1:0' 'x:%s:2' 'x:%s:6' '%s/Repositories:~' '/run/user/%s:1000' '/Users/%s:bob' '/%s/someone:home'; do
  fmt=${spec%:*}; arg=${spec##*:}
  case $spec in
    x:*) d=$(plant p.txt 'host:808%s\n' "$arg") ;;
    '%s/Repositories:~') d=$(plant p.txt '%s/Repositories/x\n' '~') ;;
    '/run/user/%s:1000') d=$(plant p.txt '/run/user/%s\n' 1000) ;;
    '/Users/%s:bob') d=$(plant p.txt '/Users/%s\n' bob) ;;
    '/%s/someone:home') d=$(plant p.txt '/%s/someone\n' home) ;;
    *) d=$(plant p.txt "$fmt\\n" "${spec##*:}") ;;
  esac
  check "private form fires: $spec" sh -c 'cd "$0" && ! env GATES_OUT="$1" PP_RUN_CONTEXT=nested sh "$2"' "$d" "$OUT" "$HY"
done

# Exclusions are exactly lock files, web/package-lock.json and the patterns file.
d=$(scratch); mkdir -p "$d/web" "$d/sub"; for f in composer.lock web/package-lock.json sub/yarn.lock; do printf 'ip 192.%s.0.9\n' 168 > "$d/$f"; done
( cd "$d" && git add -A && git commit -qm "Add locks" )
check "lock files and web/package-lock.json are excluded from both scans" hy "$d"
d=$(scratch); mkdir -p "$d/sub"; printf 'ip 192.%s.0.9\n' 168 > "$d/sub/package-lock.json"; ( cd "$d" && git add -A && git commit -qm "Add nested lock" )
check "a package-lock.json outside web/ is still scanned" sh -c 'cd "$0" && ! env GATES_OUT="$1" PP_RUN_CONTEXT=nested sh "$2"' "$d" "$OUT" "$HY"
d=$(scratch); printf 'ip 192.%s.0.9\n' 168 > "$d/notes.lockfile"; ( cd "$d" && git add -A && git commit -qm "Add lockfile" )
check "a file merely named like .lockfile is still scanned" sh -c 'cd "$0" && ! env GATES_OUT="$1" PP_RUN_CONTEXT=nested sh "$2"' "$d" "$OUT" "$HY"
d=$(scratch); printf 'bin 192.%s.0.9 \000 x\n' 168 > "$d/blob.bin"; ( cd "$d" && git add -A && git commit -qm "Add blob" )
check "a binary file is skipped (-I)" hy "$d"

# Local pattern file: parentheses and alternation stay inside their own group.
d=$(scratch); printf '%s\n' 'zz(a|b)zz' > "$d/.git/info/hygiene.local"
printf 'see zz%szz\n' b > "$d/l.txt"; printf 'see zz%szz\n' c > "$d/m.txt"; ( cd "$d" && git add -A && git commit -qm "Add l" )
check "a local pattern with a group and a pipe matches its alternation" sh -c 'cd "$0" && ! env GATES_OUT="$1" PP_RUN_CONTEXT=primary sh "$2"' "$d" "$OUT" "$HY"
d=$(scratch); printf '%s\n' 'zz(a|b)zz' > "$d/.git/info/hygiene.local"; printf 'see zz%szz\n' c > "$d/m.txt"; ( cd "$d" && git add -A && git commit -qm "Add m" )
check "a local pattern group does not leak into other text (primary, clean)" sh -c 'cd "$0" && env GATES_OUT="$1" PP_RUN_CONTEXT=primary sh "$2"' "$d" "$OUT" "$HY"
d=$(scratch); : > "$d/.git/info/hygiene.local"
check "a zero-byte local file fails in primary" sh -c 'cd "$0" && ! env GATES_OUT="$1" PP_RUN_CONTEXT=primary sh "$2"' "$d" "$OUT" "$HY"
check "a zero-byte local file passes in nested" hy "$d"
d=$(scratch); printf '%s\n' 'bad(' > "$d/.git/info/hygiene.local"
check "an invalid local pattern fails closed, never clean" sh -c 'cd "$0" && ! env GATES_OUT="$1" PP_RUN_CONTEXT=nested sh "$2"' "$d" "$OUT" "$HY"

# A leak must never be echoed into the gate output.
d=$(plant s.txt 'ip 192.%s.77.88\n' 168)
check "the leaked text is not repeated in the details or stdout" sh -c 'cd "$0" && env GATES_OUT="$1" PP_RUN_CONTEXT=nested sh "$2" > "$1/stdout.txt" 2>&1; ! /usr/bin/grep -rqF "77.88" "$1"/HY-hygiene.* "$1/stdout.txt"' "$d" "$OUT" "$HY"

# Trailers.
d=$(scratch); ( cd "$d" && printf 'x\n' > a.txt && git add -A && git commit -qm "Add a" -m "Signed-off-by: someone <s@example.invalid>" )
check "trailers: Signed-off-by is flagged" sh -c 'cd "$0" && ! env GATES_OUT="$1" sh "$2"' "$d" "$OUT" "$TR"
d=$(scratch); ( cd "$d" && printf 'x\n' > a.txt && git add -A && git commit -qm "Add a" -m "claude-session: abc" )
check "trailers: a lower-case Claude-Session key is flagged" sh -c 'cd "$0" && ! env GATES_OUT="$1" sh "$2"' "$d" "$OUT" "$TR"
d=$(scratch); ( cd "$d" && printf 'x\n' > a.txt && git add -A && git commit -qm "Ask ANTHROPIC about it" )
check "trailers: a vendor name in the subject, any case, is flagged" sh -c 'cd "$0" && ! env GATES_OUT="$1" sh "$2"' "$d" "$OUT" "$TR"
d=$(scratch); ( cd "$d" && printf 'x\n' > a.txt && git add -A && git commit -qm "Rename Claudette and the Anthropics file" )
check "trailers: a vendor name inside a longer word is not flagged" tr_ "$d"
d=$(scratch); ( cd "$d" && git checkout -q -b side && printf 'x\n' > s.txt && git add -A && git commit -qm "Side" && git checkout -q main && printf 'y\n' > m.txt && git add -A && git commit -qm "Main" && git merge -q --no-ff -m "Merge side" side )
check "trailers: a plain merge commit is clean" tr_ "$d"
d=$(scratch); ( cd "$d" && printf 'x\n' > a.txt && git add -A && git commit -qm "Add a" -m "Body line one." -m "Body paragraph two, no key." )
check "trailers: ordinary body paragraphs are clean" tr_ "$d"
d=$(scratch); ( cd "$d" && printf 'x\n' > a.txt && git add -A && git commit -qm "Add a" -m "Note: x" )
check "trailers: PHASE_BASE after the offending commit hides it (range honoured)" sh -c 'cd "$0" && env GATES_OUT="$1" PHASE_BASE=HEAD sh "$2"' "$d" "$OUT" "$TR"

# Live repo shape.
check "live repo: HY-hygiene value is 0 and denominator is files plus commits" sh -c 'env GATES_OUT="$0" PP_RUN_CONTEXT=primary sh scripts/gates/hy-hygiene.sh >/dev/null && want=$(( $(git ls-files | wc -l) + $(git rev-list --count HEAD) )) && jq -e --argjson w "$want" ".value == 0 and .denominator == \$w" "$0/HY-hygiene.json"' "$OUT"

# DEN.
R=$(mktemp -d "$ROOT/.gates/tmp/dene-XXXXXX"); mkdir -p "$R/records"; RO=${R#"$ROOT/"}
printf '{"gates":[{"id":"A","denominator_min":1},{"id":"DEN","denominator_min":1}]}\n' > "$R/m.json"
den() { env GATES_OUT="$RO" MANIFEST="$R/m.json" python3 -I scripts/gates/den.py; }
printf '{"id":"A","status":"pass","denominator":"3","denominator_source":"echo 3"}\n' > "$R/records/A.json"
check "DEN: a string denominator is a violation" sh -c '! env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py' "$RO" "$R/m.json"
printf '{"id":"A","status":"pass","denominator":0,"denominator_source":"echo 0"}\n' > "$R/records/A.json"
check "DEN: a zero denominator is a violation" sh -c '! env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py' "$RO" "$R/m.json"
printf '{"id":"A","status":"pass","denominator":3}\n' > "$R/records/A.json"
check "DEN: a missing denominator_source is a violation" sh -c '! env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py' "$RO" "$R/m.json"
printf '{"id":"A","status":"pass","denominator":3,"denominator_source":"  "}\n' > "$R/records/A.json"
check "DEN: a blank denominator_source is a violation" sh -c '! env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py' "$RO" "$R/m.json"
printf '{"id":"A","status":"pass","denominator":true,"denominator_source":"x"}\n' > "$R/records/A.json"
check "DEN: a boolean denominator is a violation" sh -c '! env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py' "$RO" "$R/m.json"
printf '{"id":"A","status":"out_of_scope","reason":"not in P0","denominator":0}\n' > "$R/records/A.json"
check "DEN: an out_of_scope record with a reason and denominator 0 is allowed" den
printf '{"id":"A","status":"out_of_scope","denominator":0}\n' > "$R/records/A.json"
check "DEN: an out_of_scope record without a reason is a violation" sh -c '! env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py' "$RO" "$R/m.json"
printf '{"id":"Z","status":"pass","denominator":3,"denominator_source":"x"}\n' > "$R/records/A.json"
check "DEN: a record whose id differs from its file name is a violation" sh -c '! env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py' "$RO" "$R/m.json"
printf '{"id":"A","status":"pass","denominator":3,"denominator_source":"x"}\n' > "$R/records/A.json"
printf '{"id":"Q","status":"pass","denominator":3,"denominator_source":"x"}\n' > "$R/records/Q.json"
check "DEN: a record for a gate the manifest lacks is a violation" sh -c '! env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py' "$RO" "$R/m.json"
rm "$R/records/Q.json"
check "DEN: valid records pass and DEN.json records value 0" sh -c 'env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py >/dev/null && jq -e ".id == \"DEN\" and .value == 0 and .denominator == 1" "$0/DEN.json"' "$RO" "$R/m.json"
check "DEN: a missing manifest file exits non-zero" sh -c '! env GATES_OUT="$0" MANIFEST="$1/none.json" python3 -I scripts/gates/den.py' "$RO" "$R"
check "DEN: GATES_OUT unset exits non-zero" sh -c '! env -u GATES_OUT MANIFEST="$0" python3 -I scripts/gates/den.py' "$R/m.json"
printf '{"gates":[{"id":"A","denominator_min":5},{"id":"DEN","denominator_min":1}]}\n' > "$R/m2.json"
check "DEN: a denominator exactly one below its minimum is a violation" sh -c '! env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py' "$RO" "$R/m2.json"
printf '{"id":"A","status":"pass","denominator":5,"denominator_source":"x"}\n' > "$R/records/A.json"
check "DEN: a denominator exactly at its minimum passes" sh -c 'env GATES_OUT="$0" MANIFEST="$1" python3 -I scripts/gates/den.py' "$RO" "$R/m2.json"

# Recipe lint and contract hygiene on the three scripts.
for f in scripts/gates/hy-hygiene.sh scripts/gates/hy-trailers.sh scripts/gates/den.py; do
  check "$f is executable" test -x "$f"
  check "$f has no || true, exit 0 or bare npx" sh -c '! /usr/bin/grep -qE "\|\| *true|exit 0|(^|[ ;|&])npx " "$0"' "$f"
done
check "the gate scripts use set -u" sh -c '/usr/bin/grep -q "^set -u" scripts/gates/hy-hygiene.sh && /usr/bin/grep -q "^set -u" scripts/gates/hy-trailers.sh'
finish
