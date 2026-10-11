#!/bin/sh
# T11: no skipped, todo or focused tests in api/tests and web/src (both must exist).
# A finder error (missing directory, bad pattern) counts as one hit, never as clean.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
lib=$(cd "$(dirname "$0")/../lib" && pwd)
. "$lib/gate-result.sh"
case $GATES_OUT in /*) ;; *) GATES_OUT=$(pwd)/$GATES_OUT ;; esac
details="$GATES_OUT/T11.details.jsonl"; : > "$details"
top=$(git rev-parse --show-toplevel 2>/dev/null) || {
  jq -nc --arg h "$(pwd)" '{rule: "repo", reason: "not_a_git_repo", hit: $h}' >> "$details"; top=$(pwd); }
cd "$top" || exit 1
GREP="/usr/bin/grep -rnHP --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=dist --exclude-dir=.git --exclude-dir=.gates"
scan() { # RULE PATTERN DIR
  out=$("$lib/nohits" $GREP -e "$2" "$3"); rc=$?
  case $rc in
    0) ;;
    1) printf '%s\n' "$out" | sed '$d' | jq -Rc --arg r "$1" '{rule: $r, hit: .}' >> "$details" ;;
    *) jq -nc --arg r "$1" --arg h "$out" '{rule: $r, reason: "finder_error", hit: $h}' >> "$details" ;;
  esac
}
scan php_skip_focus '(->(?:skip\w*|todo)\(|->only\(\s*\)|markTestSkipped|markTestIncomplete)' api/tests
scan ts_skip_focus '\b(?:it|test|describe|suite)\.(?:only|skip|todo|fixme|skipIf|runIf)\w*\b|\b(?:xit|xtest|xdescribe|fit|fdescribe)\(' web/src
php_files=0; ts_files=0
[ -d api/tests ] && php_files=$(find api/tests -name vendor -prune -o -type f -name '*.php' -print | wc -l)
[ -d web/src ] && ts_files=$(find web/src -name node_modules -prune -o -type f \( -name '*.test.ts' -o -name '*.test.tsx' \) -print | wc -l)
denominator=$((php_files + ts_files))
value=$(jq -s 'length' "$details")
gate_result T11 "$value" "$denominator" "$details"; gr=$?
printf 'T11: %s skipped, todo or focused tests across %s test files\n' "$value" "$denominator"
[ "$gr" -eq 0 ] && [ "$value" -eq 0 ]
