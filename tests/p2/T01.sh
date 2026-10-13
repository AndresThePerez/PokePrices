#!/bin/sh
# P2-i1-T01: p2-work is cut from the P1 tip and the P0 push-state branch checks accept it (any p<N>-work).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
P1_TIP=c308e45d96e6cbc21be3e06da9d07f4e0362f00d
OUT=$(mktemp -d "$(pwd)/.gates/tmp/P2-T01.XXXXXX") || exit 1
env -u TIER -u PS sh tests/p0/T01.sh > "$OUT/T01.out" 2>&1
TMPDIR=$OUT env -u TIER -u PS sh tests/p0/T01-edge.sh > "$OUT/T01-edge.out" 2>&1

check_out "HEAD is p2-work" p2-work git symbolic-ref --short HEAD
check "the P1 tip c308e45 is an ancestor of HEAD" git merge-base --is-ancestor "$P1_TIP" HEAD
check "tests/p0/T01.sh ran and printed its summary line" $GREP -q -x -E '[0-9]+ failure\(s\)' "$OUT/T01.out"
check "tests/p0/T01.sh prints no 'not ok' line in this checkout" sh -c '! '"$GREP"' -q "^not ok" "$0"' "$OUT/T01.out"
check "tests/p0/T01-edge.sh ran and printed its summary line" $GREP -q -x -E '[0-9]+ failure\(s\)' "$OUT/T01-edge.out"
# One T01-edge check is red on the P1 tip, independent of the branch name: "no home path in tracked files"
# matches the home-path regex text that three P1 test files carry on purpose (they are scanners):
# api/tests/Feature/Contract/PokeSearchContractEdgeTest.php:164, api/tests/Unit/Mapping/SetAliasTableTest.php:179
# and tests/p1/T16-edge.sh:66. It is named here exactly; any other 'not ok' line fails this test.
check "tests/p0/T01-edge.sh prints no 'not ok' line in this checkout beyond the known home-path self-match" sh -c \
  '! '"$GREP"' "^not ok" "$0" | '"$GREP"' -v -x -e "not ok - no home path in tracked files" | '"$GREP"' -q .' "$OUT/T01-edge.out"
check "the branch checks in T01-edge.sh pass in this checkout" sh -c \
  '/usr/bin/grep -q -x -F "ok - the local branches are main and p<N>-work branches only" "$0" && /usr/bin/grep -q -x -F "ok - HEAD is a symbolic ref to refs/heads/main or a refs/heads/p<N>-work branch" "$0"' "$OUT/T01-edge.out"
for f in tests/p0/T01.sh tests/p0/T01-edge.sh tests/p1/T07b.sh tests/p1/T07b-edge.sh; do
  check_out "$f is tracked as mode 100755" 100755 sh -c 'git ls-files -s "$0" | cut -d" " -f1' "$f"
done
finish
