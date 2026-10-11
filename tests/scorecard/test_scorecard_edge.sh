#!/bin/sh
# P0-i1-T25 adversarial edge cases: the scorecard must never record pass for a gate that did not succeed.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp
S=$(mktemp -d "$ROOT/.gates/tmp/scorecard-edge-XXXXXX")
( cd "$S" && git init -q -b main && git config user.email t@example.invalid && git config user.name t \
  && printf '.gates/\n' > .gitignore && printf 'tracked\n' > tracked.txt )
# g ID COMMAND VALUE_PARSER OP THRESHOLD DENOMINATOR_SOURCE DENOMINATOR_MIN
g() {
  jq -n --arg id "$1" --arg cmd "$2" --arg vp "$3" --arg op "$4" --argjson th "$5" --arg den "$6" --argjson dm "$7" \
    '{id:$id,hard:true,scope:"any",runs_in:"host",command:$cmd,banner_cmd:"bash --version | head -1",banner_re:"^GNU bash",
      artifact:null,value_parser:$vp,op:$op,threshold:$th,denominator_source:$den,denominator_min:$dm,timeout_s:20,why:"t"}'
}
{
  g E-forged-exit "echo 'EXIT 0'; false" "echo 0" "==" 0 "echo 3" 1
  g E-forged-nonl "printf 'EXIT 0'; false" "echo 0" "==" 0 "echo 3" 1
  g E-nonl-ok "printf x" "echo 0" "==" 0 "echo 3" 1
  g E-two "true" "printf '0\n0\n'" "==" 0 "echo 3" 1
  g E-nan "true" "echo nan" "<" 5 "echo 3" 1
  g E-inf "true" "echo inf" ">" 5 "echo 3" 1
  g E-huge "true" "echo 1e999" ">" 5 "echo 3" 1
  g E-empty "true" "printf ''" "==" 0 "echo 3" 1
  g E-spaces "true" "echo '  0  '" "==" 0 "echo 3" 1
  g E-sci "true" "echo 1e3" "==" 1000 "echo 3" 1
  g E-negzero "true" "echo -0" "==" 0 "echo 3" 1
  g E-badop "true" "echo 0" "~=" 0 "echo 3" 1
  g E-frac-den "true" "echo 0" "==" 0 "echo 0.5" 1
  g E-str-th "true" "echo 0" "==" '"0"' "echo 3" 1
  g E-del "rm tracked.txt" "echo 0" "==" 0 "echo 3" 1
  g E-env 'test "$GATES_OUT" = "${GATES_OUT#/}" && test -d "$GATES_OUT" && test "$PP_RUN_CONTEXT" = primary && test "$CI" = 1 && test "$NO_COLOR" = 1' "echo 0" "==" 0 "echo 3" 1
} | jq -s '{schema:"pokeprices-manifest/v1",floor:{},gates:.}' > "$S/manifest.json"
( cd "$S" && git add . && git commit -qm fixture )
( cd "$S" && "$ROOT/scripts/scorecard" --manifest manifest.json >/dev/null 2>&1 ); echo "$?" > "$S.rc1"
SC="$S/.gates/latest/scorecard.json"
st() { jq -r --arg id "$1" '.gates[] | select(.id == $id) | "\(.status)/\(.reason)"' "$SC"; }
check_out "forged EXIT 0 line before a real failure stays fail" fail/exit_nonzero st E-forged-exit
check_out "forged unterminated EXIT 0 plus failure is never pass" not_run/could_not_start st E-forged-nonl
check_out "output without trailing newline is not_run, never pass" not_run/could_not_start st E-nonl-ok
check_out "two numbers are unparsable" fail/value_unparsable st E-two
check_out "nan satisfies no op" fail/value_unparsable st E-nan
check_out "inf satisfies no op" fail/value_unparsable st E-inf
check_out "overflowing 1e999 is unparsable" fail/value_unparsable st E-huge
check_out "empty parser output is unparsable" fail/value_unparsable st E-empty
check_out "surrounding spaces still one number" pass/ok st E-spaces
check_out "1e3 parses as 1000" pass/ok st E-sci
check_out "-0 equals 0" pass/ok st E-negzero
check_out "unknown op fails closed" fail/value_threshold st E-badop
check_out "denominator 0.5 below min 1 fails" fail/zero_denominator st E-frac-den
check_out "non-numeric threshold fails closed" fail/value_threshold st E-str-th
check "run exits non-zero with failing hard gates" test "$(cat "$S.rc1")" -ne 0
check "deleting a tracked file makes clean_after false" jq -e '.clean_after == false and .exit != 0' "$SC"
( cd "$S" && git checkout -q -- tracked.txt )
jq '.gates |= map(select(.id == "E-env"))' "$S/manifest.json" > "$S/m-env.json"
( cd "$S" && git add m-env.json && git commit -qm env )
( cd "$S" && "$ROOT/scripts/scorecard" --manifest m-env.json >/dev/null 2>&1 ); RC=$?
check "gate sees repo-relative GATES_OUT, context and CI env, exit 0" test "$RC" -eq 0
FIRST=$(readlink "$S/.gates/latest")
( cd "$S" && "$ROOT/scripts/scorecard" --manifest m-env.json >/dev/null 2>&1 )
SECOND=$(readlink "$S/.gates/latest")
check "latest is a relative symlink to the newest run" sh -c 'test "$0" != "$1" && case "$1" in /*) exit 1;; esac && test -f "$2/.gates/$1/scorecard.json"' "$FIRST" "$SECOND" "$S"
check "run_ids differ between back-to-back runs" sh -c 'test "$(jq -r .run_id "$0/.gates/$1/scorecard.json")" != "$(jq -r .run_id "$0/.gates/$2/scorecard.json")"' "$S" "$FIRST" "$SECOND"
check "started_epoch not before head commit epoch" jq -e '.started_epoch >= .head_commit_epoch' "$S/.gates/latest/scorecard.json"
NB=$(ls "$S/.gates" | wc -l)
check "--only with an unknown id errors with exit 2 and writes nothing" sh -c 'cd "$0" && "$1/scripts/scorecard" --manifest manifest.json --only nope >/dev/null 2>&1; test $? -eq 2 && test "$(ls .gates | wc -l)" -eq "$2"' "$S" "$ROOT" "$NB"
jq '.gates += [.gates[0]]' "$S/m-env.json" > "$S/m-dup.json"
( cd "$S" && git add m-dup.json && git commit -qm dup )
check "duplicate gate ids are rejected with exit 2" sh -c 'cd "$0" && "$1/scripts/scorecard" --manifest m-dup.json >/dev/null 2>&1; test $? -eq 2' "$S" "$ROOT"
check "records exist for every gate including not_run" sh -c 'cd "$0"; d=.gates/$(readlink .gates/latest | xargs basename); test -f "$d/records/E-env.json"' "$S"
# fresh repo: dirty tree writes nothing
D=$(mktemp -d "$ROOT/.gates/tmp/scorecard-edge-dirty-XXXXXX")
( cd "$D" && git init -q -b main && git config user.email t@example.invalid && git config user.name t && cp "$S/m-env.json" m.json && printf '.gates/\n' > .gitignore && git add . && git commit -qm f && printf 'x\n' > stray.txt )
check "dirty tree exits 3 and creates no .gates directory" sh -c 'cd "$0" && "$1/scripts/scorecard" --manifest m.json >/dev/null 2>&1; test $? -eq 3 && test ! -e .gates' "$D" "$ROOT"
# parsers
T=$(mktemp -d "$ROOT/.gates/tmp/scorecard-edge-junit-XXXXXX")
mkdir -p "$T/api/tests/Feature" "$T/api/tests/Unit"
: > "$T/api/tests/Feature/XTest.php"; : > "$T/api/tests/Unit/YTest.php"
cat > "$T/r.xml" <<'XML'
<?xml version="1.0"?>
<testsuites><testsuite name="a"><testsuite name="b" file="/workspace/api/tests/Feature/XTest.php">
<testcase name="t1" file="/workspace/api/tests/Feature/XTest.php"/>
<testcase name="t2" file="/workspace/api/tests/Feature/XTest.php"><error/></testcase>
<testcase name="t3"><skipped/></testcase></testsuite></testsuite></testsuites>
XML
J="python3 -I scripts/lib/junit.py"
check_out "junit nested suites: bad = error + skipped, plus unlisted YTest" 3 $J "$T/r.xml" --tests-dir "$T/api/tests" --field bad
check_out "junit nested suites without tests-dir" 2 $J "$T/r.xml" --field bad
check_out "junit counts every nested testcase" 3 $J "$T/r.xml" --field tests
printf 'not xml at all' > "$T/junk.xml"; : > "$T/empty.xml"
for f in "$T/junk.xml" "$T/empty.xml" "$T/none.xml"; do
  check "junit unusable input $(basename "$f") is silent exit 2" sh -c 'out=$(python3 -I scripts/lib/junit.py "$0" --field tests); test $? -eq 2 && test -z "$out"' "$f"
done
printf '<?xml version="1.0"?><html/>' > "$T/html.xml"
check "junit non-JUnit root is exit 2" sh -c 'out=$(python3 -I scripts/lib/junit.py "$0" --field tests); test $? -eq 2 && test -z "$out"' "$T/html.xml"
printf '<coverage><project><metrics statements="3" coveredstatements="1"/></project></coverage>' > "$T/c.xml"
printf '<coverage><project><metrics statements="0" coveredstatements="0"/></project></coverage>' > "$T/c0.xml"
check_out "clover percent rounds to 2 places" 33.33 python3 -I scripts/lib/clover.py "$T/c.xml" --field percent
check_out "clover zero statements still prints 0 for statements" 0 python3 -I scripts/lib/clover.py "$T/c0.xml" --field statements
check "clover garbage is silent exit 2" sh -c 'out=$(python3 -I scripts/lib/clover.py "$0" --field percent); test $? -eq 2 && test -z "$out"' "$T/junk.xml"
finish
