#!/bin/sh
# P0-i1-T33 edge cases: S7 and D4 must catch CI and README drift and not over-fire.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp; OUT=$(mktemp -d "$ROOT/.gates/tmp/cie-XXXXXX")
W=.github/workflows/tests.yml
SHA=d23441a48e516b6c34aea4fa41551a30e30af803

# mk NAME: scratch git repo holding the real README, Makefile and workflow; prints its path.
mk() {
  d=$(mktemp -d "$ROOT/.gates/tmp/cie-$1-XXXXXX"); mkdir -p "$d/.github/workflows"
  cp README.md Makefile "$d/"; cp "$W" "$d/$W"
  ( cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t )
  echo "$d"
}
commit() { ( cd "$1" && git add -A && git commit -qm x ); }
gate() { ( cd "$1" && GATES_OUT="$OUT" sh "$ROOT/scripts/gates/$2.sh" ) >/dev/null 2>&1; }
passes() { gate "$1" "$2"; }
fails() { ! gate "$1" "$2"; }
setuses() { sed "s|actions/checkout@[^ ]*|$2|" "$1/$W" > "$1/$W.n" && mv "$1/$W.n" "$1/$W"; }

# --- S7 ---
for v in "${SHA%?}" "${SHA}a" "$(printf %s "$SHA" | tr a-f A-F)" "v6"; do
  d=$(mk s7); setuses "$d" "actions/checkout@$v"; commit "$d"
  check "S7 flags checkout pinned to [$v]" fails "$d" s7
done
d=$(mk s7); setuses "$d" "./local-action"; commit "$d"
check "S7 accepts a first-party ./local-action path" passes "$d" s7
d=$(mk s7); printf '      - uses: foo/bar@main # tag\n' >> "$d/$W"; commit "$d"
check "S7 flags an unpinned uses with a trailing comment" fails "$d" s7
d=$(mk s7); printf '      - uses: foo/bar@%s # pinned\n' "$SHA" >> "$d/$W"; commit "$d"
check "S7 accepts a 40-hex pin with a trailing comment" passes "$d" s7
d=$(mk s7); printf 'jobs:\n  x:\n    steps:\n      - uses: foo/bar@v1\n' > "$d/.github/workflows/other.yaml"; commit "$d"
check "S7 flags a tag pin in a second workflow file (.yaml)" fails "$d" s7
d=$(mk s7); commit "$d"; ( cd "$d" && git rm -q -r .github && git commit -qm none )
check "S7 fails with zero workflow files" fails "$d" s7
O2=$(mktemp -d "$ROOT/.gates/tmp/cie-o-XXXXXX"); ( cd "$d" && GATES_OUT="$O2" sh "$ROOT/scripts/gates/s7.sh" ) >/dev/null 2>&1
check "S7 zero files records value 1, denominator 0" jq -e '.value==1 and .denominator==0' "$O2/S7.json"

# --- D4 ---
d=$(mk d4); printf '  second:\n    runs-on: ubuntu-24.04\n    steps:\n      - run: echo hi\n' >> "$d/$W"; commit "$d"
check "D4 flags a second job with other steps" fails "$d" d4
d=$(mk d4); printf '  second:\n    runs-on: ubuntu-24.04\n    steps:\n      - uses: actions/checkout@%s\n      - run: make test\n' "$SHA" >> "$d/$W"; commit "$d"
check "D4 accepts a second job that is also checkout plus make test" passes "$d" d4
d=$(mk d4); sed 's/- run: make test$/- run: make test \&\& echo ok/' "$W" > "$d/$W"; commit "$d"
check "D4 flags run: make test && echo ok" fails "$d" d4
d=$(mk d4); sed '/- run: make test/d' "$W" > "$d/$W"; commit "$d"
check "D4 flags a checkout-only steps list" fails "$d" d4
d=$(mk d4); sed 's/- run: make test$/- run: make test\n        if: always()/' "$W" > "$d/$W"; commit "$d"
check "D4 flags a conditional make test step" fails "$d" d4
d=$(mk d4); sed 's/^on:/true:/' "$W" > "$d/$W"; commit "$d"
check "D4 survives the YAML on-key quirk (no checker crash on a boolean key)" passes "$d" d4
d=$(mk d4); printf '\n## Development\n\n```sh\nmake test\n```\n' >> "$d/README.md"; commit "$d"
check "D4 flags a README with two Development sections" fails "$d" d4
d=$(mk d4); sed 's/^make test  /# make test  /' README.md > "$d/README.md"; commit "$d"
check "D4 flags a README whose make test line is commented out" fails "$d" d4
d=$(mk d4); sed '/^make test  /d' README.md > "$d/README.md"; commit "$d"
check "D4 flags a README Development block without make test" fails "$d" d4
d=$(mk d4); rm "$d/README.md"; commit "$d"
check "D4 flags a missing README" fails "$d" d4
d=$(mk d4); printf 'jobs: [\n' > "$d/$W"; commit "$d"
check "D4 flags an unparsable workflow" fails "$d" d4
d=$(mk d4); commit "$d"; ( cd "$d" && git rm -q -r .github && git commit -qm none )
check "D4 fails with zero workflow files" fails "$d" d4
d=$(mk d4); sed 's/^permissions:/permissions: write-all\nxpermissions:/' "$W" > "$d/$W"; commit "$d"
check "D4 still passes when only top-level permissions change (not its remit)" passes "$d" d4
d=$(mk d4); sed 's/- run: make test$/- run: make nosuchtarget/' "$W" > "$d/$W"; commit "$d"
check "D4 flags run: make nosuchtarget (not a .PHONY target in the Makefile)" fails "$d" d4
check "D4 records the undeclared target nosuchtarget in its job_steps miss" jq -e -s 'any(.[]; .rule=="job_steps" and .target=="nosuchtarget")' "$OUT/D4.details.jsonl"
d=$(mk d4); commit "$d"
check "the real workflow's job carries a job-level if:" /usr/bin/grep -qx '    if: github.event.pull_request.draft == false' "$d/$W"
check "D4 accepts a job-level if: on the real workflow (not a step)" passes "$d" d4
d=$(mk d4); sed 's/- run: make test$/- run: make gates/' "$W" > "$d/$W"; sed '/^make gates  /d' README.md > "$d/README.md"; commit "$d"
check "D4 flags a workflow running make gates when the README block lacks it" fails "$d" d4
check "D4 records the README miss for target gates" jq -e -s 'any(.[]; .rule=="readme" and .target=="gates")' "$OUT/D4.details.jsonl"

# --- README content rules (real file) ---
check "README mentions only port 8095" sh -c '! /usr/bin/grep -oE "localhost:[0-9]+" README.md | /usr/bin/grep -qv ":8095$"'
for p in '/v1/\*' '/up' '/sanctum/\*' '/login' '/logout' '/register' '/forgot-password' '/reset-password' '/email/\*' '/user/\*' '/two-factor-challenge'; do
  check "README reserves Laravel prefix $p" /usr/bin/grep -q "\`$p\`" README.md
done
HP=$(printf '/ho%s/|/Us%s/' me ers)
check "README has no home path, LAN address or tailnet address" sh -c '! /usr/bin/grep -qE "$0|192[.]168[.]|ts[.]net" README.md' "$HP"
finish
