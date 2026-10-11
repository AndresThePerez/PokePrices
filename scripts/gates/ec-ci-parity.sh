#!/bin/sh
# EC-ci-parity: make gates passes from a fresh clone of HEAD on another compose project and port
# (F-P0-015, open question 4). The clone lives at .gates/ci-parity/<head12> (git clone
# --no-hardlinks, detached at HEAD) and is reused only when its HEAD equals ours and its tree is
# clean; it is never removed. The nested run uses COMPOSE_PROJECT_NAME=ppclean, PP_HTTP_PORT=18095
# and PP_RUN_CONTEXT=nested; its output goes to $GATES_OUT/EC-ci-parity.nested.log and its
# scorecard to $GATES_OUT/EC-ci-parity.nested.json (its DEN artifact to EC-ci-parity.nested.DEN.json); afterwards the ppclean project is taken down
# (never with volumes). value = 0 when the nested make gates exited 0 and ppclean came down,
# else 1; denominator = gate records in the nested scorecard. Refuses outside the primary context.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
details="$GATES_OUT/EC-ci-parity.details.jsonl"; : > "$details"
log="$GATES_OUT/EC-ci-parity.nested.log"
nested="$GATES_OUT/EC-ci-parity.nested.json"
project=ppclean
port=18095
record() { # CHECK OK(true|false) [NOTE]
  jq -nc --arg c "$1" --argjson ok "$2" --arg note "${3:-}" \
    '{check: $c, ok: $ok} + (if $note == "" then {} else {note: $note} end)' >> "$details"
}

ctx=${PP_RUN_CONTEXT:-primary}
if [ "$ctx" != primary ]; then
  jq -nc --arg ctx "$ctx" '{check: "run_context", ok: false, reason: "not_primary", context: $ctx}' >> "$details"
  gate_result EC-ci-parity 1 0 "$details"
  printf 'EC-ci-parity: not run in context %s (not_primary)\n' "$ctx"
  exit 1
fi

top=$(git rev-parse --show-toplevel 2>/dev/null) || top=
head=$(git rev-parse --verify HEAD 2>/dev/null) || head=
if [ -z "$top" ] || [ -z "$head" ]; then
  record git_repo false "not a git repository with a HEAD"
  gate_result EC-ci-parity 1 0 "$details"
  echo "EC-ci-parity: not a git repository with a HEAD"
  exit 1
fi
cd "$top" || exit 1
head12=$(printf '%s' "$head" | cut -c1-12)
mkdir -p .gates/ci-parity
dir=.gates/ci-parity/$head12

# Reuse the clone only when it sits at our HEAD with a clean tree; otherwise clone beside it.
reuse=false
if [ -d "$dir" ]; then
  if [ "$(git -C "$dir" rev-parse --verify HEAD 2>/dev/null)" = "$head" ] &&
    st=$(git -C "$dir" status --porcelain --untracked-files=all 2>/dev/null) && [ -z "$st" ]; then
    reuse=true
  else
    dir=.gates/ci-parity/$head12-$(date -u +%Y%m%dT%H%M%SZ)
  fi
fi
if [ "$reuse" = true ]; then
  record clone true "reused $dir"
elif git clone --quiet --no-hardlinks . "$dir" && git -C "$dir" checkout --quiet --detach "$head"; then
  record clone true "cloned $dir"
else
  record clone false "git clone or checkout failed for $dir"
  gate_result EC-ci-parity 1 0 "$details"
  echo "EC-ci-parity: could not clone HEAD into $dir"
  exit 1
fi

# The ppclean project always comes down again, also when this script is interrupted.
down_ok=pending
ppclean_down() {
  [ "$down_ok" = pending ] || return 0
  if docker compose -p "$project" -f "$dir/compose.dev.yml" down >> "$log" 2>&1; then down_ok=true; else down_ok=false; fi
}
trap 'ppclean_down; exit 1' HUP INT TERM

marker="$GATES_OUT/EC-ci-parity.start"
: > "$marker"
rm -f "$nested" "$GATES_OUT/EC-ci-parity.nested.DEN.json"
(
  cd "$dir" || exit 1
  unset GATES_OUT SCORECARD MANIFEST MAKEFLAGS MFLAGS MAKELEVEL
  env COMPOSE_PROJECT_NAME="$project" PP_HTTP_PORT="$port" PP_RUN_CONTEXT=nested make gates
) > "$log" 2>&1
rc=$?
echo "EXIT $rc" >> "$log"
record nested_make "$([ "$rc" -eq 0 ] && echo true || echo false)" "make gates exit $rc"

sc="$dir/.gates/latest/scorecard.json"
den=0
if [ -f "$sc" ] && [ "$sc" -nt "$marker" ] && [ "$(jq -r '.head' "$sc" 2>/dev/null)" = "$head" ]; then
  cp "$sc" "$nested"
  den=$(jq '.gates | length' "$nested" 2>/dev/null) || den=0
  case $den in ''|*[!0-9]*) den=0 ;; esac
  record nested_scorecard true "$den gate records"
  # The nested DEN artifact says which gates broke the zero-denominator rule.
  den_art=$(jq -r '.gates[] | select(.id == "DEN") | .artifact // empty' "$nested" 2>/dev/null) || den_art=
  if [ -n "$den_art" ] && [ -f "$dir/$den_art" ]; then cp "$dir/$den_art" "$GATES_OUT/EC-ci-parity.nested.DEN.json"; fi
else
  record nested_scorecard false "no scorecard for this HEAD written by this run"
fi

ppclean_down
trap - HUP INT TERM
record ppclean_down "$down_ok"

value=1
[ "$rc" -eq 0 ] && [ "$down_ok" = true ] && value=0
gate_result EC-ci-parity "$value" "$den" "$details"; gr=$?
printf 'EC-ci-parity: nested make gates exit %s, %s gate records, ppclean down %s\n' "$rc" "$den" "$down_ok"
[ "$gr" -eq 0 ] && [ "$value" -eq 0 ]
