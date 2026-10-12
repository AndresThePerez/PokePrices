#!/bin/sh
# P1-i1-T16b reviewer edge cases (R155): the widened "missing scorecard" verdict pattern (driven through a
# shimmed scripts/verdict that prints each string the real one could), a non-scorecard gates-only run that
# leaves nothing under gates/ even twice, the FAST list differing from the base by exactly test_ag1.sh,
# the full tier's glob still reaching test_ag1.sh, T07's pins matching run.sh, and hygiene of the task commit.
# Range checks pin the task commit, never HEAD (R114).
set -u
cd "${T16B_EDGE_ROOT:-$(dirname "$0")/../..}" || exit 1
. tests/p0/lib.sh
BASE=ca4ca6df0fdd73dc591ada342c22c3768a0cc187
TASK=2c9261e4d834e5c882134c16821fcfa25e1f992e
T=.gates/tmp/P1-i1-T16b-review; mkdir -p "$T/sc/scripts" "$T/bin"
TA=$(pwd)/$T

# ---- helpers -------------------------------------------------------------------------------------------
cat > "$T/extract.py" <<'PY'
import re, sys
# extract.py FILE DESC: the sh -c body of the one-line check whose description is DESC
for ln in open(sys.argv[1], encoding="utf-8").read().splitlines():
    m = re.match(r'check "' + re.escape(sys.argv[2]) + r'" sh -c \'(.*)\' "\$T"$', ln)
    if m:
        print(m.group(1))
        sys.exit(0)
sys.exit(1)
PY
cat > "$T/fast.py" <<'PY'
import re, sys
# fast.py VAR: the whitespace-separated words of VAR="..." read from stdin (first assignment)
m = re.search(r'^' + sys.argv[1] + r'="([^"]*)"', sys.stdin.read(), re.M)
if m is None:
    sys.exit(1)
print("\n".join(m.group(1).split()))
PY
printf '%s\n' '#!/bin/sh' 'printf "%s\n" "$FAKE_OUT"' 'exit "$FAKE_RC"' > "$T/sc/scripts/verdict"
chmod 755 "$T/sc/scripts/verdict"
python3 -I "$T/extract.py" tests/verdict/test_verdict_edge.sh "gates-only with a missing scorecard fails closed" > "$T/missing.body"
check "the missing-scorecard check body can be extracted from test_verdict_edge.sh" test -s "$T/missing.body"
# pat OUT RC: does the real check body accept a verdict that prints OUT and exits RC?
pat() { (cd "$TA/sc" && FAKE_OUT=$1 FAKE_RC=$2 /bin/sh -c "$(cat "$TA/missing.body")" .) >/dev/null 2>&1; }
accepts() { d=$1; shift; check "pattern accepts: $d" pat "$@"; }

# ---- 1. the widened pattern ---------------------------------------------------------------------------
accepts "the plain form" "GATES_FAIL scorecard:unreadable" 1
accepts "one leading gate:AG1" "GATES_FAIL gate:AG1 scorecard:unreadable" 1
check "pattern rejects: gate:AG1 followed by another gate tag" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT="GATES_FAIL gate:AG1 gate:X scorecard:unreadable" FAKE_RC=1 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"
check "pattern rejects: git:dirty before scorecard:unreadable" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT="GATES_FAIL git:dirty scorecard:unreadable" FAKE_RC=1 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"
check "pattern rejects: gate:AG1 git:dirty then scorecard:unreadable" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT="GATES_FAIL gate:AG1 git:dirty scorecard:unreadable" FAKE_RC=1 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"
check "pattern rejects: gate:AG1 alone (no scorecard:unreadable)" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT="GATES_FAIL gate:AG1" FAKE_RC=1 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"
check "pattern rejects: another gate id in AG1's place" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT="GATES_FAIL gate:AG2 scorecard:unreadable" FAKE_RC=1 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"
check "pattern rejects: a human-gate tag before scorecard:unreadable" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT="GATES_FAIL human-gate:HG-07 scorecard:unreadable" FAKE_RC=1 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"
check "pattern rejects: gate:AG1 glued to scorecard:unreadable without the space" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT="GATES_FAIL gate:AG1scorecard:unreadable" FAKE_RC=1 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"
check "pattern rejects: GATES_OK" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT="GATES_OK" FAKE_RC=1 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"
check "pattern rejects: empty output" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT="" FAKE_RC=1 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"
check "pattern rejects: the allowed text on stdout with status 0" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT="GATES_FAIL gate:AG1 scorecard:unreadable" FAKE_RC=0 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"
check "pattern rejects: the allowed text with status 2 (usage error)" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT="GATES_FAIL gate:AG1 scorecard:unreadable" FAKE_RC=2 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"
check "pattern rejects: a leading space before GATES_FAIL" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT=" GATES_FAIL scorecard:unreadable" FAKE_RC=1 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"
check "pattern rejects: NOT_DONE output shape" sh -c "$(printf '%s\n' 'cd "$0/sc" && FAKE_OUT="NOT_DONE
gate:AG1
scorecard:unreadable" FAKE_RC=1 /bin/sh -c "$(cat "$0/missing.body")" . && exit 1; exit 0')" "$TA"

