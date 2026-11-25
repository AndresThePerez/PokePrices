#!/bin/sh
# One make gates run for the acceptance tier. tests/p0/run.sh runs it once and exports GATES_REUSE_DIR (a record with
# start.marker, exit and scorecard); T31.sh, T34.sh, T35.sh and test_freeze.sh read that run when its results are newer than
# the marker and written for HEAD, and run the gates themselves otherwise. Everything here runs in a scratch git repo under
# .gates/tmp behind a stub make, docker and ec-ci-parity.sh that only record their calls: no gates, no compose, no clone.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd)
mkdir -p .gates/tmp
W=$(mktemp -d "$ROOT/.gates/tmp/gates-reuse.XXXXXX") || exit 1
mkdir -p "$W/bin" "$W/fx" "$W/rec" "$W/tests/p0" "$W/tests/gates" "$W/scripts/gates"

# ---- the scratch repo ---------------------------------------------------------------------------------
cp tests/p0/lib.sh tests/p0/run.sh tests/p0/T31.sh tests/p0/T34.sh tests/p0/T35.sh "$W/tests/p0/"
cp tests/gates/test_freeze.sh "$W/tests/gates/"
printf '# no pending gates\n' > "$W/tests/pending-gates.txt"
printf '.gates/\n' > "$W/.gitignore"
printf 'all:\n' > "$W/Makefile"
# make: records every call; "make gates" writes a fresh fake run and points .gates/latest at it, as the real one does.
cat > "$W/bin/make" <<'EOF'
#!/bin/sh
echo "make $*" >> "$CALLS"
[ "${1:-}" = gates ] || exit 0
[ -z "${STUB_NOSC:-}" ] || exit "${STUB_RC:-0}"
[ -z "${STUB_SLEEP:-}" ] || sleep 1
mkdir -p .gates
sh "$FX/build.sh" ".gates/made-$$" good
ln -sfn "made-$$" .gates/latest
exit "${STUB_RC:-0}"
EOF
# docker: the memory-limit probe answers 512M, everything else succeeds and prints nothing.
cat > "$W/bin/docker" <<'EOF'
#!/bin/sh
echo "docker $*" >> "$CALLS"
case "$*" in *ini_get*) printf 512M ;; esac
exit 0
EOF
# ec-ci-parity.sh: records its context; like the real one it refuses outside the primary context.
cat > "$W/scripts/gates/ec-ci-parity.sh" <<'EOF'
#!/bin/sh
echo "ci-parity ctx=${PP_RUN_CONTEXT:-}" >> "$CALLS"
[ "${PP_RUN_CONTEXT:-}" = primary ]
EOF
# build.sh DIR MODE: a fake finished run (MODE good, or hardfail: one hard gate failing, in the primary and the nested run).
cat > "$W/fx/build.sh" <<'EOF'
#!/bin/sh
d=$1; mode=$2; head=$(git rev-parse HEAD)
mkdir -p "$d"
s=pass; [ "$mode" = hardfail ] && s=fail
jq -n --arg h "$head" --arg s "$s" '{head: $h, exit: (if $s == "pass" then 0 else 1 end), run_context: "primary", clean_before: true, clean_after: true,
  gates: ([range(0; 39) | {id: ("G" + tostring), hard: true, status: "pass"}] | .[0].status = $s)}' > "$d/scorecard.json"
jq -n --arg h "$head" --arg s "$s" '{head: $h, run_context: "nested",
  gates: ([range(0; 37) | {id: ("N" + tostring), hard: true, status: "pass"}] | .[0].status = $s) + [{id: "EC-mex", hard: true, status: "out_of_scope"}, {id: "EC-ci-parity", hard: true, status: "out_of_scope"}]}' > "$d/EC-ci-parity.nested.json"
printf '{"details":[],"value":0,"denominator":1}\n' > "$d/AG1.json"
printf '{"details":[],"value":0}\n' > "$d/DEN.json"
printf '{"details":[],"value":0}\n' > "$d/EC-ci-parity.nested.DEN.json"
EOF
chmod 755 "$W/bin/"* "$W/scripts/gates/ec-ci-parity.sh"
( cd "$W" && git init -q -b main . && git add . && git -c core.hooksPath=/dev/null -c user.name=t -c user.email=t@example.invalid commit -q -m init )

