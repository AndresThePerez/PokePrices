#!/bin/sh
# P0-i1-T35: the nested clean-clone run works, isolates itself, and (since the T36 freeze) passes every in-scope hard gate.
# P1-i1-T07: "passes" now means the in-scope failing hard set equals the ids tests/pending-gates.txt lists.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
PG=tests/pending-gates.txt
mkdir -p .gates/tmp; T=.gates/tmp/T35; mkdir -p "$T"
$DC up -d --wait
check_out "the api PHP memory_limit is 512M (cold type coverage fits)" 512M $DC exec -T api php -r 'echo ini_get("memory_limit");'
env GATES_OUT="$T" PP_RUN_CONTEXT=primary sh scripts/gates/ec-ci-parity.sh > "$T/run.log" 2>&1
N="$T/EC-ci-parity.nested.json"
check "nested scorecard copied" test -f "$N"
check "nested run used the nested context" jq -e '.run_context == "nested"' "$N"
check "nested run tested this HEAD" sh -c 'test "$(jq -r .head "$0")" = "$(git rev-parse HEAD)"' "$N"
check "nested run recorded at least 37 gates" jq -e '(.gates | length) >= 37' "$N"
check "EC-mex and EC-ci-parity are out_of_scope in the nested run" jq -e '[.gates[] | select(.id == "EC-mex" or .id == "EC-ci-parity") | .status] == ["out_of_scope","out_of_scope"]' "$N"
P=$(pending_json "$PG") || P=invalid
check "the nested run's in-scope failing hard set matches tests/pending-gates.txt (ids out of scope there excepted)" jq -e --argjson p "$P" '[.gates[] | select(.status == "out_of_scope") | .id] as $o | ([.gates[] | select(.hard and .status != "pass" and .status != "out_of_scope") | .id] | sort) == [$p[] | select(. as $i | $o | index($i) == null)]' "$N"
check "the nested DEN has no failing details" jq -e '.value == 0 and .details == []' "$T/EC-ci-parity.nested.DEN.json"
check "no ppclean container is left running" sh -c 'test -z "$(docker ps -q --filter label=com.docker.compose.project=ppclean)"'
check "the primary stack is still healthy" $DC up -d --wait
check "the primary tree is still clean" sh -c 'test -z "$(git status --porcelain --untracked-files=all)"'
check "refuses to nest itself" sh -c '! env GATES_OUT="$0" PP_RUN_CONTEXT=nested sh scripts/gates/ec-ci-parity.sh' "$T"
finish
