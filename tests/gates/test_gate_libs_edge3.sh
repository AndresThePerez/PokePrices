#!/bin/sh
# P0-i1-T06 reviewer round 3: attacks on the R53 evaluated-eslint input and the Spectral
# severity/extends parsing.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
T=.gates/tmp/test-gate-libs-edge3; mkdir -p "$T"
F=tests/gates/fixtures/ag-values.json
jq '.good' "$F" > "$T/good.json"

ag() { # ag NAME fail|pass CHECKS : CHECKS (optional, comma list) must be exactly the failing checks
  name=$1; want=$2; only=${3:-}
  rm -f "$T/AG-values.json"
  GATES_OUT="$T" python3 -I scripts/gates/ag-values.py --inputs "$T/m.json" >/dev/null 2>&1; rc=$?
  got=$(jq -r '[.details[] | select(.ok | not) | .check] | join(",")' "$T/AG-values.json" 2>/dev/null)
  if [ "$want" = fail ]; then
    if [ "$rc" -eq 1 ] && jq -e '.value >= 1 and .denominator > 0' "$T/AG-values.json" >/dev/null 2>&1 && { [ -z "$only" ] || [ "$got" = "$only" ]; }; then printf 'ok - %s\n' "$name"
    else printf 'not ok - %s (rc=%s, failing [%s], want [%s])\n' "$name" "$rc" "$got" "$only"; fails=$((fails + 1)); fi
  else
    if [ "$rc" -eq 0 ] && jq -e '.value == 0' "$T/AG-values.json" >/dev/null 2>&1; then printf 'ok - %s\n' "$name"
    else printf 'not ok - %s (rc=%s, failing [%s])\n' "$name" "$rc" "$got"; fails=$((fails + 1)); fi
  fi
}
mut() { jq "$1" "$T/good.json" > "$T/m.json"; }
sp() { printf '%s\n' "$1" > "$T/y.yaml"; jq --rawfile x "$T/y.yaml" '.spectral_yaml = $x' "$T/good.json" > "$T/m.json"; }

# ---------- eslint_evaluated ----------
OK_NAMES='["typescript-eslint/base","typescript-eslint/eslint-recommended","typescript-eslint/strict-type-checked"]'
mut ".eslint_evaluated.config_names = $OK_NAMES";            ag "eslint evaluated good names pass" pass
mut '.eslint_evaluated.config_names = ["defineConfig > typescript-eslint/strict-type-checked"]'; ag "eslint evaluated extends-prefixed strict name passes" pass
mut '.eslint_evaluated.config_names = ["typescript-eslint/strict-type-checked-only"]'; ag "eslint evaluated lookalike strict-type-checked-only fails" fail eslint.strictTypeChecked
mut '.eslint_evaluated.config_names = ["x > typescript-eslint/strict-type-checked-only"]'; ag "eslint evaluated prefixed lookalike fails" fail eslint.strictTypeChecked
mut '.eslint_evaluated.config_names = ["x>typescript-eslint/strict-type-checked"]'; ag "eslint evaluated prefix without spaced separator fails closed" fail eslint.strictTypeChecked
mut '.eslint_evaluated.config_names = ["Typescript-ESLint/Strict-Type-Checked"]'; ag "eslint evaluated strict name is case-sensitive" fail eslint.strictTypeChecked
mut '.eslint_evaluated.config_names = [null, 5, {"name":"typescript-eslint/strict-type-checked"}]'; ag "eslint evaluated non-string names never match" fail eslint.strictTypeChecked
mut '.eslint_evaluated.config_names = []';                   ag "eslint evaluated empty names fail" fail eslint.strictTypeChecked
mut '.eslint_evaluated.config_names = "typescript-eslint/strict-type-checked"'; ag "eslint evaluated names as a string fail" fail eslint.strictTypeChecked
mut '.eslint_evaluated = null';                              ag "eslint evaluated null fails all three evaluated checks" fail eslint.strictTypeChecked,eslint.noInlineConfig,eslint.reportUnusedDisableDirectives
mut 'del(.eslint_evaluated)';                                ag "eslint evaluated missing fails all three evaluated checks" fail eslint.strictTypeChecked,eslint.noInlineConfig,eslint.reportUnusedDisableDirectives
mut '.eslint_evaluated.linterOptions = null';                ag "eslint evaluated linterOptions null fails" fail eslint.noInlineConfig,eslint.reportUnusedDisableDirectives
mut '.eslint_evaluated.linterOptions = {}';                  ag "eslint evaluated linterOptions {} fails" fail eslint.noInlineConfig,eslint.reportUnusedDisableDirectives
# A later entry turning the options off is what the evaluated (effective) value shows.
mut '.eslint_evaluated.linterOptions.noInlineConfig = false'; ag "eslint effective noInlineConfig false fails" fail eslint.noInlineConfig
for v in '"true"' 1 null '[true]' '"yes"'; do
  mut ".eslint_evaluated.linterOptions.noInlineConfig = $v"; ag "eslint effective noInlineConfig $v is not true" fail eslint.noInlineConfig
