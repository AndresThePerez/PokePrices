#!/bin/sh
# P1-i1-T07 reviewer edge cases: the pending-gates parser, the exact-equality checks in T31/T34/T35,
# TIER/PS misuse and argument handling in tests/p0/run.sh, and (T07_EDGE_LIVE=1, clean tree only) a
# live fast-tier run behind PATH shims that fail any compose stop/restart/down or `make gates`.
set -u
cd "${T07_EDGE_ROOT:-$(dirname "$0")/../..}" || exit 1
. tests/p0/lib.sh
ROOT=$(pwd)
T=.gates/tmp/T07-edge; mkdir -p "$T/bin" "$T/fx" "$T/d"
TA=$ROOT/$T
FX=$T/fx

# ---- shims for run.sh misuse cases: log argv, never run anything real --------------------------------
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

# ---- 1. ledger parser: CRLF, whitespace, comments, encodings, size ---------------------------------------
printf 'AG1 P1-i1-T29\r\n# EC-ci-parity P1-i1-T29\r\nEC-ci-parity P1-i1-T29\r\n' > "$FX/crlf.txt"
check_out "a CRLF ledger parses to the same sorted ids as an LF one" '["AG1","EC-ci-parity"]' pending_json "$FX/crlf.txt"
printf '   # AG1 P1-i1-T29\n\t# Q1 P1-i1-T29\n  AG2 \t P1-i1-T29   \n\n   \n' > "$FX/ws.txt"
check_out "indented comments are ignored; indented entries with trailing spaces and tab separators parse" '["AG2"]' pending_json "$FX/ws.txt"
printf 'AG1 P1-i1-T29' > "$FX/nonl.txt"
check_out "a last line without a trailing newline still counts" '["AG1"]' pending_json "$FX/nonl.txt"
printf 'AG1 P1-i1-T29\n# \000nul\nQ1 owner\n' > "$FX/nul.txt"
check "a ledger with a NUL byte in a comment fails closed or parses cleanly, never half-parses" sh -c '
  . tests/p0/lib.sh; o=$(pending_json "$0"); rc=$?; if [ $rc -eq 0 ]; then test "$o" = "[\"AG1\",\"Q1\"]"; else test -z "$o"; fi' "$FX/nul.txt"
printf 'AG1 P1-i1-T29\n\377\376 bad bytes\n' > "$FX/badutf.txt"
check "invalid UTF-8 in the ledger fails closed (nothing printed, non-zero)" sh -c '. tests/p0/lib.sh; o=$(pending_json "$0") && exit 1; test -z "$o"' "$FX/badutf.txt"
check "a directory given as the ledger fails closed" sh -c '. tests/p0/lib.sh; o=$(pending_json "$0") && exit 1; test -z "$o"' "$TA/d"
printf 'AG1 \xc3\xa9\n' > "$FX/uniowner.txt"
check "a non-ASCII owner is rejected (the owner alphabet is ASCII)" sh -c '. tests/p0/lib.sh; o=$(pending_json "$0") && exit 1; test -z "$o"' "$FX/uniowner.txt"
printf 'AG1 P1-i1-T29\nag1 P1-i1-T29\n' > "$FX/case.txt"
check_out "ids are case-sensitive (AG1 and ag1 are two ids, not a duplicate)" '["AG1","ag1"]' pending_json "$FX/case.txt"
i=0; : > "$FX/big.txt"; while [ "$i" -lt 3000 ]; do printf 'G%s own\n' "$i" >> "$FX/big.txt"; i=$((i + 1)); done
check_out "a 3000-line ledger parses to 3000 ids" 3000 sh -c '. tests/p0/lib.sh; pending_json "$0" | jq length' "$FX/big.txt"
check_out "pending_json without an argument reads tests/pending-gates.txt" "$(pending_json tests/pending-gates.txt)" pending_json

