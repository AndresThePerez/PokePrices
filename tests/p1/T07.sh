#!/bin/sh
# P1-i1-T07: tests/p0/run.sh has full (default), fast and task tiers (R135), and the hard-gate checks in
# T31/T34/T35 compare the failing hard set with tests/pending-gates.txt exactly. Self-contained: run.sh
# runs for real against PATH shims that log what it starts (sh, python3, docker, make, go, golangci-lint);
# the real T31/T34/T35 check lines run against fixture scorecards and fixture ledgers.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd)
T=.gates/tmp/T07; mkdir -p "$T/bin" "$T/gopath/bin" "$T/ps" "$T/fx"
TA=$ROOT/$T
# test_ag1.sh is parked out of the fast list under R153/R155 while frozen files differ; the bundle's gate phase restores it here and in run.sh.
FAST17="tests/scorecard/test_scorecard.sh tests/scorecard/test_scorecard_edge.sh tests/verdict/test_verdict.sh
tests/verdict/test_verdict_edge.sh tests/gates/test_ag1_edge.sh tests/gates/test_ci_docs.sh
tests/gates/test_ci_docs_edge.sh tests/gates/test_gate_libs.sh tests/gates/test_gate_libs_edge.sh
tests/gates/test_gate_libs_edge2.sh tests/gates/test_gate_libs_edge3.sh tests/gates/test_gate_libs_edge4.sh
tests/gates/test_gate_libs_edge5.sh tests/p0/T02.sh tests/p0/T02-edge.sh tests/p0/T19-edge.sh tests/p0/T29b-edge.sh"
IDS21="Q1 Q3 Q4 Q6 Q7 Q8 T1 T8 T11 C1 C2 C6 S6 S7 D4 AG-values AG2 AG3 HY-hygiene HY-trailers VER-selftest"
MANIFEST=$(head -n1 gates/current-manifest 2>/dev/null || echo gates/manifest-P0.json)

# ---- shims: log the command line, exit 1 when it contains $SHIM_FAIL ----------------------------------
for n in sh python3 docker make; do
  printf '%s\n' '#!/bin/sh' 'printf "%s %s\n" "${0##*/}" "$*" >> "$SHIM_LOG"' \
    'if [ -n "${SHIM_FAIL:-}" ]; then case "${0##*/} $*" in *"$SHIM_FAIL"*) exit 1;; esac; fi' 'exit 0' > "$T/bin/$n"
done
printf '%s\n' '#!/bin/sh' 'if [ "$*" = "env GOPATH" ]; then printf "%s\n" "$SHIM_GOPATH"; exit 0; fi' \
  'printf "%s %s @%s\n" "${0##*/}" "$*" "$PWD" >> "$SHIM_LOG"' \
  'if [ -n "${SHIM_FAIL:-}" ]; then case "${0##*/} $*" in *"$SHIM_FAIL"*) exit 1;; esac; fi' 'exit 0' > "$T/bin/go"
cp "$T/bin/go" "$T/gopath/bin/golangci-lint"
chmod 755 "$T/bin/"* "$T/gopath/bin/golangci-lint"

# runsh NAME [VAR=VALUE...] COMMAND...: run with the shims first on PATH and a scrubbed tier environment.
runsh() {
  n=$1; shift; : > "$TA/$n.log"
  env -u TIER -u PS -u POKESEARCH_DIR -u SHIM_FAIL PATH="$TA/bin:$PATH" SHIM_LOG="$TA/$n.log" \
    SHIM_GOPATH="$TA/gopath" "$@" > "$TA/$n.out" 2> "$TA/$n.err"
  echo "$?" > "$TA/$n.rc"
}
RUN="/bin/sh tests/p0/run.sh"

