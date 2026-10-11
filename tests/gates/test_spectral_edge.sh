#!/bin/sh
# P0-i1-T21 edge cases: each pp- rule fires alone on a one-defect contract, and the ruleset resists loosening.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
NODE="docker compose -f compose.dev.yml exec -T -w /workspace/web node"
T=.gates/tmp/spectral-edge; mkdir -p "$T"
OP='.paths["/ready"].get'

# lint NAME JQ_FILTER: mutate the committed contract, lint it, leave the sorted pp- codes in $T/NAME.codes
lint() {
  jq "$2" openapi/api.json > "$T/$1.json"
  $NODE ./node_modules/.bin/spectral lint "/workspace/$T/$1.json" --ruleset ../.spectral.yaml --fail-severity=warn -f json -o "/workspace/$T/$1.out" >/dev/null 2>&1
  jq -r '[.[].code | select(startswith("pp-"))] | unique | join(",")' "$T/$1.out" > "$T/$1.codes" 2>/dev/null
}
only() { # NAME RULE
  check_out "$1 trips exactly $2" "$2" cat "$T/$1.codes"
}

check_out "unmutated contract has no findings" "" sh -c "jq . openapi/api.json > $T/base.json && $NODE ./node_modules/.bin/spectral lint /workspace/$T/base.json --ruleset ../.spectral.yaml -f json -o /workspace/$T/base.out >/dev/null 2>&1; jq -r '[.[].code]|join(\",\")' $T/base.out"

lint empty-summary "$OP.summary = \"\" | ."
only empty-summary pp-operation-summary
lint empty-opid "$OP.operationId = \"\" | ."
only empty-opid pp-operation-id
lint open-true "$OP.responses[\"200\"].content[\"application/json\"].schema.additionalProperties = true | ."
only open-true pp-closed-objects
lint open-missing "del($OP.responses[\"503\"].content[\"application/problem+json\"].schema.additionalProperties) | ."
only open-missing pp-closed-objects
lint json-only-error "$OP.responses[\"503\"].content = {\"application/json\": $OP.responses[\"503\"].content[\"application/problem+json\"]} | ."
only json-only-error pp-error-problem-json
lint no-error "del($OP.responses[\"503\"]) | ."
only no-error pp-error-response
lint bare-2xx "$OP.responses[\"200\"].content[\"application/json\"].schema = {\"type\":\"object\"} | ."
only bare-2xx pp-typed-2xx

# A 4xx whose content map holds application/json beside application/problem+json: record the behaviour as a check.
lint json-and-problem "$OP.responses[\"503\"].content[\"application/json\"] = $OP.responses[\"503\"].content[\"application/problem+json\"] | ."
only json-and-problem ""

# A nested open object inside a schema property must still be flagged.
lint nested-open "$OP.responses[\"200\"].content[\"application/json\"].schema.properties.extra = {\"type\":\"object\",\"properties\":{\"a\":{\"type\":\"string\"}}} | ."
only nested-open pp-closed-objects

# A component schema that is an open object with properties must be flagged too.
lint component-open ".components.schemas.Loose = {\"type\":\"object\",\"properties\":{\"a\":{\"type\":\"string\"}}} | ."
only component-open pp-closed-objects

# --- the committed spec and ruleset keep their invariants -----------------------------------------
check "every object with properties is closed in the export" jq -e '[.. | objects | select(has("properties")) | .additionalProperties] | unique == [false]' openapi/api.json
check "export server is the relative /v1 (R78)" jq -e '.servers == [{"url":"/v1"}] and .info.title == "PokéPrices"' openapi/api.json
check "contact carries a name only" jq -e '.info.contact | keys == ["name"]' openapi/api.json
check "503 body lists exactly the readiness fields" jq -e '.paths["/ready"].get.responses["503"].content["application/problem+json"].schema.required | sort == ["checks","detail","instance","request_id","status","title","type"]' openapi/api.json
check "six pp- rules at severity error" sh -c 'test "$(grep -c "^  pp-" .spectral.yaml)" -ge 6 && test "$(grep -c "^    severity: error$" .spectral.yaml)" = "$(grep -c "^  pp-" .spectral.yaml)"'
check "ruleset has no overrides, formats, anchors or flow mappings with content" sh -c '! grep -Eq "^(overrides|formats|functions):|[&*][A-Za-z]|: *\{ *[^} ]|^ *<<:" .spectral.yaml'

# AG-values judges a loosened scratch ruleset (inputs mode; the tracked file is never touched).
agrun() { # NAME FILTER_COMMAND: pipe the live ruleset through the filter, then judge it
  sh -c "$2" < .spectral.yaml > "$T/$1.yaml" || return 1
  jq -Rs '{spectral_yaml: .}' "$T/$1.yaml" > "$T/$1.inputs.json"
  mkdir -p "$T/$1.out"
  GATES_OUT="$T/$1.out" python3 -I scripts/gates/ag-values.py --only spectral --inputs "$T/$1.inputs.json"
}
nagrun() { ! agrun "$@"; }
check "AG-values accepts the live ruleset in inputs mode" agrun live cat
check "AG-values rejects a formats key" nagrun formats "sed 's/^rules:/formats: [oas3]\\nrules:/'"
check "AG-values rejects a flow mapping with content" nagrun flow "sed '0,/^    severity: error\$/s//    severity: {a: b}/'"
check "AG-values rejects an overrides key" nagrun overrides "sed 's/^rules:/overrides:\\n  - files: [x]\\nrules:/'"
check "AG-values rejects two rules turned off (fewer than five enabled)" nagrun off "awk '/^    severity: error\$/ {n++; if (n<=2) {print \"    severity: off\"; next}} {print}'"
finish
