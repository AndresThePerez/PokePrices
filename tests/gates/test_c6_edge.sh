#!/bin/sh
# P0-i1-T23 review: edge cases for the C6 types gate and the generated SPA types.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
T=.gates/tmp/c6e; mkdir -p "$T/out2"
S=web/src/api/schema.d.ts
N="$DC exec -T -w /workspace/web node ./node_modules/.bin"

$N/openapi-typescript ../openapi/api.json -o "/workspace/$T/a.d.ts" >/dev/null 2>&1
$DC exec -T -e NO_COLOR=1 -e CI=1 -w /workspace/web node ./node_modules/.bin/openapi-typescript ../openapi/api.json -o "/workspace/$T/b.d.ts" >/dev/null 2>&1
check "two generations (one under NO_COLOR/CI) are byte-identical" cmp "$T/a.d.ts" "$T/b.d.ts"
check "fresh generation equals the committed file" cmp "$T/a.d.ts" "$S"
check "generated file has no timestamp, host or home path" sh -c "! /usr/bin/grep -Eq '/ho''me/|/workspace|[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:|localhost' $S"

check "exports paths, components and operations" sh -c "/usr/bin/grep -Eq '^export (interface|type) paths' $S && /usr/bin/grep -Eq '^export (interface|type) components' $S && /usr/bin/grep -Eq '^export (interface|type) operations' $S"
check "/ready is typed with the 200 status const ok" sh -c "/usr/bin/grep -q '\"/ready\"' $S && /usr/bin/grep -q 'status: \"ok\"' $S"
check "503 problem status is typed as a number" /usr/bin/grep -q 'status: number' $S

check "C6 fails when C6_TARGET is missing" sh -c '! env GATES_OUT="$0" C6_TARGET="$0/nope.d.ts" sh scripts/gates/c6.sh' "$T"
check "missing target is recorded as identical=false" sh -c 'jq -e "select(.check==\"identical\" and .ok==false)" "$0/C6.details.jsonl"' "$T"
: > "$T/empty.d.ts"
check "C6 fails on an empty target" sh -c '! env GATES_OUT="$0" C6_TARGET="$1" sh scripts/gates/c6.sh' "$T" "$T/empty.d.ts"
check "C6 refuses to run without GATES_OUT" sh -c '! env -u GATES_OUT sh scripts/gates/c6.sh'
sed 's/PokéPrices/PokePrices/' "$S" > "$T/flip.d.ts"
check "C6 fails when one character differs" sh -c '! env GATES_OUT="$0" C6_TARGET="$1" sh scripts/gates/c6.sh' "$T" "$T/flip.d.ts"

root=$PWD
rm -rf "$T/clone" 2>/dev/null
git clone -q . "$T/clone" 2>/dev/null
( cd "$T/clone" && git rm -q --cached "$S" ) >/dev/null 2>&1
check "C6 fails when the types file is untracked" sh -c 'cd "$0/$1/clone" && ! env GATES_OUT="$0/$1/out2" sh "$0/scripts/gates/c6.sh"' "$root" "$T"
check "untracked failure is recorded as tracked=false" sh -c 'jq -e "select(.check==\"tracked\" and .ok==false)" "$0/out2/C6.details.jsonl"' "$T"

check "overrides is exactly one openapi-typescript entry using the \$typescript reference" jq -e '.overrides=={"openapi-typescript":{"typescript":"$typescript"}}' web/package.json
check "prettierignore mentions the types file exactly once" sh -c '[ "$(/usr/bin/grep -c schema web/.prettierignore)" = 1 ]'
check "ESLint does not ignore the generated types" sh -c "$N/eslint -c eslint.config.js --print-config src/api/schema.d.ts | /usr/bin/grep -q linterOptions"
check "ESLint passes on the generated types with no warnings" $N/eslint -c eslint.config.js --max-warnings=0 src/api/schema.d.ts
check "tsc includes the generated types" sh -c "$N/tsc --listFilesOnly -p tsconfig.app.json | /usr/bin/grep -q schema.d.ts"
check "no eslint-disable or ts-ignore in the generated types" sh -c "! /usr/bin/grep -Eq 'eslint-disable|@ts-ignore|@ts-nocheck' $S"
finish
