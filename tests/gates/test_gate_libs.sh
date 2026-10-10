#!/bin/sh
# P0-i1-T06: nohits exit codes, gate_result shape, AG-values on fixtures.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
T=.gates/tmp/test-gate-libs; mkdir -p "$T"
printf 'alpha\nbeta\n' > "$T/in.txt"
check_out "nohits clean exits 0" 0 sh -c 'scripts/lib/nohits /usr/bin/grep -n zzz "$0" >/dev/null; echo $?' "$T/in.txt"
check_out "nohits hit exits 1" 1 sh -c 'scripts/lib/nohits /usr/bin/grep -n beta "$0" >/dev/null; echo $?' "$T/in.txt"
check_out "nohits finder error exits 2" 2 sh -c 'scripts/lib/nohits /usr/bin/grep -n beta /nonexistent/file >/dev/null; echo $?'
check_out "nohits bad regex exits 2" 2 sh -c 'scripts/lib/nohits /usr/bin/grep -nP "(" "$0" >/dev/null; echo $?' "$T/in.txt"
: > "$T/d.jsonl"; printf '{"k":1}\n' >> "$T/d.jsonl"
check "gate_result writes the contract" sh -c 'GATES_OUT="$0" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result ZZ 3 7 "$0/d.jsonl" && jq -e ".id==\"ZZ\" and .value==3 and .denominator==7 and (.details|length)==1" "$0/ZZ.json"' "$T"
F=tests/gates/fixtures/ag-values.json
jq '.good' "$F" > "$T/good.json"
check "AG-values passes the good inputs" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/good.json" && jq -e ".value == 0 and .denominator >= 15" "$0/AG-values.json"' "$T"
n=$(jq '.mutations | length' "$F"); i=0
while [ "$i" -lt "$n" ]; do
  name=$(jq -r ".mutations[$i].name" "$F")
  jq --argjson i "$i" '.mutations[$i] as $m | .good | if $m.value == null then delpaths([$m.path]) else setpath($m.path; $m.value) end' "$F" > "$T/m.json"
  rm -f "$T/AG-values.json"
  check "AG-values fails: $name" sh -c '! GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" && jq -e ".value >= 1" "$0/AG-values.json"' "$T"
  i=$((i + 1))
done
check "AG-values --only limits the groups" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/good.json" --only phpstan && jq -e ".denominator >= 3 and .denominator < 15" "$0/AG-values.json"' "$T"
check "AG-values refuses to run without GATES_OUT" sh -c '! env -u GATES_OUT python3 -I scripts/gates/ag-values.py --inputs "$0/good.json"' "$T"
# Added by the implementer: each mutation fails exactly the rule it targets, no other.
i=0
for want in phpstan.level phpstan.ignoreErrors phpstan.baseline_file phpstan.baseline_include \
    phpstan.reportUnmatchedIgnoredErrors pint.preset phpunit.failOnRisky phpunit.source_exclude \
    phpunit.db_connection tsconfig.app.noUncheckedIndexedAccess tsconfig.node.strict \
    eslint.strictTypeChecked eslint.noInlineConfig,eslint.reportUnusedDisableDirectives \
    eslint.strict_rules vitest.coverage_include vitest.thresholds_lines \
    vitest.thresholds_branches vitest.passWithNoTests spectral.custom_rules; do
  name=$(jq -r ".mutations[$i].name" "$F")
  jq --argjson i "$i" '.mutations[$i] as $m | .good | if $m.value == null then delpaths([$m.path]) else setpath($m.path; $m.value) end' "$F" > "$T/m.json"
  rm -f "$T/AG-values.json"
  check_out "AG-values names the broken rule: $name" "$want" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null; jq -r "[.details[] | select(.ok | not) | .check] | join(\",\")" "$0/AG-values.json"' "$T"
  i=$((i + 1))
