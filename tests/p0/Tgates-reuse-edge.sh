#!/bin/sh
# Edge cases for the one-gates-run acceptance tier (see Tgates-reuse.sh): results that are empty, cut short, a second old,
# or named by a odd variable value are never read as a pass; a reused run gives the verdict an own run would; reading a run
# leaves it as it was; the make wrapper only stands in for "make gates". Scratch git repo under .gates/tmp, stub make,
# docker and ec-ci-parity.sh that only record their calls and write fake results: no gates, no compose, no clone.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd)
mkdir -p .gates/tmp
W=$(mktemp -d "$ROOT/.gates/tmp/gates-reuse-edge.XXXXXX") || exit 1
mkdir -p "$W/bin" "$W/fx" "$W/rec" "$W/tests/p0" "$W/tests/gates" "$W/scripts/gates"

cp tests/p0/lib.sh tests/p0/run.sh tests/p0/T31.sh tests/p0/T34.sh tests/p0/T35.sh "$W/tests/p0/"
cp tests/gates/test_freeze.sh "$W/tests/gates/"
printf '# no pending gates\n' > "$W/tests/pending-gates.txt"
printf '.gates/\n' > "$W/.gitignore"
printf 'all:\n' > "$W/Makefile"
cat > "$W/bin/make" <<'EOF'
#!/bin/sh
echo "make $*" >> "$CALLS"
[ "${1:-}" = gates ] || exit 0
[ -z "${STUB_NOSC:-}" ] || exit "${STUB_RC:-0}"
mkdir -p .gates
sh "$FX/build.sh" ".gates/made-$$" "${STUB_MODE:-good}"
ln -sfn "made-$$" .gates/latest
exit "${STUB_RC:-0}"
EOF
cat > "$W/bin/docker" <<'EOF'
#!/bin/sh
echo "docker $*" >> "$CALLS"
case "$*" in *ini_get*) printf 512M ;; esac
exit 0
EOF
cat > "$W/scripts/gates/ec-ci-parity.sh" <<'EOF'
#!/bin/sh
echo "ci-parity ctx=${PP_RUN_CONTEXT:-}" >> "$CALLS"
[ "${PP_RUN_CONTEXT:-}" = primary ] || exit 1
sh "$FX/build.sh" "$GATES_OUT" "${STUB_MODE:-good}"
EOF
# build.sh DIR MODE: a fake finished run. good: all pass. hardfail: one hard gate fails. softfail: two of the five soft gates
# fail and every hard one passes. The nested result follows the same mode.
cat > "$W/fx/build.sh" <<'EOF'
#!/bin/sh
d=$1; mode=$2; head=$(git rev-parse HEAD)
mkdir -p "$d"
jq -n --arg h "$head" --arg m "$mode" '
  ([range(0; 34) | {id: ("H" + tostring), hard: true, status: "pass"}] + [range(0; 5) | {id: ("S" + tostring), hard: false, status: "pass"}]) as $g
  | {head: $h, exit: (if $m == "hardfail" then 1 else 0 end), run_context: "primary", clean_before: true, clean_after: true,
     gates: ($g | if $m == "hardfail" then (.[0].status = "fail") elif $m == "softfail" then (.[34].status = "fail" | .[35].status = "fail") else . end)}' > "$d/scorecard.json"
jq -n --arg h "$head" --arg m "$mode" '
  {head: $h, run_context: "nested",
   gates: ([range(0; 37) | {id: ("N" + tostring), hard: true, status: "pass"}] | (if $m == "hardfail" then (.[0].status = "fail") else . end))
     + [{id: "EC-mex", hard: true, status: "out_of_scope"}, {id: "EC-ci-parity", hard: true, status: "out_of_scope"}]}' > "$d/EC-ci-parity.nested.json"
printf '{"details":[],"value":0,"denominator":1}\n' > "$d/AG1.json"
printf '{"details":[],"value":0}\n' > "$d/DEN.json"
printf '{"details":[],"value":0}\n' > "$d/EC-ci-parity.nested.DEN.json"
EOF
chmod 755 "$W/bin/"* "$W/scripts/gates/ec-ci-parity.sh"
( cd "$W" && git init -q -b main . && git add . && git -c core.hooksPath=/dev/null -c user.name=t -c user.email=t@example.invalid commit -q -m init )
# the harness's own files are not part of the scratch tree, so the scripts' "tree is clean" checks mean something
printf 'rec/\ncalls.log\nemptydir/\nafile\nx.part\n' >> "$W/.git/info/exclude"

