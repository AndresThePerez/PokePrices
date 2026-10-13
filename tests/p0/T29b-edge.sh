#!/bin/sh
# P0-i1-T29b edge cases: the amendment changes exactly the three approved things and nothing else.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
BASE=191b1a9
T29B=ab06f7a   # the T29b amendment commit; its manifest is what this file judges (P0-i2-T01 later changed Q8, S1 and the frozen list)
M=gates/manifest-P0.json
GREP=/usr/bin/grep

# 1. gate-by-gate and key-by-key: only T1.command, EC-X9.check, EC-X10.check differ from the base manifest
D=$(mktemp -d)
git show "$BASE:$M" > "$D/old.json"
git show "$T29B:$M" > "$D/new.json"
paths() { jq -c '[paths(scalars)] | .[]' "$1" | sort; }
paths "$D/old.json" > "$D/op"; paths "$D/new.json" > "$D/np"
check "scalar key paths are identical to the base manifest" cmp -s "$D/op" "$D/np"
jq -c '[paths(scalars) as $p | {p: $p, v: getpath($p)}] | .[]' "$D/old.json" | sort > "$D/ov"
jq -c '[paths(scalars) as $p | {p: $p, v: getpath($p)}] | .[]' "$D/new.json" | sort > "$D/nv"
check_out "exactly three scalar values changed" 3 sh -c "comm -13 '$D/ov' '$D/nv' | wc -l | tr -d ' '"
T1_OLD=$(jq -r '.gates[]|select(.id=="T1").command' "$D/old.json")
T1_NEW=$(jq -r '.gates[]|select(.id=="T1").command' "$D/new.json")
check_out "T1 differs only by inserting --processes=4 after --parallel" "$T1_OLD" sh -c "printf '%s' '$T1_NEW' | sed 's/ --processes=4//'"
check "all other gates are byte-identical (jq canonical)" sh -c "[ \"\$(jq -cS '[.gates[]|select(.id!=\"T1\")]' $D/old.json)\" = \"\$(jq -cS '[.gates[]|select(.id!=\"T1\")]' $D/new.json)\" ]"
check "floor, thresholds and exit_criteria_runner unchanged" sh -c "[ \"\$(jq -cS 'del(.gates,.exit_criteria)' $D/old.json)\" = \"\$(jq -cS 'del(.gates,.exit_criteria)' $D/new.json)\" ]"
check "exit criteria other than X9/X10 unchanged" sh -c "[ \"\$(jq -cS '[.exit_criteria[]|select(.id!=\"EC-X9\" and .id!=\"EC-X10\")]' $D/old.json)\" = \"\$(jq -cS '[.exit_criteria[]|select(.id!=\"EC-X9\" and .id!=\"EC-X10\")]' $D/new.json)\" ]"

# 2. byte stability: the diff touches exactly 3 lines, 4-space indent kept, no trailing whitespace/CR/tabs
check_out "json diff vs base is 3 added + 3 removed lines" 6 sh -c "git diff -U0 $BASE $T29B -- $M |$GREP -c -E '^[+-][^+-]'"
check "no tab, CR or trailing space in the json" sh -c "! $GREP -q -P '\t|\r| \$' $M"
check "file ends with exactly one newline" sh -c "[ \"\$(tail -c1 $M | od -An -tx1 | tr -d ' ')\" = 0a ] && [ \"\$(tail -c2 $M | od -An -tx1 | tr -d ' ')\" != 0a0a ]"
check "new json keeps 2-space base indentation of the base file" sh -c "[ \"\$(git show $BASE:$M | $GREP -c '^  \"')\" = \"\$($GREP -c '^  \"' $M)\" ]"

# 3. md twin mirrors the json
MD=gates/manifest-P0.md
check "md T1 command line is the json T1 command" sh -c "$GREP -F -x -- '- command: \`$T1_NEW\`' $MD"
for id in EC-X9 EC-X10; do
  chk=$(jq -r --arg i "$id" '.exit_criteria[]|select(.id==$i)|.check' "$M")
  txt=$(jq -r --arg i "$id" '.exit_criteria[]|select(.id==$i)|.text' "$M")
  check "md $id line mirrors json text and check" sh -c "$GREP -F -x -- '- $id ($txt): \`$chk\`' $MD"
