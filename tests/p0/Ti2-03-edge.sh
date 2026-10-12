#!/bin/sh
# P0-i2-T03 edge cases (reviewer): every Ti2-03.sh assertion must bite on a mutated copy, the T34-edge
# commands must be byte-identical to 0f21557 and say what their new descriptions claim, and the real
# test_c6_edge.sh must stay inside .gates/ (and api/, web/) on a stale tree and on a second run.
# Mutants and stubs live in the git-ignored .gates/tmp/; no tracked file is edited. The one stray file
# a stub writes outside the allowed trees is removed by name (rm -f / rmdir only).
# R114 (F-P0-124): the diff checks scan $BASE..$TASK, the P0-i2-T03 task commit found by its subject (so a
# rewritten history still resolves it), never HEAD; the two queue-probe commands are found under their
# check descriptions, never by line number, so later edits to T34-edge.sh leave this script green.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
NOHITS=scripts/lib/nohits
TASK=$(git log --format=%H --grep '^\[P0-i2-T03\] Make two PokéPrices edge tests' -1)
TASK=${TASK:-P0-i2-T03-task-commit-not-found}
BASE=$(git rev-parse --short=7 "$TASK^" 2>/dev/null)
BASE=${BASE:-P0-i2-T03-base-not-found}
E=tests/p0/T34-edge.sh; C6E=tests/gates/test_c6_edge.sh; Q=api/tests/Feature/QueueProbeEdgeTest.php; ACC=tests/p0/Ti2-03.sh
OUT=.gates/tmp/Ti2-03-edge
RUN=.gates/tmp/Ti2-03-edge-run.sh
DET=.gates/tmp/c6e/clone/.gates/tmp/c6e/out2
STRAY=.gates-ti2edge-stray
STRAY2=tests/.ti2edge-stray
mkdir -p "$OUT"
cleanup() { rm -f "$STRAY/o" "$STRAY2"; rm -f "$STRAY" 2>/dev/null; rmdir "$STRAY" 2>/dev/null; }
cleanup

# --- 1. T34-edge: only the two descriptions changed; the commands are byte-identical to the base --
check "the P0-i2-T03 task commit is found by its subject and is an ancestor of HEAD" git merge-base --is-ancestor "$TASK" HEAD
check "the base is the task commit's parent, the P0-i2-T02 commit" sh -c 'git log -1 --format=%s "$0" | /usr/bin/grep -q "^\[P0-i2-T02\] "' "$BASE"
check "T34-edge differs from the base in exactly 2 removed and 2 added lines" sh -c '
  [ "$(git diff -U0 "$1..$2" -- "$0" | /usr/bin/grep -c "^-[^-]")" -eq 2 ] && [ "$(git diff -U0 "$1..$2" -- "$0" | /usr/bin/grep -c "^+[^+]")" -eq 2 ]' "$E" "$BASE" "$TASK"
check "every changed T34-edge line is a check description line" sh -c '
  git diff -U0 "$1..$2" -- "$0" | /usr/bin/grep "^[-+][^-+]" | /usr/bin/grep -vc "^[-+]check \"queue-probe .*\" \\\\\$" | /usr/bin/grep -qx 0' "$E" "$BASE" "$TASK"
# qcmd FILE REGEX: the number of the "  sh -c " command line directly under the one check whose description
# matches REGEX; empty when the description is missing or repeated or no sh -c line follows it.
qcmd() { awk -v re="$2" 'BEGIN { d = -1 } NR == d + 1 && /^  sh -c / { c = NR } $0 ~ re { n++; d = NR } END { if (n == 1 && c) print c }' "$1"; }
lineof() { awk -v n="${2:-0}" 'NR == n' "$1"; }
LIVE_RE='^check "queue-probe live probe reports '\''probe processed'\'''
NEG_RE='^check "queue-probe with a negative timeout '
git show "$BASE:$E" > "$OUT/base-T34-edge.sh"
b_live=$(qcmd "$OUT/base-T34-edge.sh" '^check "queue-probe live probe exits 0 ')
b_neg=$(qcmd "$OUT/base-T34-edge.sh" '^check "queue-probe still exits 1 with a negative timeout ')
h_live=$(qcmd "$E" "$LIVE_RE"); h_neg=$(qcmd "$E" "$NEG_RE")
check "both queue-probe commands are found under their descriptions in the base and in T34-edge" \
  test -n "$b_live" -a -n "$b_neg" -a -n "$h_live" -a -n "$h_neg"
