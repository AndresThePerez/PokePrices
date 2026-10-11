#!/bin/sh
# C1: the committed openapi/api.json equals a fresh Scramble export (cmp, never git diff, F-P0-088).
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
. "$(dirname "$0")/../lib/gate-result.sh"
details="$GATES_OUT/C1.details.jsonl"; : > "$details"
value=0
if ! git ls-files --error-unmatch openapi/api.json >/dev/null 2>&1; then
  value=1; echo '{"check":"tracked","ok":false}' >> "$details"
fi
if ! docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan scramble:export --path="/workspace/$GATES_OUT/C1.api.json"; then
  value=1; echo '{"check":"export","ok":false}' >> "$details"
fi
if ! cmp -s openapi/api.json "$GATES_OUT/C1.api.json"; then
  value=1; echo '{"check":"identical","ok":false}' >> "$details"
fi
if ! jq -e '.info.title == "PokéPrices"' openapi/api.json >/dev/null 2>&1; then
  value=1; echo '{"check":"title","ok":false}' >> "$details"
fi
denominator=$(jq '.paths | length' openapi/api.json 2>/dev/null || echo 0)
gate_result C1 "$value" "${denominator:-0}" "$details"
printf 'C1: value %s, %s paths\n' "$value" "$denominator"
[ "$value" -eq 0 ] && [ "${denominator:-0}" -ge 1 ]
