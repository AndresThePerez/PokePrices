#!/bin/sh
# HY-hygiene: no home path, server user, LAN or Tailscale address or server port map in the
# tracked files at HEAD or in any line added since the phase base (F-P0-016, F-P0-069, R35).
# Patterns: the non-comment lines of gates/hygiene.patterns (tracked, generic) plus the untracked
# local pattern file in the git directory (machine-specific names), required when PP_RUN_CONTEXT
# is primary. Scans the repository it is run from; a finder error counts as one hit, never clean.
# Hits are reported by location only, so the log never repeats the leaked text.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
details="$GATES_OUT/HY-hygiene.details.jsonl"; : > "$details"
history="$GATES_OUT/HY-hygiene.history.txt"
ctx=${PP_RUN_CONTEXT:-primary}
penalty=0
problem() { # RULE TEXT
  jq -nc --arg r "$1" --arg h "$2" '{rule: $r, hit: $h}' >> "$details"
  penalty=$((penalty + 1))
}
patterns() { # FILE: the non-comment, non-empty lines
  sed -e 's/\r$//' -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' "$1"
}

top=$(git rev-parse --show-toplevel 2>/dev/null) || top=
if [ -z "$top" ]; then
  problem not_a_git_repo "$(pwd)"
else
  cd "$top" || exit 1
fi
generic=gates/hygiene.patterns
local_file=$(git rev-parse --git-path info/hygiene.local 2>/dev/null) || local_file=
pat=
if [ -s "$generic" ]; then
  pat=$(patterns "$generic" | sed 's/.*/(?:&)/' | paste -sd'|' -)
else
  problem patterns_missing "$generic"
fi
if [ -n "$local_file" ] && [ -s "$local_file" ]; then
  lpat=$(patterns "$local_file" | sed 's/.*/(?:&)/' | paste -sd'|' -)
  if [ -n "$lpat" ]; then pat=${pat:+$pat|}$lpat; fi
elif [ "$ctx" = primary ]; then
  problem local_patterns_missing "context primary needs the untracked local pattern file"
fi

range=HEAD
if [ -n "${PHASE_BASE:-}" ]; then range="$PHASE_BASE..HEAD"; fi
started=$(date +%s)
hits=0
if [ -n "$pat" ]; then
  # Scan 1: tracked files at HEAD.
  out=$("$lib/nohits" git grep -nIP -e "$pat" HEAD -- . ':!gates/hygiene.patterns' ':!*.lock' ':!web/package-lock.json'); rc=$?
  case $rc in
    0) ;;
    1) n=$(printf '%s\n' "$out" | sed '$d' | wc -l)
       hits=$((hits + n))
       printf '%s\n' "$out" | sed '$d' | cut -d: -f2,3 | jq -Rc '{rule: "tracked_at_head", hit: .}' >> "$details" ;;
    *) problem tracked_finder_error "git grep rc=$rc" ;;
  esac
  # Scan 2: every line added in history, from the phase base (P0: the root commit).
  if git log -p --no-color --no-ext-diff --no-textconv --format='commit %H' "$range" -- . \
       ':!gates/hygiene.patterns' ':!*.lock' ':!web/package-lock.json' > "$history"; then
    out=$("$lib/nohits" /usr/bin/grep -nP "^\\+.*(?:$pat)" "$history"); rc=$?
    case $rc in
      0) ;;
      1) lines=$(printf '%s\n' "$out" | sed '$d' | cut -d: -f1)
         n=$(printf '%s\n' "$lines" | wc -l)
         hits=$((hits + n))
         # Locate each hit line as commit:file without repeating its text.
         printf '%s\n' "$lines" | awk '
           NR == FNR { want[$1] = 1; next }
           /^commit [0-9a-f]+$/ { commit = $2 }
           /^\+\+\+ / { file = substr($0, 5) }
           FNR in want { print commit ":" file }' - "$history" \
           | jq -Rc '{rule: "added_in_history", hit: .}' >> "$details" ;;
      *) problem history_finder_error "grep rc=$rc" ;;
    esac
  else
    problem history_finder_error "git log failed"
  fi
  rm -f "$history"
else
  problem patterns_empty "no pattern to scan with"
fi
elapsed=$(( $(date +%s) - started ))

files=$(git ls-files | wc -l)
commits=$(git rev-list --count "$range" 2>/dev/null) || { problem rev_list_error "$range"; commits=0; }
denominator=$((files + commits))
value=$((hits + penalty))
gate_result HY-hygiene "$value" "$denominator" "$details"; gr=$?
printf 'HY-hygiene: %s hits (%s files at HEAD, %s commits scanned, %ss)\n' "$value" "$files" "$commits" "$elapsed"
[ "$gr" -eq 0 ] && [ "$value" -eq 0 ]