# ---- 2. exact equality against fixture scorecards (the real check blocks of T31 and T35) ----------------
# gsc NAME EXIT RUNCTX "ID:HARD:STATUS ...": a fixture scorecard with exactly those gates.
gsc() {
  jq -n --argjson x "$2" --arg c "$3" --arg g "$4" '{schema: 1, run_context: $c, clean_before: true, clean_after: true, exit: $x,
    gates: [$g | split(" ")[] | split(":") | {id: .[0], hard: (.[1] == "h"), status: .[2]}]}' > "$FX/$1.json"
}
t31() { # LEDGER-TEXT SCORECARD RC: the real final-check block of T31.sh
  printf '%s' "$1" > "$FX/t31.pg"
  { printf '#!/bin/sh\nset -u\ncd "%s" || exit 1\n. tests/p0/lib.sh\nPG=%s\nrc=%s\n' "$ROOT" "$ROOT/$FX/t31.pg" "$3"
    sed -n '/^P=\$(pending_json/,/matches tests\/pending-gates\.txt/p' tests/p0/T31.sh | sed "s#\\.gates/latest/scorecard\\.json#$2#g"
    echo finish; } > "$T/t31.sh"
  sh "$T/t31.sh" 2>&1
}
t35() { # LEDGER-TEXT NESTED-SCORECARD: the real nested-run block of T35.sh
  printf '%s' "$1" > "$FX/t35.pg"
  { printf '#!/bin/sh\nset -u\ncd "%s" || exit 1\n. tests/p0/lib.sh\nPG=%s\nN=%s\n' "$ROOT" "$ROOT/$FX/t35.pg" "$2"
    sed -n '/^P=\$(pending_json/,/matches tests\/pending-gates\.txt/p' tests/p0/T35.sh
    echo finish; } > "$T/t35.sh"
  sh "$T/t35.sh" 2>&1
}
verdict_of() { printf '%s\n' "$1" | /usr/bin/grep -E '^(not )?ok - .*matches tests/pending-gates\.txt' | cut -d' ' -f1-2 | sed 's/ -$//'; }
# Ids whose natural sort order differs between locales/tools: hyphens, digits, mixed case, prefixes of each other.
MIX="AG-values:h:fail AG1:h:fail AG2:h:fail DC1:h:fail EC-ci-parity:h:fail HY-hygiene:h:fail O3:h:fail S8:h:fail T3:h:fail T6b:h:fail T7:h:fail T7-rel:h:fail"
gsc mix 1 primary "$MIX Q1:h:pass"
printf -v MIXPG '%s own\n' T7-rel T7 T6b T3 S8 O3 HY-hygiene EC-ci-parity DC1 AG2 AG1 AG-values 2>/dev/null || MIXPG=$(printf '%s own\n' T7-rel T7 T6b T3 S8 O3 HY-hygiene EC-ci-parity DC1 AG2 AG1 AG-values)
check_out "T31: python-sorted ledger ids equal jq-sorted failing ids for hyphen/digit/mixed-case ids (12 ids, ledger in reverse)" "ok" verdict_of "$(t31 "$MIXPG" "$FX/mix.json" 2)"
gsc mixnested 1 nested "$MIX EC-mex:h:out_of_scope Q1:h:pass"
check_out "T35: the same 12 ids sort identically in the nested check" "ok" verdict_of "$(t35 "$MIXPG" "$FX/mixnested.json")"
gsc zz 0 primary "Q1:h:pass"
check_out "T31: a ledger id that is not a gate of the scorecard/manifest is never satisfied (make rc and scorecard exit disagree)" "not ok" verdict_of "$(t31 'ZZ-nope own
' "$FX/zz.json" 0)"
gsc zzf 1 primary "AG1:h:fail Q1:h:pass"
check_out "T31: a failing set that is a strict subset of the ledger (unknown id listed too) flips" "not ok" verdict_of "$(t31 'AG1 own
ZZ-nope own
' "$FX/zzf.json" 2)"
gsc broken 0 primary "Q1:h:pass"
jq 'del(.gates)' "$FX/broken.json" > "$FX/nogates.json"
check_out "T31: a scorecard with no gates array does not satisfy an empty ledger" "not ok" verdict_of "$(t31 '' "$FX/nogates.json" 0)"
printf '{"gates": [' > "$FX/trunc.json"
check_out "T31: a truncated scorecard does not satisfy an empty ledger" "not ok" verdict_of "$(t31 '' "$FX/trunc.json" 0)"
gsc softlisted 1 primary "AG1:h:fail Q2:s:fail"
check_out "T31: a failing soft gate the ledger lists does not satisfy it (the ledger is for hard gates)" "not ok" verdict_of "$(t31 'AG1 own
Q2 own
' "$FX/softlisted.json" 2)"
gsc errst 1 primary "AG1:h:error Q1:h:pass"
check_out "T31: a hard gate in status error counts as failing and must be listed" "not ok" verdict_of "$(t31 '' "$FX/errst.json" 2)"
check_out "T31: ... and is satisfied once listed" "ok" verdict_of "$(t31 'AG1 own
' "$FX/errst.json" 2)"
gsc nnr 1 nested "AG1:h:fail Q9:h:not_run EC-mex:h:out_of_scope"
check_out "T35: an unlisted in-scope hard gate in not_run flips" "not ok" verdict_of "$(t35 'AG1 own
' "$FX/nnr.json")"
check_out "T35: ... and is accepted once the ledger lists it" "ok" verdict_of "$(t35 'AG1 own
Q9 own
' "$FX/nnr.json")"
gsc nsoft 1 nested "AG1:h:fail Q2:s:fail EC-mex:h:out_of_scope"
check_out "T35: a failing soft gate in the nested run is ignored" "ok" verdict_of "$(t35 'AG1 own
' "$FX/nsoft.json")"

