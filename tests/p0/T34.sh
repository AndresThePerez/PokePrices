#!/bin/sh
# P0-i1-T34: one full make gates run where every hard gate passes (tightened at the T36 freeze).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
make gates > .gates/T34-make-gates.log 2>&1
SC=.gates/latest/scorecard.json
check "scorecard exists for HEAD" sh -c 'test "$(jq -r .head "$0")" = "$(git rev-parse HEAD)"' "$SC"
check "primary context, clean before and after" jq -e '.run_context == "primary" and .clean_before and .clean_after' "$SC"
check "every hard gate passes" jq -e '[.gates[] | select(.hard and .status != "pass") | .id] == []' "$SC"
check "EC-ci-parity's nested run passes every in-scope hard gate" sh -c 'jq -e "[.gates[] | select(.hard and .status != \"pass\" and .status != \"out_of_scope\") | .id] == []" "$(dirname "$0")/EC-ci-parity.nested.json"' "$SC"
check "AG1 shows no violations against the anchor" sh -c 'jq -e "[.details[] | select(.violation == true)] == [] and .value == 0 and .denominator > 0" "$(dirname "$0")/AG1.json"' "$SC"
check "DEN shows no failing details" sh -c 'jq -e ".value == 0 and .details == []" "$(dirname "$0")/DEN.json"' "$SC"
check "AG-values clean on every group" jq -e '.gates[] | select(.id == "AG-values") | .status == "pass"' "$SC"
check "at least 4 of 5 soft gates pass" jq -e '[.gates[] | select(.hard | not) | select(.status == "pass")] | length >= 4' "$SC"
check "the tree is still clean" sh -c 'test -z "$(git status --porcelain --untracked-files=all)"'
finish
