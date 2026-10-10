#!/bin/sh
# P0-i1-T06 reviewer edge cases: nohits exit mapping, gate_result failure modes, and ways to
# make AG-values report a pass while an R20 rule is violated.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
T=.gates/tmp/test-gate-libs-edge; mkdir -p "$T"
F=tests/gates/fixtures/ag-values.json
jq '.good' "$F" > "$T/good.json"
AG='python3 -I scripts/gates/ag-values.py'

# ---------- nohits: every finder exit status maps to 0/1/2, nothing leaks through ----------
check_out "nohits maps a SIGKILLed finder (137) to 2" 2 sh -c 'scripts/lib/nohits sh -c "kill -9 \$\$" >/dev/null; echo $?'
check_out "nohits maps exit 255 to 2" 2 sh -c 'scripts/lib/nohits sh -c "exit 255" >/dev/null; echo $?'
check_out "nohits maps exit 126 (not executable) to 2" 2 sh -c 'scripts/lib/nohits "$0" >/dev/null 2>&1; echo $?' "$T/good.json"
check_out "nohits maps exit 127 (no such finder) to 2" 2 sh -c 'scripts/lib/nohits /nonexistent/finder >/dev/null 2>&1; echo $?'
check_out "nohits prints FINDER ERROR rc=N on stdout for 127" "FINDER ERROR rc=127" sh -c 'scripts/lib/nohits /nonexistent/finder 2>/dev/null | tail -n 1'
check_out "nohits clean prints exactly CLEAN" "CLEAN" sh -c 'scripts/lib/nohits sh -c "exit 1"'
check_out "nohits hit output ends with HITS and exits 1" "1 HITS" sh -c 'o=$(scripts/lib/nohits sh -c "echo hit; exit 0"); rc=$?; echo "$rc $(printf "%s" "$o" | tail -n 1)"'
check_out "nohits with no finder does not report clean" 1 sh -c 'scripts/lib/nohits >/dev/null 2>&1; r=$?; [ "$r" -ne 0 ] && echo 1 || echo 0'
check_out "nohits a finder printing CLEAN but hitting still exits 1" 1 sh -c 'scripts/lib/nohits sh -c "echo CLEAN; exit 0" >/dev/null; echo $?'

# ---------- gate_result: failures must not leave a passable artifact ----------
: > "$T/empty.jsonl"
printf '{"k":1}\n' > "$T/one.jsonl"
printf '{bad\n' > "$T/bad.jsonl"
check_out "gate_result with an empty details file writes details []" '[]' sh -c 'GATES_OUT="$0" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result Q 0 1 "$0/empty.jsonl" && jq -c .details "$0/Q.json"' "$T"
check_out "gate_result missing details exits non-zero" 1 sh -c 'GATES_OUT="$0" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result Q 0 1 "$0/nope.jsonl" >/dev/null 2>&1; [ $? -ne 0 ] && echo 1 || echo 0' "$T"
check_out "gate_result malformed details exits non-zero" 1 sh -c 'GATES_OUT="$0" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result Q 0 1 "$0/bad.jsonl" >/dev/null 2>&1; [ $? -ne 0 ] && echo 1 || echo 0' "$T"
check_out "gate_result non-numeric value abc exits non-zero" 1 sh -c 'GATES_OUT="$0" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result Q abc 1 "$0/one.jsonl" >/dev/null 2>&1; [ $? -ne 0 ] && echo 1 || echo 0' "$T"
# A failed run must not leave the previous (possibly passing) result in place.
printf '{"id":"Q","value":0,"denominator":9,"details":[]}\n' > "$T/Q.json"
check_out "gate_result failure does not leave a stale passing artifact" 1 sh -c 'GATES_OUT="$0" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result Q 0 9 "$0/nope.jsonl" >/dev/null 2>&1; jq -e ".value == 0 and .denominator == 9" "$0/Q.json" >/dev/null 2>&1 && echo 0 || echo 1' "$T"
# Contract: value and denominator are numbers (F-P0-009, R18 unparsable -> fail). The helper is the
# only writer for shell gates, so it must refuse a non-number rather than write null/string/bool.
for bad in null '"0"' true nan; do
  check_out "gate_result refuses value $bad (not a number)" 1 sh -c 'GATES_OUT="$0" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result Q "$1" 1 "$0/one.jsonl" >/dev/null 2>&1; r=$?; if [ "$r" -ne 0 ]; then echo 1; elif jq -e "(.value|type)==\"number\"" "$0/Q.json" >/dev/null 2>&1; then echo 1; else echo 0; fi' "$T" "$bad"
  check_out "gate_result refuses denominator $bad (not a number)" 1 sh -c 'GATES_OUT="$0" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result Q 0 "$1" "$0/one.jsonl" >/dev/null 2>&1; r=$?; if [ "$r" -ne 0 ]; then echo 1; elif jq -e "(.denominator|type)==\"number\"" "$0/Q.json" >/dev/null 2>&1; then echo 1; else echo 0; fi' "$T" "$bad"