done
for v in true false 1 0 '"warn"' '"off"' '"2"' '["error"]' null '"true"' 3; do
  mut ".eslint_evaluated.linterOptions.reportUnusedDisableDirectives = $v"; ag "eslint effective reportUnusedDisableDirectives $v is not error" fail eslint.reportUnusedDisableDirectives
done
mut '.eslint_evaluated.linterOptions.reportUnusedDisableDirectives = "error"'; ag "eslint effective reportUnusedDisableDirectives \"error\" passes" pass
mut '.eslint_evaluated.linterOptions.reportUnusedDisableDirectives = 2.0';     ag "eslint effective reportUnusedDisableDirectives 2.0 passes" pass
# Source text is not evidence (R53): a perfect eslint_source with an empty evaluation fails.
mut '.eslint_source = "tseslint.configs.strictTypeChecked noInlineConfig: true reportUnusedDisableDirectives: \"error\"" | .eslint_evaluated = {"config_names": [], "linterOptions": {}}'
ag "eslint a perfect source text with an empty evaluation fails" fail eslint.strictTypeChecked,eslint.noInlineConfig,eslint.reportUnusedDisableDirectives
# Names alone do not make it strict: the effective rules decide.
mut ".eslint_evaluated.config_names = $OK_NAMES | .eslint_rules.\"@typescript-eslint/no-floating-promises\" = [0]"; ag "eslint strict names present but an effective strict rule off fails" fail eslint.strict_rules
mut ".eslint_evaluated.config_names = $OK_NAMES | .eslint_rules.\"@typescript-eslint/no-explicit-any\" = [1]"; ag "eslint strict names present but a strict rule at warn fails" fail eslint.strict_rules

