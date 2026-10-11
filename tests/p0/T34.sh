#!/bin/sh
# P0-i1-T34: one full make gates run where only AG1 and EC-ci-parity (and DEN through it) may fail.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
make gates > .gates/T34-make-gates.log 2>&1
SC=.gates/latest/scorecard.json
check "scorecard exists for HEAD" sh -c 'test "$(jq -r .head "$0")" = "$(git rev-parse HEAD)"' "$SC"
check "primary context, clean before and after" jq -e '.run_context == "primary" and .clean_before and .clean_after' "$SC"
check "every hard gate except AG1, EC-ci-parity and DEN passes" jq -e '[.gates[] | select(.hard and .status != "pass") | .id] - ["AG1","EC-ci-parity","DEN"] == []' "$SC"
check "AG1 fails only for the missing anchor" sh -c 'jq -e "[.details[] | select(.violation == true) | .reason] | unique == [\"anchor_absent\"]" "$(dirname "$0")/AG1.json"' "$SC"
check "DEN fails only on AG1 and EC-ci-parity" sh -c 'jq -e "[.details[] | select(.status == \"fail\") | .gate] | unique == [\"AG1\",\"EC-ci-parity\"]" "$(dirname "$0")/DEN.json"' "$SC"
check "AG-values clean on every group" jq -e '.gates[] | select(.id == "AG-values") | .status == "pass"' "$SC"
check "at least 4 of 5 soft gates pass" jq -e '[.gates[] | select(.hard | not) | select(.status == "pass")] | length >= 4' "$SC"
check "the tree is still clean" sh -c 'test -z "$(git status --porcelain --untracked-files=all)"'
finish