done

# ---------- AG-values helpers ----------
# ag NAME EXPECT: run the gate on $T/m.json. EXPECT=fail needs rc 1, value >= 1 and value <= denominator;
# EXPECT=pass needs rc 0 and value 0.
ag() {
  name=$1; want=$2
  rm -f "$T/AG-values.json"
  GATES_OUT="$T" sh -c "$AG --inputs $T/m.json" >/dev/null 2>&1; rc=$?
  if [ "$want" = fail ]; then
    if [ "$rc" -eq 1 ] && jq -e '.value >= 1 and .value <= .denominator and .denominator > 0' "$T/AG-values.json" >/dev/null 2>&1; then
      printf 'ok - %s\n' "$name"; else printf 'not ok - %s (rc=%s)\n' "$name" "$rc"; fails=$((fails + 1)); fi
  else
    if [ "$rc" -eq 0 ] && jq -e '.value == 0' "$T/AG-values.json" >/dev/null 2>&1; then
      printf 'ok - %s\n' "$name"; else printf 'not ok - %s (rc=%s)\n' "$name" "$rc"; fails=$((fails + 1)); fi
  fi
}
mut() { jq "$1" "$T/good.json" > "$T/m.json"; }
mutx() { jq --rawfile x "$2" "$1" "$T/good.json" > "$T/m.json"; }  # filter uses $x

PHPUNIT_HEAD='<phpunit failOnEmptyTestSuite="true" failOnRisky="true" failOnWarning="true" failOnSkipped="true" failOnIncomplete="true" beStrictAboutTestsThatDoNotTestAnything="true">'
PHPUNIT_ENV='<php><env name="DB_CONNECTION" value="pgsql"/></php>'

# ---------- AG-values: denominator never 0, empty input never passes, per group ----------
for g in phpstan pint phpunit tsconfig eslint vitest spectral; do
  printf '{}\n' > "$T/m.json"
  rm -f "$T/AG-values.json"
  check "AG-values --only $g on empty inputs fails with a positive denominator" sh -c '! GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" --only "$1" >/dev/null 2>&1 && jq -e ".denominator > 0 and .value == .denominator" "$0/AG-values.json"' "$T" "$g"