# ---- criterion 1: TIER defaults to full; full = today's loop plus tests/p1/T*.sh after tests/p0/T*.sh ----
expect_full() {
  printf '%s\n' 'make gates'
  for t in tests/p0/T*.sh tests/p1/T*.sh tests/p2/T03.sh tests/p2/T14.sh tests/gates/test_*.sh tests/scorecard/test_*.sh tests/verdict/test_*.sh; do
    [ -f "$t" ] && printf 'sh %s\n' "$t"
  done
  printf '%s\n' 'docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pest -c phpunit.xml' \
    'docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/vitest run --config vitest.config.ts'
}
expect_full > "$T/full.expected"
runsh default $RUN
runsh full TIER=full $RUN
check "the full tier runs tests/p0/T*.sh, tests/p1/T*.sh, tests/p2/T03.sh and T14.sh, gates, scorecard and verdict tests, then Pest and Vitest, exactly" cmp -s "$T/full.expected" "$T/full.log"
check "without TIER the runner runs the full tier (same commands, same order)" cmp -s "$T/full.log" "$T/default.log"
check "the full tier includes this script (tests/p1 is in the runner)" /usr/bin/grep -qx 'sh tests/p1/T07.sh' "$T/full.log"
check "the full tier still runs test_freeze.sh and T31.sh (nothing dropped from today's loop)" sh -c '/usr/bin/grep -qx "sh tests/gates/test_freeze.sh" "$0" && /usr/bin/grep -qx "sh tests/p0/T31.sh" "$0"' "$T/full.log"
check_out "the full tier exits 0 when every step passes" 0 cat "$T/full.rc"
check "the runner still prints '# <script>' before each script" /usr/bin/grep -qx '# tests/p1/T07.sh' "$T/full.out"
runsh fullfail TIER=full SHIM_FAIL="sh tests/p0/T02.sh" $RUN
check "a failing script makes the full tier exit non-zero and the loop continues to Vitest" sh -c 'test "$(cat "$0/fullfail.rc")" -ne 0 && cmp -s "$0/full.log" "$0/fullfail.log"' "$TA"
runsh fullpest TIER=full SHIM_FAIL="vendor/bin/pest" $RUN
check "a failing Pest run makes the full tier exit non-zero" sh -c 'test "$(cat "$0")" -ne 0' "$T/fullpest.rc"

# ---- criterion 2: the fast tier ---------------------------------------------------------------------
runsh fast TIER=fast $RUN
check_out "the fast tier exits 0 when every step passes" 0 cat "$T/fast.rc"
printf 'sh %s\n' $FAST17 > "$T/fast17.expected"
check "the fast tier starts with the self-contained scripts of review-2 §4 less test_ag1.sh, in order" sh -c 'head -n 17 "$0/fast.log" | cmp -s - "$0/fast17.expected"' "$TA"
check "every fast step before the scorecard runs a tracked script" sh -c '
  n=$(wc -l < "$0"); test "$n" -ge 19 || exit 1
  head -n $((n - 1)) "$0" | while read -r c s; do test "$c" = sh && git ls-files --error-unmatch "$s" >/dev/null 2>&1 || exit 1; done' "$T/fast.log"
check "the fast list includes this script (self-contained P1 scripts join it)" /usr/bin/grep -qx 'sh tests/p1/T07.sh' "$T/fast.log"
tail -n 1 "$T/fast.log" > "$T/fast.last"
check "the fast tier ends with scripts/scorecard on the current manifest (gates/current-manifest, else manifest-P0)" sh -c '
  read -r py i sc m path o ids < "$0"
  test "$py $i $sc $m $path $o" = "python3 -I scripts/scorecard --manifest $1 --only" && test -n "$ids"' "$T/fast.last" "$MANIFEST"
ONLY=$(sed 's/.* --only //' "$T/fast.last")
check "the scorecard --only list holds all 21 fast gate ids" sh -c 'for i in $1; do case ",$0," in *",$i,"*) ;; *) exit 1;; esac; done' "$ONLY" "$IDS21"
check "every --only id is a gate of the current manifest (scorecard would exit 2 otherwise)" sh -c '
  for i in $(printf "%s" "$0" | tr , " "); do jq -e --arg i "$i" "any(.gates[]; .id == \$i)" "$1" >/dev/null || exit 1; done' "$ONLY" "$MANIFEST"
check "the fast tier starts no docker, make, go or golangci-lint command itself" sh -c '! /usr/bin/grep -Eq "^(docker|make|go|golangci-lint) " "$0"' "$T/fast.log"
runsh fastfail TIER=fast SHIM_FAIL="sh tests/gates/test_ag1_edge.sh" $RUN
check "a failing fast script makes the tier exit non-zero and the scorecard still runs" sh -c 'test "$(cat "$0/fastfail.rc")" -ne 0 && cmp -s "$0/fast.log" "$0/fastfail.log"' "$TA"
runsh fastsc TIER=fast SHIM_FAIL="scripts/scorecard" $RUN
check "a failing fast scorecard makes the tier exit non-zero" sh -c 'test "$(cat "$0")" -ne 0' "$T/fastsc.rc"

