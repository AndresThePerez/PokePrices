#!/bin/sh
# P0-i1-T26: the verdict self-test passes, and each fixture alone gives its expected outcome.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
T=.gates/tmp/verdict; mkdir -p "$T"
check "selftest passes" scripts/verdict --selftest gates/fixtures/verdict --out "$T/VER-selftest.json"
check "selftest counted at least 20 cases" jq -e '.denominator >= 20 and .value == 0' "$T/VER-selftest.json"
check "at least two cases expect DONE" sh -c 'test "$(cat gates/fixtures/verdict/*/expected.txt | /usr/bin/grep -cx DONE)" -ge 2'
mkdir -p "$T/broken"; cp -r gates/fixtures/verdict/done_happy "$T/broken/"; printf 'NOT_DONE\n' > "$T/broken/done_happy/expected.txt"
check "selftest fails when an expectation is wrong" sh -c '! scripts/verdict --selftest "$0/broken" --out "$0/b.json" && jq -e ".value == 1" "$0/b.json"' "$T"
mkdir -p "$T/none"; for c in open_high not_run; do cp -r "gates/fixtures/verdict/$c" "$T/none/"; done
check "selftest fails when no case expects DONE" sh -c '! scripts/verdict --selftest "$0/none" --out "$0/n.json"' "$T"
check "ratchet: level 8 to 7 is looser" sh -c 'printf "parameters:\n    level: 8\n" > "$0/a.neon"; printf "parameters:\n    level: 7\n" > "$0/b.neon"; test "$(python3 -I scripts/lib/ratchet.py compare --kind phpstan-neon "$0/a.neon" "$0/b.neon")" = looser' "$T"
check "ratchet: an added ignoreErrors entry is looser" sh -c 'printf "parameters:\n    level: 8\n" > "$0/a.neon"; printf "parameters:\n    level: 8\n    ignoreErrors:\n        - \"#x#\"\n" > "$0/b.neon"; test "$(python3 -I scripts/lib/ratchet.py compare --kind phpstan-neon "$0/a.neon" "$0/b.neon")" = looser' "$T"
check "ratchet: tsconfig strict true to false is looser" sh -c 'printf "{\"compilerOptions\":{\"strict\":true}}" > "$0/a.json"; printf "{\"compilerOptions\":{\"strict\":false}}" > "$0/b.json"; test "$(python3 -I scripts/lib/ratchet.py compare --kind tsconfig "$0/a.json" "$0/b.json")" = looser' "$T"
check "ratchet: tsconfig with comments and a new strict flag is stricter" sh -c 'printf "{\n // c\n \"compilerOptions\":{\"strict\":true,},}" > "$0/a.json"; printf "{\"compilerOptions\":{\"strict\":true,\"noUnusedLocals\":true}}" > "$0/b.json"; test "$(python3 -I scripts/lib/ratchet.py compare --kind tsconfig "$0/a.json" "$0/b.json")" = stricter' "$T"
check "ratchet: vitest lines 80 to 85 is stricter" sh -c 'printf "thresholds: { lines: 80, branches: 70 }\n" > "$0/a.ts"; printf "thresholds: { lines: 85, branches: 70 }\n" > "$0/b.ts"; test "$(python3 -I scripts/lib/ratchet.py compare --kind vitest-thresholds "$0/a.ts" "$0/b.ts")" = stricter' "$T"
check "ratchet: vitest include change is undecidable" sh -c 'printf "include: [\"src/**\"], thresholds: { lines: 80 }\n" > "$0/a.ts"; printf "include: [\"src/lib/**\"], thresholds: { lines: 80 }\n" > "$0/b.ts"; test "$(python3 -I scripts/lib/ratchet.py compare --kind vitest-thresholds "$0/a.ts" "$0/b.ts")" = undecidable' "$T"
check "ratchet: hash kind never says stricter" sh -c 'printf a > "$0/a.txt"; printf b > "$0/b.txt"; test "$(python3 -I scripts/lib/ratchet.py compare --kind hash "$0/a.txt" "$0/b.txt")" = looser' "$T"
check "ratchet: manifest kind names phpstan-params and vitest match their plan aliases" sh -c 'printf "parameters:\n    level: 8\n" > "$0/a.neon"; printf "parameters:\n    level: 9\n" > "$0/b.neon"; printf "thresholds: { lines: 80 }\n" > "$0/a.ts"; printf "thresholds: { lines: 70 }\n" > "$0/b.ts"; test "$(python3 -I scripts/lib/ratchet.py compare --kind phpstan-params "$0/a.neon" "$0/b.neon")" = stricter && test "$(python3 -I scripts/lib/ratchet.py compare --kind phpstan-neon "$0/a.neon" "$0/b.neon")" = stricter && test "$(python3 -I scripts/lib/ratchet.py compare --kind vitest "$0/a.ts" "$0/b.ts")" = looser && test "$(python3 -I scripts/lib/ratchet.py compare --kind vitest-thresholds "$0/a.ts" "$0/b.ts")" = looser' "$T"
check "gates-only mode never needs findings" sh -c 'scripts/verdict --help | /usr/bin/grep -q -- --gates-only'
finish