# ---- 2. the live verdict on a missing scorecard: exactly one of the two allowed lines -----------------------
printf '%s\n' "$(scripts/verdict --gates-only --manifest gates/manifest-P0.json --scorecard "$T/nope.json" 2>/dev/null)" > "$T/live-missing.out"
check "live: a missing scorecard prints exactly the plain or the AG1-led GATES_FAIL line" sh -c '
  o=$(cat "$0"); test "$o" = "GATES_FAIL scorecard:unreadable" -o "$o" = "GATES_FAIL gate:AG1 scorecard:unreadable"' "$T/live-missing.out"

# ---- 3. a gates-only run on a non-scorecard file leaves nothing under gates/, even twice ---------------------
cp gates/manifest-P0.json "$T/not-a-scorecard.json"
for n in 1 2; do
  scripts/verdict --gates-only --manifest gates/manifest-P0.json --scorecard "$T/not-a-scorecard.json" > "$T/nsc$n.out" 2> "$T/nsc$n.err"
  echo "$?" > "$T/nsc$n.rc"
done
check_out "non-scorecard run 1 exits 1" 1 cat "$T/nsc1.rc"
check_out "non-scorecard run 2 exits 1" 1 cat "$T/nsc2.rc"
check "both non-scorecard runs print the same GATES_FAIL line with scorecard:schema" sh -c 'cmp -s "$0/nsc1.out" "$0/nsc2.out" && /usr/bin/grep -q "^GATES_FAIL .*scorecard:schema" "$0/nsc1.out"' "$T"
check "after two scratch-scorecard runs nothing exists under gates/verdict" test ! -e gates/verdict
check "after two scratch-scorecard runs git sees no change under gates/" sh -c 'test -z "$(git status --porcelain --untracked-files=all -- gates)"'
check "every other test_verdict_edge.sh call with --scorecard under gates/ is a usage check (status 2)" sh -c '
  ! /usr/bin/grep -n "scripts/verdict.*--scorecard gates/" tests/verdict/test_verdict_edge.sh | /usr/bin/grep -v -e "-eq 2"'

