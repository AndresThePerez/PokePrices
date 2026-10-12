#!/bin/sh
# P0-i2-T01 reviewer edge cases (F-P0-092, F-P0-095, F-P0-099): attacks on the Q8 --no-editorconfig
# flag, the S1 repositories guard and the new AG1 alternates and frozen entries. Negative checks run
# the real tools (Prettier in the node service, jq, composer, ag1.py) in throwaway clones under
# .gates/tmp/i2T01-review (left in place; no recursive delete). Never runs docker compose up.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
M=gates/manifest-P0.json
WS=/workspace/${PP_WS_PREFIX:-}
T=.gates/tmp/i2T01-review; mkdir -p "$T"
gate_cmd() { jq -r --arg i "$1" '.gates[] | select(.id == $i) | .command' "$2"; }
fresh() { d=$(mktemp -d "$T/c-XXXXXX") && git clone -q --no-hardlinks . "$d/repo" && printf '%s/repo\n' "$d"; }
webclone() { c=$(fresh) && ln -s /workspace/web/node_modules "$c/web/node_modules" && printf '%s\n' "$c"; }
# q8 CLONE [strip]: run the clone's own Q8 command with -w at the clone's web/; "strip" drops --no-editorconfig
q8() {
  cmd=$(gate_cmd Q8 "$1/$M" | sed "s#-w /workspace/web #-w $WS$1/web #")
  [ "${2:-}" = strip ] && cmd=$(printf '%s' "$cmd" | sed 's/ --no-editorconfig//')
  mkdir -p "$1/.gates/q8"; : > "$1/.gates/q8/Q8.prettier.txt"
  GATES_OUT="$1/.gates/q8" sh -c "$cmd" >/dev/null 2>&1
  return 0
}
listed() { q8 "$1"; $GREP -qx 'src/main.tsx' "$1/.gates/q8/Q8.prettier.txt"; }
not_listed_noflag() { q8 "$1" strip; ! $GREP -qx 'src/main.tsx' "$1/.gates/q8/Q8.prettier.txt"; }
count() { q8 "$1"; awk 'NF{n++} END{print n+0}' "$1/.gates/q8/Q8.prettier.txt"; }
has_files_noflag() { q8 "$1" strip; [ -s "$1/.gates/q8/Q8.prettier.txt" ]; }
reindent() { sed -i -E 's/^( +)/\1\1/' "$1/web/src/main.tsx"; }

# --- 1. Q8: the flag removes the input; the fixtures really bite when the flag is stripped
C=$(webclone); reindent "$C"
printf 'root = true\n\n[*]\nindent_style = space\nindent_size = 4\n' > "$C/.editorconfig"
check "control (flag stripped, real Prettier): a root .editorconfig hides the re-indented file" not_listed_noflag "$C"
check "with the committed flag the same tree lists the re-indented file" listed "$C"

C=$(webclone); reindent "$C"
mkdir -p "$C/web/src" && printf '[*]\nindent_size = 4\n' > "$C/web/src/.editorconfig"
check "control (flag stripped): a web/src/.editorconfig [*] hides the re-indented file" not_listed_noflag "$C"
check "a web/src/.editorconfig [*] does not hide it with the flag (adversary reproduction)" listed "$C"

# the reverse direction: an .editorconfig must not make a clean tree fail Q8 either (Q8 independent of it)
C=$(webclone)
printf 'root = true\n\n[*]\nindent_style = tab\nend_of_line = crlf\nindent_size = 8\n' > "$C/web/src/.editorconfig"
check "control (flag stripped): a tab/crlf .editorconfig makes a clean tree list files" has_files_noflag "$C"
check_out "a tab/crlf web/src/.editorconfig lists nothing on a clean clone with the flag" 0 count "$C"

