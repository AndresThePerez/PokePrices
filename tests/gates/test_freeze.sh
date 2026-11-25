#!/bin/sh
# P0-i1-T36: the freeze covers every listed config and the whole gate set is green.
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
check "frozen.sha256 tracked" git ls-files --error-unmatch gates/frozen.sha256
check "freeze list regenerates identically" sh -c 'python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | cmp -s - gates/frozen.sha256'
check "every frozen hash verifies" sh -c '/usr/bin/grep -v "#" gates/frozen.sha256 | sha256sum -c --status'
for p in Makefile scripts/scorecard scripts/verdict compose.dev.yml api/phpunit.xml api/phpstan.neon web/eslint.config.js web/vitest.config.ts web/tsconfig.app.json .spectral.yaml docker/nginx/pokeprices.conf gates/manifest-P0.json gates/ratchet.json gates/hygiene.patterns .github/workflows/tests.yml; do
  check "frozen: $p" /usr/bin/grep -q "  $p\$" gates/frozen.sha256
done
check "subtree entries present" sh -c '/usr/bin/grep -q "  web/package.json#.scripts$" gates/frozen.sha256 && /usr/bin/grep -q "  api/composer.json#.scripts$" gates/frozen.sha256'
check "jitter file zero tolerance" jq -e '(.tolerance | keys == ["D4","Q2","Q5","S10","S7"]) and ([.tolerance[]] | all(. == 0)) and (.calibration_runs | length == 0)' gates/jitter-P0.json
check "anchor is the commit that added frozen.sha256" sh -c 'test -n "$(git log --diff-filter=A --format=%H -- gates/frozen.sha256 | tail -1)"'
check "frozen.sha256 never lists itself" sh -c 'test -s gates/frozen.sha256 && ! /usr/bin/grep -q "  gates/frozen.sha256$" gates/frozen.sha256'
check "lock lines are sorted by path" sh -c 'test -s gates/frozen.sha256 && cut -c67- gates/frozen.sha256 | LC_ALL=C sort -c'
mkdir -p .gates/tmp/T36; T=.gates/tmp/T36
jq '.frozen_files.list += ["docs/no-such-config/*"]' gates/manifest-P0.json > "$T/m-missing.json"
check "freeze: an entry matching no tracked file exits 2, prints nothing and names the entry" sh -c 'python3 -I scripts/lib/freeze.py --manifest "$0" > "$0.out" 2> "$0.err"; test "$?" -eq 2 && test ! -s "$0.out" && /usr/bin/grep -qF "docs/no-such-config/* matches no tracked file" "$0.err"' "$T/m-missing.json"
jq '.frozen_files.list += ["web/package.json#.noSuchSubtree"]' gates/manifest-P0.json > "$T/m-subtree.json"
check "freeze: an absent subtree exits 2, prints nothing and names it" sh -c 'python3 -I scripts/lib/freeze.py --manifest "$0" > "$0.out" 2> "$0.err"; test "$?" -eq 2 && test ! -s "$0.out" && /usr/bin/grep -qF ".noSuchSubtree" "$0.err"' "$T/m-subtree.json"
make gates > .gates/T36-make-gates.log 2>&1; rc=$?
check "make gates exits 0" test "$rc" -eq 0
check "every hard gate passes in the primary run" jq -e '[.gates[] | select(.hard and .status != "pass")] == []' .gates/latest/scorecard.json
check "the nested clean-clone run passed too" sh -c 'jq -e ".gates[] | select(.id == \"EC-ci-parity\") | .status == \"pass\"" .gates/latest/scorecard.json'
finish
