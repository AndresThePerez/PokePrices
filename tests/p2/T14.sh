#!/bin/sh
# P2-i1-T14: the price operations runbook. docs/OPERATIONS.md has exactly the eight level-2 sections in order, names
# only pokeprices:prices:* commands the app registers, gives every artisan command with the compose prefix, carries
# the runbook lines the review record requires, says nothing real-time, and trips no hygiene pattern. README.md
# points to it. Reads tracked docs and asks the running api container for its command list; it writes nothing.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
F=docs/OPERATIONS.md
API="$DC exec -T -w /workspace/api api"
PREFIX='docker compose -f compose.dev.yml exec -T -w /workspace/api api'

HEADINGS='## Daily routine
## Ingest a day by hand
## Resume a failed run
## Replay
## Map rebuild then replay
## Partitions and retention
## Failure runbook
## What never happens'

check "the runbook exists and is tracked" sh -c 'test -s "$0" && git ls-files --error-unmatch "$0" >/dev/null' "$F"
check_out "there are exactly 8 level-2 headings" 8 sh -c "$GREP -c '^## ' \"\$0\"" "$F"
check_out "the level-2 headings are the exact titles, in order" "$HEADINGS" sh -c "$GREP '^## ' \"\$0\"" "$F"
check "README.md points to docs/OPERATIONS.md" $GREP -q -F 'docs/OPERATIONS.md' README.md

# Every pokeprices:prices:* command the document names must be registered.
REGISTERED=$($API php artisan list --raw 2>/dev/null)
check "the api container lists commands (the stack is up)" test -n "$REGISTERED"
NAMED=$($GREP -o -E 'pokeprices:prices:[a-z][a-z-]*' "$F" 2>/dev/null | sort -u)
check "the runbook names at least one pokeprices:prices command" test -n "$NAMED"
for cmd in ingest replay freshness movement-volume; do
  check "the runbook names pokeprices:prices:$cmd" $GREP -q -F "pokeprices:prices:$cmd" "$F"
done
for cmd in $NAMED; do
  check "the named command $cmd is registered in the api container" sh -c 'printf "%s\n" "$1" | '"$GREP"' -q -x -E "^$0( .*)?\$"' "$cmd" "$REGISTERED"
done
for cmd in $($GREP -o -E 'pokeprices:(map|oracle):[a-z][a-z-]*' "$F" 2>/dev/null | sort -u); do
  check "the named command $cmd is registered in the api container" sh -c 'printf "%s\n" "$1" | '"$GREP"' -q -x -E "^$0( .*)?\$"' "$cmd" "$REGISTERED"
done

# Every artisan invocation carries the compose prefix.
check "the runbook has artisan invocations" $GREP -q -F 'php artisan' "$F"
check "every line with 'php artisan' carries the full compose exec prefix" \
  sh -c '! '"$GREP"' -F "php artisan" "$0" | '"$GREP"' -v -F -e "$1"' "$F" "$PREFIX"
check "every compose invocation is exec -T, restart, up -d --wait or ps" \
  sh -c '! '"$GREP"' -F "docker compose" "$0" | '"$GREP"' -v -E "docker compose -f compose\.dev\.yml (exec -T|restart|up -d --wait|ps)"' "$F"
check "the runbook never uses docker run, compose run, down -v or rm -rf" \
  sh -c '! '"$GREP"' -q -E "docker run|compose run|down -v|--volumes|rm -rf" "$0"' "$F"

# The commands and their exit-code meanings.
for flag in --sync --queued --force --resume --wait --fresh --order --days --digest --json --markdown; do
  check "the runbook shows the option $flag" $GREP -q -F -e "$flag" "$F"
done
for key in pokeprices:map:build pokeprices:map:audit pokeprices:map:golden pokeprices:map:oracle schedule:list \
  pokeprices-ingest-pending pokeprices-freshness /v1/prices/freshness ingest_runs ingest_run_chunks failed_jobs \
  price_events price_points price_daily price_latest price_movers movement_volume source_product_map_history \
  series_epoch map_build_id code_sha pricing:ingest pg_locks; do
  check "the runbook carries: $key" $GREP -q -F -e "$key" "$F"
done
for reason in 'bad_value:' manifest_mismatch marker_day_mismatch missing_file bad_document bad_marker refused skipped_unchanged superseded; do
  check "the failure runbook names the status or reason: $reason" $GREP -q -F -e "$reason" "$F"
done
check "the runbook says what the exit codes are (0 and 1)" sh -c ''"$GREP"' -q -i "exit" "$0" && '"$GREP"' -q -E "exits? 1|Exit 1|exit code 1|\| *1 *\|" "$0"' "$F"

