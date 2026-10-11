#!/bin/sh
# S5: the shipped runtime images run as a non-root user and .dockerignore excludes every .env (F-P0-084).
# Judges the freshly built tags' .Config.User, never an image id (R43).
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
top=$(git rev-parse --show-toplevel 2>/dev/null) && cd "$top"
details="$GATES_OUT/S5.details.jsonl"
: > "$details"
project=${COMPOSE_PROJECT_NAME:-pokeprices}
fail=0
n=0
for img in api web; do
  n=$((n + 1))
  tag="$project-$img:gate"
  if docker build -q -f "docker/$img/Dockerfile" --target runtime -t "$tag" . >"$GATES_OUT/S5.$img.build.log" 2>&1; then
    u=$(docker inspect --format '{{.Config.User}}' "$tag" 2>/dev/null)
    rc=$?
    ok=true
    [ "$rc" -eq 0 ] || ok=false
    case "${u%%:*}" in ''|0|root) ok=false ;; esac
    reason=user
  else
    u=""
    ok=false
    reason=build_failed
  fi
  [ "$ok" = true ] || fail=$((fail + 1))
  jq -nc --arg i "$tag" --arg u "$u" --arg r "$reason" --argjson ok "$ok" '{image: $i, user: $u, check: $r, ok: $ok}' >> "$details"
done
if /usr/bin/grep -qx '\*\*/\.env' .dockerignore; then
  jq -nc '{check: "dockerignore_env", ok: true}' >> "$details"
else
  fail=$((fail + 1))
  jq -nc '{check: "dockerignore_env", ok: false, reason: "**/.env missing from .dockerignore"}' >> "$details"
fi
gate_result S5 "$fail" "$n" "$details"; gr=$?
printf 'S5: %s failures across %s runtime images\n' "$fail" "$n"
[ "$gr" -eq 0 ] && [ "$fail" -eq 0 ]
