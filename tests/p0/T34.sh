#!/bin/sh
# P0-i1-T34: one full make gates run where every hard gate passes (tightened at the T36 freeze).
# P1-i1-T07: "passes" now means the failing hard set equals the ids tests/pending-gates.txt lists.
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
# gates_point: make .gates/latest name the reused run, as the make gates it stands in for would have.
gates_point() { _r=${REUSE_SC%/scorecard.json}; case $_r in .gates/*) _r=${_r#.gates/} ;; esac; ln -sfn "$_r" .gates/latest; }
# With a reusable run the make gates line below stands in for it: the recorded exit status and .gates/latest on that run.
reuse=0
if gates_reuse; then reuse=1; printf 'gates reuse: read %s; not running make gates again\n' "$REUSE_SC"; fi
make() {
  if [ "$reuse" = 1 ] && [ "${1:-}" = gates ]; then
    gates_point
    return "$REUSE_RC"
  fi
  command make "$@"
}
PG=tests/pending-gates.txt
make gates > .gates/T34-make-gates.log 2>&1
SC=.gates/latest/scorecard.json
check "scorecard exists for HEAD" sh -c 'test "$(jq -r .head "$0")" = "$(git rev-parse HEAD)"' "$SC"
check "primary context, clean before and after" jq -e '.run_context == "primary" and .clean_before and .clean_after' "$SC"
P=$(pending_json "$PG") || P=invalid
check "the failing hard set matches tests/pending-gates.txt" jq -e --argjson p "$P" '([.gates[] | select(.hard and .status != "pass") | .id] | sort) == $p' "$SC"
check "EC-ci-parity's nested run: the in-scope failing hard set matches tests/pending-gates.txt (ids out of scope there excepted)" sh -c 'jq -e --argjson p "$1" "[.gates[] | select(.status == \"out_of_scope\") | .id] as \$o | ([.gates[] | select(.hard and .status != \"pass\" and .status != \"out_of_scope\") | .id] | sort) == [\$p[] | select(. as \$i | \$o | index(\$i) == null)]" "$(dirname "$0")/EC-ci-parity.nested.json"' "$SC" "$P"
check "AG1 shows no violations against the anchor" sh -c 'jq -e "[.details[] | select(.violation == true)] == [] and .value == 0 and .denominator > 0" "$(dirname "$0")/AG1.json"' "$SC"
check "DEN shows no failing details" sh -c 'jq -e ".value == 0 and .details == []" "$(dirname "$0")/DEN.json"' "$SC"
check "AG-values clean on every group" jq -e '.gates[] | select(.id == "AG-values") | .status == "pass"' "$SC"
check "at least 4 of 5 soft gates pass" jq -e '[.gates[] | select(.hard | not) | select(.status == "pass")] | length >= 4' "$SC"
check "the tree is still clean" sh -c 'test -z "$(git status --porcelain --untracked-files=all)"'
finish
