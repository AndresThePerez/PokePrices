#!/bin/sh
# P2-i1-T03: the day-close ADR draft exists, in drafts/, with the exact headings, the D1-D8 numbers and the
# honesty wording, and nothing in docs/adr calls the feed real-time. Reads tracked docs only: no data, no stack.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
F=docs/adr/drafts/0001-day-close.md

HEADINGS='## Context
## Decision: the day and the close
## Decision: carry-forward
## Decision: previous close and change
## Decision: epochs
## Decision: schedule and freshness
## Decision: movement thresholds (draft)
## Alternatives considered
## Consequences
## Open for Andres'

check "the draft exists at docs/adr/drafts/0001-day-close.md" test -s "$F"
check "the draft is tracked by git (not a loose file)" sh -c 'git ls-files --error-unmatch "$0" >/dev/null' "$F"
check "no ADR 1 sits outside drafts/ (it counts only after Andres moves it)" sh -c 'test -z "$(git ls-files docs/adr | '"$GREP"' -v "^docs/adr/drafts/" | '"$GREP"' -i "day-close")"'

check_out "the title line is exact" '# ADR 1: Day close for PokéPrices prices' sh -c 'head -n 1 "$0"' "$F"
check "the status line is exact and says Proposed, not Accepted" \
  $GREP -q -x -F 'Status: Proposed (draft for Andres; he moves it to docs/adr/ to accept it)' "$F"
check "the date line is an ISO date" $GREP -q -x -E 'Date: 20[0-9]{2}-[01][0-9]-[0-3][0-9]' "$F"
check "the draft never marks itself accepted" sh -c '! '"$GREP"' -q -i -E "^status: *accepted" "$0"' "$F"

check_out "there are exactly 10 level-2 headings" 10 sh -c "$GREP -c '^## ' \"\$0\"" "$F"
check_out "the level-2 headings are the exact titles, in order" "$HEADINGS" sh -c "$GREP '^## ' \"\$0\"" "$F"

# D1-D8 numbers and identifiers (fixed strings, whole words where a number could hide in a longer one).
for n in 14 26 5.00 1.00 2.00; do
  check "the draft states the number $n" $GREP -q -w -F -e "$n" "$F"
done
for k in marketPrice series_epoch marker_day_mismatch manifest_mismatch 'latest marker' midPrice lowPrice \
  previous_close draft-v1 source_product_map_history ingest-pending 'minute 40' 'daily close' \
  'is_filled' 'filled_from' 'n_obs' 'price_events' 'price_points' 'price_daily' 'price_latest' 'price_movers' \
  'within_slo' 'half away from zero' 'map build' 'code sha'; do
  check "the draft carries the key phrase: $k" $GREP -q -F -e "$k" "$F"
done
check "the placeholder line is present, exactly once" \
  sh -c 'test "$('"$GREP"' -c -x -F "Measured volume: pending (T13)." "$0")" = 1' "$F"
check "the two thresholds are marked pending measurement" \
  sh -c '[ "$('"$GREP"' -c -i "pending measurement" "$0")" -ge 2 ]' "$F"
check "R180a is stated: replay never writes price_events" \
  sh -c ''"$GREP"' -i -q "replay" "$0" && '"$GREP"' -i -q -E "never (writes|writing) .*price_events|price_events.*never" "$0"' "$F"
check "R180c is stated: replay relabels history with the current map's epochs" \
  sh -c ''"$GREP"' -q -i "relabel" "$0" && '"$GREP"' -q -i "current map" "$0"' "$F"

# Honesty: no forbidden word on word boundaries, so "deliver", "deliveries" and "delivery" do not trip it.
for w in live real-time 1D; do
  check "no standalone '$w' in the draft (word-boundary grep -wi)" sh -c '! '"$GREP"' -q -w -i -e "$1" "$0"' "$F" "$w"
done
check "the feed is never called real-time anywhere under docs/adr (tracked files)" \
  sh -c '! git ls-files -z docs/adr | xargs -0 '"$GREP"' -q -w -i -e "real-time" --'
check "no 'as of today' and no 24h wording in the draft" sh -c '! '"$GREP"' -q -i -E "as of today|24 ?h([^a-z]|$)" "$0"' "$F"
check "the draft says it is a daily close, published once a day" $GREP -q -i -E 'daily close' "$F"
check "the draft has no home path and no private address" \
  sh -c '! '"$GREP"' -q -E "/home/|/Users/|(^|[^0-9])(10|192\.168|172\.(1[6-9]|2[0-9]|3[01]))\.[0-9]+\.[0-9]+|100\.[0-9]+\.[0-9]+\.[0-9]+" "$0"' "$F"
check "the draft is tracked as plain text mode 100644" \
  sh -c 'test "$(git ls-files -s "$0" | cut -d" " -f1)" = 100644' "$F"
check_out "this script is tracked as mode 100755" 100755 sh -c 'git ls-files -s tests/p2/T03.sh | cut -d" " -f1'
finish
