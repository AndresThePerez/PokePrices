#!/bin/sh
# P0-i1-T06 reviewer round 2: attacks on the R50 fixes (comment strippers, pinned eslint rules,
# phpunit shape, spectral enabled-rule counting, gate_result number validation).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
T=.gates/tmp/test-gate-libs-edge2; mkdir -p "$T"
F=tests/gates/fixtures/ag-values.json
jq '.good' "$F" > "$T/good.json"
printf '{"k":1}\n' > "$T/one.jsonl"

ag() { # ag NAME fail|pass : run the gate on $T/m.json
  name=$1; want=$2
  rm -f "$T/AG-values.json"
  GATES_OUT="$T" python3 -I scripts/gates/ag-values.py --inputs "$T/m.json" >/dev/null 2>&1; rc=$?
  if [ "$want" = fail ]; then
    if [ "$rc" -eq 1 ] && jq -e '.value >= 1 and .denominator > 0' "$T/AG-values.json" >/dev/null 2>&1; then printf 'ok - %s\n' "$name"
    else printf 'not ok - %s (rc=%s)\n' "$name" "$rc"; fails=$((fails + 1)); fi
  else
    if [ "$rc" -eq 0 ] && jq -e '.value == 0' "$T/AG-values.json" >/dev/null 2>&1; then printf 'ok - %s\n' "$name"
    else printf 'not ok - %s (rc=%s)\n' "$name" "$rc"; fails=$((fails + 1)); fi
  fi
}
mut() { jq "$1" "$T/good.json" > "$T/m.json"; }
mutx() { jq --rawfile x "$2" "$1" "$T/good.json" > "$T/m.json"; }

# ---------- nohits ----------
check_out "nohits with an empty-string finder exits 2" 2 sh -c 'scripts/lib/nohits "" >/dev/null 2>&1; echo $?'

# ---------- gate_result ----------
gr() { # gr VALUE DENOM -> prints "rc exists"
  rm -f "$T/Q.json"
  sh -c 'GATES_OUT="$0" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result Q "$1" "$2" "$0/one.jsonl"' "$T" "$1" "$2" >/dev/null 2>&1
  r=$?; [ -f "$T/Q.json" ] && e=yes || e=no; echo "$r $e"
}
check_out "gate_result accepts the numeric literal 1e3 and writes a number" "0 yes" gr 1e3 1
check_out "gate_result accepts a float value 0.5" "0 yes" gr 0.5 2
check_out "gate_result rejects 1e999 (infinite) and writes nothing" "1 no" gr 1e999 1
check_out "gate_result rejects two JSON values in one argument" "1 no" gr '1 2' 1
check_out "gate_result rejects trailing garbage 1abc" "1 no" gr 1abc 1
check_out "gate_result rejects hex 0x10" "1 no" gr 0x10 1
check_out "gate_result rejects an object denominator" "1 no" gr 0 '{"a":1}'
check_out "gate_result rejects a null denominator" "1 no" gr 0 null
check_out "gate_result rejects an empty denominator" "1 no" gr 0 ''
check_out "gate_result with a nonexistent GATES_OUT fails" "1" sh -c 'GATES_OUT="$0/nodir" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result Q 0 1 "$0/one.jsonl" >/dev/null 2>&1; echo $?' "$T"
check_out "gate_result valid run leaves details as an array of the input lines" "[{\"k\":1}]" sh -c 'GATES_OUT="$0" && export GATES_OUT && . scripts/lib/gate-result.sh && gate_result Q 0 1 "$0/one.jsonl" && jq -c .details "$0/Q.json"' "$T"

