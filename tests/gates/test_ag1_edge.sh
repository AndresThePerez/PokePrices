#!/bin/sh
# P0-i1-T30 reviewer edge cases for AG1: lock tampering after the anchor, subtree loss, symlinks,
# discovery quirks, repo-root and GATES_OUT contract. Negative checks call the real script (R96).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp
A="$ROOT/scripts/gates/ag1.py"
new() {
  d=$(mktemp -d "$ROOT/.gates/tmp/ag1e-XXXXXX")
  ( cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t
    mkdir -p gates api web .gates && printf '.gates/\n' > .gitignore
    cp "$ROOT/gates/manifest-P0.json" "$ROOT/gates/manifest-P0.approved.sha256" "$ROOT/gates/ratchet.json" gates/
    printf 'parameters:\n    level: 8\n' > api/phpstan.neon
    printf '{"compilerOptions":{"strict":true}}\n' > web/tsconfig.app.json
    printf '{"name":"x","scripts":{"build":"vite build"}}\n' > web/package.json
    printf 'gates:\n\tscripts/scorecard\n' > Makefile
    git add -A && git commit -qm "Base" )
  printf '%s\n' "$d"
}
lock() {
  ( cd "$1" && { sha256sum api/phpstan.neon web/tsconfig.app.json Makefile gates/manifest-P0.json gates/manifest-P0.approved.sha256 gates/ratchet.json
      printf '%s  web/package.json#.scripts\n' "$(jq -S .scripts web/package.json | sha256sum | cut -c1-64)"; } > gates/frozen.sha256 )
}
freeze() { lock "$1"; ( cd "$1" && git add -A && git commit -qm "Freeze" ); }
# fails <repo> <jq filter over AG1.json>: AG1 must exit non-zero AND leave evidence matching the filter.
fails_with() { sh -c 'cd "$0" && rm -f .gates/AG1.json && ! GATES_OUT=.gates python3 -I "$1" && jq -e "$2" .gates/AG1.json' "$1" "$A" "$2"; }
reason() { printf '[.details[] | select(.reason == "%s" and .violation)] | length >= 1' "$1"; }

d=$(new); freeze "$d"
( cd "$d" && sed -i 's/level: 8/level: 3/' api/phpstan.neon && lock "$d" && git add -A && git commit -qm "Loosen and re-bless the lock" )
check "re-blessing the lock after the anchor does not launder a looser config (F-P0-075)" fails_with "$d" "$(reason looser)"

d=$(new); freeze "$d"
( cd "$d" && git rm -q gates/frozen.sha256 && git commit -qm "Drop lock" )
check "deleting the lock after the anchor fails with lock_absent_at_head" fails_with "$d" "$(reason lock_absent_at_head)"
( cd "$d" && sed -i 's/level: 8/level: 1/' api/phpstan.neon && lock "$d" && sed -i '/^$/d' gates/frozen.sha256 && git add -A && git commit -qm "Re-add a weaker lock" )
check "re-adding a weaker lock keeps the first add as anchor and still fails" fails_with "$d" "$(reason looser)"

d=$(new); freeze "$d"
( cd "$d" && jq 'del(.scripts)' web/package.json > p && mv p web/package.json && git commit -qam "Lose subtree" )
check "a package.json that lost its frozen .scripts subtree fails" fails_with "$d" "$(reason looser)"

d=$(new); freeze "$d"
( cd "$d" && sed -i 's/level: 8/level: 9/' api/phpstan.neon && git commit -qam "Raise" && sed -i 's/level: 9/level: 2/' api/phpstan.neon )
check "an uncommitted loosening on top of a stricter commit fails" fails_with "$d" "$(reason looser)"

d=$(new); freeze "$d"
( cd "$d" && printf 'parameters:\n    level: 0\n' > real.neon && rm Makefile && ln -s real.neon Makefile && git add -A && git commit -qm "Symlink" )
check "a frozen file replaced by a symlink fails as missing_frozen_path" fails_with "$d" "$(reason missing_frozen_path)"