# newrun NAME MODE EXIT: a finished fake run .gates/NAME and its record rec/NAME, the marker dated long before the results.
newrun() {
  mkdir -p "$W/.gates"
  ( cd "$W" && sh fx/build.sh ".gates/$1" "$2" ) || exit 1
  mkdir -p "$W/rec/$1"
  : > "$W/rec/$1/start.marker"; touch -t 200001010000.00 "$W/rec/$1/start.marker"
  printf '%s\n' "${3:-0}" > "$W/rec/$1/exit"
  printf '.gates/%s/scorecard.json\n' "$1" > "$W/rec/$1/scorecard"
}
# go SCRIPT [VAR=VALUE...]: run a script of the scratch repo with the stubs first on PATH; OUT holds its output.
go() {
  s=$1; shift
  : > "$W/calls.log"
  OUT=$(cd "$W" && env -u GATES_REUSE_DIR -u TIER PATH="$W/bin:$PATH" CALLS="$W/calls.log" FX="$W/fx" "$@" sh "$s" 2>&1)
}
saw() { case $OUT in *"$1"*) return 0 ;; esac; return 1; }
# calls_of SCRIPT: how many times it ran the gates (T35 runs the nested clean-clone script instead).
calls_of() {
  case $1 in
    */T35.sh) /usr/bin/grep -c '^ci-parity ctx=primary' "$W/calls.log" ;;
    *) /usr/bin/grep -c '^make gates' "$W/calls.log" ;;
  esac
}
count_lines() { printf '%s\n' "$OUT" | /usr/bin/grep -c "$1"; }

check "the five files parse (sh -n)" sh -c 'for f in tests/p0/run.sh tests/p0/T31.sh tests/p0/T34.sh tests/p0/T35.sh tests/gates/test_freeze.sh; do sh -n "$f" || exit 1; done'
check "gates_reuse is the same text in T31.sh, T34.sh, T35.sh and test_freeze.sh" sh -c '
  a=$(sed -n "/^gates_reuse() {/,/^}/p" tests/p0/T31.sh | cksum); test -n "$(sed -n "/^gates_reuse() {/,/^}/p" tests/p0/T31.sh)" || exit 1
  for f in tests/p0/T34.sh tests/p0/T35.sh tests/gates/test_freeze.sh; do test "$a" = "$(sed -n "/^gates_reuse() {/,/^}/p" "$f" | cksum)" || exit 1; done'

