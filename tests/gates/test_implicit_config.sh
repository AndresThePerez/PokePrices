#!/bin/sh
# P0-i2-T01 (F-P0-092, F-P0-095, F-P0-099): a committed implicit config input can no longer flip a
# frozen gate. Q8 runs Prettier with --no-editorconfig; S1 refuses a composer.json repositories key;
# the C3 validator and the nested ignore files are frozen; trivy.yaml and web/.npmrc may not exist.
# Negative checks run the real tools in throwaway clones under .gates/tmp (left in place; no
# recursive delete). Needs the pokeprices node service up; never runs docker compose up.
# PP_WS_PREFIX is only for the phase-A preview: this repo's path below the /workspace mount,
# with a trailing slash (default empty).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
M=gates/manifest-P0.json; R=gates/ratchet.json; MD=gates/manifest-P0.md
BASE_SHA=adcf920b2b1aedb908f3cdb4e38cc89069b1bdae9644d7eed5cfebdc52f3429a
WS=/workspace/${PP_WS_PREFIX:-}
T=.gates/tmp/i2T01; mkdir -p "$T"
A=$(git log --diff-filter=A --format=%H -- gates/frozen.sha256 | tail -n 1)
git show "$A~1:$M" > "$T/base.json"; git show "$A~1:$R" > "$T/base-ratchet.json"; git show "$A~1:$MD" > "$T/base.md"
gate_cmd() { jq -r --arg i "$1" '.gates[] | select(.id == $i) | .command' "$2"; }
fresh() { d=$(mktemp -d "$T/c-XXXXXX") && git clone -q --no-hardlinks . "$d/repo" && printf '%s/repo\n' "$d"; }