# The review record's runbook lines.
check "a long replay: freshness answers a breach-like no-prices state until the replay's ok runs land" \
  sh -c ''"$GREP"' -q -F "no prices ingested" "$0" && '"$GREP"' -q -F "last_run" "$0" && '"$GREP"' -q -i "mode.*replay.*running\|replay.*status.*running" "$0"' "$F"
check "a failing day re-runs at every :40 scan; only refused days are suppressed" \
  sh -c ''"$GREP"' -q -i "every :40" "$0" && '"$GREP"' -q -i "only .*refused" "$0"' "$F"
check "after job-class code changes, restart the worker and the scheduler" \
  $GREP -q -F 'docker compose -f compose.dev.yml restart worker scheduler' "$F"
check "tests/p2/T07.sh leaves the dev database in the ascending replay state" \
  sh -c ''"$GREP"' -q -F "tests/p2/T07.sh" "$0" && '"$GREP"' -q -i "ascending" "$0"' "$F"
check "t09WithLockHeld hangs rather than fails on a real lock leak" \
  sh -c ''"$GREP"' -q -F "t09WithLockHeld" "$0" && '"$GREP"' -q -i "hang" "$0"' "$F"
check "a sub-second marker is stored truncated to the second" \
  sh -c ''"$GREP"' -q -i "sub-second" "$0" && '"$GREP"' -q -i "truncated to the second" "$0"' "$F"
check "replay --fresh refuses once any dispatched_at is set; the outbox is append-only until P5" \
  sh -c ''"$GREP"' -q -F "dispatched_at" "$0" && '"$GREP"' -q -i "append-only" "$0" && '"$GREP"' -q -F "P5" "$0"' "$F"
check "R180a and R180c are named" sh -c ''"$GREP"' -q -F "R180a" "$0" && '"$GREP"' -q -F "R180c" "$0"' "$F"
check "the map rebuild section says history is relabelled with the current epochs" \
  sh -c 'sed -n "/^## Map rebuild then replay/,/^## Partitions/p" "$0" | '"$GREP"' -q -i "relabel"' "$F"
check "the map rebuild section names the open decision on source_product_map_history" \
  sh -c 'sed -n "/^## Map rebuild then replay/,/^## Partitions/p" "$0" | '"$GREP"' -q -F "source_product_map_history"' "$F"

# Honesty: no forbidden word on word boundaries; nothing says the feed is current right now.
for w in live real-time realtime 1D; do
  check "no standalone '$w' in the runbook (word-boundary grep -wi)" sh -c '! '"$GREP"' -q -w -i -e "$1" "$0"' "$F" "$w"
done
check "no 'as of today' and no 24h wording in the runbook" sh -c '! '"$GREP"' -q -i -E "as of today|24 ?h([^a-z]|$)" "$0"' "$F"
check "the runbook says daily close" $GREP -q -i 'daily close' "$F"
check "PokéPrices is spelled with the é; no unaccented spelling in prose" \
  sh -c ''"$GREP"' -q -F "PokéPrices" "$0" && ! '"$GREP"' -q -E "Poke(Prices|Search)" "$0"' "$F"

# Hygiene: the frozen pattern file, read-only (PCRE, non-comment lines), and the ports allowed on the workstation.
PAT=$(sed -e 's/\r$//' -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' gates/hygiene.patterns | sed 's/.*/(?:&)/' | paste -sd'|' -)
check "the hygiene pattern file yields a pattern" test -n "$PAT"
check "the runbook trips no pattern of gates/hygiene.patterns" sh -c '! '"$GREP"' -q -P -e "$1" "$0"' "$F" "$PAT"
check "no http port other than 8095 and 8081 is named" \
  sh -c '! '"$GREP"' -o -E "localhost:[0-9]+" "$0" | '"$GREP"' -v -E "localhost:(8095|8081)$"' "$F"
check "no ssh, scp, rsync or hostname-shaped server reference" \
  sh -c '! '"$GREP"' -q -i -E "(^|[^a-z])(ssh|scp|rsync)([^a-z]|$)|tailscale|\.home([^a-z]|$)" "$0"' "$F"

check "the runbook is tracked as plain text mode 100644" \
  sh -c 'test "$(git ls-files -s "$0" | cut -d" " -f1)" = 100644' "$F"
check_out "this script is tracked as mode 100755" 100755 sh -c 'git ls-files -s tests/p2/T14.sh | cut -d" " -f1'
finish
