#!/bin/sh
# P0-i1-T06 reviewer round 5: R56 duplicate-key and formats rules, plus a gate-contract regression sweep.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
T=.gates/tmp/test-gate-libs-edge5; mkdir -p "$T"
jq '.good' tests/gates/fixtures/ag-values.json > "$T/good.json"

ag() { # ag NAME pass | fail [CHECKS]
  name=$1; want=$2; only=${3:-}
  rm -f "$T/AG-values.json"
  GATES_OUT="$T" python3 -I scripts/gates/ag-values.py --inputs "$T/m.json" >/dev/null 2>&1; rc=$?
  got=$(jq -r '[.details[] | select(.ok | not) | .check] | join(",")' "$T/AG-values.json" 2>/dev/null)
  if [ "$want" = fail ]; then
    if [ "$rc" -eq 1 ] && jq -e '.value >= 1 and .denominator > 0' "$T/AG-values.json" >/dev/null 2>&1 && { [ -z "$only" ] || [ "$got" = "$only" ]; }; then printf 'ok - %s\n' "$name"
    else printf 'not ok - %s (rc=%s, failing [%s], want [%s])\n' "$name" "$rc" "$got" "$only"; fails=$((fails + 1)); fi
  else
    if [ "$rc" -eq 0 ] && jq -e '.value == 0 and .denominator == 31' "$T/AG-values.json" >/dev/null 2>&1; then printf 'ok - %s\n' "$name"
    else printf 'not ok - %s (rc=%s, failing [%s])\n' "$name" "$rc" "$got"; fails=$((fails + 1)); fi
  fi
}
sp() { printf '%s\n' "$1" > "$T/y.yaml"; jq --rawfile x "$T/y.yaml" '.spectral_yaml = $x' "$T/good.json" > "$T/m.json"; }
E='extends: ["spectral:oas"]'
B='  pp-a: error
  pp-b: error
  pp-c: error
  pp-d: error'

# --- duplicate keys: same key in different mappings is fine, in one mapping is not ---
sp "$E
rules:
  pp-a:
    given: \$
    severity: warn
  pp-b:
    given: \$
    severity: warn
  pp-c:
    given: \$
    severity: warn
  pp-d:
    given: \$
    severity: warn
  pp-e:
    given: \$
    severity: warn";                                 ag "the same keys in sibling rule bodies pass" pass
sp "$E
rules:
  pp-a:
    given: \$
    then:
      function: truthy
  pp-b:
    given: \$
    then:
      function: truthy
  pp-c: error
  pp-d: error
  pp-e: error";                                      ag "a nested then.function in sibling rules passes" pass
sp "$E
rules:
$B
  pp-e:
    given: \$
    then:
      - function: truthy
      - function: falsy";                            ag "the same key in sibling list items passes" pass
sp "$E
rules:
$B
  pp-e:
    given: \$
    then:
      function: truthy
      function: falsy";                              ag "a repeated key deep in a rule body fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e:
    given: \$
    then:
      - function: truthy
        function: falsy";                            ag "a repeated key inside one list-item mapping fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e: error
  \"pp-e\": off";                                    ag "a quoted spelling of a repeated rule key fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e: error
  'pp-e': off";                                      ag "a single-quoted spelling of a repeated rule key fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e:
    severity: error
    severity: off";                                  ag "severity repeated within one rule body fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e:
    severity: off
    severity: error";                                ag "severity repeated with off first also fails" fail spectral.subset
sp "$E
documentationUrl: https://example.test/a
documentationUrl: https://example.test/b
rules:
$B
  pp-e: error";                                      ag "a repeated top-level documentationUrl fails" fail spectral.subset
sp "$E
extends: [spectral:asyncapi]
rules:
$B
  pp-e: error";                                      ag "a repeated top-level extends fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e: error
functions:
  - a
functionsDir: ./fn
functions:
  - b";                                              ag "a repeated top-level functions key fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e: error
functions:
  - a
functionsDir: ./fn";                                 ag "functions, functionsDir and documentationUrl are accepted keys" pass
sp "$E
rules:
$B
  pp-e: error
x: 1
x: 2";                                               ag "an unknown key still fails as an unknown key" fail spectral.subset
# --- formats anywhere is rejected, lookalike keys are not ---
sp "$E
rules:
$B
  pp-e:
    given: \$
    x-formats: keep";                                ag "a key x-formats is not formats" pass
sp "$E
rules:
$B
  pp-e:
    given: \$
    formatsx: keep";                                 ag "a key formatsx is not formats" pass
sp "$E
rules:
$B
  pp-e:
    given: \$
    \"formats\":
      - oas3";                                       ag "a quoted formats key fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e:
    given: \$
    'formats':
      - oas3";                                       ag "a single-quoted formats key fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e:
    then:
      - formats: [oas3]";                            ag "formats as a list-item key fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e:
    description: |
      formats:
        - oas2";                                     ag "the word formats: inside a block scalar is text, not a key" pass
sp "$E
rules:
$B
  pp-e: error # formats: [oas2]";                    ag "formats inside a comment is ignored" pass
sp "$E
formats:
  - oas3.1
rules:
$B
  pp-e: error";                                      ag "formats at the top level fails even for oas3.1" fail spectral.subset

# --- gate contract regression: denominator, artifact and exit status on a subset failure ---
sp "$E
rules:
$B
  pp-e: error
  pp-e: off"
rm -f "$T/AG-values.json"
check_out "AG-values subset failure writes exit 1 and an artifact with value <= denominator" "1 true" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null 2>&1; rc=$?; echo "$rc $(jq -r ".value >= 1 and .value <= .denominator and .denominator >= 1" "$0/AG-values.json")"' "$T"
check_out "AG-values subset failure names the check spectral.subset once" "spectral.subset" sh -c 'jq -r "[.details[] | select(.check|startswith(\"spectral\")) | .check] | join(\",\")" "$0/AG-values.json"' "$T"
check_out "AG-values --only spectral on a subset failure has denominator 1" "1" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" --only spectral >/dev/null 2>&1; jq -r .denominator "$0/AG-values.json"' "$T"
check_out "AG-values --only spectral on a good file has denominator 2" "2" sh -c 'jq -r ".spectral_yaml" "$0/good.json" > "$0/g.yaml"; jq --rawfile x "$0/g.yaml" ".spectral_yaml = \$x" "$0/good.json" > "$0/g.json"; GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/g.json" --only spectral >/dev/null 2>&1; jq -r .denominator "$0/AG-values.json"' "$T"
check_out "AG-values good fixture still passes with 31 checks" "0 31" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/good.json" >/dev/null 2>&1; jq -r "\"\(.value) \(.denominator)\"" "$0/AG-values.json"' "$T"
finish
