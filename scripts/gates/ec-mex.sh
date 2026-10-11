#!/bin/sh
# EC-mex: the local .mex scaffold exists, stays untracked (git directory info/exclude), meets its
# content floor, passes mex check and leaks nothing the hygiene patterns know (R8, R35, F-P0-017).
# The mex binary is MEX_BIN, else mex on PATH, else the newest nvm install under $HOME; it runs
# with its node directory first on PATH. Hygiene hits are reported by location only.
# value = failed checks; denominator = scaffold files found.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
details="$GATES_OUT/EC-mex.details.jsonl"; : > "$details"
fail=0
record() { # CHECK OK(true|false) [NOTE]
  jq -nc --arg c "$1" --argjson ok "$2" --arg note "${3:-}" \
    '{check: $c, ok: $ok} + (if $note == "" then {} else {note: $note} end)' >> "$details"
  [ "$2" = true ] || fail=$((fail + 1))
}
patterns() { # FILE: the non-comment, non-empty lines, each wrapped as a group
  sed -e 's/\r$//' -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' "$1" | sed 's/.*/(?:&)/' | paste -sd'|' -
}

top=$(git rev-parse --show-toplevel 2>/dev/null) || top=
if [ -z "$top" ]; then
  record git_repo false "not a git repository"
else
  cd "$top" || exit 1
fi

# Scaffold files and their hashes (the denominator).
files=0
if [ -d .mex ]; then
  for f in $(find .mex -type f | LC_ALL=C sort); do
    sum=$(sha256sum "$f" | cut -d' ' -f1)
    jq -nc --arg f "$f" --arg s "$sum" '{file: $f, sha256: $s}' >> "$details"
    files=$((files + 1))
  done
fi

ctx=${PP_RUN_CONTEXT:-primary}
if [ "$ctx" != primary ]; then
  jq -nc --arg ctx "$ctx" '{check: "run_context", ok: false, reason: "not_primary", context: $ctx}' >> "$details"
  gate_result EC-mex 1 "$files" "$details"
  printf 'EC-mex: not run in context %s (not_primary)\n' "$ctx"
  exit 1
fi

for f in .mex/ROUTER.md .mex/AGENTS.md; do
  if [ -f "$f" ] && [ -s "$f" ]; then record "exists:$f" true; else record "exists:$f" false; fi
done

tracked=$(git ls-files .mex 2>/dev/null) || tracked=error
if [ -z "$tracked" ]; then record untracked true; else record untracked false "git ls-files .mex is not empty"; fi
if git check-ignore -q .mex/ROUTER.md; then record ignored true; else record ignored false; fi

for want in compose.dev.yml 'make gates' 8095; do
  if [ -f .mex/ROUTER.md ] && /usr/bin/grep -qF -e "$want" .mex/ROUTER.md; then
    record "router_names:$want" true
  else
    record "router_names:$want" false
  fi
done

# Hygiene: the tracked generic patterns plus the untracked local ones find nothing under .mex.
pat=
if [ -s gates/hygiene.patterns ]; then pat=$(patterns gates/hygiene.patterns); fi
local_file=$(git rev-parse --git-path info/hygiene.local 2>/dev/null) || local_file=
if [ -n "$local_file" ] && [ -s "$local_file" ]; then
  lpat=$(patterns "$local_file")
  if [ -n "$lpat" ]; then pat=${pat:+$pat|}$lpat; fi
fi
if [ -z "$pat" ]; then
  record hygiene false "no hygiene patterns found"
elif [ ! -d .mex ]; then
  record hygiene false "no .mex directory to scan"
else
  out=$("$lib/nohits" /usr/bin/grep -rnIP -e "$pat" .mex); rc=$?
  case $rc in
    0) record hygiene true ;;
    1) where=$(printf '%s\n' "$out" | sed '$d' | cut -d: -f1,2 | paste -sd' ' -)
       record hygiene false "hits at $where" ;;
    *) record hygiene false "finder error rc=$rc" ;;
  esac
fi

# mex check, through MEX_BIN, else PATH, else the newest nvm install.
mex=${MEX_BIN:-}
if [ -z "$mex" ]; then mex=$(command -v mex 2>/dev/null) || mex=; fi
if [ -z "$mex" ]; then
  mex=$(ls -d "$HOME"/.nvm/versions/node/*/bin/mex 2>/dev/null | sort -V | tail -n 1)
fi
if [ -z "$mex" ] || [ ! -x "$mex" ]; then
  record mex_check false "mex binary not found"
else
  summary=$(PATH="$(dirname "$mex"):$PATH" "$mex" check --quiet 2>&1); rc=$?
  summary=$(printf '%s' "$summary" | tail -n 1)
  if [ "$rc" -eq 0 ]; then record mex_check true "$summary"; else record mex_check false "rc=$rc $summary"; fi
fi

gate_result EC-mex "$fail" "$files" "$details"; gr=$?
printf 'EC-mex: %s failed checks, %s scaffold files\n' "$fail" "$files"
[ "$gr" -eq 0 ] && [ "$fail" -eq 0 ]