# --- 2. AG1: the new alternates and frozen entries in unusual shapes
ag1_flags() { # $1 clone $2 reason $3 path
  ( cd "$1" && mkdir -p .gates/ag1 && ! GATES_OUT=.gates/ag1 python3 -I scripts/gates/ag1.py >/dev/null 2>&1 \
    && jq -e --arg r "$2" --arg p "$3" '[.details[] | select(.reason == $r and .path == $p and .violation)] | length >= 1' .gates/ag1/AG1.json >/dev/null )
}
C=$(fresh); ln -s /nonexistent-target "$C/web/.npmrc"
check "a dangling web/.npmrc symlink fails AG1 (lexists)" ag1_flags "$C" alternate_present web/.npmrc
C=$(fresh); mkdir "$C/trivy.yaml"
check "a trivy.yaml directory fails AG1" ag1_flags "$C" alternate_present trivy.yaml
C=$(fresh); : > "$C/web/.npmrc"; printf '.npmrc\n' >> "$C/.git/info/exclude"
check "a git-ignored web/.npmrc (untracked, excluded) still fails AG1" ag1_flags "$C" alternate_present web/.npmrc
C=$(fresh); rm "$C/api/.gitignore"
check "deleting api/.gitignore fails AG1 (missing frozen path)" ag1_flags "$C" missing_frozen_path api/.gitignore
C=$(fresh); rm "$C/api/tests/Support/ContractValidator.php"
check "deleting the C3 validator fails AG1 (missing frozen path)" ag1_flags "$C" missing_frozen_path api/tests/Support/ContractValidator.php
C=$(fresh); : > "$C/trivy.yaml"; jq '.alternates -= ["trivy.yaml", "web/.npmrc"]' "$C/gates/ratchet.json" > "$C/r.new" && mv "$C/r.new" "$C/gates/ratchet.json"
check "dropping the alternates from HEAD's ratchet.json does not hide trivy.yaml (anchor union)" ag1_flags "$C" alternate_present trivy.yaml
check "dropping the alternates edits a frozen file and fails AG1 too" ag1_flags "$C" looser gates/ratchet.json
C=$(fresh); git -C "$C" rm -q -f web/.gitignore
check "git rm of web/.gitignore (tracked, frozen) fails AG1" ag1_flags "$C" missing_frozen_path web/.gitignore

# --- 3. S1 guard: the real jq tail against odd composer.json shapes (exit status must refuse, never pass vacuously)
S1TAIL=$(gate_cmd S1 "$M"); S1TAIL=${S1TAIL##* && }
guard_rc() { # $1 = composer.json text -> exit status of the committed guard
  d=$(mktemp -d "$T/g-XXXXXX") && mkdir "$d/api" && printf '%s' "$1" > "$d/api/composer.json" && ( cd "$d" && sh -c "$S1TAIL" ) >/dev/null 2>&1; echo $?
}
check_out "guard: the tail is the repositories guard" "jq -e 'has(\"repositories\") | not' api/composer.json > /dev/null" printf '%s' "$S1TAIL"
guard_rcz() { if [ "$(guard_rc "$1")" -ne 0 ]; then echo nonzero; else echo zero; fi; }
check_out "guard accepts a composer.json without repositories" 0 guard_rc '{"name":"a/b"}'
check_out "guard refuses repositories: {}" 1 guard_rc '{"repositories":{}}'
check_out "guard refuses repositories: []" 1 guard_rc '{"repositories":[]}'
check_out "guard refuses repositories: null" 1 guard_rc '{"repositories":null}'
check_out "guard refuses repositories: false" 1 guard_rc '{"repositories":false}'
check_out "guard refuses repositories: [{packagist.org:false}]" 1 guard_rc '{"repositories":[{"packagist.org":false}]}'
check_out "guard refuses repositories after other keys" 1 guard_rc '{"name":"a/b","require":{},"config":{},"repositories":{"x":{"type":"vcs","url":"https://example.invalid/x.git"}}}'
check_out "guard refuses a duplicate repositories key (last wins in jq)" 1 guard_rc '{"repositories":{"a":1},"repositories":{}}'
check_out "guard does not pass a composer.json that is a JSON array" nonzero guard_rcz '[]'
check_out "guard does not pass a composer.json that is a string" nonzero guard_rcz '"repositories"'
check_out "guard does not pass malformed JSON" nonzero guard_rcz '{"repositories":'
check_out "guard does not pass an empty composer.json" nonzero guard_rcz ''

# --- 4. S1 end to end: the committed command (real composer in the api service) fails with a repositories key
s1_full() { # $1 clone, $2 = "key" to add a repositories key; prints the exit status of the clone's own S1 command
  c=$1
  [ "$2" = key ] && { jq '. + {repositories: [{"packagist.org": false}]}' "$c/api/composer.json" > "$c/api/composer.json.new" && mv "$c/api/composer.json.new" "$c/api/composer.json"; }
  cmd=$(gate_cmd S1 "$c/$M" | sed "s#-w /workspace/api #-w $WS$c/api #")
  mkdir -p "$c/.gates/s1"
  ( cd "$c" && GATES_OUT=.gates/s1 sh -c "$cmd" >/dev/null 2>&1 ); echo $?
}
C=$(fresh)
check_out "S1 committed command passes on a clean clone (real composer audit)" 0 s1_full "$C" none
C=$(fresh)
check_out "S1 committed command exits non-zero when composer.json gains a repositories key" 1 s1_full "$C" key
finish
