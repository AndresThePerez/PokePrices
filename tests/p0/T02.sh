#!/bin/sh
# P0-i1-T02: the approved manifest is tracked, byte-identical and hash-recorded.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
WS=.superpowers/sdd/P0-i1
for f in gates/manifest-P0.json gates/manifest-P0.md gates/manifest-P0.approved.sha256; do
  check "tracked: $f" git ls-files --error-unmatch "$f"
done
if [ -f "$WS/manifest-P0.json" ]; then
  check "JSON byte-identical to the approved workspace copy" cmp gates/manifest-P0.json "$WS/manifest-P0.json"
  check "markdown byte-identical to the approved workspace copy" cmp gates/manifest-P0.md "$WS/manifest-P0.md"
fi
check "approved file is one sha256sum line" grep -Eqx '[0-9a-f]{64}  gates/manifest-P0.json' gates/manifest-P0.approved.sha256
check "approved hash verifies" sha256sum -c --status gates/manifest-P0.approved.sha256
check_out "34 hard gates" 34 jq '[.gates[] | select(.hard)] | length' gates/manifest-P0.json
check_out "5 soft gates" 5 jq '[.gates[] | select(.hard | not)] | length' gates/manifest-P0.json
check "floor ids all present" jq -e '((.floor.hard + .floor.soft) - [.gates[].id]) == []' gates/manifest-P0.json
check_out "repos names only pokeprices" '["pokeprices"]' jq -c .repos gates/manifest-P0.json
check "every gate has denominator_min >= 1" jq -e 'all(.gates[]; .denominator_min >= 1)' gates/manifest-P0.json
check "no gate command uses npx" sh -c '! jq -r ".gates[].command" gates/manifest-P0.json | /usr/bin/grep -qw npx'
finish
