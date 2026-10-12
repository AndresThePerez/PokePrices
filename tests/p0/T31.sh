#!/bin/sh
# P0-i1-T31: make targets exist, are idempotent, keep the tree clean, and make gates runs all 39 gates.
# P1-i1-T07: the failing hard set must equal the ids tests/pending-gates.txt lists, exactly.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
PG=tests/pending-gates.txt
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
P=$(pending_json "$PG") || P=invalid
want_rc=0; want_exit=0; [ "$P" = "[]" ] || { want_rc=2; want_exit=1; }
check "make gates exits 0 when the ledger lists no ids, else 2 (make) with scorecard exit 1, and the failing hard set matches tests/pending-gates.txt" sh -c 'test "$0" -eq "$1" && jq -e --argjson x "$2" --argjson p "$3" "(.exit == \$x) and (([.gates[] | select(.hard and .status != \"pass\") | .id] | sort) == \$p)" .gates/latest/scorecard.json' "$rc" "$want_rc" "$want_exit" "$P"
finish