# ---- each script, each kind of record ------------------------------------------------------------------
for S in tests/p0/T31.sh tests/p0/T34.sh tests/gates/test_freeze.sh tests/p0/T35.sh; do
  n=${S##*/}
  newrun fresh good
  go "$S" GATES_REUSE_DIR="$W/rec/fresh"
  check "$n fresh results: one reuse line" saw 'gates reuse: read '
  check_out "$n fresh results: the gates are not run again" 0 calls_of "$S"
  check "$n fresh results: no fallback line" sh -c '! printf "%s\n" "$0" | /usr/bin/grep -q "gates reuse: not used"' "$OUT"

  newrun stale good
  touch -t 199901010000.00 "$W/.gates/stale/scorecard.json"
  go "$S" GATES_REUSE_DIR="$W/rec/stale"
  check "$n results older than the marker: one fallback line, the script runs its own" saw 'gates reuse: not used'
  check_out "$n results older than the marker: the gates run here" 1 calls_of "$S"

  newrun equal good
  touch -r "$W/rec/equal/start.marker" "$W/.gates/equal/scorecard.json"
  go "$S" GATES_REUSE_DIR="$W/rec/equal"
  check_out "$n results dated the same as the marker count as stale" 1 calls_of "$S"

  newrun nofile good
  rm -f "$W/.gates/nofile/scorecard.json" "$W/.gates/nofile/EC-ci-parity.nested.json"
  go "$S" GATES_REUSE_DIR="$W/rec/nofile"
  check "$n missing results file: one fallback line" saw 'gates reuse: not used'
  check_out "$n missing results file: the gates run here" 1 calls_of "$S"

  newrun noexit good
  rm -f "$W/rec/noexit/exit"
  go "$S" GATES_REUSE_DIR="$W/rec/noexit"
  check_out "$n record without an exit status: the gates run here" 1 calls_of "$S"

  newrun otherhead good
  jq '.head = "0000000000000000000000000000000000000000"' "$W/.gates/otherhead/scorecard.json" > "$W/x.json" && mv "$W/x.json" "$W/.gates/otherhead/scorecard.json"
  jq '.head = "0000000000000000000000000000000000000000"' "$W/.gates/otherhead/EC-ci-parity.nested.json" > "$W/x.json" && mv "$W/x.json" "$W/.gates/otherhead/EC-ci-parity.nested.json"
  go "$S" GATES_REUSE_DIR="$W/rec/otherhead"
  check_out "$n results for another commit: the gates run here" 1 calls_of "$S"

  go "$S"
  check "$n variable unset: no reuse line at all" sh -c '! printf "%s\n" "$0" | /usr/bin/grep -q "gates reuse"' "$OUT"
  check_out "$n variable unset: the script runs its own gates" 1 calls_of "$S"
done

# T35 reads the nested run: a gates run that left no nested result is not enough for it, the others do not need it
newrun nonested good
rm -f "$W/.gates/nonested/EC-ci-parity.nested.DEN.json"
go tests/p0/T35.sh GATES_REUSE_DIR="$W/rec/nonested"
check_out "T35.sh without a nested DEN result runs the nested clean-clone script itself" 1 calls_of tests/p0/T35.sh
go tests/p0/T34.sh GATES_REUSE_DIR="$W/rec/nonested"
check_out "T34.sh does not need the nested DEN result of that record" 0 calls_of tests/p0/T34.sh

# ---- criterion b: a reused hard-gate failure fails the way an own run would ------------------------------
newrun hard hardfail 0
newrun twice good 2
go tests/p0/T31.sh GATES_REUSE_DIR="$W/rec/fresh"
check "T31.sh reads a good run as passing" saw 'ok - make gates exits 0 when the ledger lists no ids'
check "T31.sh reads the scorecard as written by the tier's run (its marker follows the record's)" saw 'ok - the scorecard was written by this make gates run'
go tests/p0/T31.sh GATES_REUSE_DIR="$W/rec/hard"
check "T31.sh fails a reused run with a failing hard gate" saw 'not ok - make gates exits 0 when the ledger lists no ids'
go tests/p0/T31.sh GATES_REUSE_DIR="$W/rec/twice"
check "T31.sh fails a reused run whose make gates exited 2 against an empty ledger" saw 'not ok - make gates exits 0 when the ledger lists no ids'
go tests/p0/T34.sh GATES_REUSE_DIR="$W/rec/fresh"
check "T34.sh reads a good run as passing" saw 'ok - the failing hard set matches tests/pending-gates.txt'
go tests/p0/T34.sh GATES_REUSE_DIR="$W/rec/hard"
check "T34.sh fails a reused run with a failing hard gate" saw 'not ok - the failing hard set matches tests/pending-gates.txt'
go tests/gates/test_freeze.sh GATES_REUSE_DIR="$W/rec/fresh"
check "test_freeze.sh reads a good run as passing" saw 'ok - every hard gate passes in the primary run'
check "test_freeze.sh reads exit 0 as passing" saw 'ok - make gates exits 0'
go tests/gates/test_freeze.sh GATES_REUSE_DIR="$W/rec/hard"
check "test_freeze.sh fails a reused run with a failing hard gate" saw 'not ok - every hard gate passes in the primary run'
go tests/gates/test_freeze.sh GATES_REUSE_DIR="$W/rec/twice"
check "test_freeze.sh fails a reused run whose make gates exited 2" saw 'not ok - make gates exits 0'
go tests/p0/T35.sh GATES_REUSE_DIR="$W/rec/fresh"
check "T35.sh reads a good nested run as passing" saw "ok - the nested run's in-scope failing hard set matches"
go tests/p0/T35.sh GATES_REUSE_DIR="$W/rec/hard"
check "T35.sh fails a reused nested run with a failing hard gate" saw "not ok - the nested run's in-scope failing hard set matches"

# ---- run.sh: one make gates for the whole tier, read by the four scripts ------------------------------------
rm -rf "$W/.gates"
go tests/p0/run.sh STUB_SLEEP=1
check_out "the full tier runs make gates once" 1 calls_of tests/p0/T31.sh
check_out "the four scripts each print the reuse line" 4 count_lines 'gates reuse: read '
check_out "no script falls back to its own run" 0 count_lines 'gates reuse: not used'
check_out "T35.sh never starts the nested clean-clone script for the run" 0 calls_of tests/p0/T35.sh
rm -rf "$W/.gates"
go tests/p0/run.sh STUB_NOSC=1 STUB_RC=2
check "a make gates that wrote no scorecard is said in one line" saw 'gates reuse: make gates wrote no new scorecard'
check_out "then T31, T34 and test_freeze each run the gates and run.sh's own run makes four" 4 calls_of tests/p0/T31.sh
check_out "then T35 runs the nested clean-clone script itself" 1 calls_of tests/p0/T35.sh
check_out "and no script prints a reuse line" 0 count_lines 'gates reuse: read '

case $W in "$ROOT"/.gates/tmp/gates-reuse.*) rm -rf "$W" ;; esac
finish