# newrun NAME MODE EXIT: a finished fake run .gates/NAME and its record rec/NAME, the marker dated long before the results.
newrun() {
  mkdir -p "$W/.gates"
  ( cd "$W" && sh fx/build.sh ".gates/$1" "$2" ) || exit 1
  mkdir -p "$W/rec/$1"
  : > "$W/rec/$1/start.marker"; touch -t 200001010000.00 "$W/rec/$1/start.marker"
  printf '%s\n' "${3:-0}" > "$W/rec/$1/exit"
  printf '.gates/%s/scorecard.json\n' "$1" > "$W/rec/$1/scorecard"
}
go() {
  s=$1; shift
  : > "$W/calls.log"
  OUT=$(cd "$W" && env -u GATES_REUSE_DIR -u TIER PATH="$W/bin:$PATH" CALLS="$W/calls.log" FX="$W/fx" "$@" sh "$s" 2>&1; echo "exit=$?")
}
saw() { case $OUT in *"$1"*) return 0 ;; esac; return 1; }
verdict() { printf '%s\n' "$OUT" | /usr/bin/grep -E '^(ok|not ok) - |^exit='; }
calls_of() {
  case $1 in
    */T35.sh) /usr/bin/grep -c '^ci-parity ctx=primary' "$W/calls.log" ;;
    *) /usr/bin/grep -c '^make gates' "$W/calls.log" ;;
  esac
}
# green_or_own S: a script handed bad results either ran the work itself or did not exit 0: it is never a silent pass.
green_or_own() { [ "$(calls_of "$1")" -ge 1 ] || ! saw 'exit=0'; }
ALL="tests/p0/T31.sh tests/p0/T34.sh tests/gates/test_freeze.sh tests/p0/T35.sh"