lineof "$OUT/base-T34-edge.sh" "$b_live" > "$OUT/base-live-probe.line"; lineof "$E" "$h_live" > "$OUT/head-live-probe.line"
lineof "$OUT/base-T34-edge.sh" "$b_neg" > "$OUT/base-negative-timeout.line"; lineof "$E" "$h_neg" > "$OUT/head-negative-timeout.line"
for k in live-probe negative-timeout; do
  check "T34-edge $k command (found under its description) is byte-identical to $BASE" cmp "$OUT/base-$k.line" "$OUT/head-$k.line"
  check "T34-edge $k command is a non-empty sh -c line" sh -c '/usr/bin/grep -q "^  sh -c " "$0"' "$OUT/head-$k.line"
done
# the description claims, matched against the command beneath them
check "live-probe command: --timeout=0 first, one retry with --timeout=8, both grep 'probe processed'" sh -c '
  l=$(cat "$0"); [ "$(printf "%s" "$l" | /usr/bin/grep -o -e "--timeout=[0-9-]*" | tr "\n" " ")" = "--timeout=0 --timeout=8 " ] &&
  [ "$(printf "%s" "$l" | /usr/bin/grep -o "probe processed" | wc -l)" -eq 2 ]' "$OUT/head-live-probe.line"
check "negative-timeout command: timeout 60, --timeout=-5, accepts rc 0 or 1 and nothing else" sh -c '
  l=$(cat "$0"); printf "%s" "$l" | /usr/bin/grep -qF "timeout 60 " && printf "%s" "$l" | /usr/bin/grep -qF -e "--timeout=-5" &&
  printf "%s" "$l" | /usr/bin/grep -qF "test \\\$rc -eq 0 -o \\\$rc -eq 1\"" && ! printf "%s" "$l" | /usr/bin/grep -qE "rc -eq [2-9]"' "$OUT/head-negative-timeout.line"
check "the line directly above each command is its own queue-probe description" sh -c '
  awk -v n="$(($1 - 1))" "NR == n" "$0" | /usr/bin/grep -q "^check \"queue-probe live probe" &&
  awk -v n="$(($2 - 1))" "NR == n" "$0" | /usr/bin/grep -q "^check \"queue-probe with a negative timeout"' "$E" "${h_live:-0}" "${h_neg:-0}"
# the locator follows the commands when lines move (F-P0-124) and refuses an ambiguous description
{ printf '%s\n' '# inserted 1' '# inserted 2' '# inserted 3'; cat "$E"; printf '%s\n' '# a later note'; } > "$OUT/t34-shifted.sh"
s_live=$(qcmd "$OUT/t34-shifted.sh" "$LIVE_RE"); s_neg=$(qcmd "$OUT/t34-shifted.sh" "$NEG_RE")
check "three lines inserted above and one appended: both commands are found 3 lines lower, unchanged" sh -c '
  [ "$1 $2" = "$(($3 + 3)) $(($4 + 3))" ] && [ "$(awk -v n="$1" "NR == n" "$0")" = "$(cat "$5")" ]' \
  "$OUT/t34-shifted.sh" "${s_live:-x}" "${s_neg:-x}" "${h_live:-0}" "${h_neg:-0}" "$OUT/head-live-probe.line"
{ cat "$E"; $GREP -e "$LIVE_RE" "$E"; printf '%s\n' '  sh -c "true"'; } > "$OUT/t34-dupdesc.sh"
d_live=$(qcmd "$OUT/t34-dupdesc.sh" "$LIVE_RE")
check "a repeated live-probe description makes the locator find nothing" test -z "$d_live"
check "QueueProbeEdgeTest keeps -5 in the dataset that asserts exit 1" sh -c '
  /usr/bin/grep -qF "assertExitCode(1)" "$0" && /usr/bin/grep -qF -e "$1" "$0"' "$Q" "->with(['0', '-5', 'abc', '']);"

