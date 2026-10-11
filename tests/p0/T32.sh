#!/bin/sh
# P0-i1-T32: .mex exists, stays untracked, meets its content floor, passes mex check and hygiene.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
mkdir -p .gates/tmp; T=.gates/tmp/T32; mkdir -p "$T"
check "EC-mex passes" env GATES_OUT="$T" PP_RUN_CONTEXT=primary sh scripts/gates/ec-mex.sh
check "EC-mex counted at least 3 scaffold files" jq -e '.denominator >= 3 and .value == 0' "$T/EC-mex.json"
check "EC-mex recorded a sha256 per scaffold file" jq -e '[.details[] | select(.sha256 != null)] | length >= 3' "$T/EC-mex.json"
check "nothing under .mex is tracked" sh -c 'test -z "$(git ls-files .mex)"'
check "tree is clean with .mex present" sh -c 'test -z "$(git status --porcelain --untracked-files=all)"'
mv .mex/ROUTER.md "$T/ROUTER.md.bak"
rm -f "$T/EC-mex.json"
check "EC-mex fails without ROUTER.md" sh -c '! env GATES_OUT="$0" PP_RUN_CONTEXT=primary sh scripts/gates/ec-mex.sh' "$T"
# Stricter than the plan (R96, R97): the failure must come from the gate itself, with its evidence.
check "EC-mex without ROUTER.md wrote value >= 1" jq -e '.value >= 1' "$T/EC-mex.json"
mv "$T/ROUTER.md.bak" .mex/ROUTER.md
cp .mex/ROUTER.md "$T/ROUTER.md.keep"
/usr/bin/grep -v '8095' "$T/ROUTER.md.keep" > .mex/ROUTER.md
rm -f "$T/EC-mex.json"
check "EC-mex fails when ROUTER.md drops the port" sh -c '! env GATES_OUT="$0" PP_RUN_CONTEXT=primary sh scripts/gates/ec-mex.sh' "$T"
check "EC-mex without the port wrote value >= 1" jq -e '.value >= 1' "$T/EC-mex.json"
cp "$T/ROUTER.md.keep" .mex/ROUTER.md
check "EC-mex passes again after restore" env GATES_OUT="$T" PP_RUN_CONTEXT=primary sh scripts/gates/ec-mex.sh
check "EC-mex after restore wrote value 0" jq -e '.value == 0' "$T/EC-mex.json"
# Called directly in a non-primary context, the gate reports value 1 with reason not_primary.
rm -f "$T/EC-mex.json"
check "EC-mex fails in a nested context" sh -c '! env GATES_OUT="$0" PP_RUN_CONTEXT=nested sh scripts/gates/ec-mex.sh' "$T"
check "EC-mex nested context reports value 1, not_primary" \
  jq -e '.value == 1 and ([.details[] | select(.reason == "not_primary")] | length == 1)' "$T/EC-mex.json"
finish