# ---- 4. FAST list: base list minus test_ag1.sh, same order, nothing else changed in run.sh --------------------
git show "$BASE:tests/p0/run.sh" | python3 -I "$T/fast.py" FAST > "$T/fast.base"
git show "$TASK:tests/p0/run.sh" | python3 -I "$T/fast.py" FAST > "$T/fast.task"
/usr/bin/grep -vx 'tests/gates/test_ag1.sh' "$T/fast.base" > "$T/fast.base.minus"
check "FAST at the task commit is the base list less test_ag1.sh, order unchanged" cmp -s "$T/fast.base.minus" "$T/fast.task"
check "the base FAST list held test_ag1.sh exactly once (the removal is real)" sh -c 'test "$(/usr/bin/grep -cx tests/gates/test_ag1.sh "$0")" -eq 1' "$T/fast.base"
check "test_ag1_edge.sh stays in FAST" /usr/bin/grep -qx 'tests/gates/test_ag1_edge.sh' "$T/fast.task"
check "FAST no longer lists test_ag1.sh" sh -c '! /usr/bin/grep -qx tests/gates/test_ag1.sh "$0"' "$T/fast.task"
git show "$BASE:tests/p0/run.sh" | /usr/bin/grep '^FAST_GATES=' > "$T/fg.base"
git show "$TASK:tests/p0/run.sh" | /usr/bin/grep '^FAST_GATES=' > "$T/fg.task"
check "FAST_GATES is unchanged" cmp -s "$T/fg.base" "$T/fg.task"
check_out "run.sh changed by exactly 2 added and 1 removed lines" "2	1	tests/p0/run.sh" git diff --numstat "$BASE" "$TASK" -- tests/p0/run.sh
check "the added comment in run.sh names R155" sh -c 'git diff -U0 "$0" "$1" -- tests/p0/run.sh | /usr/bin/grep "^+#" | /usr/bin/grep -q R155' "$BASE" "$TASK"
git show "$BASE:tests/p0/run.sh" | sed '/^# AG1.s live self-checks/d' | sed 's# tests/gates/test_ag1.sh##;s#^tests/gates/test_ag1.sh ##' > "$T/run.base.norm"
git show "$TASK:tests/p0/run.sh" | sed '/^# AG1.s live self-checks/d' > "$T/run.task.norm"
check "outside the one comment and the one removal, run.sh is byte-identical to the base" cmp -s "$T/run.base.norm" "$T/run.task.norm"

# ---- 5. the full tier still reaches test_ag1.sh; the fast tier does not run it -------------------------------
check "tests/gates/test_ag1.sh is tracked at the task commit" sh -c 'git cat-file -e "$0:tests/gates/test_ag1.sh"' "$TASK"
check "the full tier line of run.sh still globs tests/gates/test_*.sh" sh -c 'git show "$0:tests/p0/run.sh" | /usr/bin/grep -F "run_scripts tests/p0/T*.sh tests/p1/T*.sh tests/gates/test_*.sh" | /usr/bin/grep -q .' "$TASK"
check "that glob expands to include tests/gates/test_ag1.sh" sh -c 'for f in tests/gates/test_*.sh; do echo "$f"; done | /usr/bin/grep -qx tests/gates/test_ag1.sh'
for n in sh python3 docker make go golangci-lint; do
  printf '%s\n' '#!/bin/sh' 'printf "%s %s\n" "${0##*/}" "$*" >> "$SHIM_LOG"' \
    'if [ -n "${SHIM_FAIL:-}" ]; then case "${0##*/} $*" in *"$SHIM_FAIL"*) exit 1;; esac; fi' 'exit 0' > "$T/bin/$n"
done
chmod 755 "$T/bin/"*
runsh() {
  n=$1; shift; : > "$TA/$n.log"
  env -u TIER -u PS -u POKESEARCH_DIR -u SHIM_FAIL PATH="$TA/bin:$PATH" SHIM_LOG="$TA/$n.log" "$@" > "$TA/$n.out" 2> "$TA/$n.err"
  echo "$?" > "$TA/$n.rc"
}
RUN="/bin/sh tests/p0/run.sh"
runsh fast TIER=fast $RUN
check "shimmed fast tier never runs test_ag1.sh" sh -c '! /usr/bin/grep -qx "sh tests/gates/test_ag1.sh" "$0"' "$T/fast.log"
check "shimmed fast tier still runs test_ag1_edge.sh once" sh -c 'test "$(/usr/bin/grep -cx "sh tests/gates/test_ag1_edge.sh" "$0")" -eq 1' "$T/fast.log"
runsh task TIER=task $RUN tests/gates/test_ag1.sh
check "TIER=task with test_ag1.sh named still runs it (once, first)" sh -c 'test "$(/usr/bin/grep -cx "sh tests/gates/test_ag1.sh" "$0/task.log")" -eq 1 && test "$(head -n 1 "$0/task.log")" = "sh tests/gates/test_ag1.sh"' "$TA"
# every FAST script, failed in turn, must still turn the whole tier red and still reach the scorecard
while read -r s; do
  runsh ff TIER=fast SHIM_FAIL="sh $s" $RUN
  check "failing $s makes the fast tier exit non-zero and the scorecard still runs" sh -c 'test "$(cat "$0/ff.rc")" -ne 0 && tail -n 1 "$0/ff.log" | /usr/bin/grep -q scorecard' "$TA"