# --- 2. Ti2-03.sh: a runner copy with only the three input paths swapped (it sits two levels under
#        the root, as .gates/tmp/ does, so its own cd lands on the repository root) ------------------
sed 's|^E=tests/p0/T34-edge.sh; C6E=tests/gates/test_c6_edge.sh; Q=.*$|E=$ME; C6E=$MC; Q=$MQ|' "$ACC" > "$RUN"
check "runner swaps exactly the input-path line" sh -c '[ "$(/usr/bin/grep -c "^E=\$ME; C6E=\$MC; Q=\$MQ\$" "$0")" -eq 1 ]' "$RUN"
check "runner differs from the real script in exactly that one line" sh -c '[ "$(diff "$0" "$1" | /usr/bin/grep -c "^<")" -eq 1 ]' "$ACC" "$RUN"
check "the real script sets all three inputs on one line" $GREP -qxF 'E=tests/p0/T34-edge.sh; C6E=tests/gates/test_c6_edge.sh; Q=api/tests/Feature/QueueProbeEdgeTest.php' "$ACC"

# Stubs for test_c6_edge.sh. File mtimes are pushed 3 s ahead so they are strictly newer than the mark
# even inside one filesystem timestamp tick.
GOOD='mkdir -p .gates/tmp/c6e/clone/.gates/tmp/c6e/out2
d=.gates/tmp/c6e/clone/.gates/tmp/c6e/out2/C6.details.jsonl
: > "$d"; touch -d "now + 3 seconds" "$d"'
printf '%s\nexit 0\n' "$GOOD" > "$OUT/good.stub"
printf '%s\nexit 3\n' "$GOOD" > "$OUT/fail.stub"
{ printf '%s\n' "$GOOD"; printf '%s\n' 'touch -d "now + 3 seconds" .gates-ti2edge-stray' 'exit 0'; } > "$OUT/stray-root.stub"
{ printf '%s\n' "$GOOD"; printf '%s\n' 'touch -d "now + 3 seconds" tests/.ti2edge-stray' 'exit 0'; } > "$OUT/stray-tests.stub"
# an absolute GATES_OUT smuggled through a variable the static regex does not list
{ printf '%s\n' "$GOOD" 'out=$(readlink -f .)/.gates-ti2edge-stray'
  printf '%s\n' 'mkdir -p "$out"; : > "$out/o"; touch -d "now + 3 seconds" "$out/o"' 'env GATES_OUT="$out" true' 'exit 0'; } > "$OUT/smuggle.stub"
# a clone details file left over from an earlier run that the stub never rewrites
printf '%s\n' 'mkdir -p .gates/tmp/c6e/clone/.gates/tmp/c6e/out2' \
  'touch -d 2001-01-01 .gates/tmp/c6e/clone/.gates/tmp/c6e/out2/C6.details.jsonl' 'exit 0' > "$OUT/stale.stub"
printf '%s\n' 'rm -f .gates/tmp/c6e/clone/.gates/tmp/c6e/out2/C6.details.jsonl' 'exit 0' > "$OUT/empty.stub"

run() { # name T34 C6E QUEUE
  ME=$2 MC=$3 MQ=$4 sh "$RUN" > "$OUT/$1.out" 2>&1; cleanup; }
D1='T34-edge no longer claims the negative-timeout probe '"'still exits 1'"
D2='T34-edge says the negative-timeout check proves rc 0 or 1 and no hang'
D3="T34-edge's live-probe description names its retry"
D4='QueueProbeEdgeTest pins exit 1 for a -5 timeout'
D5='test_c6_edge passes no absolute GATES_OUT'
D6='test_c6_edge captures no absolute repo root'
D7='test_c6_edge.sh still passes'
D8='test_c6_edge.sh wrote nothing outside .gates/, api/ and web/'
D9="the untracked-types case recorded its details under the clone's .gates/"
ok() { $GREP -qxF "ok - $1" "$OUT/$2.out"; }
bad() { $GREP -qF "not ok - $1" "$OUT/$2.out"; }

# control: a good stub with the real T34-edge and test file: every one of the nine checks is ok
run control "$E" "$OUT/good.stub" "$Q"
check "control: nine ok lines and no not ok line" sh -c '[ "$(/usr/bin/grep -c "^ok - " "$0")" -eq 9 ] && ! /usr/bin/grep -q "^not ok" "$0"' "$OUT/control.out"
check "control: the runner ends with 0 failure(s)" $GREP -qxF '0 failure(s)' "$OUT/control.out"

