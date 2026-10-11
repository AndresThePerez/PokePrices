#!/bin/sh
# S7: every non-local `uses:` in the tracked workflows is pinned to a 40-hex SHA; first-party
# actions/* are pinned too in this repo. value = unpinned uses lines (a finder error counts as one);
# denominator = workflow files; no workflow file at all is value 1.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
details="$GATES_OUT/S7.details.jsonl"; : > "$details"
top=$(git rev-parse --show-toplevel 2>/dev/null) || {
  jq -nc --arg h "$(pwd)" '{rule: "repo", reason: "not_a_git_repo", hit: $h}' >> "$details"; top=$(pwd); }
cd "$top" || exit 1
pattern='uses:\s*(?!\./)[^@\s]+(?:@(?![0-9a-f]{40}\b)\S*|\s*$)'
files=0
for f in $(git ls-files '.github/workflows/*.yml' '.github/workflows/*.yaml'); do
  files=$((files + 1))
  out=$("$lib/nohits" /usr/bin/grep -nP -e "$pattern" "$f"); rc=$?
  case $rc in
    0) ;;
    1) printf '%s\n' "$out" | /usr/bin/grep -E '^[0-9]+:' | jq -Rc --arg f "$f" \
         '(index(":")) as $i | {rule: "unpinned_uses", file: $f, line: (.[:$i] | tonumber), hit: .[$i + 1:]}' >> "$details" ;;
    *) jq -nc --arg f "$f" --arg o "$out" '{rule: "finder", file: $f, reason: "finder_error", output: $o}' >> "$details" ;;
  esac
done
[ "$files" -gt 0 ] || jq -nc '{rule: "workflows", reason: "no tracked .github/workflows/*.yml"}' >> "$details"
value=$(jq -s 'length' "$details")
gate_result S7 "$value" "$files" "$details"; gr=$?
printf 'S7: %s unpinned uses lines across %s workflow files\n' "$value" "$files"
[ "$gr" -eq 0 ] && [ "$value" -eq 0 ]