# ---------- eslint: comment stripper and pinned rules ----------
printf '%s\n' "export default [tseslint.configs.strictTypeChecked, { linterOptions: { noInlineConfig: true, reportUnusedDisableDirectives: 'error' } }];" > "$T/ok.js"
mutx '.eslint_source = $x' "$T/ok.js";                       ag "eslint real settings in code pass" pass
printf '%s\n' "const s = 'a /* not a comment'; export default [tseslint.configs.strictTypeChecked, { linterOptions: { noInlineConfig: true, reportUnusedDisableDirectives: 'error' } }]; /* real */" > "$T/x.js"
mutx '.eslint_source = $x' "$T/x.js";                        ag "eslint string containing /* does not swallow the real settings" pass
printf '%s\n' "const u = 'https://example.test/a'; export default [tseslint.configs.strictTypeChecked, { linterOptions: { noInlineConfig: true, reportUnusedDisableDirectives: 'error' } }];" > "$T/x.js"
mutx '.eslint_source = $x' "$T/x.js";                        ag "eslint string containing // keeps the settings after it" pass
printf '%s\n' "/* a string \"x */\" ends here */ tseslint.configs.strictTypeChecked noInlineConfig: true reportUnusedDisableDirectives: 'error'" > "$T/x.js"
mutx '.eslint_source = $x' "$T/x.js";                        ag "eslint block comment ends at the first */ even next to quotes" pass
printf '%s\n' "/* strictTypeChecked noInlineConfig: true reportUnusedDisableDirectives: 'error' */" > "$T/x.js"
mutx '.eslint_source = $x | .eslint_evaluated = {"config_names": [], "linterOptions": {"noInlineConfig": false, "reportUnusedDisableDirectives": 1}}' "$T/x.js"; ag "eslint settings only in one block comment fail" fail  # R53: judged on the evaluated config
printf '%s\n' "/* open" "strictTypeChecked noInlineConfig: true reportUnusedDisableDirectives: 'error'" > "$T/x.js"
mutx '.eslint_source = $x | .eslint_evaluated = {"config_names": [], "linterOptions": {"noInlineConfig": false, "reportUnusedDisableDirectives": 1}}' "$T/x.js"; ag "eslint unterminated block comment hides the settings" fail  # R53: judged on the evaluated config
# A quote inside a regex literal must not turn the next comment into a string.
printf '%s\n' "const r = /'/;" "// tseslint.configs.strictTypeChecked" "// linterOptions: { noInlineConfig: true, reportUnusedDisableDirectives: 'error' }" "export default [];" > "$T/x.js"
mutx '.eslint_source = $x | .eslint_evaluated = {"config_names": [], "linterOptions": {"noInlineConfig": false, "reportUnusedDisableDirectives": 1}}' "$T/x.js"; ag "eslint regex literal with a quote does not unhide commented settings" fail  # R53: judged on the evaluated config
printf '%s\n' "const r = /\"/;" "/* strictTypeChecked noInlineConfig: true reportUnusedDisableDirectives: 'error' */" "export default [];" > "$T/x.js"
mutx '.eslint_source = $x | .eslint_evaluated = {"config_names": [], "linterOptions": {"noInlineConfig": false, "reportUnusedDisableDirectives": 1}}' "$T/x.js"; ag "eslint regex literal with a double quote does not unhide a block comment" fail  # R53: judged on the evaluated config
for r in no-explicit-any no-floating-promises no-unsafe-assignment no-unsafe-member-access no-unsafe-call no-unsafe-return no-misused-promises restrict-template-expressions no-unnecessary-condition no-non-null-assertion; do
  mut ".eslint_rules.\"@typescript-eslint/$r\" = [true]";    ag "eslint $r [true] is not error" fail
  mut ".eslint_rules.\"@typescript-eslint/$r\" = \"2\"";     ag "eslint $r string \"2\" is not error" fail
  mut ".eslint_rules.\"@typescript-eslint/$r\" = [[2]]";     ag "eslint $r nested [[2]] is not error" fail
  mut ".eslint_rules.\"@typescript-eslint/$r\" = [\"error\", {}]"; ag "eslint $r [error, options] is error" pass
  mut "del(.eslint_rules.\"@typescript-eslint/$r\")";        ag "eslint $r missing fails" fail
done
mut '.eslint_rules = null';                                  ag "eslint rules null fails" fail
mut '.eslint_rules."@typescript-eslint/no-explicit-any" = 2.0'; ag "eslint rule severity 2.0 is error" pass