# F-P0-107 wording checks bite
sed 's/returns within 60 s with rc 0 or 1 (no hang; QueueProbeEdgeTest pins rc 1)/still exits 1 with a negative timeout when no worker answers/' "$E" > "$OUT/t34-old.sh"
run t34old "$OUT/t34-old.sh" "$OUT/good.stub" "$Q"
check "bites: the old 'still exits 1' claim restored" bad "$D1" t34old
check "bites: the old claim restored also drops the rc 0 or 1 wording" bad "$D2" t34old
check "bites: the other checks stay ok on that mutant" sh -c '[ "$(/usr/bin/grep -c "^not ok" "$0")" -eq 2 ]' "$OUT/t34old.out"
sed 's/, one retry with an 8 s timeout//' "$E" > "$OUT/t34-noretry.sh"
run t34noretry "$OUT/t34-noretry.sh" "$OUT/good.stub" "$Q"
check "bites: the retry dropped from the live-probe description" bad "$D3" t34noretry
# the old wording kept alive in a comment next to the new one must still fail the 'no longer claims' check
{ cat "$E"; echo '# still exits 1 with a negative timeout (stale note)'; } > "$OUT/t34-comment.sh"
run t34comment "$OUT/t34-comment.sh" "$OUT/good.stub" "$Q"
check "bites: the old claim lingering in a comment" bad "$D1" t34comment
: > "$OUT/t34-empty.sh"
run t34empty "$OUT/t34-empty.sh" "$OUT/good.stub" "$Q"
check "an empty T34-edge copy fails both positive wording checks" sh -c '/usr/bin/grep -qF "not ok - $1" "$0" && /usr/bin/grep -qF "not ok - $2" "$0"' "$OUT/t34empty.out" "$D2" "$D3"
run t34missing "$OUT/absent.sh" "$OUT/good.stub" "$Q"
check "a missing T34-edge copy fails both positive wording checks" sh -c '/usr/bin/grep -qF "not ok - $1" "$0" && /usr/bin/grep -qF "not ok - $2" "$0"' "$OUT/t34missing.out" "$D2" "$D3"

# QueueProbeEdgeTest backing check bites
sed "s/'-5', //" "$Q" > "$OUT/q-no5.php";               run qno5 "$E" "$OUT/good.stub" "$OUT/q-no5.php"
check "bites: '-5' removed from the dataset" bad "$D4" qno5
sed "s/'-5'/'-6'/" "$Q" > "$OUT/q-6.php";               run q6 "$E" "$OUT/good.stub" "$OUT/q-6.php"
check "bites: '-5' replaced by '-6'" bad "$D4" q6
sed 's/assertExitCode(1)/assertExitCode(0)/' "$Q" > "$OUT/q-rc0.php"; run qrc0 "$E" "$OUT/good.stub" "$OUT/q-rc0.php"
check "bites: the exit-code assertion changed to 0" bad "$D4" qrc0
run qmissing "$E" "$OUT/good.stub" "$OUT/absent.php"
check "bites: a missing QueueProbeEdgeTest" bad "$D4" qmissing

# F-P0-108 static and behavioural checks bite on stubs
run stub-fail "$E" "$OUT/fail.stub" "$Q"
check "bites: a test_c6_edge that exits non-zero" bad "$D7" stub-fail
run stub-empty "$E" "$OUT/empty.stub" "$Q"
check "bites: an empty test_c6_edge records no details" bad "$D9" stub-empty
check "an empty test_c6_edge still exits 0 and writes nothing stray" sh -c '/usr/bin/grep -qxF "ok - $1" "$0" && /usr/bin/grep -qxF "ok - $2" "$0"' "$OUT/stub-empty.out" "$D7" "$D8"
run stub-stale "$E" "$OUT/stale.stub" "$Q"
check "bites: a stale details file from an earlier run is not 'recorded'" bad "$D9" stub-stale
run stub-missing "$E" "$OUT/absent.stub" "$Q"
check "bites: a missing test_c6_edge" bad "$D7" stub-missing
run stub-stray "$E" "$OUT/stray-root.stub" "$Q"
check "bites: a stray file at the repository root (name starts with .gates)" bad "$D8 (want [0], got [1])" stub-stray
check "that stray run leaves the other eight checks ok" sh -c '[ "$(/usr/bin/grep -c "^not ok" "$0")" -eq 1 ]' "$OUT/stub-stray.out"
run stub-stray2 "$E" "$OUT/stray-tests.stub" "$Q"
check "bites: a stray file under tests/" bad "$D8 (want [0], got [1])" stub-stray2
run stub-smuggle "$E" "$OUT/smuggle.stub" "$Q"
check "bites: an absolute GATES_OUT smuggled through an unlisted variable is caught by the write check" bad "$D8 (want [0], got [1])" stub-smuggle
check "the stub strays were removed" test ! -e "$STRAY" -a ! -e "$STRAY2"