# ---- 3. run.sh misuse (real runner, shimmed PATH) ----------------------------------------------------------
for v in 'upper|TIER=FAST' 'trail|TIER=fast ' 'inject|TIER=fast;touch .gates/tmp/T07-edge/pwned' 'num|TIER=1' 'fullish|TIER=full2' 'dash|TIER=-fast' 'dot|TIER=.'; do
  nm=${v%%|*}; var=${v#*|}
  runsh "u-$nm" "$var" $RUN
  check "TIER variant '$nm' is unknown: exit 2, usage on stderr, stdout empty, nothing run" sh -c '
    test "$(cat "$0.rc")" -eq 2 && /usr/bin/grep -q "^usage: " "$0.err" && test ! -s "$0.out" && test ! -s "$0.log"' "$T/u-$nm"
done
check "a TIER value with a shell metacharacter did not execute" test ! -e "$T/pwned"
runsh emptytier TIER= $RUN
runsh deflt $RUN
check "an empty TIER= behaves as the default (full): same commands, exit 0" sh -c 'test "$(cat "$0/emptytier.rc")" -eq 0 && test -s "$0/deflt.log" && cmp -s "$0/emptytier.log" "$0/deflt.log"' "$TA"
runsh mixedmiss TIER=task $RUN tests/p0/T02.sh tests/p0/T99-no-such-script.sh
check "task with one real and one missing script: exit 2 and the real one was not run either" sh -c '
  test "$(cat "$0.rc")" -eq 2 && /usr/bin/grep -q "^usage: " "$0.err" && test ! -s "$0.log"' "$T/mixedmiss"
runsh dirarg TIER=task $RUN tests/p0
check "task with a directory as the script: exit 2 and nothing run" sh -c 'test "$(cat "$0.rc")" -eq 2 && test ! -s "$0.log"' "$T/dirarg"
runsh inj TIER=task $RUN "tests/p0/T02.sh;touch $T/pwned2"
check "a script argument with a shell metacharacter is a missing file: exit 2, nothing executed" sh -c '
  test "$(cat "$0.rc")" -eq 2 && test ! -s "$0.log" && test ! -e "$1"' "$T/inj" "$T/pwned2"
printf 'exit 0\n' > "$T/d/a b.sh"
runsh spaced TIER=task $RUN "$T/d/a b.sh"
check "a script path with a space reaches sh as one argument" sh -c '/usr/bin/grep -qxF "sh $0" "$1"' "$T/d/a b.sh" "$T/spaced.log"
for v in 'psfull|TIER=full PS=1' 'psyes|TIER=task PS=yes' 'ps2|TIER=task PS=2' 'psfast|TIER=fast PS=1'; do
  nm=${v%%|*}; vars=${v#*|}
  # shellcheck disable=SC2086 # vars splits into separate assignments on purpose
  runsh "u-$nm" $vars $RUN tests/p1/T07.sh
  check "$nm ($vars): exit 2, usage line, nothing run" sh -c 'test "$(cat "$0.rc")" -eq 2 && /usr/bin/grep -q "^usage: " "$0.err" && test ! -s "$0.log"' "$T/u-$nm"
done
runsh psempty TIER=task PS= $RUN tests/p1/T07.sh
check "PS= (empty) behaves as PS=0: no Go command" sh -c 'test "$(cat "$0.rc")" -eq 0 && ! /usr/bin/grep -q "^go " "$0.log"' "$T/psempty"
runsh namedfail TIER=task SHIM_FAIL="sh tests/p0/T02.sh" $RUN tests/p0/T02.sh
runsh namedok TIER=task $RUN tests/p0/T02.sh
check "a failing named script does not stop the fast tier (same commands as a passing one) but the exit is non-zero" sh -c '
  test "$(cat "$0/namedfail.rc")" -ne 0 && cmp -s "$0/namedok.log" "$0/namedfail.log"' "$TA"
# static: the fast list and the fast function name none of the forbidden steps (comment lines dropped)
sed -n '/^FAST="/,/^FAST_GATES=/p' tests/p0/run.sh > "$T/fastlist.txt"
sed -n '/^fast_tier()/,/^}/p' tests/p0/run.sh >> "$T/fastlist.txt"
check "the fast list and fast_tier() hold no make, test_freeze, EC-ci-parity, ec-ci-parity.sh, o1.sh or compose stop/restart/down" sh -c '
  test -s "$0" && /usr/bin/grep -v "^[[:space:]]*#" "$0" | /usr/bin/grep -Eqi "make|test_freeze|ec-ci-parity|gates/o1|compose|[[:space:]]down|stop|restart" && exit 1; exit 0' "$T/fastlist.txt"
check "every script in the fast list exists, is tracked and is a shell script" sh -c '
  for t in $(sed -n "/^FAST=\"/,/^tests\/p1\/T07.sh\"/p" tests/p0/run.sh | tr -d "\"" | sed "s/^FAST=//"); do
    git ls-files --error-unmatch "$t" >/dev/null 2>&1 && sh -n "$t" || exit 1; done'

# ---- 4. live fast tier behind violation-detecting shims (T07_EDGE_LIVE=1, clean tree) --------------------
if [ "${T07_EDGE_LIVE:-0}" = 1 ] && [ -z "$(git status --porcelain)" ]; then
  L=$T/live; mkdir -p "$L"; : > "$L/docker.log"; : > "$L/make.log"; : > "$L/sh.log"; : > "$L/violations.log"
  cat > "$L/docker" <<'EOF'
#!/bin/sh
printf '%s\n' "$*" >> "$LIVE_DIR/docker.log"
seen=0; skip=0
for a in "$@"; do
  if [ "$seen" = 0 ]; then [ "$a" = compose ] && seen=1; continue; fi
  if [ "$skip" = 1 ]; then skip=0; continue; fi
  case $a in
    -f|--file|-p|--project-name|--profile|--env-file|--project-directory) skip=1 ;;
    -*) ;;
    stop|restart|down|kill|rm|pause) printf 'docker compose %s\n' "$*" >> "$LIVE_DIR/violations.log"; exit 99 ;;
    *) break ;;
  esac
