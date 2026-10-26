#!/bin/sh
# P0-i1-T02 edge cases: the manifest identity checks must really bite.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
APPROVED=5f5178088e4290a7033a8555006b56e234173d95a65743564d2464b35479c7b1
M=gates/manifest-P0.json
H=gates/manifest-P0.approved.sha256

# --- identity of the committed object
check_out "HEAD blob hashes to the approved sha256" "$APPROVED" sh -c "git show HEAD:$M | sha256sum | cut -d' ' -f1"
check "sha256sum -c --strict passes" sha256sum -c --strict --status "$H"
check_out "approved file is exactly one line" 1 sh -c "wc -l < $H | tr -d ' '"
check "approved file has no CR and no BOM" sh -c "! /usr/bin/grep -q \"$(printf '\r')\" $H && [ \"\$(head -c3 $H | od -An -tx1 | tr -d ' ')\" != efbbbf ]"
check "script is executable in the index" sh -c "git ls-files -s tests/p0/T02.sh | grep -q '^100755'"
check "JSON has 39 gates with unique ids" jq -e '(.gates|length)==39 and ((.gates|map(.id)|unique|length)==39)' $M
check "every gate has the 15 required fields" jq -e 'all(.gates[]; ["id","hard","scope","runs_in","command","banner_cmd","banner_re","value_parser","op","threshold","denominator_source","denominator_min","timeout_s","why","built_by"] - keys == [])' $M
check "built_by values look like P0-i1-T<NN>" jq -e 'all(.gates[]; .built_by|test("^P0-i1-T[0-9]{2}$"))' $M

# --- mutation: copy the repo and break it; T02.sh in the copy must fail
SCR=.gates/tmp/T02-edge-clone.$$   # git-ignored scratch; left in place (no recursive delete in this repo workflow)
mkdir -p .gates/tmp
git clone -q --no-hardlinks . "$SCR" || { echo "not ok - clone"; exit 1; }
mut() { # desc, mutation command (run inside clone); T02.sh must then FAIL
  desc=$1; shift
  ( cd "$SCR" && git checkout -q -- . && "$@" ) >/dev/null 2>&1
  if ( cd "$SCR" && sh tests/p0/T02.sh ) >/dev/null 2>&1; then
    printf 'not ok - %s (T02.sh still passes)\n' "$desc"; fails=$((fails + 1))
  else printf 'ok - %s\n' "$desc"; fi
}
( cd "$SCR" && git checkout -q -- . && sh tests/p0/T02.sh ) >/dev/null 2>&1 || { echo "not ok - clone baseline does not pass"; fails=$((fails+1)); }
mut "one-byte JSON change is caught" sh -c "printf ' ' >> $M"
mut "single-space hash line is caught" sh -c "sed -i 's/  gates/ gates/' $H"
mut "CRLF hash line is caught" sh -c "sed -i 's/\$/\r/' $H"
mut "floor id naming a missing gate is caught" sh -c "jq '.floor.hard += [\"ZZ-nope\"]' $M > x && mv x $M; sha256sum $M > $H"
mut "denominator_min 0 is caught" sh -c "jq '.gates[0].denominator_min = 0' $M > x && mv x $M; sha256sum $M > $H"
mut "bare npx in a command is caught" sh -c "jq '.gates[0].command = \"npx foo\"' $M > x && mv x $M; sha256sum $M > $H"
finish