# static regexes against hand-built subjects
abs_cases="GATES_OUT=/x/o
GATES_OUT=\"/x/o\"
GATES_OUT=\"\$0/o\"
GATES_OUT=\"\$root/o\"
GATES_OUT=\"\$HOME/o\"
GATES_OUT=\"\$(pwd)/o\"
GATES_OUT=\"\$PWD/o\"
root=\$PWD"
printf '%s\n' "$abs_cases" > "$OUT/abs.lines"
n=0
while IFS= read -r l; do
  n=$((n + 1)); { cat "$C6E"; printf '%s\n' "$l"; } > "$OUT/c6e-abs$n.sh"
  ME=$E MC="$OUT/c6e-abs$n.sh" MQ=$Q sh "$RUN" > "$OUT/abs$n.out" 2>&1; cleanup
  check "bites: absolute-root form $n appended to test_c6_edge ($l)" sh -c '/usr/bin/grep -qE "^not ok - test_c6_edge (passes no absolute GATES_OUT|captures no absolute repo root)" "$0"' "$OUT/abs$n.out"
done < "$OUT/abs.lines"
cleanup

# --- 3. the real test_c6_edge.sh: confined to .gates/, api/ and web/, on a stale tree, twice ---------
mark=$OUT/real.mark
before_latest=$(readlink .gates/latest 2>/dev/null)
git ls-files -s web/src/api/schema.d.ts openapi/api.json gates/frozen.sha256 scripts/gates/c6.sh > "$OUT/idx.before"
sha256sum web/src/api/schema.d.ts openapi/api.json > "$OUT/sha.before"
ls -A .gates | $GREP -vx tmp > "$OUT/gates.before"
mkdir -p .gates/tmp/c6e/clone/.gates/tmp/c6e/out2
: > .gates/tmp/c6e/clone/ti2edge-stale-file; : > .gates/tmp/c6e/clone/.gates/tmp/c6e/out2/ti2edge-stale-details
touch -d 2001-01-01 .gates/tmp/c6e/clone/.gates/tmp/c6e/out2/C6.details.jsonl
: > "$mark"
sh "$C6E" > "$OUT/real1.out" 2>&1; rc1=$?
check "real run 1 on a stale clone dir passes (0 failures)" sh -c '[ "$1" -eq 0 ] && /usr/bin/grep -qxF "0 failure(s)" "$0"' "$OUT/real1.out" "$rc1"
check "real run 1 rebuilt the clone (the stale file is gone)" test ! -e .gates/tmp/c6e/clone/ti2edge-stale-file
check "real run 1 wrote fresh details inside the clone's .gates/" sh -c 'test "$0" -nt "$1"' "$DET/C6.details.jsonl" "$mark"
check "clone details record tracked=false" sh -c 'jq -e "select(.check==\"tracked\" and .ok==false)" "$0"' "$DET/C6.details.jsonl"
check "clone details record tracked=false exactly once" sh -c '[ "$(jq -c "select(.check==\"tracked\")" "$0" | wc -l)" -eq 1 ]' "$DET/C6.details.jsonl"
check "the container-side generation landed in the main repo's .gates/tmp (fresh, equal to the committed types)" sh -c 'test .gates/tmp/c6e/out2/C6.schema.d.ts -nt "$0" && cmp .gates/tmp/c6e/out2/C6.schema.d.ts web/src/api/schema.d.ts' "$mark"
check_out "real run 1 wrote nothing outside .gates/, api/ and web/" 0 \
  sh -c 'find . \( -path ./.git -o -path ./.gates -o -path ./api -o -path ./web \) -prune -o -newer "$0" -type f -print | wc -l | tr -d " "' "$mark"
