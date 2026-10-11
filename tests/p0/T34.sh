#!/bin/sh
# P0-i1-T34: one full make gates run where only AG1 (and DEN and EC-ci-parity through it) may fail.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
make gates > .gates/T34-make-gates.log 2>&1
SC=.gates/latest/scorecard.json
check "scorecard exists for HEAD" sh -c 'test "$(jq -r .head "$0")" = "$(git rev-parse HEAD)"' "$SC"
check "primary context, clean before and after" jq -e '.run_context == "primary" and .clean_before and .clean_after' "$SC"
check "every hard gate except AG1, DEN and EC-ci-parity passes (exactly those three fail)" jq -e '[.gates[] | select(.hard and .status != "pass") | .id] | sort == ["AG1","DEN","EC-ci-parity"]' "$SC"
check "EC-ci-parity's nested run fails only on AG1 and DEN" sh -c 'jq -e "[.gates[] | select(.hard and .status != \"pass\" and .status != \"out_of_scope\") | .id] | sort == [\"AG1\",\"DEN\"]" "$(dirname "$0")/EC-ci-parity.nested.json"' "$SC"
check "AG1 fails only for the missing anchor" sh -c 'jq -e "[.details[] | select(.violation == true) | .reason] | unique == [\"anchor_absent\"]" "$(dirname "$0")/AG1.json"' "$SC"
check "DEN fails only on AG1" sh -c 'jq -e "[.details[] | select(.status == \"fail\") | .gate] | unique == [\"AG1\"]" "$(dirname "$0")/DEN.json"' "$SC"
check "AG-values clean on every group" jq -e '.gates[] | select(.id == "AG-values") | .status == "pass"' "$SC"
check "at least 4 of 5 soft gates pass" jq -e '[.gates[] | select(.hard | not) | select(.status == "pass")] | length >= 4' "$SC"
check "the tree is still clean" sh -c 'test -z "$(git status --porcelain --untracked-files=all)"'
finish
