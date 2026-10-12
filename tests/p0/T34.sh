#!/bin/sh
# P0-i1-T34: one full make gates run where every hard gate passes (tightened at the T36 freeze).
# P1-i1-T07: "passes" now means the failing hard set equals the ids tests/pending-gates.txt lists.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
PG=tests/pending-gates.txt
make gates > .gates/T34-make-gates.log 2>&1
SC=.gates/latest/scorecard.json
check "scorecard exists for HEAD" sh -c 'test "$(jq -r .head "$0")" = "$(git rev-parse HEAD)"' "$SC"
check "primary context, clean before and after" jq -e '.run_context == "primary" and .clean_before and .clean_after' "$SC"
P=$(pending_json "$PG") || P=invalid
check "the failing hard set matches tests/pending-gates.txt" jq -e --argjson p "$P" '([.gates[] | select(.hard and .status != "pass") | .id] | sort) == $p' "$SC"
check "EC-ci-parity's nested run: the in-scope failing hard set matches tests/pending-gates.txt (ids out of scope there excepted)" sh -c 'jq -e --argjson p "$1" "[.gates[] | select(.status == \"out_of_scope\") | .id] as \$o | ([.gates[] | select(.hard and .status != \"pass\" and .status != \"out_of_scope\") | .id] | sort) == [\$p[] | select(. as \$i | \$o | index(\$i) == null)]" "$(dirname "$0")/EC-ci-parity.nested.json"' "$SC" "$P"
check "AG1 shows no violations against the anchor" sh -c 'jq -e "[.details[] | select(.violation == true)] == [] and .value == 0 and .denominator > 0" "$(dirname "$0")/AG1.json"' "$SC"
check "DEN shows no failing details" sh -c 'jq -e ".value == 0 and .details == []" "$(dirname "$0")/DEN.json"' "$SC"
check "AG-values clean on every group" jq -e '.gates[] | select(.id == "AG-values") | .status == "pass"' "$SC"
check "at least 4 of 5 soft gates pass" jq -e '[.gates[] | select(.hard | not) | select(.status == "pass")] | length >= 4' "$SC"
check "the tree is still clean" sh -c 'test -z "$(git status --porcelain --untracked-files=all)"'
finish