check_out "real run 1 touched no tracked file in api/ or web/ (outside ignored caches)" 0 \
  sh -c 'git status --porcelain --untracked-files=all api web | wc -l | tr -d " "'
check_out "real run 1 left the main working tree clean (this script itself aside)" 0 sh -c 'git status --porcelain | /usr/bin/grep -vc "tests/p0/Ti2-03-edge.sh"'
check_out "the main index still tracks the types file" 1 sh -c 'git ls-files web/src/api/schema.d.ts | wc -l | tr -d " "'
: > "$mark"
sh "$C6E" > "$OUT/real2.out" 2>&1; rc2=$?
check "real run 2 straight after run 1 passes (idempotent)" sh -c '[ "$1" -eq 0 ] && /usr/bin/grep -qxF "0 failure(s)" "$0"' "$OUT/real2.out" "$rc2"
check "real run 2 rewrote the clone details" sh -c 'test "$0" -nt "$1"' "$DET/C6.details.jsonl" "$mark"
check_out "real run 2 wrote nothing outside .gates/, api/ and web/" 0 \
  sh -c 'find . \( -path ./.git -o -path ./.gates -o -path ./api -o -path ./web \) -prune -o -newer "$0" -type f -print | wc -l | tr -d " "' "$mark"
check "the latest scorecard symlink was not moved" test "$(readlink .gates/latest 2>/dev/null)" = "$before_latest"
ls -A .gates | $GREP -vx tmp > "$OUT/gates.after"
check "no new entry appeared in .gates/ besides tmp/" cmp "$OUT/gates.before" "$OUT/gates.after"
git ls-files -s web/src/api/schema.d.ts openapi/api.json gates/frozen.sha256 scripts/gates/c6.sh > "$OUT/idx.after"
sha256sum web/src/api/schema.d.ts openapi/api.json > "$OUT/sha.after"
check "the index entries of the types file, contract, lock and C6 script are unchanged" cmp "$OUT/idx.before" "$OUT/idx.after"
check "the committed types file and OpenAPI contract are byte-unchanged" cmp "$OUT/sha.before" "$OUT/sha.after"

# --- 4. hygiene and naming of the three task files and of this script ----------------------------
pat=$(sed -e 's/\r$//' -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' gates/hygiene.patterns | sed 's/.*/(?:&)/' | paste -sd'|' -)
lf=$(git rev-parse --git-path info/hygiene.local)
[ -s "$lf" ] && pat="$pat|$(sed -e 's/\r$//' -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' "$lf" | sed 's/.*/(?:&)/' | paste -sd'|' -)"
# run-time assembled extras (R113): Docker bridge, Tailscale CGNAT, RFC 1918 ranges, a home path, a tilde path
extra=$(printf '%s|%s|%s|%s|%s|%s' '\b172\.1[67]\.' '\b100\.[0-9]+\.[0-9]+\.[0-9]+\b' '\b192\.168\.' '\b10\.[0-9]+\.[0-9]+\.' "/$(printf ho)me/" "~/$(printf Repo)")
for f in "$E" "$C6E" "$ACC" tests/p0/Ti2-03-edge.sh; do
  check "hygiene patterns find nothing in $f" $NOHITS $GREP -qP "$pat" "$f"
  check "no Docker-bridge, Tailscale, LAN address or home path in $f" $NOHITS $GREP -qP "$extra" "$f"
done
check "the added lines of the task commit say PokéPrices with the accent" sh -c '! git diff -U0 "$0" "$1" -- tests | /usr/bin/grep "^+" | /usr/bin/grep -q "PokePrices"' "$BASE" "$TASK"
check "the three task files are mode 100755" sh -c '[ "$(git ls-files -s "$0" "$1" "$2" | /usr/bin/grep -c "^100755")" -eq 3 ]' "$E" "$C6E" "$ACC"
check "the task commit message is the subject alone" sh -c '[ "$(git log --format=%B "$0..$1" | sed "/^\$/d" | wc -l | tr -d " ")" -eq 1 ]' "$BASE" "$TASK"
check "the task commit message has no trailer, Claude, Anthropic or AI reference" sh -c '! git log --format=%B "$0..$1" | /usr/bin/grep -qiE "co-authored|claude|anthropic|session|(^|[^a-z])ai([^a-z]|$)|agent"' "$BASE" "$TASK"
cleanup
finish