# ---- criterion 4: the fast tier never runs make gates, test_freeze.sh, a compose stop/restart or EC-ci-parity
check "the fast list names no make, test_freeze.sh, EC-ci-parity, ec-ci-parity.sh or O1" sh -c '! /usr/bin/grep -Eq "make|test_freeze|EC-ci-parity|ec-ci-parity|(^|[ ,])O1(,|\$)|(^|[ ,])DEN(,|\$)" "$0"' "$T/fast.log"
# Content scan (comment lines dropped): a compose stop/restart/down, test_freeze, ec-ci-parity.sh, or
# make gates in command position. This script is skipped: it names them in order to search for them.
BAD='compose[^#]*[[:space:]](stop|restart|down)([[:space:]]|$)|test_freeze|ec-ci-parity\.sh|(^|[;&|]|\$\(|then|do|else)[[:space:]]*make[[:space:]]+gates'
check "the forbidden-command pattern catches o1.sh (compose stop), test_freeze.sh (make gates) and ec-ci-parity.sh (compose down)" sh -c '
  for f in scripts/gates/o1.sh tests/gates/test_freeze.sh scripts/gates/ec-ci-parity.sh; do
    /usr/bin/grep -v "^[[:space:]]*#" "$f" | /usr/bin/grep -Eq "$0" || exit 1; done' "$BAD"
check "no fast-list script runs make gates, test_freeze.sh, EC-ci-parity or a compose stop/restart/down" sh -c '
  n=$(wc -l < "$1"); head -n $((n - 1)) "$1" | while read -r c s; do
    test "$s" = tests/p1/T07.sh && continue
    ! /usr/bin/grep -v "^[[:space:]]*#" "$s" | /usr/bin/grep -Eq "$0" || exit 1; done' "$BAD" "$T/fast.log"
