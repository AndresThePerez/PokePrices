#!/bin/sh
# P0-i1-T30: AG1 approval, anchor, ratchet and discovery behaviour.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp; OUT=$(mktemp -d "$ROOT/.gates/tmp/ag1-XXXXXX"); O=${OUT#"$ROOT/"}
ag1() { ( cd "$1" && GATES_OUT="$2" python3 -I "$ROOT/scripts/gates/ag1.py" ); }
# A shell function is not visible inside sh -c, so negated runs call the script directly (A) and
# must still leave a verdict with value > 0: a missing or crashing ag1.py never counts as a fail.
A="$ROOT/scripts/gates/ag1.py"
new() {
  d=$(mktemp -d "$ROOT/.gates/tmp/ag1s-XXXXXX")
  ( cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t
    mkdir -p gates api web .gates && printf '.gates/\n' > .gitignore
    cp "$ROOT/gates/manifest-P0.json" "$ROOT/gates/manifest-P0.approved.sha256" "$ROOT/gates/ratchet.json" gates/
    printf 'parameters:\n    level: 8\n' > api/phpstan.neon
    printf '{"compilerOptions":{"strict":true,"noUncheckedIndexedAccess":true,"exactOptionalPropertyTypes":true}}\n' > web/tsconfig.app.json
    printf '{"name":"x","scripts":{"build":"vite build"}}\n' > web/package.json
    printf 'gates:\n\tscripts/scorecard\n' > Makefile
    git add -A && git commit -qm "Base" )
  printf '%s\n' "$d"
}
freeze() {
  ( cd "$1" && { sha256sum api/phpstan.neon web/tsconfig.app.json Makefile gates/manifest-P0.json gates/manifest-P0.approved.sha256 gates/ratchet.json
      printf '%s  web/package.json#.scripts\n' "$(jq -S .scripts web/package.json | sha256sum | cut -c1-64)"; } > gates/frozen.sha256
    git add -A && git commit -qm "Freeze" )
}
d=$(new)
check "before the freeze: fails with anchor_absent" sh -c 'cd "$0" && ! GATES_OUT="$1" python3 -I "$2" && jq -e "[.details[] | select(.reason == \"anchor_absent\")] | length == 1" "$1/AG1.json"' "$d" .gates "$A"
freeze "$d"
check "right after the freeze: passes" ag1 "$d" .gates
( cd "$d" && sed -i 's/level: 8/level: 9/' api/phpstan.neon && git commit -qam "Raise" )
check "phpstan level 8 to 9 is stricter and passes" ag1 "$d" .gates
check "the stricter entry is listed for the next re-hash" jq -e '[.details[] | select(.reason == "stricter" and .path == "api/phpstan.neon" and .violation == false)] | length == 1' "$d/.gates/AG1.json"
( cd "$d" && sed -i 's/level: 9/level: 7/' api/phpstan.neon && git commit -qam "Lower" )
check "phpstan level 9 to 7 against the anchor fails" sh -c 'cd "$0" && ! GATES_OUT=.gates python3 -I "$1" && jq -e ".value > 0" .gates/AG1.json' "$d" "$A"
d=$(new); freeze "$d"; ( cd "$d" && printf 'gates:\n\t-scripts/scorecard\n' > Makefile && git commit -qam "Edit" )
check "any change to a hash-kind file fails" sh -c 'cd "$0" && ! GATES_OUT=.gates python3 -I "$1" && jq -e ".value > 0" .gates/AG1.json' "$d" "$A"
d=$(new); freeze "$d"; ( cd "$d" && jq '.scripts.build = "true"' web/package.json > p && mv p web/package.json && git commit -qam "Scripts" )
check "a package.json scripts change fails" sh -c 'cd "$0" && ! GATES_OUT=.gates python3 -I "$1" && jq -e ".value > 0" .gates/AG1.json' "$d" "$A"
d=$(new); freeze "$d"; ( cd "$d" && jq '.version = "1.0.0"' web/package.json > p && mv p web/package.json && git commit -qam "Version" )
check "a package.json change outside frozen subtrees passes" ag1 "$d" .gates
d=$(new); freeze "$d"; printf 'parameters:\n    level: 0\n' > "$d/api/phpstan.neon.dist"
check "an untracked alternate config fails" sh -c 'cd "$0" && ! GATES_OUT=.gates python3 -I "$1" && jq -e ".value > 0" .gates/AG1.json' "$d" "$A"
d=$(new); freeze "$d"; ( cd "$d" && printf '{}' > web/tsconfig.loose.json && git add -A && git commit -qm "Loose" )
check "a tracked config outside the frozen list fails" sh -c 'cd "$0" && ! GATES_OUT=.gates python3 -I "$1" && jq -e ".value > 0" .gates/AG1.json' "$d" "$A"
d=$(new); freeze "$d"; ( cd "$d" && printf '{}\n' >> gates/manifest-P0.json && git commit -qam "Manifest" )
check "an edited manifest fails the approval check" sh -c 'cd "$0" && ! GATES_OUT=.gates python3 -I "$1" && jq -e "[.details[] | select(.reason == \"manifest_unapproved\")] | length > 0" .gates/AG1.json' "$d" "$A"
d=$(new); freeze "$d"; ( cd "$d" && sed -i '1d' gates/frozen.sha256 && git commit -qam "Shrink" )
check "dropping a line from frozen.sha256 after the anchor fails" sh -c 'cd "$0" && ! GATES_OUT=.gates python3 -I "$1" && jq -e "[.details[] | select(.reason == \"lock_entry_dropped\")] | length == 1" .gates/AG1.json' "$d" "$A"
check "live repo: approval passes, anchor absent until T36" sh -c '! GATES_OUT="$0" python3 -I scripts/gates/ag1.py && jq -e "[.details[] | select(.reason == \"manifest_unapproved\" or .reason == \"hash_mismatch\")] | length == 0" "$0/AG1.json"' "$O"
check "live repo: anchor_absent is the only violation until T36" jq -e '[.details[] | select(.violation) | .reason] == ["anchor_absent"] and .denominator == 0' "$O/AG1.json"
finish
