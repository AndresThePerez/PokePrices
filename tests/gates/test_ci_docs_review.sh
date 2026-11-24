#!/bin/sh
# CI-1-T01 review edges: the tests workflow's triggers and job guard, the make test recipe's shape, and
# D4's generalised rule against smuggled commands, reusable-workflow jobs, a missing Makefile, several
# .PHONY lines and README near-misses.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp; OUT=$(mktemp -d "$ROOT/.gates/tmp/cir-XXXXXX")
W=.github/workflows/tests.yml
SHA=d23441a48e516b6c34aea4fa41551a30e30af803

mk() {
  d=$(mktemp -d "$ROOT/.gates/tmp/cir-$1-XXXXXX"); mkdir -p "$d/.github/workflows"
  cp README.md Makefile "$d/"; cp "$W" "$d/$W"
  ( cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t )
  echo "$d"
}
commit() { ( cd "$1" && git add -A && git commit -qm x ); }
gate() { ( cd "$1" && GATES_OUT="$OUT" sh "$ROOT/scripts/gates/$2.sh" ) >/dev/null 2>&1; }
passes() { gate "$1" "$2"; }
fails() { ! gate "$1" "$2"; }
setrun() { sed "s@- run: make test\$@- run: $2@" "$1/$W" > "$1/$W.n" && mv "$1/$W.n" "$1/$W"; }

