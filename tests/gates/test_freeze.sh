#!/bin/sh
# P0-i1-T36: the freeze covers every listed config and the whole gate set is green.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
check "frozen.sha256 tracked" git ls-files --error-unmatch gates/frozen.sha256
check "freeze list regenerates identically" sh -c 'python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | cmp -s - gates/frozen.sha256'
check "every frozen hash verifies" sh -c '/usr/bin/grep -v "#" gates/frozen.sha256 | sha256sum -c --status'
for p in Makefile scripts/scorecard scripts/verdict compose.dev.yml api/phpunit.xml api/phpstan.neon web/eslint.config.js web/vitest.config.ts web/tsconfig.app.json .spectral.yaml docker/nginx/pokeprices.conf gates/manifest-P0.json gates/ratchet.json gates/hygiene.patterns .github/workflows/gates.yml; do
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
