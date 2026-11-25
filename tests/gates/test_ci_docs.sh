#!/bin/sh
# P0-i1-T33 (R306): CI is checkout plus make test; README mirrors it; S7 and D4 catch drift.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp; OUT=$(mktemp -d "$ROOT/.gates/tmp/ci-XXXXXX")
W=.github/workflows/tests.yml
check "workflow parses" python3 -I -c 'import sys, yaml; yaml.safe_load(open(sys.argv[1]))' "$W"
check "steps are exactly checkout plus make test" python3 -I -c '
import sys, yaml
w = yaml.safe_load(open(sys.argv[1]))
jobs = list(w["jobs"].values())
ok = len(jobs) == 1
steps = jobs[0]["steps"]
ok = ok and len(steps) == 2 and steps[0]["uses"].startswith("actions/checkout@") and len(steps[0]["uses"].split("@")[1]) == 40 and steps[1] == {"run": "make test"}
raise SystemExit(0 if ok else 1)' "$W"
check "README names PokéPrices with the accent" /usr/bin/grep -q 'PokéPrices' README.md
check "README has exactly one Development section" sh -c 'test "$(/usr/bin/grep -c "^## Development$" README.md)" -eq 1'
check "README reserves the SPA auth prefix" /usr/bin/grep -q '/account/' README.md
check "README makes no real-time claim" sh -c '! /usr/bin/grep -qiE "real-time|realtime|live prices" README.md'
check "README says make test compares openapi/api.json and the generated types with fresh copies" /usr/bin/grep -Eq '^make test  .*openapi/api[.]json.*web/src/api/schema[.]d[.]ts' README.md
# The README's opening: what it is, the live link, the demo, the pictures, the stack, the map of the parts.
check "README gives the live link in its first twelve lines" sh -c 'sed -n 1,12p README.md | /usr/bin/grep -qF "https://pokeprices.andrestheperez.com"'
check "README keeps the non-commercial statement near the top, word for word" sh -c 'sed -n 1,30p README.md | /usr/bin/grep -qF "PokéPrices is a personal portfolio project, not a business. It has no ads, no affiliate links and no paid features, and it sells no data. It exists to show how I build and run software."'
check "README keeps the See it working demo sentence, word for word" /usr/bin/grep -qF 'The home page also shows a sample of the demo'"'"'s collection under "See it working" with no sign-in: its portfolio total, three watched cards and an alert.' README.md
check "README orders its opening: link, non-commercial, demo, pictures, stack, Mermaid map, then Development" sh -c '
  n() { /usr/bin/grep -n -m1 -F -e "$1" README.md | cut -d: -f1; }
  l=$(n "https://pokeprices.andrestheperez.com"); c=$(n "is a personal portfolio project"); d=$(n "The home page also shows a sample"); i=$(n "](docs/media/"); s=$(n "## What it is built with"); m=$(n "$(printf "\\140\\140\\140mermaid")"); v=$(n "## Development")
  for x in "$l" "$c" "$d" "$i" "$s" "$m" "$v"; do test -n "$x" || exit 1; done
  test "$l" -lt "$c" && test "$c" -lt "$d" && test "$d" -lt "$i" && test "$i" -lt "$s" && test "$s" -lt "$m" && test "$m" -lt "$v"'
check "README holds four to six pictures, each with alt text and an existing path" sh -c '
  n=$(/usr/bin/grep -oE "!\[[^]]+\]\(docs/media/[^)]+\)" README.md | wc -l)
  test "$n" -ge 4 && test "$n" -le 6 || exit 1
  test "$(/usr/bin/grep -c "!\[\]" README.md)" -eq 0 || exit 1
  /usr/bin/grep -oE "!\[[^]]+\]\(docs/media/[^)]+\)" README.md | sed "s/.*(\(.*\))/\1/" | while read -r f; do test -s "$f" || exit 1; done'
check "README uses every picture in docs/media and each is under 400 KB" sh -c '
  for f in docs/media/*; do /usr/bin/grep -qF "($f)" README.md || exit 1; test "$(wc -c < "$f")" -le 409600 || exit 1; done'
check "README has exactly one Mermaid flowchart with quoted labels" sh -c '
  test "$(/usr/bin/grep -c "^\`\`\`mermaid$" README.md)" -eq 1 || exit 1
  b=$(awk "/^\`\`\`mermaid$/{f=1;next} /^\`\`\`$/{f=0} f" README.md)
  printf "%s\n" "$b" | sed -n 1p | /usr/bin/grep -qE "^flowchart (LR|TD)$" || exit 1
  ! printf "%s\n" "$b" | /usr/bin/grep -qE "\[[^\"]|\{|\(\(|subgraph" || exit 1
  for w in collection "product map" ingest rollup API queue cache scheduler catalogue; do printf "%s\n" "$b" | /usr/bin/grep -qi "$w" || exit 1; done'
check "README stack list names the versions in the manifests" sh -c '
  v() { sed -n "s/.*\"$1\": \"\\^\\{0,1\\}\\([0-9][0-9.]*\\)\".*/\\1/p" web/package.json | head -1; }
  for p in react vite tailwindcss typescript vitest; do /usr/bin/grep -qF "$(v $p)" README.md || exit 1; done
  /usr/bin/grep -qF "Laravel 13" README.md && /usr/bin/grep -qF "PHP 8.5" README.md && /usr/bin/grep -qF "PostgreSQL 17" README.md && /usr/bin/grep -qF "Redis 8" README.md'
