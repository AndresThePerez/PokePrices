#!/bin/sh
# S6: every base image digest-pinned, in tracked Dockerfiles and compose image: (F-P0-023, F-P0-060).
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
details="$GATES_OUT/S6.details.jsonl"; : > "$details"
top=$(git rev-parse --show-toplevel 2>/dev/null) || {
  jq -nc --arg h "$(pwd)" '{rule: "repo", reason: "not_a_git_repo", hit: $h}' >> "$details"; top=$(pwd); }
cd "$top" || exit 1
froms=0
for f in $(git ls-files | /usr/bin/grep -E '(^|/)Dockerfile[^/]*$'); do
  # One TSV line per FROM: count, image, verdict.
  out=$(awk '
    BEGIN { n = 0 }
    toupper($1) == "FROM" {
      n++
      i = 2
      while (i <= NF && $i ~ /^--/) i++
      img = $i
      ok = 0
      if (img == "" || index(img, "$") > 0) ok = 0
      else if (img == "scratch" || (img in alias)) ok = 1
      else if (img ~ /@sha256:[0-9a-f]+$/ && length(substr(img, index(img, "@sha256:") + 8)) == 64) ok = 1
      if (i + 2 <= NF && toupper($(i + 1)) == "AS") alias[$(i + 2)] = 1
      if (!ok) printf "%d\t%s\n", FNR, img
    }
    END { printf "count\t%d\n", n }' "$f")
  c=$(printf '%s\n' "$out" | awk -F'\t' '$1 == "count" { print $2 }')
  froms=$((froms + c))
  printf '%s\n' "$out" | awk -F'\t' '$1 != "count"' | jq -Rc --arg f "$f" \
    'split("\t") | {rule: "dockerfile_from", file: $f, line: (.[0] | tonumber), image: .[1]}' >> "$details"
done
[ "$froms" -gt 0 ] || jq -nc '{rule: "dockerfile_from", reason: "no FROM lines in any tracked Dockerfile"}' >> "$details"
cfg="$GATES_OUT/S6.compose.json"
if docker compose --profile '*' -f compose.dev.yml config --no-env-resolution --format json > "$cfg" 2>"$GATES_OUT/S6.compose.err"; then
  images=$(jq '[.services[] | select(has("build") | not)] | length' "$cfg")
  jq -c '.services | to_entries[] | select(.value | has("build") | not)
    | select((.value.image // "") | test("@sha256:[0-9a-f]{64}$") | not)
    | {rule: "compose_image", service: .key, image: (.value.image // null)}' "$cfg" >> "$details"
else
  images=0
  jq -nc --rawfile e "$GATES_OUT/S6.compose.err" '{rule: "compose_image", reason: "docker compose config failed", error: $e}' >> "$details"
fi
rm -f "$cfg"
denominator=$((froms + images))
value=$(jq -s 'length' "$details")
gate_result S6 "$value" "$denominator" "$details"; gr=$?
printf 'S6: %s unpinned images across %s FROM lines and compose image references\n' "$value" "$denominator"
[ "$gr" -eq 0 ] && [ "$value" -eq 0 ]
