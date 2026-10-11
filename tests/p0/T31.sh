#!/bin/sh
# P0-i1-T31: make targets exist, are idempotent, keep the tree clean, and make gates runs all 39 gates.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
check "make -n gates calls scorecard then verdict --gates-only" sh -c 'make -n gates | /usr/bin/grep -q "scripts/scorecard --manifest gates/manifest-P0.json" && make -n gates | /usr/bin/grep -q "scripts/verdict --gates-only"'
check "every compose exec in the recipes has -T" sh -c '! make -n init gates | /usr/bin/grep -E "exec " | /usr/bin/grep -vq -- " -T "'
check "down never removes volumes" sh -c '! make -n down | /usr/bin/grep -Eq -- "-v\b|--volumes"'
check "make verdict without FINDINGS refuses" sh -c '! make verdict >/dev/null 2>&1'
check "make init succeeds" make init
check "make init is idempotent" make init
check "api/.env exists (existence only)" test -e api/.env
check "init leaves the tree clean" sh -c 'test -z "$(git status --porcelain --untracked-files=all)"'
mkdir -p .gates
: > .gates/T31-start.marker
make gates > .gates/T31-make-gates.log 2>&1; rc=$?
check "make gates produced a scorecard" test -f .gates/latest/scorecard.json
check "the scorecard was written by this make gates run (not a stale one)" test .gates/latest/scorecard.json -nt .gates/T31-start.marker
check_out "the scorecard holds all 39 manifest gates" 39 jq '.gates | length' .gates/latest/scorecard.json
check "make gates exits 0 and every hard gate passes (the gate set is frozen since T36)" sh -c 'test "$0" -eq 0 && jq -e "[.gates[] | select(.hard and .status != \"pass\") | .id] == []" .gates/latest/scorecard.json' "$rc"
finish
