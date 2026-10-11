#!/bin/sh
# C6: the committed SPA types equal a fresh openapi-typescript generation.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
. "$(dirname "$0")/../lib/gate-result.sh"
target=${C6_TARGET:-web/src/api/schema.d.ts}
details="$GATES_OUT/C6.details.jsonl"; : > "$details"
value=0
git ls-files --error-unmatch web/src/api/schema.d.ts >/dev/null 2>&1 || { value=1; echo '{"check":"tracked","ok":false}' >> "$details"; }
docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/openapi-typescript ../openapi/api.json -o "/workspace/$GATES_OUT/C6.schema.d.ts" \
  || { value=1; echo '{"check":"generate","ok":false}' >> "$details"; }
cmp -s "$target" "$GATES_OUT/C6.schema.d.ts" || { value=1; echo '{"check":"identical","ok":false}' >> "$details"; }
denominator=$(jq '.paths | length' openapi/api.json 2>/dev/null || echo 0)
gate_result C6 "$value" "${denominator:-0}" "$details"
printf 'C6: value %s, %s paths\n' "$value" "$denominator"
[ "$value" -eq 0 ] && [ "${denominator:-0}" -ge 1 ]
