#!/bin/sh
# Drift checks in make test, review edges: the recipe pin of test_ci_docs_review.sh must refuse a recipe
# whose contract or types comparison is removed, reordered, ignored, swallowed or pointed at another file,
# and each comparison must read the file the line before it writes. Static: never runs make or a container.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp; OUT=$(mktemp -d "$ROOT/.gates/tmp/cid-XXXXXX")
T=$(printf '\t')

# The pin itself: the mkpy function of test_ci_docs_review.sh, taken as written, so these cases follow it.
PIN=$(sed -n "/^mkpy() {/,/^'; }\$/p" tests/gates/test_ci_docs_review.sh)
eval "$PIN"
# pin_in DIR: run the pin against DIR/Makefile.
pin_in() { ( cd "$1" && mkpy ); }
bites() { ! pin_in "$1"; }
# mutant NAME: a scratch directory for one changed Makefile; prints its path.
mutant() { d="$OUT/$1"; mkdir -p "$d"; echo "$d"; }

d=$(mutant same); cp Makefile "$d/Makefile"
check "the pin accepts an unchanged copy of the Makefile" pin_in "$d"

d=$(mutant removed); sed "/^${T}cmp -s openapi\/api[.]json /d" Makefile > "$d/Makefile"
check "the pin refuses a recipe without the contract comparison" bites "$d"

d=$(mutant swapped); awk -v t="$T" '
  $0 == t "cmp -s openapi/api.json .gates/make-test.api.json" { print t "cmp -s web/src/api/schema.d.ts .gates/make-test.schema.d.ts"; next }
  $0 == t "cmp -s web/src/api/schema.d.ts .gates/make-test.schema.d.ts" { print t "cmp -s openapi/api.json .gates/make-test.api.json"; next }
  { print }' Makefile > "$d/Makefile"
check "the pin refuses the two comparisons in the other order (each would read a file not yet written)" bites "$d"

d=$(mutant dash); sed "s/^${T}cmp -s openapi/${T}-cmp -s openapi/" Makefile > "$d/Makefile"
check "the pin refuses a contract comparison whose failure make ignores (leading -)" bites "$d"

d=$(mutant swallowed); sed "s/^${T}\(cmp -s web.*\)\$/${T}\1 || true/" Makefile > "$d/Makefile"
check "the pin refuses a types comparison followed by || true" bites "$d"

d=$(mutant stale); sed 's#--path=/workspace/[.]gates/make-test[.]api[.]json#--path=/workspace/.gates/make-test.api2.json#' Makefile > "$d/Makefile"
check "the pin refuses an export written to a file the comparison does not read (a stale file would pass)" bites "$d"

# The real recipe: each cmp compares a tracked file with the file the line before it has just written,
# seen from the host through the service's ./:/workspace mount, under .gates/ (never tracked).
pairs() { python3 -I -c '
import subprocess, sys, yaml
lines = open("Makefile", encoding="utf-8").read().split("\n")
recipe = []
for l in lines[lines.index("test: init") + 1:]:
    if not l.startswith("\t"):
        break
    recipe.append(l[1:])
services = yaml.safe_load(open("compose.dev.yml", encoding="utf-8"))["services"]
tracked = set(subprocess.run(["git", "ls-files"], capture_output=True, text=True, check=True).stdout.split("\n"))
cmps = [n for n, r in enumerate(recipe) if r.split()[0] == "cmp"]
ok = len(cmps) == 2 and all(n > 0 for n in cmps)
fresh_files = []
for n in cmps if ok else []:
    committed, fresh = recipe[n].split()[-2:]
    w = recipe[n - 1].split()
    service = w[w.index("-w") + 2]
    out = [a.split("=", 1)[1] for a in w if a.startswith("--path=")] or [w[w.index("-o") + 1]]
    ok = ok and out == ["/workspace/" + fresh] and fresh.startswith(".gates/make-test.")
    ok = ok and "./:/workspace" in services[service]["volumes"]
    ok = ok and committed in tracked and fresh not in tracked
    fresh_files.append(fresh)
ok = ok and len(set(fresh_files)) == 2
ignored = bool(fresh_files) and all(subprocess.run(["git", "check-ignore", "-q", f]).returncode == 0 for f in fresh_files)
sys.exit(0 if ok and ignored else 1)
'; }
check "each comparison reads the file the line before it writes, through the mount, and that file is git-ignored" pairs
finish