done
check_out "AG-values artifact has id AG-values and integer value/denominator" "true" sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/good.json" >/dev/null && jq -r "(.id==\"AG-values\") and ((.value|type)==\"number\") and (.value|floor)==.value and (.denominator|floor)==.denominator" "$0/AG-values.json"' "$T"
printf '[]\n' > "$T/list.json"
check_out "AG-values exits 2 when --inputs is a JSON list" 2 sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/list.json" >/dev/null 2>&1; echo $?' "$T"
check_out "AG-values exits 2 when --inputs file is missing" 2 sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/nope.json" >/dev/null 2>&1; echo $?' "$T"
check_out "AG-values exits 2 when GATES_OUT is not a directory" 2 sh -c 'GATES_OUT="$0/good.json" python3 -I scripts/gates/ag-values.py --inputs "$0/good.json" >/dev/null 2>&1; echo $?' "$T"
check_out "AG-values exits 2 on an empty --only" 2 sh -c 'GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/good.json" --only "" >/dev/null 2>&1; echo $?' "$T"

# ---------- AG-values: wrongly typed values never pass ----------
mut '.phpstan_params.level = "8"';                          ag "phpstan level string 8 fails" fail
mut '.phpstan_params.level = "max"';                        ag "phpstan level max fails (int >= 8 as written, open question 9)" fail
mut '.phpstan_params.level = 8.0';                          ag "phpstan level 8.0 float fails closed" fail
mut '.phpstan_params.level = true';                         ag "phpstan level boolean fails" fail
mut '.phpstan_params.level = 9';                            ag "phpstan level 9 passes" pass
mut '.phpstan_params.ignoreErrors = null';                  ag "phpstan ignoreErrors null fails" fail
mut 'del(.phpstan_params.ignoreErrors)';                    ag "phpstan ignoreErrors missing fails" fail
mut '.phpstan_params.reportUnmatchedIgnoredErrors = "true"'; ag "phpstan reportUnmatchedIgnoredErrors string true fails" fail
mut '.baseline_exists = "false"';                           ag "baseline_exists string false fails" fail
mut 'del(.baseline_exists)';                                ag "baseline_exists missing fails" fail
mut '.phpstan_neon_text = "includes:\n    - ./PHPSTAN-BASELINE.NEON\n"'; ag "phpstan baseline include is case-insensitive" fail
mut '.phpstan_neon_text = "# baseline removed\nparameters:\n    level: 8\n"'; ag "phpstan neon mentioning baseline in a comment fails closed" fail
mut '.phpstan_neon_text = null';                            ag "phpstan neon text null fails" fail
mut '.tsconfig_app.compilerOptions.strict = "true"';        ag "tsconfig strict string true fails" fail
mut '.tsconfig_node.compilerOptions.exactOptionalPropertyTypes = 1'; ag "tsconfig flag 1 fails" fail
mut 'del(.tsconfig_node)';                                  ag "tsconfig node missing fails" fail
mut '.vitest_test.coverage.thresholds.lines = true';        ag "vitest lines boolean true fails" fail
mut '.vitest_test.coverage.thresholds.branches = "99"';     ag "vitest branches string 99 fails" fail
mut '.vitest_test.coverage.thresholds.lines = null';        ag "vitest lines null fails" fail
mut '.vitest_test.coverage.include = "src/**"';             ag "vitest include as string fails" fail
mut '.vitest_test.coverage.include = ["src/**", "other/**"]'; ag "vitest include with an extra entry fails" fail
mut 'del(.vitest_test.coverage)';                           ag "vitest coverage missing fails" fail
mut '.vitest_test = null';                                  ag "vitest test null fails" fail
mut '.eslint_rules."@typescript-eslint/no-unnecessary-condition" = ["warn"]'; ag "eslint no-unnecessary-condition warn fails" fail
mut '.eslint_rules."@typescript-eslint/no-unnecessary-condition" = []'; ag "eslint no-unnecessary-condition empty array fails" fail
mut '.eslint_rules."@typescript-eslint/no-unnecessary-condition" = ["error"]'; ag "eslint no-unnecessary-condition error passes" pass
mut '.eslint_rules = {}';                                   ag "eslint rule missing fails" fail
mut '.pint.preset = "Laravel"';                             ag "pint preset is case-sensitive" fail
mut '.pint = []';                                           ag "pint as a list fails" fail

# ---------- AG-values: phpunit.xml shapes ----------
printf '%s' "<phpunit failOnEmptyTestSuite=\"true\"" > "$T/x.xml"
mutx '.phpunit_xml = $x' "$T/x.xml";                        ag "phpunit truncated xml fails" fail
printf '%s' "<other failOnEmptyTestSuite=\"true\"/>" > "$T/x.xml"
mutx '.phpunit_xml = $x' "$T/x.xml";                        ag "phpunit wrong root element fails" fail
printf '%s' "<!-- ${PHPUNIT_HEAD} --><phpunit><source><include><directory>app</directory></include></source>${PHPUNIT_ENV}</phpunit>" > "$T/x.xml"
mutx '.phpunit_xml = $x' "$T/x.xml";                        ag "phpunit flags only inside an xml comment fail" fail
printf '%s' "${PHPUNIT_HEAD}<source><include><directory>app</directory></include></source><php><!-- <env name=\"DB_CONNECTION\" value=\"pgsql\"/> --></php></phpunit>" > "$T/x.xml"
mutx '.phpunit_xml = $x' "$T/x.xml";                        ag "phpunit DB_CONNECTION only in an xml comment fails" fail
printf '%s' "${PHPUNIT_HEAD}<source><include><directory>app</directory></include></source><php><env name=\"DB_CONNECTION\" value=\"pgsql\"/><env name=\"DB_CONNECTION\" value=\"sqlite\"/></php></phpunit>" > "$T/x.xml"
mutx '.phpunit_xml = $x' "$T/x.xml";                        ag "phpunit a second DB_CONNECTION sqlite env fails" fail
printf '%s' "${PHPUNIT_HEAD}<source><include><directory>./app/</directory></include></source>${PHPUNIT_ENV}</phpunit>" > "$T/x.xml"
mutx '.phpunit_xml = $x' "$T/x.xml";                        ag "phpunit ./app/ include is accepted" pass
printf '%s' "${PHPUNIT_HEAD}<source><include><directory>app/Models</directory></include></source>${PHPUNIT_ENV}</phpunit>" > "$T/x.xml"
mutx '.phpunit_xml = $x' "$T/x.xml";                        ag "phpunit include narrowed to app/Models fails" fail
printf '%s' "${PHPUNIT_HEAD}<source><include><directory>app</directory></include><exclude><directory>app/Providers/../Http</directory></exclude></source>${PHPUNIT_ENV}</phpunit>" > "$T/x.xml"
mutx '.phpunit_xml = $x' "$T/x.xml";                        ag "phpunit exclude spelled with .. fails" fail

# ---------- AG-values: ways to pass while an R20 rule is violated (each must FAIL the gate) ----------
# R20: phpunit <source> excludes only the R11 T2 set. A <file> exclude removes a file from coverage.
printf '%s' "${PHPUNIT_HEAD}<source><include><directory>app</directory></include><exclude><directory>app/Providers</directory><file>app/Http/Controllers/Secret.php</file></exclude></source>${PHPUNIT_ENV}</phpunit>" > "$T/x.xml"
mutx '.phpunit_xml = $x' "$T/x.xml";                        ag "phpunit <exclude><file> is a source exclude and fails" fail
# R20: DB_CONNECTION must be pgsql for the suite. An env element outside <php> is ignored by PHPUnit.
printf '%s' "${PHPUNIT_HEAD}<source><include><directory>app</directory></include></source><testsuites><env name=\"DB_CONNECTION\" value=\"pgsql\"/></testsuites></phpunit>" > "$T/x.xml"
mutx '.phpunit_xml = $x' "$T/x.xml";                        ag "phpunit DB_CONNECTION env outside <php> fails" fail
# R20: source includes app. A suffix that matches no file includes nothing.
printf '%s' "${PHPUNIT_HEAD}<source><include><directory suffix=\".nomatch\">app</directory></include></source>${PHPUNIT_ENV}</phpunit>" > "$T/x.xml"
mutx '.phpunit_xml = $x' "$T/x.xml";                        ag "phpunit include directory with a narrowing suffix fails" fail
# R20: ESLint strictTypeChecked, noInlineConfig, reportUnusedDisableDirectives. A comment is not config.
mut '.eslint_source = "// tseslint.configs.strictTypeChecked\n// linterOptions: { noInlineConfig: true, reportUnusedDisableDirectives: \"error\" }\nexport default [];" | .eslint_evaluated = {"config_names": [], "linterOptions": {"noInlineConfig": false, "reportUnusedDisableDirectives": 1}}'  # R53: evidence is the evaluated config such a source yields
ag "eslint config whose required settings only appear in comments fails" fail
# R20: ESLint strictTypeChecked. Spreading it and switching its rules off afterwards is not strict.
mut '.eslint_rules."@typescript-eslint/no-explicit-any" = [0] | .eslint_rules."@typescript-eslint/no-floating-promises" = [0]'
ag "eslint effective config with strictTypeChecked rules turned off fails" fail
# F-P0-009 spectral: >= 5 custom pp-* rules and spectral:oas extended.
mut '.spectral_yaml = "extends: [\"spectral:oas\"]\nrules:\n  pp-a: {}\n  pp-a: {}\n  pp-a: {}\n  pp-a: {}\n  pp-a: {}\n"'
ag "spectral five duplicate keys of one rule count as one rule" fail
mut '.spectral_yaml = "extends: [] # spectral:oas\nrules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: {}\n"'
ag "spectral extends that names spectral:oas only in a comment fails" fail
mut '.spectral_yaml = "extends:\n  # - spectral:oas\n  - \"spectral:asyncapi\"\nrules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: {}\n"'
ag "spectral extends with the oas line commented out fails" fail
# F-P0-009 vitest: not true. A truthy non-boolean still lets an empty suite pass.
mut '.vitest_test.passWithNoTests = 1';                     ag "vitest passWithNoTests truthy number 1 fails" fail
mut '.vitest_test.passWithNoTests = "true"';                ag "vitest passWithNoTests string true fails" fail
mut 'del(.vitest_test.passWithNoTests)';                    ag "vitest passWithNoTests absent passes" pass

# ---------- regression: a violation is reported by count, never by crash ----------
mut '.tsconfig_app = [] | .pint = "x" | .eslint_rules = 3 | .vitest_test = [] | .phpstan_params = 5'
ag "AG-values survives a non-object in every slot" fail
check_out "AG-values reports exit 1 and a summary line for a violation" "1 AG-values: 5 violation(s) of 31 checks" sh -c 'jq ".phpstan_params.level = 1 | .pint.preset = 1 | .tsconfig_app.compilerOptions.strict = false | .vitest_test.passWithNoTests = true | .baseline_exists = true" "$0/good.json" > "$0/m.json"; o=$(GATES_OUT="$0" python3 -I scripts/gates/ag-values.py --inputs "$0/m.json" 2>&1); echo "$? $o"' "$T"
finish
