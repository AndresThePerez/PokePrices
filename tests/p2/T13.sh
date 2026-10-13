#!/bin/sh
# P2-i1-T13: the measured movement volume is recorded (plan item 7, review note). docs/measurements/movement-volume.md
# exists, lists every day the dev database's movement_volume holds, has the four rule tables, and the ADR draft's
# "Measured volume:" line carries the draft-v1 mean the measurement file states. It never regenerates the file and
# never diffs against a regeneration: a newly pulled day must not break it. Needs the running pokeprices stack.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
M=docs/measurements/movement-volume.md
ADR=docs/adr/drafts/0001-day-close.md

if [ ! -d .data/snapshots ]; then
  printf 'skip - T13 movement volume: .data/snapshots is absent (no collected days on this machine)\n'
  exit 0
fi

DAYS=$($DC exec -T postgres psql -U pokeprices -d pokeprices -Atc "select distinct day from movement_volume order by 1" 2>/dev/null)
# The draft-v1 mean, from the measurement file's summary row "| draft-v1 | pct | abs | mean | min | max |".
MEAN=$($GREP -m 1 '^| draft-v1 | ' "$M" 2>/dev/null | cut -d'|' -f5 | tr -d ' ')

check "the measurement file exists and is tracked" sh -c 'test -s "$0" && git ls-files --error-unmatch "$0" >/dev/null' "$M"
check "movement_volume on the dev database holds at least one day" test -n "$DAYS"
for day in $DAYS; do
  check "the measurement file lists the day $day" $GREP -q -F -e "$day" "$M"
done
for rule in draft-v1 tight loose coarse; do
  check "the measurement file has the $rule table" sh -c ''"$GREP"' -q -E "^## $1 \(" "$0"' "$M" "$rule"
done
check "the measurement file states a draft-v1 mean with two decimals" sh -c 'printf "%s" "$0" | '"$GREP"' -q -x -E "[0-9]+\.[0-9]{2}"' "$MEAN"
check_out "the ADR draft has exactly one line beginning 'Measured volume:'" 1 sh -c "$GREP -c '^Measured volume:' \"\$0\"" "$ADR"
check "the ADR's 'Measured volume:' line carries the draft-v1 mean of the measurement file" \
  sh -c ''"$GREP"' "^Measured volume:" "$0" | '"$GREP"' -q -w -F -e "$1"' "$ADR" "$MEAN"
check "the ADR's 'Measured volume:' line points to the measurement file" \
  sh -c ''"$GREP"' "^Measured volume:" "$0" | '"$GREP"' -q -F "$1"' "$ADR" "$M"
check "the measurement file never says live or real-time" sh -c '! '"$GREP"' -q -w -i -E "live|real-time|realtime" "$0"' "$M"
check "the measurement file has no home path and no private address" \
  sh -c '! '"$GREP"' -q -E "/home/|/Users/|(^|[^0-9])(10|192\.168|172\.(1[6-9]|2[0-9]|3[01]))\.[0-9]+\.[0-9]+|100\.[0-9]+\.[0-9]+\.[0-9]+" "$0"' "$M"
check_out "this script is tracked as mode 100755" 100755 sh -c 'git ls-files -s tests/p2/T13.sh | cut -d" " -f1'
finish