# ---------- spectral severity ----------
E='extends: ["spectral:oas"]'
five() { printf '%s\nrules:\n' "$E"; for n in a b c d; do printf '  pp-%s: {}\n' "$n"; done; printf '  pp-e: %s\n' "$1"; }
sp "$(five '"-1"')";        ag "spectral quoted \"-1\" counts as off" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sp "$(five "'-1'")";        ag "spectral single-quoted '-1' counts as off" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sp "$(five '-1 # off')";    ag "spectral -1 with a trailing comment counts as off" fail spectral.custom_rules
sp "$(five '{ severity: -1 }')"; ag "spectral flow body severity -1 counts as off" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sp "$(five '{ severity: off, given: "$" }')"; ag "spectral flow body with severity off counts as off" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sp "$(five '{ severity: "off" }')"; ag "spectral quoted severity off counts as off" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sp "$(five "$(printf '\n    given: "$"\n    then:\n      function: truthy\n    severity: warn')")"; ag "spectral severity warn counts as enabled" pass  # R54: body rewritten in block form
sp "$(five '3')";           ag "spectral severity 3 (hint) counts as enabled" pass
sp "$(five "$(printf '\n    given: "$.severity-1"')")"; ag "spectral -1 inside a different token does not disable" pass  # R54: body rewritten in block form
sp "$(five 'false')";       ag "spectral false counts as off" fail spectral.custom_rules
sp "$(five 'FALSE')";       ag "spectral FALSE counts as off" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sp "$(five 'true')";        ag "spectral true counts as enabled" fail spectral.subset  # R54 FLIP: true is not in the severity token list
# The same off values written another way YAML and Spectral read as -1 / off.
sp "$(five '-1.0')";        ag "spectral float -1.0 is severity -1 (off)" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sp "$(five '&o off')";      ag "spectral anchored off is off" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sp "$(printf '%s\nrules:\n  pp-a: &o off\n  pp-b: *o\n  pp-c: *o\n  pp-d: *o\n  pp-e: *o\n' "$E")"; ag "spectral aliases of an off anchor are off" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sp "$(printf '%s\nrules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: {}\noverrides:\n  - files: ["openapi/*.json"]\n    rules:\n      pp-a: off\n      pp-b: off\n      pp-c: off\n' "$E")"; ag "spectral overrides that turn three pp rules off for the spec file fail" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails

# ---------- spectral extends ----------
R='rules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: {}'
sx() { sp "$(printf '%s\n%b' "$1" "$R")"; }
sx 'extends: spectral:oas';                                   ag "spectral bare scalar extends passes" pass
sx 'extends: "spectral:oas"';                                 ag "spectral quoted scalar extends passes" pass
sx 'extends: [spectral:oas]';                                 ag "spectral flow list extends passes" pass
sx 'extends: [[spectral:oas, all]]';                          ag "spectral nested all passes" pass
sx 'extends: [[spectral:oas, recommended]]';                  ag "spectral nested recommended passes" pass
sx 'extends: [[spectral:oas, off]]';                          ag "spectral nested off fails" fail spectral.extends_oas
sx 'extends: [[spectral:oas, "off"]]';                        ag "spectral nested quoted off fails" fail spectral.extends_oas
sx 'extends: [[spectral:oas, OFF]]';                          ag "spectral nested OFF fails" fail spectral.extends_oas
sx 'extends: [[spectral:oas, bogus]]';                        ag "spectral nested unknown mode fails" fail spectral.extends_oas
sx 'extends: [[spectral:oas]]';                               ag "spectral one-element tuple fails" fail spectral.extends_oas
sx 'extends: [spectral:oas, [spectral:oas, off]]';            ag "spectral good entry plus an off entry fails" fail spectral.extends_oas
sx 'extends: [[spectral:oas, off], spectral:oas]';            ag "spectral off entry then a good entry fails" fail spectral.extends_oas
sx 'extends: [spectral:oas3]';                                ag "spectral lookalike spectral:oas3 fails" fail spectral.extends_oas
sx 'extends: [spectral:asyncapi]';                            ag "spectral asyncapi only fails" fail spectral.extends_oas
sx 'extends: []';                                             ag "spectral empty extends fails" fail spectral.extends_oas
sx 'extends:';                                                ag "spectral missing extends value fails" fail spectral.extends_oas
sx 'extends: [spectral:oas';                                  ag "spectral unterminated flow list fails" fail spectral.extends_oas
sx 'extends: [spectral:oas]]';                                ag "spectral trailing bracket fails" fail spectral.extends_oas
sx 'extends: &e [spectral:oas]';                              ag "spectral anchored extends fails closed" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sx 'extends: !!seq [spectral:oas]';                           ag "spectral tagged extends fails closed" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sx 'extends: *e';                                             ag "spectral alias extends fails" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sx 'extends: {spectral:oas: all}';                            ag "spectral mapping extends fails" fail spectral.extends_oas
sx 'extends: "spectral:oas" # [off]';                         ag "spectral comment after extends is ignored" pass
sx 'extends: [spectral:oas, spectral:asyncapi]';              ag "spectral oas plus asyncapi passes" pass
sx 'extends:
  - spectral:oas';                                            ag "spectral block list extends passes" pass
sx 'extends:
  - [spectral:oas, off]';                                     ag "spectral block list entry with off fails" fail spectral.extends_oas
sx 'extends:
  - [spectral:oas, all]
  - [spectral:oas, off]';                                     ag "spectral block list good then off fails" fail spectral.extends_oas
sx 'extends:
  - spectral:oas
extends: [spectral:asyncapi]';                                ag "spectral duplicate extends keys fail" fail spectral.subset  # R56: duplicate keys fail the subset
sx 'extends: [spectral:oas]
extends: [spectral:oas]';                                     ag "spectral duplicate identical extends keys fail" fail spectral.subset  # R56: duplicate keys fail the subset
sx 'description: extends: [spectral:oas]';                    ag "spectral extends text inside another key fails" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sx '  extends: [spectral:oas]';                               ag "spectral indented extends is not top-level" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sx 'Extends: [spectral:oas]';                                 ag "spectral Extends key case-sensitive fails" fail spectral.subset  # R54: restricted YAML subset, the spectral group fails
sx 'extends: [spectral:oas, off]';                            ag "spectral top-level tuple with off fails" fail spectral.extends_oas
printf '' > "$T/y.yaml"; jq --rawfile x "$T/y.yaml" '.spectral_yaml = $x' "$T/good.json" > "$T/m.json"
ag "spectral empty yaml fails both spectral checks" fail spectral.extends_oas,spectral.custom_rules
mut '.spectral_yaml = null';                                  ag "spectral yaml null fails both checks" fail spectral.extends_oas,spectral.custom_rules
mut '.spectral_yaml = 7';                                     ag "spectral yaml number fails both checks" fail spectral.extends_oas,spectral.custom_rules
finish