done
check "md has no stale --parallel --order-by T1 command" sh -c "! $GREP -F -- 'pest -c phpunit.xml --parallel --order-by' $MD"
check "md has no stale prose EC-X9/X10 checks" sh -c "! $GREP -E -- 'same check as D4|the scorecard.s make_exit' $MD"
check "md diff vs base is 3 lines changed" sh -c "[ \"\$(git diff -U0 $BASE $T29B -- $MD |$GREP -v -E '^(\+\+\+|---)' | $GREP -c -E '^[+-]')\" = 6 ]"
check "T07 edge mirrors the 4-worker command" sh -c "$GREP -q -- '--parallel --processes=4 --order-by=random' tests/p0/T07-edge.sh"

# 4. approved hash matches the blob, and a stale hash is rejected
check "approved hash verifies the committed blob" sh -c "git show HEAD:$M | sha256sum | cut -d' ' -f1 | cmp -s - <(cut -d' ' -f1 gates/manifest-P0.approved.sha256) 2>/dev/null || [ \"\$(git show HEAD:$M | sha256sum | cut -d' ' -f1)\" = \"\$(cut -d' ' -f1 gates/manifest-P0.approved.sha256)\" ]"
check "old pre-amendment hash is gone from the tree" sh -c "! git grep -q "ee4ee1d811ec371b20046faae6303e26e1939ca9f861e4f8d904228d08713""7f2" -- gates tests scripts"
check "the file the T02 literal names equals the hash file" sh -c "[ \"\$(sed -n 's/^APPROVED=//p' tests/p0/T02-edge.sh)\" = \"\$(cut -d' ' -f1 gates/manifest-P0.approved.sha256)\" ]"

# 5. widened subject regex: accepts the intended form and still rejects forgeries
RE='^\[P[0-9]+-i[0-9]+-(T[0-9]{2}[a-z]?|HG[0-9]{2})\] .+'
RE_LINE=$($GREP -F 'every subject carries' -A1 tests/p0/T01-edge.sh | $GREP -o -E '\^\\\[P[^"]*' | head -n1)
check_out "T01-edge uses the documented widened regex" "$RE" printf '%s' "$RE_LINE"
acc() { printf '%s\n' "$2" | $GREP -q -E "$RE"; }
rej() { ! printf '%s\n' "$2" | $GREP -q -E "$RE"; }
check "accepts [P0-i1-T29b] x" acc d '[P0-i1-T29b] x'
check "accepts [P0-i1-T29] x" acc d '[P0-i1-T29] x'
check "rejects two suffix letters [P0-i1-T29bb]" rej d '[P0-i1-T29bb] x'
check "rejects one-digit task [P0-i1-T2b]" rej d '[P0-i1-T2b] x'
check "rejects uppercase suffix [P0-i1-T29B]" rej d '[P0-i1-T29B] x'
check "rejects digit suffix [P0-i1-T290] glued (3 digits)" rej d '[P0-i1-T290] x'
check "rejects empty description" rej d '[P0-i1-T29b] '
check "rejects missing space" rej d '[P0-i1-T29b]x'
check "rejects no prefix" rej d 'T29b x'

# 6. the regex plus trailer/AI/session guards bite on a scratch repo
S=.gates/tmp/T29b-edge.$$; mkdir -p .gates/tmp
git clone -q --no-hardlinks . "$S" || { echo "not ok - clone"; exit 1; }
( cd "$S" && git -c user.name=t -c user.email=t@example.invalid commit -q --allow-empty -m '[P0-i1-T29bb] forged' )
check "T01-edge subject check fails when a [..T29bb] subject exists" sh -c "! ( cd $S && sh tests/p0/T01-edge.sh 2>&1 | $GREP -q '^ok - every subject carries' )"
( cd "$S" && git reset -q --hard "$(git -C . rev-parse origin/HEAD 2>/dev/null || git rev-parse HEAD~1)" && git -c user.name=t -c user.email=t@example.invalid commit -q --allow-empty -m '[P0-i1-T29b] fine

Co-Authored-By: x' )
check "T01-edge trailer check fails on a trailer-bearing commit" sh -c "! ( cd $S && sh tests/p0/T01-edge.sh 2>&1 | $GREP -q '^ok - no commit has a trailer' )"
rm -rf "$D"
finish
