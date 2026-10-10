#!/bin/sh
# P0-i1-T06 reviewer round 4: attacks on the R54/R55 restricted-subset validator for .spectral.yaml.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
T=.gates/tmp/test-gate-libs-edge4; mkdir -p "$T"
jq '.good' tests/gates/fixtures/ag-values.json > "$T/good.json"

ag() { # ag NAME pass | fail [CHECKS]  (CHECKS = exact comma list of failing checks)
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

sp "$E
rules:
$B
  pp-e: error";                                   ag "baseline: block-form rules with plain severities pass" pass
# --- characters that look like YAML properties but sit inside scalars ---
sp "$E
documentationUrl: \"https://example.test/a&b*c!d\"
rules:
$B
  pp-e:
    message: \"a & b * c ! d\"
    description: 'x &y *z !w'
    given: \"\$.paths[*]\"";                       ag "quoted & * ! inside string values do not trip the subset" pass
sp "$E
rules:
$B
  pp-e:
    message: wow & * ! ok
    given: \$.paths[*]";                           ag "mid-scalar & * ! in plain values do not trip the subset" pass
sp "$E
rules:
$B
  pp-e:
    description: |
      & not an anchor
      * not an alias
      ! not a tag
      overrides: not a key
    severity: warn";                               ag "block scalar text holding & * ! and overrides: does not trip the subset" pass
# --- comments ---
sp "$E
# overrides:
  # &anchor *alias !tag
rules:
$B
  pp-e: error # overrides: [x]";                   ag "comments containing overrides:, anchors and flow do not trip the subset" pass
sp "$E
rules:
$B
  pp-e: error
# pp-f: off";                                      ag "a trailing comment line is ignored" pass
# --- line endings and document structure ---
printf '%s\r\n' "$E" "rules:" "  pp-a: error" "  pp-b: error" "  pp-c: error" "  pp-d: error" "  pp-e: error" > "$T/y.yaml"
jq --rawfile x "$T/y.yaml" '.spectral_yaml = $x' "$T/good.json" > "$T/m.json";   ag "CRLF line endings pass" pass
sp "---
$E
rules:
$B
  pp-e: error";                                    ag "document start marker --- fails closed" fail spectral.subset
sp "$E
rules:
$B
  pp-e: error
---
rules: {}";                                        ag "a second YAML document fails closed" fail spectral.subset
sp "$E
rules:
$B
  pp-e: error
...";                                              ag "document end marker ... fails closed" fail spectral.subset
sp "$E
rules:
$B
$(printf '\tpp-e: error')";                        ag "tab-indented rule line fails closed" fail spectral.subset
sp "$E
rules:
$B
  pp-e: error
$(printf '\t')# tab then comment";                 ag "a tab-only comment line is not a violation" pass
printf '\357\273\277%s\n' "$E" > "$T/y.yaml"; printf '%s\n' "rules:" "$B" "  pp-e: error" >> "$T/y.yaml"
jq --rawfile x "$T/y.yaml" '.spectral_yaml = $x' "$T/good.json" > "$T/m.json";   ag "a BOM before extends fails closed, never passes" fail
# --- structure the subset must reject ---
sp "$E
rules:
$B
  pp-e: error
overrides:
  - files: [\"*.json\"]";                          ag "overrides key after the rules fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e: error
Overrides:
  - x";                                            ag "wrongly cased Overrides key fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e: error
rules:
  pp-f: error";                                    ag "duplicate top-level rules key fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e: error
  <<: {}";                                         ag "merge key under rules fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e: !!str error";                              ag "a tag on a rule value fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e:
    severity: &s error";                           ag "an anchor on a nested severity fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e:
    given: [\"\$\"]";                              ag "flow sequence inside a rule body fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e:
    severity: 4";                                  ag "severity 4 fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e:
    severity: 0.0";                                ag "severity 0.0 fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e:
    severity: Error";                              ag "severity Error (case) fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e:
    severity:";                                    ag "empty nested severity fails" fail spectral.subset
sp "$E
rules:
$B
  pp-e: ~";                                        ag "null rule value ~ fails" fail spectral.subset
# --- ways to pass with fewer than five live pp- rules (must FAIL) ---
# A key repeated under rules: YAML parsers either reject it or keep the last value.
sp "$E
rules:
$B
  pp-e: error
  pp-e: off";                                      ag "pp-e repeated with a final off does not count as enabled" fail
sp "$E
rules:
$B
  pp-e: error
  pp-a: off";                                      ag "pp-a repeated with a final off does not count as enabled" fail
sp "$E
rules:
$B
  pp-e: error
  pp-e: error";                                    ag "an identical repeated rule key fails closed" fail
sp "$E
rules:
$B
  pp-e: error
  pp-a:
    severity: off";                                ag "pp-a repeated with a nested final severity off fails" fail
# formats scopes a whole ruleset (or one rule) to document types; oas2 never matches this OpenAPI 3 spec.
sp "$E
formats:
  - oas2
rules:
$B
  pp-e: error";                                    ag "ruleset formats limited to oas2 never applies to the OpenAPI 3 spec" fail
sp "$E
rules:
  pp-a:
    formats:
      - oas2
  pp-b:
    formats:
      - oas2
  pp-c:
    formats:
      - oas2
  pp-d: error
  pp-e: error";                                    ag "rules limited to oas2 do not count as live pp- rules" fail
sp "$E
formats:
  - oas3
rules:
$B
  pp-e: error";                                    ag "ruleset formats oas3 passes" fail spectral.subset  # R56 FLIP: any formats key fails the subset
finish
