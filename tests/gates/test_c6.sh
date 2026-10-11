#!/bin/sh
# P0-i1-T23: C6 passes on the committed types and catches drift.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
T=.gates/tmp/c6; mkdir -p "$T"
check "openapi-typescript pinned" sh -c 'test "$(jq -r ".devDependencies[\"openapi-typescript\"]" web/package.json)" = 7.13.0'
check "typescript peer resolved through a frozen override" jq -e '.overrides["openapi-typescript"].typescript == "$typescript"' web/package.json
check "C6 passes on the committed types" env GATES_OUT="$T" sh scripts/gates/c6.sh
check "C6 counted at least one path" jq -e '.denominator >= 1' "$T/C6.json"
cp web/src/api/schema.d.ts "$T/drift.d.ts"; printf '\n' >> "$T/drift.d.ts"
check "C6 fails on drift" sh -c '! env GATES_OUT="$0" C6_TARGET="$0/drift.d.ts" sh scripts/gates/c6.sh' "$T"
check "generated types keep the raw é" /usr/bin/grep -q 'PokéPrices' web/src/api/schema.d.ts
finish