# --- 1. the exact approved diff (base = the anchor's parent, which holds the adcf920b manifest)
check_out "base manifest is the adcf920b approval" "$BASE_SHA" sh -c 'sha256sum "$0" | cut -c1-64' "$T/base.json"
Q8_WANT=$(gate_cmd Q8 "$T/base.json" | sed 's/ --config .prettierrc.json / --config .prettierrc.json --no-editorconfig /')
S1_WANT="$(gate_cmd S1 "$T/base.json") && jq -e 'has(\"repositories\") | not' api/composer.json > /dev/null"
check_out "Q8 is the base command plus --no-editorconfig (F-P0-092)" "$Q8_WANT" gate_cmd Q8 "$M"
check_out "S1 is the base command plus the repositories guard" "$S1_WANT" gate_cmd S1 "$M"
list_want() { jq -c '.frozen_files.list
  | .[:index(".dockerignore")] + ["api/.gitignore", "web/.gitignore"] + .[index(".dockerignore"):]
  | .[:index("api/tests/TestCase.php") + 1] + ["api/tests/Support/*"] + .[index("api/tests/TestCase.php") + 1:]' "$T/base.json"; }
check_out "frozen list gains exactly api/.gitignore, web/.gitignore, api/tests/Support/* in place" "$(list_want)" jq -c .frozen_files.list "$M"
rest() { jq -cS 'del(.frozen_files.list) | .gates |= map(if .id == "Q8" or .id == "S1" then del(.command) else . end)' "$1"; }
rest_same() { [ "$(rest "$T/base.json")" = "$(rest "$M")" ]; }
check "nothing else in the manifest changed" rest_same
ratchet_same() { [ "$(jq -cS '.alternates += ["trivy.yaml", "web/.npmrc"]' "$T/base-ratchet.json")" = "$(jq -cS . "$R")" ]; }
check "ratchet.json only appends trivy.yaml and web/.npmrc to alternates" ratchet_same
check "md Q8 command line mirrors the json" $GREP -F -x -- "- command: \`$Q8_WANT\`" "$MD"
check "md S1 command line mirrors the json" $GREP -F -x -- "- command: \`$S1_WANT\`" "$MD"
md_list() { printf -- '- list: %s' "$(jq -r '.frozen_files.list | map("`" + . + "`") | join(", ")' "$M")"; }
check "md frozen list line mirrors the json list" $GREP -F -x -- "$(md_list)" "$MD"
check_out "md changes exactly three lines" 3 sh -c 'diff "$0" "$1" | /usr/bin/grep -c "^>"' "$T/base.md" "$MD"
check "approved file verifies the manifest" sha256sum -c --strict --status gates/manifest-P0.approved.sha256
check "the T02 edge literal is the approved hash" sh -c '[ "$(sed -n "s/^APPROVED=//p" tests/p0/T02-edge.sh)" = "$(cut -c1-64 gates/manifest-P0.approved.sha256)" ]'
check "T12 edge mirrors the new Q8 flags" $GREP -qF -- '--config .prettierrc.json --no-editorconfig --ignore-path .prettierignore --list-different .' tests/p0/T12-edge.sh

# --- 2. the freeze anchor itself carries the change (anchor commit amended, R105/R108)
check "the anchor commit's manifest is the working manifest" sh -c 'git show "$0:gates/manifest-P0.json" | cmp -s - gates/manifest-P0.json' "$A"
check "lock regenerates identically" sh -c 'python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | cmp -s - gates/frozen.sha256'
for p in api/tests/Support/ContractValidator.php api/.gitignore web/.gitignore; do
  check "the anchor lock freezes $p" sh -c 'git show "$0:gates/frozen.sha256" | /usr/bin/grep -q "  $1\$"' "$A" "$p"
done
mkdir -p "$T/ag1"
check "AG1 passes on this repo" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag1.py' "$T/ag1"
check_out "AG1 evaluates all 465 lock entries" 465 jq -r .denominator "$T/ag1/AG1.json"

# --- 3. Q8 bite in a throwaway clone of HEAD (the adversary's reproduction, F-P0-092)
q8() { # $1 = clone (repo-relative): run the clone's own Q8 command with -w at the clone's web/
  cmd=$(gate_cmd Q8 "$1/$M" | sed "s#-w /workspace/web #-w $WS$1/web #")
  mkdir -p "$1/.gates/q8"
  GATES_OUT="$1/.gates/q8" sh -c "$cmd" >/dev/null 2>&1
  return 0
}
listed() { q8 "$1"; $GREP -qx 'src/main.tsx' "$1/.gates/q8/Q8.prettier.txt"; }
C=$(fresh); ln -s /workspace/web/node_modules "$C/web/node_modules"
q8 "$C"; check_out "clean clone: Q8 lists nothing" 0 awk 'NF{n++} END{print n+0}' "$C/.gates/q8/Q8.prettier.txt"
sed -i -E 's/^( +)/\1\1/' "$C/web/src/main.tsx"   # double every indent: a true 4-space re-indent
check "main.tsx re-indented to 4 spaces is listed" listed "$C"
jq '. + {prettier: {tabWidth: 4}}' "$C/web/package.json" > "$C/web/package.json.new" && mv "$C/web/package.json.new" "$C/web/package.json"
check "a package.json prettier key does not hide it (explicit --config)" listed "$C"
printf 'src/main.tsx\n' >> "$C/web/.gitignore"
check "a web/.gitignore entry does not hide it (explicit --ignore-path)" listed "$C"
printf '[src/main.tsx]\nindent_size = 4\n' > "$C/web/.editorconfig"
check "a section-scoped web/.editorconfig does not hide it (F-P0-092)" listed "$C"
printf 'root = true\n\n[*]\nindent_style = space\nindent_size = 4\n' > "$C/.editorconfig"
check "a root .editorconfig does not hide it either" listed "$C"

# --- 4. AG1 bites in throwaway clones: new alternates and new frozen entries
ag1_clean() { ( cd "$1" && mkdir -p .gates/ag1 && GATES_OUT=.gates/ag1 python3 -I scripts/gates/ag1.py >/dev/null 2>&1 ); }
ag1_flags() { # $1 clone, $2 reason, $3 path: AG1 exits non-zero and names that reason for that path
  ( cd "$1" && mkdir -p .gates/ag1 && ! GATES_OUT=.gates/ag1 python3 -I scripts/gates/ag1.py >/dev/null 2>&1 \
    && jq -e --arg r "$2" --arg p "$3" '[.details[] | select(.reason == $r and .path == $p and .violation)] | length >= 1' .gates/ag1/AG1.json >/dev/null )
}
C=$(fresh); check "clone baseline: AG1 passes" ag1_clean "$C"
C=$(fresh); : > "$C/trivy.yaml"
check "a trivy.yaml (S10's trivy reads it from /repo) fails AG1" ag1_flags "$C" alternate_present trivy.yaml
C=$(fresh); : > "$C/web/.npmrc"
check "a web/.npmrc (S2's npm audit reads it) fails AG1" ag1_flags "$C" alternate_present web/.npmrc
C=$(fresh); printf '\n// probe\n' >> "$C/api/tests/Support/ContractValidator.php"
check "editing the C3 validator fails AG1 (F-P0-095)" ag1_flags "$C" looser api/tests/Support/ContractValidator.php
C=$(fresh); printf 'zz-hidden\n' >> "$C/web/.gitignore"
check "editing web/.gitignore fails AG1 (F-P0-099)" ag1_flags "$C" looser web/.gitignore
C=$(fresh); printf 'zz-hidden\n' >> "$C/api/.gitignore"
check "editing api/.gitignore fails AG1 (F-P0-099)" ag1_flags "$C" looser api/.gitignore

# --- 5. S1 guard: a composer.json repositories key could switch off the advisory source
s1_guard() { # $1 clone: run only the text after the last " && " of the clone's S1 command
  cmd=$(gate_cmd S1 "$1/$M")
  case $cmd in *" && jq -e "*) ;; *) return 3 ;; esac
  ( cd "$1" && sh -c "${cmd##* && }" )
}
s1_refuses() { s1_guard "$1"; [ $? -eq 1 ]; }
C=$(fresh)
check "S1 guard accepts the committed composer.json" s1_guard "$C"
jq '. + {repositories: [{"packagist.org": false}]}' "$C/api/composer.json" > "$C/api/composer.json.new" && mv "$C/api/composer.json.new" "$C/api/composer.json"
check "S1 guard refuses a composer.json repositories key" s1_refuses "$C"
finish
