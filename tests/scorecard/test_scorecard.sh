#!/bin/sh
# P0-i1-T25: scripts/scorecard executes unmasked, frames logs, and computes status from raw fields.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp
S=$(mktemp -d "$ROOT/.gates/tmp/scorecard-XXXXXX")
( cd "$S" && git init -q -b main && git config user.email t@example.invalid && git config user.name t \
  && printf '.gates/\n' > .gitignore && cp "$ROOT/tests/scorecard/fixtures/manifest-mini.json" manifest.json \
  && git add . && git commit -qm "fixture" )
run() { ( cd "$S" && env "$@" "$ROOT/scripts/scorecard" --manifest manifest.json ); }
check "mixed manifest exits non-zero" sh -c '! ( cd "$0" && "$1/scripts/scorecard" --manifest manifest.json >/dev/null 2>&1 )' "$S" "$ROOT"
SC="$S/.gates/latest/scorecard.json"
check "latest symlink points at a run directory" test -f "$SC"
st() { jq -r --arg id "$1" '.gates[] | select(.id == $id) | "\(.status)/\(.reason)"' "$SC"; }
check_out "G-pass" pass/ok st G-pass
check_out "G-exit" fail/exit_nonzero st G-exit
check_out "G-value" fail/value_threshold st G-value
check_out "G-zero" fail/zero_denominator st G-zero
check_out "G-min" fail/zero_denominator st G-min
check_out "G-unparsable" fail/value_unparsable st G-unparsable
check_out "G-empty-artifact" fail/value_unparsable st G-empty-artifact
check_out "G-banner" fail/banner_missing st G-banner
check_out "G-timeout" not_run/timeout st G-timeout
check_out "G-pipe" fail/exit_nonzero st G-pipe
check_out "G-secondary" fail/secondary_threshold st G-secondary
check_out "G-soft" fail/exit_nonzero st G-soft
check_out "G-primary in primary context" pass/ok st G-primary
check "every log ends with EXIT n matching the record" sh -c 'jq -r ".gates[] | select(.status != \"not_run\") | \"\(.log) \(.exit)\"" "$0" | while read -r log ex; do test "$(tail -1 "$1/$log")" = "EXIT $ex" || exit 1; done' "$SC" "$S"
check "log sha256 recorded correctly" sh -c 'jq -r ".gates[] | \"\(.log_sha256)  \(.log)\"" "$0" | ( cd "$1" && sha256sum -c --status )' "$SC" "$S"
check "every log starts with the GATE frame naming HEAD" sh -c 'h=$(git -C "$1" rev-parse HEAD); jq -r ".gates[].log" "$0" | while read -r log; do head -1 "$1/$log" | /usr/bin/grep -q "^## GATE .* head=$h " || exit 1; done' "$SC" "$S"
check "head, tree and clean flags recorded" sh -c 'test "$(jq -r .head "$0")" = "$(git -C "$1" rev-parse HEAD)" && test "$(jq -r .tree "$0")" = "$(git -C "$1" rev-parse "HEAD^{tree}")" && jq -e ".clean_before and .clean_after" "$0"' "$SC" "$S"
check "records written per gate" sh -c 'test "$(ls "$(dirname "$0")"/records/*.json | wc -l)" -eq 13' "$SC"
check "schema id" jq -e '.schema == "pokeprices-scorecard/v1"' "$SC"
jq '.gates |= map(select(.id == "G-pass" or .id == "G-soft" or .id == "G-primary"))' "$S/manifest.json" > "$S/m2.json"
( cd "$S" && git add m2.json && git commit -qm "m2" )
check "all hard pass exits 0 even with a failing soft gate" sh -c 'cd "$0" && "$1/scripts/scorecard" --manifest m2.json >/dev/null 2>&1' "$S" "$ROOT"
check "nested context records primary-scope gates out_of_scope" sh -c 'cd "$0" && PP_RUN_CONTEXT=nested "$1/scripts/scorecard" --manifest m2.json >/dev/null 2>&1; jq -e ".run_context == \"nested\" and (.gates[] | select(.id == \"G-primary\") | .status == \"out_of_scope\")" .gates/latest/scorecard.json' "$S" "$ROOT"
check "GITHUB_ACTIONS=true means ci context" sh -c 'cd "$0" && GITHUB_ACTIONS=true "$1/scripts/scorecard" --manifest m2.json >/dev/null 2>&1; jq -e ".run_context == \"ci\"" .gates/latest/scorecard.json' "$S" "$ROOT"
printf 'x\n' > "$S/dirty.txt"
check "dirty tree: refuses with exit 3" sh -c 'cd "$0" && "$1/scripts/scorecard" --manifest m2.json >/dev/null 2>&1; test $? -eq 3' "$S" "$ROOT"
rm "$S/dirty.txt"
jq '.gates += [{"id":"G-dirty","hard":false,"scope":"any","runs_in":"host","command":"touch made-by-gate.txt","banner_cmd":"bash --version | head -1","banner_re":"^GNU bash","artifact":null,"value_parser":"echo 0","op":"==","threshold":0,"denominator_source":"echo 1","denominator_min":1,"timeout_s":20,"why":"t"}]' "$S/m2.json" > "$S/m3.json"
( cd "$S" && git add m3.json && git commit -qm "m3" )
check "a gate that dirties the tree makes the run fail with clean_after false" sh -c 'cd "$0" && ! "$1/scripts/scorecard" --manifest m3.json >/dev/null 2>&1 && jq -e ".clean_after == false" .gates/latest/scorecard.json' "$S" "$ROOT"
F=tests/scorecard/fixtures
check_out "junit pest tests" 4 python3 -I scripts/lib/junit.py $F/junit-pest.xml --field tests
check_out "junit pest bad" 1 python3 -I scripts/lib/junit.py $F/junit-pest.xml --field bad
check_out "junit vitest tests" 3 python3 -I scripts/lib/junit.py $F/junit-vitest.xml --field tests
check "junit missing file is exit 2 and silent" sh -c 'out=$(python3 -I scripts/lib/junit.py /nonexistent.xml --field tests); test $? -eq 2 && test -z "$out"'
check_out "clover percent" 87.5 python3 -I scripts/lib/clover.py $F/clover.xml --field percent
check_out "clover statements" 16 python3 -I scripts/lib/clover.py $F/clover.xml --field statements
check "clover zero statements has no percent" sh -c 'out=$(python3 -I scripts/lib/clover.py "$0" --field percent); test $? -eq 2 && test -z "$out"' "$F/clover-empty.xml"
check "scorecard passes the recipe lint by construction" sh -c '! /usr/bin/grep -nE "exit 0|exit\(0\)|\|\| *true|\|\| *:" scripts/scorecard scripts/lib/junit.py scripts/lib/clover.py'
finish
