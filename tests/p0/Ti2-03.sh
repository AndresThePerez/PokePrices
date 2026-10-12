#!/bin/sh
# P0-i2-T03 (F-P0-107, F-P0-108): two edge tests say what they prove, and test_c6_edge.sh writes
# only under .gates/ (repo-relative GATES_OUT, the gate contract).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
E=tests/p0/T34-edge.sh; C6E=tests/gates/test_c6_edge.sh; Q=api/tests/Feature/QueueProbeEdgeTest.php
# F-P0-107
check "T34-edge no longer claims the negative-timeout probe 'still exits 1'" sh -c '! /usr/bin/grep -qF "$1" "$0"' "$E" 'still exits 1 with a negative timeout'
check "T34-edge says the negative-timeout check proves rc 0 or 1 and no hang" $GREP -qF 'returns within 60 s with rc 0 or 1' "$E"
check "T34-edge's live-probe description names its retry" $GREP -qF 'one retry with an 8 s timeout' "$E"
check "QueueProbeEdgeTest pins exit 1 for a -5 timeout" sh -c '/usr/bin/grep -qF "assertExitCode(1)" "$0" && /usr/bin/grep -qF "$1" "$0"' "$Q" "'-5'"
# F-P0-108
abs_out='GATES_OUT="?(/|\$0/|\$root|\$PWD|\$HOME|\$\(pwd\)|`pwd`)'
check "test_c6_edge passes no absolute GATES_OUT" sh -c '! /usr/bin/grep -qE "$1" "$0"' "$C6E" "$abs_out"
check "test_c6_edge captures no absolute repo root" sh -c '! /usr/bin/grep -qE "$1" "$0"' "$C6E" '\$PWD|\$\(pwd\)|`pwd`|root='
mkdir -p .gates/tmp; mark=.gates/tmp/i2-03.mark; : > "$mark"
sh "$C6E" >/dev/null 2>&1; rc=$?
check "test_c6_edge.sh still passes" test "$rc" -eq 0
check_out "test_c6_edge.sh wrote nothing outside .gates/, api/ and web/" 0 \
  sh -c 'find . \( -path ./.git -o -path ./.gates -o -path ./api -o -path ./web \) -prune -o -newer "$0" -type f -print | wc -l | tr -d " "' "$mark"
check "the untracked-types case recorded its details under the clone's .gates/" \
  sh -c 'test .gates/tmp/c6e/clone/.gates/tmp/c6e/out2/C6.details.jsonl -nt "$0"' "$mark"
finish