# --- the real workflow's semantics (what GitHub does with it) ---
wfpy() { python3 -I -c '
import sys, yaml
doc = yaml.safe_load(open(sys.argv[1], encoding="utf-8"))
on = doc.get("on", doc.get(True))
job = doc["jobs"]["tests"]
checks = {
  "triggers": on == {"pull_request": {"types": ["opened", "synchronize", "reopened", "ready_for_review"]}},
  "guard": job.get("if") == "github.event.pull_request.draft == false",
  "pin": job["steps"][0] == {"uses": "actions/checkout@" + sys.argv[2]},
  "run": job["steps"][1] == {"run": "make test"} and len(job["steps"]) == 2,
  "perm": doc.get("permissions") == {"contents": "read"},
  "conc": doc.get("concurrency") == {"group": "tests-${{ github.event.pull_request.number }}", "cancel-in-progress": True},
  "jobs": list(doc["jobs"]) == ["tests"],
  "timeout": isinstance(job.get("timeout-minutes"), int) and 0 < job["timeout-minutes"] <= 30,
}
bad = [k for k, v in checks.items() if not v]
sys.exit(1 if bad else 0)
' "$W" "$SHA"; }
check "workflow runs only on non-draft PR events: four types, no push, draft guard, pinned checkout, 30 min cap" wfpy
check "the workflow does not run on push (no push key anywhere in the file)" sh -c '! /usr/bin/grep -Eq "^[[:space:]]*(push|pull_request_target|workflow_dispatch|schedule):" "$0"' "$W"

# --- make test recipe shape (static; never runs make) ---
mkpy() { python3 -I -c '
import re, sys
lines = open("Makefile", encoding="utf-8").read().split("\n")
i = lines.index("test: init")
recipe = []
for l in lines[i + 1:]:
    if not l.startswith("\t"):
        break
    recipe.append(l[1:])
want = [
  "vendor/bin/pest -c phpunit.xml --parallel --processes=3",
  "./node_modules/.bin/vitest run --config vitest.config.ts",
  "./node_modules/.bin/tsc -b",
  "./node_modules/.bin/eslint -c eslint.config.js --max-warnings=0 .",
  "php artisan scramble:export --path=/workspace/.gates/make-test.api.json",
  "cmp -s openapi/api.json .gates/make-test.api.json",
  "./node_modules/.bin/openapi-typescript ../openapi/api.json -o /workspace/.gates/make-test.schema.d.ts",
  "cmp -s web/src/api/schema.d.ts .gates/make-test.schema.d.ts",
]
pre = ["$(DC) exec -T -w /workspace/api api ", "$(DC) exec -T -w /workspace/web node ", "$(DC) exec -T -w /workspace/web node ", "$(DC) exec -T -w /workspace/web node ",
       "$(DC) exec -T -w /workspace/api api ", "", "$(DC) exec -T -w /workspace/web node ", ""]
ok = len(recipe) == 8 and len(want) == 8 and len(pre) == 8 and all(r == p + w for r, p, w in zip(recipe, pre, want))
ok = ok and not any(re.match(r"[-@+]", r) or re.search(r"\|\||\||;|&&|\btee\b|\btrue\b", r) for r in recipe)
phony = set()
for l in lines:
    if l.startswith(".PHONY:"):
        phony.update(l.split()[1:])
sys.exit(0 if ok and "test" in phony else 1)
'; }
check "make test: eight lines in order (four direct-binary suites, the contract export and its cmp, the types generation and its cmp), -T exec, 3 pest workers, no failure-swallowing syntax, test is .PHONY" mkpy
check "make test depends on init (a fresh runner has no vendor, node_modules or api/.env)" /usr/bin/grep -qx 'test: init' Makefile
check "Makefile keeps the pipefail shell flags" /usr/bin/grep -qx '.SHELLFLAGS := -o pipefail -c' Makefile

# --- D4: commands smuggled past the generalised rule ---
for r in 'make test extra' 'make test FOO=1' 'make TEST' 'make  test' 'make test;' 'make test|tee x' 'bash -c make' 'make -C api test' 'make'; do
  d=$(mk d4); setrun "$d" "\"$r\""; commit "$d"
  check "D4 flags the run command [$r]" fails "$d" d4
done
d=$(mk d4); setrun "$d" 'make test # trailing yaml comment'; commit "$d"
check "D4 accepts make test with a YAML comment (the parsed command is still exactly make test)" passes "$d" d4
d=$(mk d4); setrun "$d" '"make test"'; commit "$d"
check "D4 accepts a quoted \"make test\" (same parsed command)" passes "$d" d4
d=$(mk d4); sed 's/- run: make test$/- run: make test\n        shell: bash/' "$W" > "$d/$W"; commit "$d"
check "D4 flags a run step carrying a shell: key" fails "$d" d4
d=$(mk d4); sed 's/- run: make test$/- run: make test\n        working-directory: api/' "$W" > "$d/$W"; commit "$d"
check "D4 flags a run step carrying working-directory:" fails "$d" d4
d=$(mk d4); sed 's/- run: make test$/- run: make test\n        env: {X: y}/' "$W" > "$d/$W"; commit "$d"
check "D4 flags a run step carrying env:" fails "$d" d4
d=$(mk d4); printf '  reuse:\n    uses: ./.github/workflows/other.yml\n' >> "$d/$W"; commit "$d"
check "D4 flags a second job that is a reusable-workflow call with no steps" fails "$d" d4
check "D4 records the stepless job as a job_steps miss (no checker crash)" jq -e -s 'any(.[]; .rule=="job_steps" and .job=="reuse") and all(.[]; .rule!="checker")' "$OUT/D4.details.jsonl"
d=$(mk d4); printf '  empty:\n    runs-on: ubuntu-24.04\n    steps: []\n' >> "$d/$W"; commit "$d"
check "D4 flags a second job with an empty steps list" fails "$d" d4
d=$(mk d4); printf '      - run: make test\n' >> "$d/$W"; commit "$d"
check "D4 flags a third step that repeats make test" fails "$d" d4
d=$(mk d4); sed -n '1,/^    steps:/p' "$W" > "$d/$W"; printf '      - run: make test\n      - uses: actions/checkout@%s\n' "$SHA" >> "$d/$W"; commit "$d"
check "D4 flags run before checkout (order matters)" fails "$d" d4
d=$(mk d4); sed "s|uses: actions/checkout@[^ ]*|uses: actions/checkout@v6|" "$W" > "$d/$W"; commit "$d"
check "D4 flags a tag-pinned checkout" fails "$d" d4
d=$(mk d4); sed 's|- uses: actions/checkout@\([0-9a-f]*\)|- uses: actions/cache@\1|' "$W" > "$d/$W"; commit "$d"
check "D4 flags a 40-hex pin on a first step that is not checkout" fails "$d" d4

# --- D4: Makefile handling ---
d=$(mk d4); git -C "$d" rm -q -f --cached Makefile 2>/dev/null; mv "$d/Makefile" "$d/Makefile.gone"; commit "$d"
check "D4 treats a missing Makefile as a miss" fails "$d" d4
check "D4 records a missing Makefile as a miss, not a checker crash" jq -e -s 'any(.[]; .rule=="makefile" or .rule=="job_steps") and all(.[]; .rule!="checker")' "$OUT/D4.details.jsonl"
d=$(mk d4); sed 's/^\.PHONY: .*/.PHONY: help up init gates verdict down/' Makefile > "$d/Makefile"; commit "$d"
check "D4 flags make test when test is not declared .PHONY" fails "$d" d4
d=$(mk d4); sed 's/^\.PHONY: .*/# .PHONY: test/' Makefile > "$d/Makefile"; printf '.PHONY: help\n' >> "$d/Makefile"; commit "$d"
check "D4 ignores a commented-out .PHONY line" fails "$d" d4
d=$(mk d4); sed 's/^\.PHONY: .*/.PHONY: help up init gates verdict down/' Makefile > "$d/Makefile"; printf '.PHONY:\ttest\n' >> "$d/Makefile"; commit "$d"
check "D4 reads a second .PHONY line that uses a tab separator" passes "$d" d4
d=$(mk d4); sed 's/^\.PHONY: .*/.PHONY: help up init gates verdict down/' Makefile > "$d/Makefile"; printf 'test:\n\t@true\n' >> "$d/Makefile"; commit "$d"
check "D4 does not accept a plain (non-.PHONY) rule named test" fails "$d" d4
d=$(mk d4); printf '  second:\n    runs-on: ubuntu-24.04\n    steps:\n      - uses: actions/checkout@%s\n      - run: make gates\n' "$SHA" >> "$d/$W"; commit "$d"
check "D4 accepts two jobs running two documented targets (test and gates)" passes "$d" d4
d=$(mk d4); printf '  second:\n    runs-on: ubuntu-24.04\n    steps:\n      - uses: actions/checkout@%s\n      - run: make gates\n' "$SHA" >> "$d/$W"; sed '/^make gates  /d' README.md > "$d/README.md"; commit "$d"
check "D4 flags a second job's target that the README block lacks (test documented, gates not)" fails "$d" d4
check "D4 names gates, not test, as the undocumented target" jq -e -s 'any(.[]; .rule=="readme" and .target=="gates") and all(.[]; .target!="test")' "$OUT/D4.details.jsonl"

# --- D4: README near-misses ---
d=$(mk d4); sed 's/^make test  /make test-all  /' README.md > "$d/README.md"; commit "$d"
check "D4 flags a README whose line is make test-all (prefix is not the target)" fails "$d" d4
d=$(mk d4); sed 's/^make test  /make test --dry-run  /' README.md > "$d/README.md"; commit "$d"
check "D4 flags a README whose line is make test with an extra flag (bare command required)" fails "$d" d4
d=$(mk d4); sed 's/^make test  /make testing  /' README.md > "$d/README.md"; commit "$d"
check "D4 flags a README whose line is make testing" fails "$d" d4

# --- D4: output contract ---
d=$(mk d4); commit "$d"
O3=$(mktemp -d "$ROOT/.gates/tmp/cir-o-XXXXXX"); ( cd "$d" && GATES_OUT="$O3" sh "$ROOT/scripts/gates/d4.sh" ) >/dev/null 2>&1
check "D4 on a clean scratch tree records value 0 and an integer denominator of at least 2" jq -e '.value==0 and (.denominator|type=="number") and .denominator>=2 and (.denominator==(.denominator|floor))' "$O3/D4.json"
d=$(mk d4); sed 's/- run: make test$/- run: make nosuch/' "$W" > "$d/$W"; commit "$d"
O4=$(mktemp -d "$ROOT/.gates/tmp/cir-o-XXXXXX"); ( cd "$d" && GATES_OUT="$O4" sh "$ROOT/scripts/gates/d4.sh" ) >/dev/null 2>&1
check "D4 value counts misses as a positive integer" jq -e '.value>=1 and (.value|floor)==.value' "$O4/D4.json"
check "D4 details is one JSON object per line" sh -c 'jq -e -c "type==\"object\"" "$0/D4.details.jsonl" >/dev/null && [ "$(wc -l < "$0/D4.details.jsonl")" -ge 1 ]' "$O4"
finish