done < "$T/fast.task"

# ---- 6. T07's pins follow run.sh ---------------------------------------------------------------------------
python3 -I "$T/fast.py" FAST17 < tests/p1/T07.sh > "$T/t07.fast17"
/usr/bin/grep -vx 'tests/p1/T07.sh' "$T/fast.task" > "$T/fast.task.noT07"
check "T07.sh's FAST17 pin equals run.sh's FAST (less T07.sh itself, which the pin excludes), in order" cmp -s "$T/t07.fast17" "$T/fast.task.noT07"
sed -n 's/^runsh fastfail TIER=fast SHIM_FAIL="sh \([^"]*\)" .*/\1/p' tests/p1/T07.sh > "$T/t07.shim"
check "T07.sh's fastfail shim names exactly one script" sh -c 'test "$(wc -l < "$0")" -eq 1' "$T/t07.shim"
check "T07.sh's fastfail shim names a script that is in the fast list (else it would prove nothing)" sh -c '/usr/bin/grep -qxF -f "$0" "$1"' "$T/t07.shim" "$T/fast.task"
check "T07.sh's head/line-count pins match the 17 scripts before T07.sh itself" sh -c '/usr/bin/grep -q "head -n 17" tests/p1/T07.sh && /usr/bin/grep -q "test \"\$n\" -ge 19" tests/p1/T07.sh && test "$(wc -l < "$0")" -eq 17' "$T/fast.task.noT07"

# ---- 7. commit hygiene -------------------------------------------------------------------------------------
check_out "the task range holds exactly one commit" 1 sh -c 'git rev-list --count "$0..$1"' "$BASE" "$TASK"
git log --format=%B "$BASE..$TASK" | sed '/^$/d' > "$T/msg.txt"
check_out "the commit message is a single subject line" 1 sh -c 'wc -l < "$0"' "$T/msg.txt"
check "the subject starts with [P1-i1-T16b] and names PokéPrices with the accent" /usr/bin/grep -q '^\[P1-i1-T16b\] .*Pok.*Prices' "$T/msg.txt"
check "no trailer, Claude, Anthropic or AI mention in the message" sh -c '! /usr/bin/grep -Eiq "co-authored|claude|anthropic|session|(^|[^A-Za-z])AI([^A-Za-z]|$)|agent|generated" "$0"' "$T/msg.txt"
sed -e 's/\r$//' -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' gates/hygiene.patterns | sed 's/.*/(?:&)/' | paste -sd'|' - > "$T/hy.pat"
git diff -U0 "$BASE" "$TASK" | /usr/bin/grep '^+' > "$T/added.txt"
check "the task diff adds no line matching gates/hygiene.patterns" sh -c '! /usr/bin/grep -qP -f - "$1" <<EOF2
$(cat "$0")
EOF2' "$T/hy.pat" "$T/added.txt"
check "the task touched only the three named test files" sh -c 'test "$(git diff --name-only "$0" "$1" | sort | paste -sd" " -)" = "tests/p0/run.sh tests/p1/T07.sh tests/verdict/test_verdict_edge.sh"' "$BASE" "$TASK"

# ---- 8. no stray file under gates/ after all of the above ------------------------------------------------------
check "nothing is untracked or modified under gates/ at the end" sh -c 'test -z "$(git status --porcelain --untracked-files=all -- gates)"'
finish