check "README says nothing of loop steps, phase verdicts or freeze records unexplained" sh -c '! /usr/bin/grep -qiE "loop step|phase verdict|freeze record" README.md'
check "README has a stack heading, a map heading and an In this repository paragraph that does not repeat the opening" sh -c '
  /usr/bin/grep -qx "## What it is built with" README.md && /usr/bin/grep -qx "## How a price gets to the page" README.md || exit 1
  ! sed -n "/^## In this repository$/,/^## /p" README.md | /usr/bin/grep -qiE "daily-close Pokémon card prices|intraday|every.*price.*source"'
check "every relative link in README resolves to a path" sh -c '
  /usr/bin/grep -oE "\]\((docs|api|web|openapi|gates|scripts)/[^)#]*" README.md | sed "s/^](//" | while read -r f; do test -e "$f" || exit 1; done'
# The README's engineering section: eight problems with their evidence, then the decisions and what comes next.
check "README has the engineering section, then its decisions list, then its next-steps list, before Development" sh -c '
  n() { /usr/bin/grep -n -m1 -x -F -e "$1" README.md | cut -d: -f1; }
  e=$(n "## Problems and how I solved them"); d=$(n "### Decisions and trade-offs"); w=$(n "### What I would do next"); v=$(n "## Development")
  for x in "$e" "$d" "$w" "$v"; do test -n "$x" || exit 1; done
  test "$e" -lt "$d" && test "$d" -lt "$w" && test "$w" -lt "$v"'
check "each of the engineering section's eight problems names a path that exists" python3 -I -c '
import os, re, sys
t = open("README.md", encoding="utf-8").read()
m = re.search(r"^## Problems and how I solved them\n(.*?)^### Decisions and trade-offs$", t, re.S | re.M)
if not m:
    sys.exit(1)
items = re.split(r"^(?=[0-9]+\. )", m.group(1), flags=re.M)[1:]
if len(items) != 8:
    sys.exit(1)
for n, it in enumerate(items, 1):
    if not it.startswith("%d. **" % n):
        sys.exit(1)
    paths = [p.split("::")[0] for p in re.findall(r"`([A-Za-z0-9_./-]+(?:::[^`]*)?)`", it)]
    if not any("/" in p and os.path.exists(p) for p in paths):
        sys.exit(1)'
check "every relative link in the engineering section resolves to a file, and its anchor to a heading" python3 -I -c '
import os, re, sys
t = open("README.md", encoding="utf-8").read()
m = re.search(r"^## Problems and how I solved them\n(.*?)^## ", t, re.S | re.M)
if not m:
    sys.exit(1)
def slug(h):
    return re.sub(r"[^a-z0-9 _-]", "", h.strip().lower()).replace(" ", "-")
links = re.findall(r"\]\(([^)]+)\)", m.group(1))
if not links:
    sys.exit(1)
for l in links:
    if l.startswith("http"):
        continue
    f, _, a = l.partition("#")
    if not os.path.isfile(f):
        sys.exit(1)
    if a:
        heads = [slug(x) for x in re.findall(r"^#+ (.*)$", open(f, encoding="utf-8").read(), re.M)]
        if a not in heads:
            sys.exit(1)'
check "the decisions list links at least three records and every docs/adr path it names exists" python3 -I -c '
import os, re, sys
t = open("README.md", encoding="utf-8").read()
m = re.search(r"^### Decisions and trade-offs\n(.*?)^### ", t, re.S | re.M)
if not m:
    sys.exit(1)
named = re.findall(r"docs/adr/[A-Za-z0-9_./-]*[A-Za-z0-9]", m.group(1))
linked = set(re.findall(r"\]\((docs/adr/[^)#]+)", m.group(1)))
if len(linked) < 3 or not set(named) <= linked:
    sys.exit(1)
sys.exit(0 if all(os.path.isfile(p) for p in linked) else 1)'
check "the engineering section is at most 124 lines, shows no full-tier command and names no planning id" python3 -I -c '
import re, sys
t = open("README.md", encoding="utf-8").read()
m = re.search(r"^(## Problems and how I solved them\n.*?)^## ", t, re.S | re.M)
if not m or m.group(1).count("\n") > 124:
    sys.exit(1)
fences = re.findall(r"```[a-z]*\n(.*?)```", m.group(1), re.S)
lines = [l for f in fences for l in f.splitlines()]
if any("make gates" in l or ("tests/p0/run.sh" in l and "TIER=fast" not in l) for l in lines):
    sys.exit(1)
sys.exit(1 if re.search(r"\bPD-i[0-9]|\bP[0-9]-i[0-9]|\bT[0-9]{2}\b|\bR[0-9]{2,3}\b", t) else 0)'
check "S7 passes" env GATES_OUT="$OUT" sh scripts/gates/s7.sh
check "D4 passes" env GATES_OUT="$OUT" sh scripts/gates/d4.sh
d=$(mktemp -d "$ROOT/.gates/tmp/cis-XXXXXX"); mkdir -p "$d/.github/workflows"
cp README.md Makefile "$d/"; sed 's/actions\/checkout@[0-9a-f]*/actions\/checkout@v6/' "$W" > "$d/$W"
( cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t && git add -A && git commit -qm s )
check "S7 catches a tag-pinned action" sh -c 'cd "$0" && ! env GATES_OUT="$1" sh "$2/scripts/gates/s7.sh"' "$d" "$OUT" "$ROOT"
cp "$W" "$d/$W"; printf '      - run: npm test\n' >> "$d/$W"; ( cd "$d" && git commit -qam extra )
check "D4 catches an extra CI step" sh -c 'cd "$0" && ! env GATES_OUT="$1" sh "$2/scripts/gates/d4.sh"' "$d" "$OUT" "$ROOT"
finish