check "no --only gate command (nor the script it runs) does any of those" sh -c '
  for i in $(printf "%s" "$1" | tr , " "); do
    cmd=$(jq -r --arg i "$i" ".gates[] | select(.id == \$i) | .command" "$2"); test -n "$cmd" || exit 1
    ! printf "%s\n" "$cmd" | /usr/bin/grep -Eq "$0" || exit 1
    for w in $cmd; do case "$w" in scripts/*) if [ -f "$w" ]; then ! /usr/bin/grep -v "^[[:space:]]*#" "$w" | /usr/bin/grep -Eq "$0" || exit 1; fi;; esac; done
  done' "$BAD" "$ONLY" "$MANIFEST"

# ---- criterion 3: the task tier ---------------------------------------------------------------------
runsh task TIER=task $RUN tests/p0/T31.sh tests/p1/T07.sh
{ printf 'sh %s\n' tests/p0/T31.sh tests/p1/T07.sh; cat "$T/fast.log"; } > "$T/task.expected"
check "the task tier runs the named scripts, then the whole fast tier, and nothing else" cmp -s "$T/task.expected" "$T/task.log"
check_out "the task tier exits 0 when every step passes" 0 cat "$T/task.rc"
runsh taskps TIER=task PS=1 POKESEARCH_DIR="$TA/ps" $RUN tests/p1/T07.sh
{ printf 'sh tests/p1/T07.sh\n'; cat "$T/fast.log"
  for c in 'go build ./...' 'go vet ./...' 'go vet -tags acceptance ./internal/acceptance' \
    'go vet -tags relevance ./internal/relevance' 'go test -race ./...' 'golangci-lint run'; do printf '%s @%s\n' "$c" "$TA/ps"; done
} > "$T/taskps.expected"
check "PS=1 adds the PokéSearch Go checks in \$POKESEARCH_DIR, in order, golangci-lint from \$(go env GOPATH)/bin" cmp -s "$T/taskps.expected" "$T/taskps.log"
check_out "the task tier with PS=1 exits 0 when every step passes" 0 cat "$T/taskps.rc"
runsh taskpsfail TIER=task PS=1 POKESEARCH_DIR="$TA/ps" SHIM_FAIL="go vet -tags acceptance" $RUN tests/p1/T07.sh
check "a failing Go check stops the Go chain and makes the task tier exit non-zero" sh -c '
  test "$(cat "$0/taskpsfail.rc")" -ne 0 && tail -n 1 "$0/taskpsfail.log" | /usr/bin/grep -q "^go vet -tags acceptance " &&
  ! /usr/bin/grep -q "^golangci-lint" "$0/taskpsfail.log"' "$TA"
runsh tasknamedfail TIER=task SHIM_FAIL="sh tests/p0/T31.sh" $RUN tests/p0/T31.sh
check "a failing named script makes the task tier exit non-zero" sh -c 'test "$(cat "$0")" -ne 0' "$T/tasknamedfail.rc"

# ---- criterion 4: unknown TIER and misuse exit 2 with a usage line, before running anything --------------
for v in 'bogus|TIER=bogus|' 'fastargs|TIER=fast|tests/p0/T02.sh' 'fullargs|TIER=full|tests/p0/T02.sh' \
         'tasknone|TIER=task|' 'taskmissing|TIER=task|tests/p0/T99-no-such-script.sh' 'fastps|TIER=fast PS=1|'; do
  nm=${v%%|*}; r=${v#*|}; vars=${r%%|*}; arg=${r#*|}
  # shellcheck disable=SC2086 # vars and arg split into separate words on purpose
  runsh "u-$nm" $vars $RUN $arg
  check "$nm: exits 2 with a usage line on stderr and runs nothing" sh -c '
    test "$(cat "$0.rc")" -eq 2 && /usr/bin/grep -q "^usage: " "$0.err" && test ! -s "$0.log"' "$T/u-$nm"
done

# ---- criterion 5: the ledger parser --------------------------------------------------------------------
FX=$T/fx
printf '%s\n' '# pending hard gates' '' 'EC-ci-parity P1-i1-T29' '   ' '# AG2 P1-i1-T99' 'AG1	P1-i1-T29' > "$FX/pg-ok.txt"
check_out "pending_json lists the uncommented ids sorted, ignoring # comments and blank lines" '["AG1","EC-ci-parity"]' pending_json "$FX/pg-ok.txt"
printf '%s\n' '# AG1 P1-i1-T29' '# EC-ci-parity P1-i1-T29' > "$FX/pg-none.txt"
check_out "a ledger of comments only lists no ids" '[]' pending_json "$FX/pg-none.txt"
: > "$FX/pg-empty.txt"
check_out "an empty ledger lists no ids" '[]' pending_json "$FX/pg-empty.txt"
for v in 'noowner|AG1' 'extra|AG1 P1-i1-T29 more' 'trailing|AG1 P1-i1-T29 # note' 'dup|AG1 P1-i1-T29\nAG1 P1-i1-T30' \
         'badid|AG1; P1-i1-T29' 'hashowner|AG1 P1-i1-T29\nQ1 # owner'; do
  nm=${v%%|*}; printf "${v#*|}\n" > "$FX/pg-$nm.txt"
  check "pending_json rejects a ledger with $nm (fails, prints nothing)" sh -c '. tests/p0/lib.sh; o=$(pending_json "$0") && exit 1; test -z "$o"' "$FX/pg-$nm.txt"
done
check "pending_json rejects a missing ledger" sh -c '. tests/p0/lib.sh; ! pending_json "$0/no-such-file.txt" >/dev/null 2>&1' "$FX"
check "the real ledger parses" pending_json tests/pending-gates.txt
check "every id the real ledger lists is a hard gate of the current manifest" sh -c '
  . tests/p0/lib.sh; p=$(pending_json tests/pending-gates.txt) || exit 1
  jq -e --argjson p "$p" "[.gates[] | select(.hard) | .id] as \$h | all(\$p[]; . as \$i | \$h | index(\$i) != null)" "$0"' "$MANIFEST"

# ---- criterion 5: T31/T34/T35 compare the failing hard set with the ledger, exactly --------------------
printf '%s\n' 'AG1 P1-i1-T29' 'EC-ci-parity P1-i1-T29' > "$FX/pg-ae.txt"
printf '%s\n' 'AG1 P1-i1-T29' 'EC-ci-parity P1-i1-T29' 'Q1 P1-i1-T99' > "$FX/pg-aeq.txt"
printf '%s\n' 'AG1 P1-i1-T29' 'EC-ci-parity' > "$FX/pg-bad.txt"
# sc NAME EXIT JQ: a fixture scorecard; every gate passes unless JQ changes it.
sc() {
  jq -n --argjson x "$2" '{schema: 1, head: "0000000000000000000000000000000000000000", run_context: "primary",
    clean_before: true, clean_after: true, exit: $x, gates: [
      {id: "Q1", hard: true, status: "pass"}, {id: "AG1", hard: true, status: "pass"},
      {id: "EC-mex", hard: true, status: "pass"}, {id: "EC-ci-parity", hard: true, status: "pass"},
      {id: "Q2", hard: false, status: "pass"}]}' | jq "$3" > "$FX/$1.json"
}
F='(.gates[] | select(.id == "AG1" or .id == "EC-ci-parity")) |= (.status = "fail")'
sc clean 0 '.'
sc ae 1 "$F"
sc ae0 0 "$F"
sc aeq 1 "$F"' | (.gates[] | select(.id == "Q1")) |= (.status = "fail")'
sc aesoft 1 "$F"' | (.gates[] | select(.id == "Q2")) |= (.status = "fail")'
sc aenotrun 1 "$F"' | (.gates[] | select(.id == "Q1")) |= (.status = "not_run")'
O='(.gates[] | select(.id == "EC-mex" or .id == "EC-ci-parity")) |= (.status = "out_of_scope") | .run_context = "nested"'
sc nclean 0 "$O"
sc nae 1 "$O"' | (.gates[] | select(.id == "AG1")) |= (.status = "fail")'
sc naeq 1 "$O"' | (.gates[] | select(.id == "AG1" or .id == "Q1")) |= (.status = "fail")'
sc naesoft 1 "$O"' | (.gates[] | select(.id == "AG1" or .id == "Q2")) |= (.status = "fail")'

# t31 LEDGER SCORECARD RC: the real final-check block of T31.sh (from its pending_json line to the check).
t31() {
  { printf '#!/bin/sh\nset -u\ncd "%s" || exit 1\n. tests/p0/lib.sh\nPG=%s\nrc=%s\n' "$ROOT" "$1" "$3"
    sed -n '/^P=\$(pending_json/,/matches tests\/pending-gates\.txt/p' tests/p0/T31.sh | sed "s#\\.gates/latest/scorecard\\.json#$2#g"
    echo finish; } > "$T/t31.sh"
  sh "$T/t31.sh" 2>&1
}
# t35 LEDGER NESTED-SCORECARD: the real nested-run block of T35.sh.
t35() {
  { printf '#!/bin/sh\nset -u\ncd "%s" || exit 1\n. tests/p0/lib.sh\nPG=%s\nN=%s\n' "$ROOT" "$1" "$2"
    sed -n '/^P=\$(pending_json/,/matches tests\/pending-gates\.txt/p' tests/p0/T35.sh
    echo finish; } > "$T/t35.sh"
  sh "$T/t35.sh" 2>&1
}
# t34 LEDGER PRIMARY NESTED: a copy of the real T34.sh without its make gates run, reading fixtures.
t34() {
  d=$T/t34; mkdir -p "$d"; cp "$2" "$d/scorecard.json"; cp "$3" "$d/EC-ci-parity.nested.json"
  sed -e '/^make gates/d' -e "s#^SC=.*#SC=$d/scorecard.json#" -e "s#^PG=.*#PG=$1#" \
      -e 's#^cd .*#cd "'"$ROOT"'" || exit 1#' tests/p0/T34.sh > "$d/T34-copy.sh"
  sh "$d/T34-copy.sh" 2>&1
}
# result OUTPUT NAME-FRAGMENT: "ok" or "not ok" for the one check whose name holds the fragment.
result() {
  l=$(printf '%s\n' "$1" | /usr/bin/grep -F -- "$2" | /usr/bin/grep -E '^(not )?ok - ')
  [ "$(printf '%s\n' "$l" | /usr/bin/grep -c .)" -eq 1 ] || { echo "missing or ambiguous"; return; }
  case "$l" in "not ok - "*) echo "not ok";; *) echo ok;; esac
}
PM='matches tests/pending-gates.txt'
check "T31.sh, T34.sh and T35.sh each name the ledger check 'matches tests/pending-gates.txt' and read PG=tests/pending-gates.txt" sh -c '
  for f in tests/p0/T31.sh tests/p0/T34.sh tests/p0/T35.sh; do
    /usr/bin/grep -q "matches tests/pending-gates.txt" "$f" && /usr/bin/grep -qx "PG=tests/pending-gates.txt" "$f" || exit 1
  done'
for v in "T31 exact ledger, scorecard exit 1, make exit 2|ok|$FX/pg-ae.txt|$FX/ae.json|2" \
         "T31 empty ledger, every hard gate passes, make exit 0|ok|$FX/pg-none.txt|$FX/clean.json|0" \
         "T31 soft failures do not count|ok|$FX/pg-ae.txt|$FX/aesoft.json|2" \
         "T31 exact ledger in another order than the scorecard|ok|$FX/pg-aeq.txt|$FX/aeq.json|2" \
         "T31 extra failing id|not ok|$FX/pg-ae.txt|$FX/aeq.json|2" \
         "T31 extra not_run id|not ok|$FX/pg-ae.txt|$FX/aenotrun.json|2" \
         "T31 listed-but-passing id|not ok|$FX/pg-aeq.txt|$FX/ae.json|2" \
         "T31 empty ledger but failing gates|not ok|$FX/pg-none.txt|$FX/ae.json|2" \
         "T31 ledger lists ids but make exited 0|not ok|$FX/pg-ae.txt|$FX/ae.json|0" \
         "T31 ledger lists ids but make exited 1 (make fails with 2)|not ok|$FX/pg-ae.txt|$FX/ae.json|1" \
         "T31 ledger lists ids but the scorecard exited 0|not ok|$FX/pg-ae.txt|$FX/ae0.json|2" \
         "T31 empty ledger but make exited 2|not ok|$FX/pg-none.txt|$FX/clean.json|2" \
         "T31 malformed ledger|not ok|$FX/pg-bad.txt|$FX/clean.json|0" \
         "T31 missing ledger|not ok|$FX/no-such-ledger.txt|$FX/clean.json|0"; do
  nm=${v%%|*}; r=${v#*|}; want=${r%%|*}; r=${r#*|}; pg=${r%%|*}; r=${r#*|}; s=${r%%|*}; rc=${r#*|}
  check_out "$nm: $want" "$want" result "$(t31 "$pg" "$s" "$rc")" "$PM"
done
for v in "exact ledger|ok|ok|$FX/pg-ae.txt|$FX/ae.json|$FX/nae.json" \
         "empty ledger, nothing failing|ok|ok|$FX/pg-none.txt|$FX/clean.json|$FX/nclean.json" \
         "soft failures do not count|ok|ok|$FX/pg-ae.txt|$FX/aesoft.json|$FX/naesoft.json" \
         "exact ledger in another order than the scorecard|ok|ok|$FX/pg-aeq.txt|$FX/aeq.json|$FX/naeq.json" \
         "extra failing id|not ok|not ok|$FX/pg-ae.txt|$FX/aeq.json|$FX/naeq.json" \
         "listed-but-passing id|not ok|not ok|$FX/pg-aeq.txt|$FX/ae.json|$FX/nae.json" \
         "empty ledger but failing gates|not ok|not ok|$FX/pg-none.txt|$FX/ae.json|$FX/nae.json" \
         "listed AG1 passing in the nested run|ok|not ok|$FX/pg-ae.txt|$FX/ae.json|$FX/nclean.json" \
         "malformed ledger|not ok|not ok|$FX/pg-bad.txt|$FX/clean.json|$FX/nclean.json"; do
  nm=${v%%|*}; r=${v#*|}; wp=${r%%|*}; r=${r#*|}; wn=${r%%|*}; r=${r#*|}; pg=${r%%|*}; r=${r#*|}; p=${r%%|*}; n=${r#*|}
  o=$(t34 "$pg" "$p" "$n")
  check_out "T34 primary, $nm: $wp" "$wp" result "$o" "the failing hard set $PM"
  check_out "T34 nested, $nm: $wn" "$wn" result "$o" "nested run: the in-scope failing hard set $PM"
  check_out "T35 nested, $nm: $wn" "$wn" result "$(t35 "$pg" "$n")" "$PM"
done
printf '%s\n' 'AG1 P1-i1-T29' > "$FX/pg-a.txt"
sc aoos 1 "$F"' | (.gates[] | select(.id == "EC-ci-parity")) |= (.status = "out_of_scope")'
check_out "T34 primary: an out_of_scope gate the ledger does not list fails the check" "not ok" result "$(t34 "$FX/pg-a.txt" "$FX/aoos.json" "$FX/nae.json")" "the failing hard set $PM"

# ---- hygiene ---------------------------------------------------------------------------------------
check "every tests/p1 script is tracked as 100755" sh -c 'for f in tests/p1/*.sh; do test "$(git ls-files -s -- "$f" | cut -d" " -f1)" = 100755 || exit 1; done'
finish