# ---------- phpunit ----------
H='<phpunit failOnEmptyTestSuite="true" failOnRisky="true" failOnWarning="true" failOnSkipped="true" failOnIncomplete="true" beStrictAboutTestsThatDoNotTestAnything="true">'
INC='<source><include><directory>app</directory></include>'
ENV='<php><env name="DB_CONNECTION" value="pgsql"/></php>'
px() { printf '%s' "$1" > "$T/x.xml"; mutx '.phpunit_xml = $x' "$T/x.xml"; }
px "$H$INC<exclude><directory>app/Providers/../Http</directory></exclude></source>$ENV</phpunit>"; ag "phpunit exclude app/Providers/../Http fails" fail
px "$H$INC<exclude><directory>app/Providers</directory></exclude><exclude><file>app/X.php</file></exclude></source>$ENV</phpunit>"; ag "phpunit a second <exclude> with a file fails" fail
px "$H$INC<exclude><directory>app/Providers</directory></exclude><exclude><directory>app/Providers</directory></exclude></source>$ENV</phpunit>"; ag "phpunit two identical Providers excludes pass" pass
px "$H$INC<exclude><directory>./app/Providers/</directory></exclude></source>$ENV</phpunit>"; ag "phpunit ./app/Providers/ exclude passes" pass
px "$H$INC<exclude><directory>app/Providers</directory><directory>app/Providers/Extra</directory></exclude></source>$ENV</phpunit>"; ag "phpunit a nested Providers subdirectory exclude fails" fail
px "$H<source><include><directory prefix=\"Zz\">app</directory></include></source>$ENV</phpunit>"; ag "phpunit include with a prefix attribute fails" fail
px "$H<source><include><directory prefix=\"Zz\">app</directory><directory>app</directory></include></source>$ENV</phpunit>"; ag "phpunit one unfiltered app include is enough" pass
px "$H$INC</source><php><env name=\"DB_CONNECTION\" value=\"pgsql\" force=\"false\"/><env name=\"DB_CONNECTION\" value=\"sqlite\"/></php></phpunit>"; ag "phpunit pgsql force=false plus a sqlite env fails" fail
px "$H$INC</source><php><env name=\"DB_CONNECTION\" value=\"pgsql\"/></php><php><env name=\"DB_CONNECTION\" value=\"sqlite\"/></php></phpunit>"; ag "phpunit sqlite in a second <php> block fails" fail
px "$H$INC</source><php><env name=\"DB_CONNECTION\" value=\"PGSQL\"/></php></phpunit>"; ag "phpunit DB_CONNECTION is case-sensitive pgsql" fail
px "$H$INC</source><php><env name=\"DB_CONNECTION\" value=\" pgsql\"/></php></phpunit>"; ag "phpunit DB_CONNECTION with a space fails" fail
px "$H<source><exclude><directory>app/Providers</directory></exclude></source>$ENV</phpunit>"; ag "phpunit without an include fails" fail

# ---------- spectral ----------
sp() { printf '%s' "$1" > "$T/y.yaml"; mutx '.spectral_yaml = $x' "$T/y.yaml"; }
E='extends: ["spectral:oas"]\n'
sp "$(printf "${E}rules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: off\n")"; ag "spectral four enabled plus one off fails" fail
sp "$(printf "${E}rules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: {}\n  pp-f: off\n")"; ag "spectral five enabled plus an extra off passes" pass
sp "$(printf "${E}rules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: {} # off\n")"; ag "spectral off word in a trailing comment does not disable" pass
sp "$(printf "${E}rules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  # pp-e: {}\n")"; ag "spectral a commented pp-e does not count" fail
# Spectral severity -1 is off (0 is error): five rules at -1 enable nothing.
sp "$(printf "${E}rules:\n  pp-a: -1\n  pp-b: -1\n  pp-c: -1\n  pp-d: -1\n  pp-e: -1\n")"; ag "spectral inline severity -1 (off) does not count" fail
sp "$(printf "${E}rules:\n  pp-a:\n    severity: -1\n  pp-b:\n    severity: -1\n  pp-c:\n    severity: -1\n  pp-d:\n    severity: -1\n  pp-e:\n    severity: -1\n")"; ag "spectral body severity -1 (off) does not count" fail
# extends [[spectral:oas, off]] extends the ruleset with every rule disabled.
sp "$(printf 'extends: [["spectral:oas", off]]\nrules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: {}\n')"; ag "spectral extends spectral:oas with off fails" fail
sp "$(printf 'extends: ["spectral:oas"]\nextends2: x\nrules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: {}\n')"; ag "spectral unrelated extends2 key does not matter" fail  # R54 FLIP: unknown top-level keys fail
sp "$(printf 'extends: ["spectral:asyncapi"]\ndescription: spectral:oas\nrules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  pp-e: {}\n')"; ag "spectral oas named only in another key fails" fail
sp "$(printf "${E}rules:\n  pp-a: {}\n  pp-b: {}\n  pp-c: {}\n  pp-d: {}\n  PP-e: {}\n")"; ag "spectral upper-case PP-e does not count" fail
finish
