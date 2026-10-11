#!/bin/sh
# P0-i1-T33: CI is checkout plus make gates; README mirrors it; S7 and D4 catch drift.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp; OUT=$(mktemp -d "$ROOT/.gates/tmp/ci-XXXXXX")
W=.github/workflows/gates.yml
check "workflow parses" python3 -I -c 'import sys, yaml; yaml.safe_load(open(sys.argv[1]))' "$W"
check "steps are exactly checkout plus make gates" python3 -I -c '
import sys, yaml
w = yaml.safe_load(open(sys.argv[1]))
jobs = list(w["jobs"].values())
ok = len(jobs) == 1
steps = jobs[0]["steps"]
ok = ok and len(steps) == 2 and steps[0]["uses"].startswith("actions/checkout@") and len(steps[0]["uses"].split("@")[1]) == 40 and steps[1] == {"run": "make gates"}
raise SystemExit(0 if ok else 1)' "$W"
check "README names PokéPrices with the accent" /usr/bin/grep -q 'PokéPrices' README.md
check "README has exactly one Development section" sh -c 'test "$(/usr/bin/grep -c "^## Development$" README.md)" -eq 1'
check "README reserves the SPA auth prefix" /usr/bin/grep -q '/account/' README.md
check "README makes no real-time claim" sh -c '! /usr/bin/grep -qiE "real-time|realtime|live prices" README.md'
check "S7 passes" env GATES_OUT="$OUT" sh scripts/gates/s7.sh
check "D4 passes" env GATES_OUT="$OUT" sh scripts/gates/d4.sh
d=$(mktemp -d "$ROOT/.gates/tmp/cis-XXXXXX"); mkdir -p "$d/.github/workflows"
cp README.md "$d/"; sed 's/actions\/checkout@[0-9a-f]*/actions\/checkout@v6/' "$W" > "$d/$W"
( cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t && git add -A && git commit -qm s )
check "S7 catches a tag-pinned action" sh -c 'cd "$0" && ! env GATES_OUT="$1" sh "$2/scripts/gates/s7.sh"' "$d" "$OUT" "$ROOT"
cp "$W" "$d/$W"; printf '      - run: npm test\n' >> "$d/$W"; ( cd "$d" && git commit -qam extra )
check "D4 catches an extra CI step" sh -c 'cd "$0" && ! env GATES_OUT="$1" sh "$2/scripts/gates/d4.sh"' "$d" "$OUT" "$ROOT"
finish