d=$(new); freeze "$d"; ln -s nowhere "$d/api/phpstan.neon.dist"
check "a dangling symlink at an alternate path fails" fails_with "$d" "$(reason alternate_present)"

d=$(new); freeze "$d"
( cd "$d" && printf '{}\n' > phpstan.neon && git add -A && git commit -qm "Root phpstan" )
check "a tracked root-level phpstan.neon is discovered (globstar zero directories)" fails_with "$d" "$(reason unlisted_config)"

d=$(new); freeze "$d"
( cd "$d" && mkdir -p web/node_modules_x web/src/vendored && printf '{}\n' > web/node_modules_x/tsconfig.json && printf '{}\n' > web/src/vendored/tsconfig.json && git add -A && git commit -qm "Lookalike dirs" )
check "node_modules and vendor lookalike directory names are not excluded" fails_with "$d" '[.details[] | select(.reason == "unlisted_config")] | length == 2'

d=$(new); freeze "$d"
( cd "$d" && mkdir -p web/node_modules/pkg api/vendor/x && printf '{}\n' > web/node_modules/pkg/tsconfig.json && printf '{}\n' > api/vendor/x/phpunit.xml && git add -f -A && git commit -qm "Vendored" )
check "real node_modules and vendor trees are excluded from discovery" sh -c 'cd "$0" && GATES_OUT=.gates python3 -I "$1"' "$d" "$A"

d=$(new); freeze "$d"
( cd "$d" && mkdir -p .github/workflows && printf 'on: push\n' > .github/workflows/ci.yml && git add -A && git commit -qm "Workflow" )
check "a tracked workflow outside the frozen list fails" fails_with "$d" "$(reason unlisted_config)"

d2=$(new); ( cd "$d2" && printf 'x\n' > untracked.cfg && lock "$d2" && printf '%s  untracked.cfg\n' "$(sha256sum untracked.cfg | cut -c1-64)" >> gates/frozen.sha256 && git add gates api web Makefile .gitignore && git commit -qm "Freeze with untracked target" )
check "a lock entry for an untracked file fails" fails_with "$d2" "$(reason anchor_unreadable)"

d=$(new); freeze "$d"
check "running from a subdirectory fails with not_repo_root" sh -c 'cd "$0/api" && mkdir -p ../.gates && ! GATES_OUT=../.gates python3 -I "$1" && jq -e "[.details[] | select(.reason == \"not_repo_root\")] | length == 1" ../.gates/AG1.json' "$d" "$A"
check "GATES_OUT unset exits 2 and writes no verdict" sh -c 'cd "$0" && rm -f .gates/AG1.json; env -u GATES_OUT python3 -I "$1"; [ $? -eq 2 ] && [ ! -e .gates/AG1.json ]' "$d" "$A"
check "GATES_OUT pointing at a missing directory exits 2" sh -c 'cd "$0" && GATES_OUT=.gates/nope python3 -I "$1"; [ $? -eq 2 ]' "$d" "$A"
check "a clean freeze: AG1.json has the contract schema, newline-terminated, denominator 7" sh -c 'cd "$0" && GATES_OUT=.gates python3 -I "$1" >.gates/stdout && jq -e ".id == \"AG1\" and .value == 0 and .denominator == 7 and (.details | type == \"array\")" .gates/AG1.json && [ -z "$(tail -c1 .gates/AG1.json)" ] && [ -z "$(tail -c1 .gates/stdout)" ]' "$d" "$A"

d=$(new); freeze "$d"
( cd "$d" && printf '%s  Makefile\n' "$(sha256sum Makefile | cut -c1-64)" >> gates/frozen.sha256 && git commit -qam "Duplicate line at HEAD" )
check "a duplicate line in the HEAD lock is reported" fails_with "$d" "$(reason duplicate_lock_entry)"
finish