done
exec /usr/bin/docker "$@"
EOF
  cat > "$L/make" <<'EOF'
#!/bin/sh
printf '%s\n' "$*" >> "$LIVE_DIR/make.log"
case " $* " in *" -n "*|*" --dry-run "*) exec /usr/bin/make "$@" ;; esac
case " $* " in *" gates "*|*" verdict "*) printf 'make %s\n' "$*" >> "$LIVE_DIR/violations.log"; exit 99 ;; esac
exec /usr/bin/make "$@"
EOF
  cat > "$L/sh" <<'EOF'
#!/bin/sh
printf '%s\n' "$*" >> "$LIVE_DIR/sh.log"
exec /bin/sh "$@"
EOF
  chmod 755 "$L/docker" "$L/make" "$L/sh"
  # positive controls: the shims really trap a compose restart/stop/down and make gates (they exit 99 before running anything)
  LIVE_DIR=$TA/live; export LIVE_DIR
  "$L/docker" compose -f compose.dev.yml restart web > /dev/null 2>&1; r1=$?
  "$L/docker" compose -f compose.dev.yml stop api > /dev/null 2>&1; r2=$?
  "$L/make" gates > /dev/null 2>&1; r4=$?
  check "live shims: compose restart, compose stop and make gates are trapped (exit 99 before anything real runs)" test "$r1 $r2 $r4" = "99 99 99"
  check "live shims: the traps were logged as violations" test "$(wc -l < "$L/violations.log")" -eq 3
  : > "$L/violations.log"
  before=$(readlink .gates/latest 2>/dev/null)
  env -u PS -u POKESEARCH_DIR TIER=fast LIVE_DIR="$TA/live" PATH="$TA/live:$PATH" /bin/sh tests/p0/run.sh > "$L/out.txt" 2> "$L/err.txt"; echo "$?" > "$L/rc"
  [ -n "$before" ] && ln -sfn "$before" .gates/latest
  check_out "live fast tier exits 0" 0 cat "$L/rc"
  check "live fast tier: no compose stop/restart/down/kill/rm and no make gates/verdict was attempted" test ! -s "$L/violations.log"
  check "live fast tier: no sh on PATH was started on test_freeze.sh, ec-ci-parity.sh or o1.sh (first word of each sh call)" sh -c '! cut -d" " -f1 "$0" | /usr/bin/grep -Eqi "test_freeze|ec-ci-parity|gates/o1"' "$L/sh.log"
  check "live fast tier: the shims were exercised (sh saw the fast scripts; the scorecard ran docker)" sh -c '/usr/bin/grep -q "test_scorecard.sh" "$0/sh.log" && test -s "$0/docker.log"' "$L"
  check "live fast tier: the scorecard summary reports clean_after true" /usr/bin/grep -q "clean_after true" "$L/out.txt"
else
  printf '# live fast-tier checks skipped (set T07_EDGE_LIVE=1 on a clean tree; they take about 60 s and move .gates/latest)\n'
fi
finish
