#!/bin/sh
# HY-trailers: no commit trailer of any kind and no Claude or Anthropic reference in any commit
# message from the phase base (P0: the root commit) to HEAD. Scans the repository it is run from.
# value = offending commits; denominator = commits scanned; a git error counts as one offence.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
details="$GATES_OUT/HY-trailers.details.jsonl"; : > "$details"
bad=0; scanned=0; penalty=0
range=HEAD
if [ -n "${PHASE_BASE:-}" ]; then range="$PHASE_BASE..HEAD"; fi
if commits=$(git rev-list "$range" 2>/dev/null); then
  for c in $commits; do
    scanned=$((scanned + 1))
    reasons=
    if msg=$(git log -1 --format=%B "$c" 2>/dev/null); then
      parsed=$(printf '%s\n' "$msg" | git interpret-trailers --parse --no-divider 2>&1) \
        || reasons="$reasons interpret_trailers_error"
      [ -z "$parsed" ] || reasons="$reasons parsed_trailer"
      if printf '%s\n' "$msg" | /usr/bin/grep -qiP '^(co-authored-by|claude-session|signed-off-by|generated-by|assisted-by)\s*:'; then
        reasons="$reasons trailer_line"
      fi
      if printf '%s\n' "$msg" | /usr/bin/grep -qiP '\b(claude|anthropic)\b'; then
        reasons="$reasons claude_or_anthropic"
      fi
    else
      reasons=" message_unreadable"
    fi
    if [ -n "$reasons" ]; then
      bad=$((bad + 1))
      jq -nc --arg c "$c" --arg r "${reasons# }" '{commit: $c, reasons: ($r | split(" "))}' >> "$details"
    fi
  done
else
  penalty=1
  jq -nc --arg r "$range" '{reason: "rev_list_error", range: $r}' >> "$details"
fi
value=$((bad + penalty))
gate_result HY-trailers "$value" "$scanned" "$details"; gr=$?
printf 'HY-trailers: %s offending commits of %s scanned\n' "$value" "$scanned"
[ "$gr" -eq 0 ] && [ "$value" -eq 0 ]