done
check_out "every fixture mutation has a named expectation" "$n" echo "$i"
# Added by the implementer: a crash must never look like a clean failure or a pass.
check_out "AG-values exits 1 (not a crash) on a violation" 1 sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null 2>&1; echo $?' "$T"
check_out "AG-values exits 2 and names GATES_OUT when it is unset" "2 1" sh -c 'msg=$(env -u GATES_OUT python3 -I scripts/gates/ag-values.py --inputs "$0/good.json" 2>&1); rc=$?; printf "%s\n" "$msg" | /usr/bin/grep -c "GATES_OUT must be set" | { read -r c; echo "$rc $c"; }' "$T"
printf '{}\n' > "$T/empty.json"
rm -f "$T/AG-values.json"
check "AG-values fails every check on empty inputs" sh -c '! GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/empty.json" && jq -e ".denominator >= 15 and .value == .denominator" "$0/AG-values.json"' "$T"
printf '{"phpstan_params": "x", "pint": [], "phpunit_xml": "<phpunit", "tsconfig_app": 1, "tsconfig_node": {"compilerOptions": []}, "eslint_rules": {"@typescript-eslint/no-unnecessary-condition": []}, "vitest_test": {"passWithNoTests": true, "coverage": {"include": "src/**", "thresholds": {"lines": true, "branches": "99"}}}, "spectral_yaml": 5, "phpstan_params_x": null}\n' > "$T/junk.json"
rm -f "$T/AG-values.json"
check "AG-values fails, never crashes, on wrongly typed inputs" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/junk.json" >/dev/null 2>&1; [ $? -eq 1 ] && jq -e ".denominator >= 15 and .value == .denominator" "$0/AG-values.json"' "$T"
check "AG-values details name every check with ok and reason" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/good.json" >/dev/null && jq -e "(.details | length) == .denominator and all(.details[]; (.check | type) == \"string\" and (.ok | type) == \"boolean\" and has(\"reason\"))" "$0/AG-values.json"' "$T"
check "AG-values --only rejects an unknown group by name" sh -c '! GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/good.json" --only phpstan,nope 2>"$0/err.txt" && /usr/bin/grep -q "unknown group.*nope" "$0/err.txt"' "$T"
rm -f "$T/AG-values.json"
check "AG-values live file groups write a consistent artifact" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --only pint,phpunit,spectral >/dev/null 2>&1; rc=$?; jq -e "(.value|type)==\"number\" and .denominator >= 1" "$0/AG-values.json" >/dev/null && if jq -e ".value == 0" "$0/AG-values.json" >/dev/null; then [ $rc -eq 0 ]; else [ $rc -eq 1 ]; fi' "$T"
# Fix round 1 (R50), implementer RED checks.
check_out "nohits with no finder exits 2" 2 sh -c 'scripts/lib/nohits >/dev/null 2>&1; echo $?'
for r in no-explicit-any no-floating-promises no-unsafe-assignment no-unsafe-member-access no-unsafe-call \
    no-unsafe-return no-misused-promises restrict-template-expressions no-unnecessary-condition no-non-null-assertion; do
  for sev in '[0]' '[1]' '["warn"]' '"off"'; do
    jq --arg r "@typescript-eslint/$r" --argjson s "$sev" '.eslint_rules[$r] = $s' "$T/good.json" > "$T/m.json"
    check_out "AG-values pinned strictTypeChecked rule $r at $sev fails" "eslint.strict_rules" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null; jq -r "[.details[] | select(.ok | not) | .check] | join(\",\")" "$0/AG-values.json"' "$T"
  done
  jq --arg r "@typescript-eslint/$r" 'del(.eslint_rules[$r])' "$T/good.json" > "$T/m.json"
  check_out "AG-values pinned strictTypeChecked rule $r missing fails" "eslint.strict_rules" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null; jq -r "[.details[] | select(.ok | not) | .check] | join(\",\")" "$0/AG-values.json"' "$T"
done
jq '.eslint_rules |= with_entries(.value = ["error", {}])' "$T/good.json" > "$T/m.json"
check "AG-values accepts pinned rules spelled error with options" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null' "$T"
for off in 'off' -1 false '\n    severity: off\n    given: $' '\n    severity: -1' '\n    severity: false' '\n    recommended: false'; do  # R54: block bodies, unquoted tokens
  jq --arg off "$off" '.spectral_yaml = "extends: [\"spectral:oas\"]\nrules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: \($off | gsub("\\\\n"; "\n"))\n"' "$T/good.json" > "$T/m.json"
  check_out "AG-values spectral rule disabled as [$off] does not count" "spectral.custom_rules" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null; jq -r "[.details[] | select(.ok | not) | .check] | join(\",\")" "$0/AG-values.json"' "$T"
done
jq '.spectral_yaml = "extends: [\"spectral:oas\"] # base\nrules:\n  pp-a: # typed 2xx\n    severity: error\n  pp-b:\n    severity: warn\n    given: \"$\"\n  pp-c: {}\n  pp-d: {}\n  pp-e: {}\n"' "$T/good.json" > "$T/m.json"
check "AG-values spectral counts rules carrying trailing comments and nested bodies" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null' "$T"
jq '.spectral_yaml = "extends: [\"spectral:oas\"]\nrules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n#  pp-e: {}\n  # pp-f: {}\n"' "$T/good.json" > "$T/m.json"
check_out "AG-values spectral commented-out rules do not count" "spectral.custom_rules" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null; jq -r "[.details[] | select(.ok | not) | .check] | join(\",\")" "$0/AG-values.json"' "$T"
# Fix round 2 (R53): ESLint is judged on the evaluated config, never on source text.
ev() { # ev NAME WANT FILTER: WANT is the comma-joined failing checks, "" for a pass
  jq "$3" "$T/good.json" > "$T/m.json"
  check_out "AG-values eslint evaluated: $1" "$2" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null; jq -r "[.details[] | select(.ok | not) | .check] | join(\",\")" "$0/AG-values.json"' "$T"
}
ev "commented-out settings in source do not matter" "" '.eslint_source = "// nothing here\nexport default [];"'
ev "source text alone cannot pass" "eslint.strictTypeChecked,eslint.noInlineConfig,eslint.reportUnusedDisableDirectives" '.eslint_source = "tseslint.configs.strictTypeChecked noInlineConfig: true reportUnusedDisableDirectives: \"error\"" | .eslint_evaluated = {"config_names": [], "linterOptions": {"noInlineConfig": false, "reportUnusedDisableDirectives": 1}}'
ev "noInlineConfig false" "eslint.noInlineConfig" '.eslint_evaluated.linterOptions.noInlineConfig = false'
ev "noInlineConfig string true" "eslint.noInlineConfig" '.eslint_evaluated.linterOptions.noInlineConfig = "true"'
ev "noInlineConfig missing" "eslint.noInlineConfig" 'del(.eslint_evaluated.linterOptions.noInlineConfig)'
ev "reportUnusedDisableDirectives warn (1)" "eslint.reportUnusedDisableDirectives" '.eslint_evaluated.linterOptions.reportUnusedDisableDirectives = 1'
ev "reportUnusedDisableDirectives \"warn\"" "eslint.reportUnusedDisableDirectives" '.eslint_evaluated.linterOptions.reportUnusedDisableDirectives = "warn"'
ev "reportUnusedDisableDirectives true (normalised to warn)" "eslint.reportUnusedDisableDirectives" '.eslint_evaluated.linterOptions.reportUnusedDisableDirectives = true'
ev "reportUnusedDisableDirectives off (0)" "eslint.reportUnusedDisableDirectives" '.eslint_evaluated.linterOptions.reportUnusedDisableDirectives = 0'
ev "reportUnusedDisableDirectives missing" "eslint.reportUnusedDisableDirectives" 'del(.eslint_evaluated.linterOptions.reportUnusedDisableDirectives)'
ev "reportUnusedDisableDirectives \"error\" passes" "" '.eslint_evaluated.linterOptions.reportUnusedDisableDirectives = "error"'
ev "strict-type-checked-only is not strictTypeChecked" "eslint.strictTypeChecked" '.eslint_evaluated.config_names = ["typescript-eslint/base", "typescript-eslint/strict-type-checked-only"]'
ev "a name merely containing strict-type-checked fails" "eslint.strictTypeChecked" '.eslint_evaluated.config_names = ["my/typescript-eslint/strict-type-checked-copy"]'
ev "defineConfig extends prefix still counts" "" '.eslint_evaluated.config_names = ["app > typescript-eslint/base", "app > typescript-eslint/strict-type-checked"]'
ev "config names not a list" "eslint.strictTypeChecked" '.eslint_evaluated.config_names = "typescript-eslint/strict-type-checked"'
ev "evaluated object missing" "eslint.strictTypeChecked,eslint.noInlineConfig,eslint.reportUnusedDisableDirectives" 'del(.eslint_evaluated)'
for attr in 'prefix="Foo"' 'suffix=".php"'; do
  jq --arg a "$attr" '.phpunit_xml |= sub("<include><directory>app</directory>"; "<include><directory \($a)>app</directory>")' "$T/good.json" > "$T/m.json"
  check_out "AG-values phpunit include directory with $attr fails" "phpunit.source_include" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null; jq -r "[.details[] | select(.ok | not) | .check] | join(\",\")" "$0/AG-values.json"' "$T"
done
jq '.phpunit_xml |= sub("<directory>app/Providers</directory>"; "<directory suffix=\"Kernel.php\">app/Providers</directory><directory>app/Providers</directory>")' "$T/good.json" > "$T/m.json"
check_out "AG-values phpunit exclude directory with attributes fails" "phpunit.source_exclude" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null; jq -r "[.details[] | select(.ok | not) | .check] | join(\",\")" "$0/AG-values.json"' "$T"
for v in false null; do
  jq --argjson v "$v" '.vitest_test.passWithNoTests = $v' "$T/good.json" > "$T/m.json"
  check "AG-values vitest passWithNoTests $v passes" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null' "$T"
done
for v in 0 '"false"' '[]' '{}'; do
  jq --argjson v "$v" '.vitest_test.passWithNoTests = $v' "$T/good.json" > "$T/m.json"
  check_out "AG-values vitest passWithNoTests $v fails" "vitest.passWithNoTests" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null; jq -r "[.details[] | select(.ok | not) | .check] | join(\",\")" "$0/AG-values.json"' "$T"
done
sp2() { # sp2 NAME WANT YAML(printf format)
  printf "$3" > "$T/y.yaml"
  jq --rawfile x "$T/y.yaml" '.spectral_yaml = $x' "$T/good.json" > "$T/m.json"
  check_out "AG-values spectral: $1" "$2" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" >/dev/null; jq -r "[.details[] | select(.ok | not) | .check] | join(\",\")" "$0/AG-values.json"' "$T"
}
R5='rules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: {}\n'
sp2 "five rules at severity 0 (error) count" "" 'extends: ["spectral:oas"]\nrules:\n  pp-a: 0\n  pp-b: 3\n  pp-c:\n    severity: 0\n  pp-d: warn\n  pp-e:\n    severity: hint\n'
sp2 "bare extends scalar passes" "" "extends: spectral:oas\n$R5"
sp2 "quoted bare extends scalar passes" "" "extends: 'spectral:oas'\n$R5"
sp2 "block list extends passes" "" "extends:\n  - spectral:oas\n$R5"
sp2 "tuple with all passes" "" "extends: [[spectral:oas, all]]\n$R5"
sp2 "top-level tuple with recommended passes" "" "extends: [spectral:oas, recommended]\n$R5"
sp2 "block list tuple with recommended passes" "" "extends:\n  - [\"spectral:oas\", \"recommended\"]\n$R5"
sp2 "top-level tuple with off fails" "spectral.extends_oas" "extends: [spectral:oas, off]\n$R5"
sp2 "block list tuple with off fails" "spectral.extends_oas" "extends:\n  - [spectral:oas, off]\n$R5"
sp2 "oas plus a later oas off entry fails" "spectral.extends_oas" "extends:\n  - spectral:oas\n  - [spectral:oas, off]\n$R5"
sp2 "tuple with an unknown mode fails" "spectral.extends_oas" "extends: [[spectral:oas, most]]\n$R5"
sp2 "one-element tuple fails closed" "spectral.extends_oas" "extends: [[spectral:oas]]\n$R5"
sp2 "a lookalike ruleset name fails" "spectral.extends_oas" "extends: [spectral:oas3]\n$R5"
sp2 "unparsable extends fails closed" "spectral.extends_oas" "extends: [spectral:oas\n$R5"
sp2 "duplicate extends keys fail closed" "spectral.subset" "extends: [spectral:oas]\nextends: [spectral:asyncapi]\n$R5"
sp2 "empty extends fails" "spectral.extends_oas" "extends:\n$R5"
# Fix round 3 (R54): .spectral.yaml is judged under a restricted, fail-closed YAML subset.
sp2 "a tag fails the group" "spectral.subset" "extends: [spectral:oas]\nrules:\n  pp-a: !!str off\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: {}\n"
sp2 "a tag on a rule body fails the group" "spectral.subset" "extends: [spectral:oas]\n$R5  pp-f: !custom\n    given: x\n"
sp2 "a merge key fails the group" "spectral.subset" "extends: [spectral:oas]\nrules:\n  pp-a:\n    <<: {}\n    given: x\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: {}\n"
sp2 "an anchor on a rule body fails the group" "spectral.subset" "extends: [spectral:oas]\n$R5  pp-f: &base\n    given: x\n"
sp2 "an alias in a block list fails the group" "spectral.subset" "extends:\n  - *oas\n$R5"
sp2 "an unknown top-level key fails the group" "spectral.subset" "extends: [spectral:oas]\naliases:\n  X: y\n$R5"
sp2 "overrides fails the group" "spectral.subset" "extends: [spectral:oas]\n${R5}overrides:\n  - files:\n      - x.json\n"
sp2 "a quoted \"0\" severity fails the group" "spectral.subset" "extends: [spectral:oas]\n$R5  pp-f: \"0\"\n"
sp2 "a quoted error severity fails the group" "spectral.subset" "extends: [spectral:oas]\n$R5  pp-f:\n    severity: 'error'\n"
sp2 "a float severity fails the group" "spectral.subset" "extends: [spectral:oas]\n$R5  pp-f: 1.0\n"
sp2 "severity 4 fails the group" "spectral.subset" "extends: [spectral:oas]\n$R5  pp-f:\n    severity: 4\n"
sp2 "severity Off fails the group" "spectral.subset" "extends: [spectral:oas]\n$R5  pp-f: Off\n"
sp2 "a non-empty flow mapping in rules fails the group" "spectral.subset" "extends: [spectral:oas]\n$R5  pp-f: {severity: warn}\n"
sp2 "a flow sequence in a rule body fails the group" "spectral.subset" "extends: [spectral:oas]\n$R5  pp-f:\n    given: [\"\$.a\", \"\$.b\"]\n"
sp2 "a top-level flow formats list fails the group" "spectral.subset" "extends: [spectral:oas]\nformats: [oas3]\n$R5"
sp2 "a non-pp rule with a bad severity fails the group" "spectral.subset" "extends: [spectral:oas]\n$R5  operation-tags: maybe\n"
sp2 "the allowed top-level keys pass" "" "extends: [spectral:oas]\ndocumentationUrl: https://example.test/rules\nfunctionsDir: functions\nfunctions:\n  - problemJson\n$R5"
sp2 "empty {} rule bodies pass (plan fixture form)" "" "extends: [spectral:oas]\n$R5"
sp2 "unquoted JSONPath with [*] is plain scalar content" "" "extends: [spectral:oas]\n${R5}  pp-f:\n    given: \$.paths[*][get,put]\n    message: wow! *really* & more\n    severity: warn\n"
sp2 "quoted JSONPath with [*] passes" "" "extends: [spectral:oas]\n${R5}  pp-f:\n    given: \"\$.paths[*]\"\n    then:\n      - field: \"&x\"\n        function: truthy\n"
sp2 "an off non-pp rule passes" "" "extends: [spectral:oas]\n$R5  operation-tags: off\n  info-contact: -1\n"
sp2 "a subset failure replaces both spectral checks" "spectral.subset" "extends: &x [spectral:oas]\nrules:\n  pp-a: {}\n"
rm -f "$T/AG-values.json"
check "AG-values a spectral subset failure is one check naming the construct" sh -c 'printf "extends: [spectral:oas]\nrules:\n  pp-a: &o off\n" > "$0/y.yaml"; jq --rawfile x "$0/y.yaml" ".spectral_yaml = \$x" "$0/good.json" > "$0/m.json"; GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" --only spectral >/dev/null; jq -e ".denominator == 1 and .value == 1 and (.details[0].reason | test(\"anchor\"))" "$0/AG-values.json"' "$T"
# Fix round 4 (R56): duplicate mapping keys at any level and any formats key fail the subset.
sp2 "a duplicate rule key fails the group" "spectral.subset" "extends: [spectral:oas]\n${R5}  pp-e: off\n"
sp2 "a duplicate quoted rule key fails the group" "spectral.subset" "extends: [spectral:oas]\n${R5}  \"pp-e\": warn\n"
sp2 "a duplicate key inside a rule body fails the group" "spectral.subset" "extends: [spectral:oas]\n${R5}  pp-f:\n    severity: error\n    given: x\n    severity: off\n"
sp2 "a duplicate key inside a list item mapping fails the group" "spectral.subset" "extends: [spectral:oas]\n${R5}  pp-f:\n    then:\n      - field: a\n        function: truthy\n        field: b\n"
sp2 "a duplicate key deeper in a nested mapping fails the group" "spectral.subset" "extends: [spectral:oas]\n${R5}  pp-f:\n    then:\n      functionOptions:\n        match: a\n        match: b\n"
sp2 "a duplicate documentationUrl fails the group" "spectral.subset" "extends: [spectral:oas]\ndocumentationUrl: a\ndocumentationUrl: b\n$R5"
sp2 "the same key in sibling list items passes" "" "extends: [spectral:oas]\n${R5}  pp-f:\n    then:\n      - field: a\n        function: truthy\n      - field: b\n        function: truthy\n"
sp2 "the same key in sibling rule bodies passes" "" "extends: [spectral:oas]\nrules:\n  pp-a:\n    severity: warn\n  pp-b:\n    severity: warn\n  pp-c:\n    severity: warn\n  pp-d:\n    severity: warn\n  pp-e:\n    severity: warn\n"
sp2 "a key reappearing after a nested mapping closes passes" "" "extends: [spectral:oas]\n${R5}  pp-f:\n    then:\n      field: a\n    given: x\n  pp-g:\n    then:\n      field: b\n    given: y\n"
sp2 "top-level formats oas3 fails the group" "spectral.subset" "extends: [spectral:oas]\nformats:\n  - oas3\n$R5"
sp2 "formats inside a rule body fails the group" "spectral.subset" "extends: [spectral:oas]\n${R5}  pp-f:\n    formats:\n      - oas3\n    given: x\n"
sp2 "a key merely containing formats passes" "" "extends: [spectral:oas]\n${R5}  pp-f:\n    x-formats: oas2\n    \"xformatsx\": y\n    message: formats\n"
sp2 "formats as a list-item key fails the group" "spectral.subset" "extends: [spectral:oas]\n${R5}  pp-f:\n    then:\n      - formats: oas3\n"
for bad in infinite -infinite '[1]' '{}' '""'; do
  check_out "gate_result refuses value $bad and leaves no artifact" "1 absent" sh -c 'printf "{\"value\":0}\n" > "$0/RR.json"; GATES_OUT="$0" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result RR "$1" 1 "$0/d.jsonl" >/dev/null 2>&1; r=$?; [ "$r" -ne 0 ] && r=1; if [ -e "$0/RR.json" ]; then echo "$r present"; else echo "$r absent"; fi' "$T" "$bad"
done
check_out "gate_result still accepts a float value" "1.5" sh -c 'GATES_OUT="$0" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result RR 1.5 2 "$0/d.jsonl" && jq -r .value "$0/RR.json"' "$T"
finish
