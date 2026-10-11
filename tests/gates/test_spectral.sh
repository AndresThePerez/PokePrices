#!/bin/sh
# P0-i1-T21: the PokéPrices ruleset rejects the bad fixture and accepts the committed contract.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
NODE="docker compose -f compose.dev.yml exec -T -w /workspace/web node"
T=.gates/tmp/spectral; mkdir -p "$T"
check "spectral-cli pinned" sh -c 'test "$(jq -r ".devDependencies[\"@stoplight/spectral-cli\"]" web/package.json)" = 6.17.0'
check "bad fixture fails the ruleset" sh -c '! '"$NODE"' ./node_modules/.bin/spectral lint ../tests/gates/fixtures/openapi-bad.json --ruleset ../.spectral.yaml --fail-severity=warn -f json -o /workspace/'"$T"'/bad.json'
for rule in pp-operation-id pp-operation-summary pp-error-response pp-error-problem-json pp-typed-2xx pp-closed-objects; do
  check "bad fixture trips $rule" jq -e --arg r "$rule" 'any(.[]; .code == $r)' "$T/bad.json"
done
check "committed contract passes" $NODE ./node_modules/.bin/spectral lint ../openapi/api.json --ruleset ../.spectral.yaml --fail-severity=warn
check "AG-values fully green" env GATES_OUT="$T" python3 -I scripts/gates/ag-values.py
finish
