#!/bin/sh
# C3: every /v1 response in the feature suite matched openapi/api.json.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
. "$(dirname "$0")/../lib/gate-result.sh"
ledger="$GATES_OUT/C3.ledger.jsonl"; : > "$ledger"
docker compose -f compose.dev.yml exec -T -e PP_C3_LEDGER="/workspace/$ledger" -w /workspace/api api \
  vendor/bin/pest -c phpunit.xml --testsuite=Feature --log-junit="/workspace/$GATES_OUT/C3.junit.xml"
pest_rc=$?
bad=$(python3 -I scripts/lib/junit.py "$GATES_OUT/C3.junit.xml" --field bad 2>/dev/null || echo 1)
unvalidated=$(jq -s '[.[] | select(.validated | not)] | length' "$ledger")
validated=$(jq -s '[.[] | select(.validated and .reason != "test_route" and .reason != "head_request")] | length' "$ledger")
[ "$pest_rc" -eq 0 ] || bad=$((bad + 1))
value=$((unvalidated + bad))
jq -c '.' "$ledger" > "$GATES_OUT/C3.details.jsonl"
gate_result C3 "$value" "$validated" "$GATES_OUT/C3.details.jsonl"
printf 'C3: %s unvalidated or failing, %s validated responses\n' "$value" "$validated"
[ "$value" -eq 0 ] && [ "$validated" -ge 1 ]
