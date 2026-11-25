#!/bin/sh
# P0-i1-T35: the nested clean-clone run works, isolates itself, and (since the T36 freeze) passes every in-scope hard gate.
# P1-i1-T07: "passes" now means the in-scope failing hard set equals the ids tests/pending-gates.txt lists.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
# gates_reuse: when tests/p0/run.sh already ran make gates, GATES_REUSE_DIR names its record (start.marker, exit, scorecard).
# Succeeds, with REUSE_SC (the scorecard) and REUSE_RC (the exit status) set, only when that run is complete, its scorecard is
# newer than the start marker and it was written for HEAD. Anything else prints one line saying why and fails, and the script
# runs its own. With GATES_REUSE_DIR unset it prints nothing and fails: a script run alone behaves as it always did.
gates_reuse() {
  [ -n "${GATES_REUSE_DIR:-}" ] || return 1
  _d=$GATES_REUSE_DIR; _why=
  if [ ! -f "$_d/start.marker" ] || [ ! -f "$_d/exit" ] || [ ! -f "$_d/scorecard" ]; then
    _why="the record in $_d is incomplete"
  else
    REUSE_SC=$(cat "$_d/scorecard"); REUSE_RC=$(cat "$_d/exit")
    case $REUSE_RC in '' | *[!0-9]*) _why="the recorded exit status is not a number" ;; esac
    if [ -n "$_why" ]; then :
    elif [ ! -f "$REUSE_SC" ]; then _why="$REUSE_SC is missing"
    elif [ ! "$REUSE_SC" -nt "$_d/start.marker" ]; then _why="$REUSE_SC is not newer than the start marker"
    elif [ "$(jq -r '.head // empty' "$REUSE_SC" 2>/dev/null)" != "$(git rev-parse HEAD 2>/dev/null || echo none)" ]; then _why="$REUSE_SC is not for HEAD"
    fi
  fi
  [ -z "$_why" ] && return 0
  printf 'gates reuse: not used (%s); this script runs its own\n' "$_why"
  return 1
}
PG=tests/pending-gates.txt
mkdir -p .gates/tmp; T=.gates/tmp/T35; mkdir -p "$T"
$DC up -d --wait
check_out "the api PHP memory_limit is 512M (cold type coverage fits)" 512M $DC exec -T api php -r 'echo ini_get("memory_limit");'
# The gates run of this tier already made the nested run: its two results sit beside that run's scorecard.
reuse=0
if gates_reuse; then
  reuse=1; RD=${REUSE_SC%/*}
  for f in EC-ci-parity.nested.json EC-ci-parity.nested.DEN.json; do
    if [ ! -f "$RD/$f" ] || [ ! "$RD/$f" -nt "$GATES_REUSE_DIR/start.marker" ]; then
      printf 'gates reuse: not used (%s/%s is missing or not newer than the start marker); this script runs its own\n' "$RD" "$f"; reuse=0; break
    fi
  done
fi
if [ "$reuse" = 1 ]; then
  printf 'gates reuse: read the nested run in %s; not running it again\n' "$RD"
  cp "$RD/EC-ci-parity.nested.json" "$RD/EC-ci-parity.nested.DEN.json" "$T/"
else
  env GATES_OUT="$T" PP_RUN_CONTEXT=primary sh scripts/gates/ec-ci-parity.sh > "$T/run.log" 2>&1
fi
N="$T/EC-ci-parity.nested.json"
check "nested scorecard copied" test -f "$N"
check "nested run used the nested context" jq -e '.run_context == "nested"' "$N"
check "nested run tested this HEAD" sh -c 'test "$(jq -r .head "$0")" = "$(git rev-parse HEAD)"' "$N"
check "nested run recorded at least 37 gates" jq -e '(.gates | length) >= 37' "$N"
check "EC-mex and EC-ci-parity are out_of_scope in the nested run" jq -e '[.gates[] | select(.id == "EC-mex" or .id == "EC-ci-parity") | .status] == ["out_of_scope","out_of_scope"]' "$N"
P=$(pending_json "$PG") || P=invalid
check "the nested run's in-scope failing hard set matches tests/pending-gates.txt (ids out of scope there excepted)" jq -e --argjson p "$P" '[.gates[] | select(.status == "out_of_scope") | .id] as $o | ([.gates[] | select(.hard and .status != "pass" and .status != "out_of_scope") | .id] | sort) == [$p[] | select(. as $i | $o | index($i) == null)]' "$N"
check "the nested DEN has no failing details" jq -e '.value == 0 and .details == []' "$T/EC-ci-parity.nested.DEN.json"
check "no ppclean container is left running" sh -c 'test -z "$(docker ps -q --filter label=com.docker.compose.project=ppclean)"'
check "the primary stack is still healthy" $DC up -d --wait
check "the primary tree is still clean" sh -c 'test -z "$(git status --porcelain --untracked-files=all)"'
check "refuses to nest itself" sh -c '! env GATES_OUT="$0" PP_RUN_CONTEXT=nested sh scripts/gates/ec-ci-parity.sh' "$T"
finish
