#!/bin/sh
# P0-i1-T31: make targets exist, are idempotent, keep the tree clean, and make gates runs all 39 gates.
# P1-i1-T07: the failing hard set must equal the ids tests/pending-gates.txt lists, exactly.
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
# With a reusable run the make gates line below stands in for it: the recorded exit status, .gates/latest on that run and the
# run's own start marker as this script's (the scorecard check compares against it).
reuse=0
if gates_reuse; then reuse=1; printf 'gates reuse: read %s; not running make gates again\n' "$REUSE_SC"; fi
make() {
  if [ "$reuse" = 1 ] && [ "${1:-}" = gates ]; then
    touch -r "$GATES_REUSE_DIR/start.marker" .gates/T31-start.marker || return 1
    gates_point
    return "$REUSE_RC"
  fi
  command make "$@"
}
PG=tests/pending-gates.txt
check "make -n gates calls scorecard then verdict --gates-only" sh -c 'make -n gates | /usr/bin/grep -q "scripts/scorecard --manifest gates/manifest-P0.json" && make -n gates | /usr/bin/grep -q "scripts/verdict --gates-only"'
check "every compose exec in the recipes has -T" sh -c '! make -n init gates | /usr/bin/grep -E "exec " | /usr/bin/grep -vq -- " -T "'
check "down never removes volumes" sh -c '! make -n down | /usr/bin/grep -Eq -- "-v\b|--volumes"'
check "make verdict without FINDINGS refuses" sh -c '! make verdict >/dev/null 2>&1'
check "make init succeeds" make init
check "make init is idempotent" make init
check "api/.env exists (existence only)" test -e api/.env
check "init leaves the tree clean" sh -c 'test -z "$(git status --porcelain --untracked-files=all)"'
mkdir -p .gates
: > .gates/T31-start.marker
make gates > .gates/T31-make-gates.log 2>&1; rc=$?
check "make gates produced a scorecard" test -f .gates/latest/scorecard.json
check "the scorecard was written by this make gates run (not a stale one)" test .gates/latest/scorecard.json -nt .gates/T31-start.marker
check_out "the scorecard holds all 39 manifest gates" 39 jq '.gates | length' .gates/latest/scorecard.json
P=$(pending_json "$PG") || P=invalid
want_rc=0; want_exit=0; [ "$P" = "[]" ] || { want_rc=2; want_exit=1; }
check "make gates exits 0 when the ledger lists no ids, else 2 (make) with scorecard exit 1, and the failing hard set matches tests/pending-gates.txt" sh -c 'test "$0" -eq "$1" && jq -e --argjson x "$2" --argjson p "$3" "(.exit == \$x) and (([.gates[] | select(.hard and .status != \"pass\") | .id] | sort) == \$p)" .gates/latest/scorecard.json' "$rc" "$want_rc" "$want_exit" "$P"
finish