# 1. results that exist and are newer than the marker but cannot be read: empty, cut mid-line, not JSON; an exit record that is empty or not a number
for S in $ALL; do
  n=${S##*/}
  newrun empty good; : > "$W/.gates/empty/scorecard.json"
  go "$S" GATES_REUSE_DIR="$W/rec/empty"
  check "1 $n: an empty scorecard is not reused" saw 'gates reuse: not used'
  check "1 $n: an empty scorecard: the script runs its own" sh -c 'test "$(grep -c "^make gates\|^ci-parity ctx=primary" "$0")" -ge 1' "$W/calls.log"
  newrun cut good; head -c 40 "$W/.gates/cut/scorecard.json" > "$W/x.part"; mv "$W/x.part" "$W/.gates/cut/scorecard.json"
  go "$S" GATES_REUSE_DIR="$W/rec/cut"
  check "1 $n: a scorecard cut mid-line is not reused" saw 'gates reuse: not used'
  newrun noexit0 good; : > "$W/rec/noexit0/exit"
  go "$S" GATES_REUSE_DIR="$W/rec/noexit0"
  check "1 $n: an empty exit record is not reused" saw 'gates reuse: not used'
  newrun junkexit good '0; true'
  go "$S" GATES_REUSE_DIR="$W/rec/junkexit"
  check "1 $n: an exit record that is not a number is not reused" saw 'gates reuse: not used'
done

# 2. one second either side of the marker
newrun sec good
for S in tests/p0/T34.sh tests/gates/test_freeze.sh; do
  n=${S##*/}
  t=$(stat -c %Y "$W/.gates/sec/scorecard.json")
  touch -d "@$((t + 1))" "$W/rec/sec/start.marker"
  go "$S" GATES_REUSE_DIR="$W/rec/sec"
  check "2 $n: a marker one second newer than the results: not reused" saw 'gates reuse: not used'
  check_out "2 $n: a marker one second newer than the results: the gates run here" 1 calls_of "$S"
  touch -d "@$((t - 1))" "$W/rec/sec/start.marker"
  go "$S" GATES_REUSE_DIR="$W/rec/sec"
  check "2 $n: a marker one second older than the results: reused" saw 'gates reuse: read '
  check_out "2 $n: a marker one second older than the results: no gates run" 0 calls_of "$S"
done

# 3. the variable names something that is not a record: a missing path, an empty folder, a file; and a record whose path holds a space
mkdir -p "$W/emptydir"; : > "$W/afile"
for S in $ALL; do
  n=${S##*/}
  for v in "$W/no/such/path" "$W/emptydir" "$W/afile"; do
    go "$S" GATES_REUSE_DIR="$v"
    check "3 $n: variable $(basename "$v"): one fallback line" saw 'gates reuse: not used'
    check "3 $n: variable $(basename "$v"): no reuse line" sh -c '! printf "%s\n" "$0" | grep -q "gates reuse: read "' "$OUT"
    check "3 $n: variable $(basename "$v"): the script runs its own" sh -c 'test "$(grep -c "^make gates\|^ci-parity ctx=primary" "$0")" -ge 1' "$W/calls.log"
  done
  newrun "with space" good
  go "$S" GATES_REUSE_DIR="$W/rec/with space"
  check "3 $n: a record folder and results with a space in the name are reused" saw 'gates reuse: read '
  check_out "3 $n: a record with a space in its name: no gates run" 0 calls_of "$S"
done

# 4. a reused run gives the verdict an own run gives, on a hard failure and on a soft one
same_verdict() { # SCRIPT MODE EXIT
  rm -rf "$W/.gates"; mkdir -p "$W/.gates"
  go "$1" STUB_MODE="$2" STUB_RC="$3"; plain=$(verdict)
  rm -rf "$W/.gates"; newrun dif "$2" "$3"
  go "$1" GATES_REUSE_DIR="$W/rec/dif"; reused=$(verdict)
  saw 'gates reuse: read ' || return 1
  [ "$plain" = "$reused" ] || { printf '%s\n--- plain\n%s\n--- reused\n%s\n' "$1" "$plain" "$reused" >&2; return 1; }
}
for S in $ALL; do
  n=${S##*/}
  check "4 $n: a hard gate failed: the reused verdict is the own-run verdict" same_verdict "$S" hardfail 2
  check "4 $n: only soft gates failed: the reused verdict is the own-run verdict" same_verdict "$S" softfail 0
done
rm -rf "$W/.gates"; newrun soft2 softfail 0
go tests/p0/T34.sh GATES_REUSE_DIR="$W/rec/soft2"
check "4 T34.sh: two soft failures and no hard one is still red (fewer than 4 of 5 soft pass)" saw 'not ok - at least 4 of 5 soft gates pass'
check "4 T34.sh: ... while the hard checks are green" saw 'ok - the failing hard set matches tests/pending-gates.txt'

# 5. the four scripts in sequence on one record: none consumes, moves or rewrites it
rm -rf "$W/.gates"; newrun seq good
snap() { ( cd "$W" && find rec/seq .gates/seq -type f -exec cksum {} + && find rec/seq .gates/seq -type f -printf '%p %T@\n' ) | sort; }
before=$(snap); seqok=1
for S in $ALL; do
  go "$S" GATES_REUSE_DIR="$W/rec/seq"
  saw 'gates reuse: read ' || { seqok=0; printf 'no reuse line for %s\n' "$S" >&2; }
  [ "$(calls_of "$S")" = 0 ] || seqok=0
  [ "$(snap)" = "$before" ] || { seqok=0; printf 'record changed after %s\n' "$S" >&2; }
done
check "5 all four scripts, one after the other, read the same record and leave it untouched" test "$seqok" = 1

# 6. the make wrapper stands in for "make gates" only: the other make calls of T31 still reach make
rm -rf "$W/.gates"; newrun wrap good
go tests/p0/T31.sh GATES_REUSE_DIR="$W/rec/wrap"
check_out "6 T31.sh under reuse: make init still runs, twice" 2 /usr/bin/grep -c '^make init$' "$W/calls.log"
check "6 T31.sh under reuse: make -n gates and make verdict still reach make" sh -c 'grep -q "^make -n gates$" "$0" && grep -q "^make verdict$" "$0"' "$W/calls.log"
check_out "6 T31.sh under reuse: only make gates itself is not run" 0 calls_of tests/p0/T31.sh

# 7. T35: a nested result that is old, or cut short, never turns the check green
rm -rf "$W/.gates"; newrun oldnest good
touch -t 199901010000.00 "$W/.gates/oldnest/EC-ci-parity.nested.json"
go tests/p0/T35.sh GATES_REUSE_DIR="$W/rec/oldnest"
check "7 T35.sh: a nested result older than the marker is not reused" saw 'gates reuse: not used'
check_out "7 T35.sh: ... and it runs the nested clean-clone script itself" 1 calls_of tests/p0/T35.sh
newrun cutnest good; head -c 30 "$W/.gates/cutnest/EC-ci-parity.nested.json" > "$W/x.part"; mv "$W/x.part" "$W/.gates/cutnest/EC-ci-parity.nested.json"
go tests/p0/T35.sh GATES_REUSE_DIR="$W/rec/cutnest"
check "7 T35.sh: a nested result cut mid-line is not a silent pass" green_or_own tests/p0/T35.sh
newrun cutden good; : > "$W/.gates/cutden/EC-ci-parity.nested.DEN.json"
go tests/p0/T35.sh GATES_REUSE_DIR="$W/rec/cutden"
check "7 T35.sh: an empty nested DEN result is not a silent pass" green_or_own tests/p0/T35.sh

# 8. run.sh: a value inherited from the caller never counts, and a red gates run keeps the tier red
rm -rf "$W/.gates"; newrun inh good
go tests/p0/run.sh GATES_REUSE_DIR="$W/rec/inh" STUB_NOSC=1
check "8 run.sh: an inherited variable is dropped when make gates wrote nothing new" sh -c '! printf "%s\n" "$0" | grep -q "gates reuse: read "' "$OUT"
rm -rf "$W/.gates"
go tests/p0/run.sh STUB_MODE=hardfail STUB_RC=2
check "8 run.sh: a hard gate failing in the one gates run still fails the tier" sh -c 'printf "%s\n" "$0" | tail -n 1 | grep -vq "^exit=0$"' "$OUT"
check "8 run.sh: ... and T34.sh says so" saw 'not ok - the failing hard set matches tests/pending-gates.txt'
check_out "8 run.sh: ... from one make gates run" 1 calls_of tests/p0/T31.sh

case $W in "$ROOT"/.gates/tmp/gates-reuse-edge.*) rm -rf "$W" ;; esac
finish
