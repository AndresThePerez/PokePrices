#!/bin/sh
# P2-i1-T07: the real replay (exit criterion 2; review F5, F6; R180). pokeprices:prices:replay --fresh runs over every
# collected day in .data/snapshots twice on the dev database: descending first, ascending last, so the database ends
# in the ascending state. Each run must be identical (three passes, same order), cover exactly the days with SUCCESS,
# hold rows in price_points, price_daily and price_latest in every pass (never a vacuous pass), and the two orders
# must give equal digests. price_events is never compared (R180b). The raw snapshot files are only read.
# Takes minutes; run it with a 20-minute timeout. Needs the running pokeprices stack.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
API="$DC exec -T -w /workspace/api api"

if [ ! -d .data/snapshots ]; then
  printf 'skip - T07 real replay: .data/snapshots is absent (no collected days on this machine)\n'
  exit 0
fi

mkdir -p .gates/tmp
T=$(mktemp -d "$(pwd)/.gates/tmp/P2-T07.XXXXXX") || exit 1
SHA=$(git rev-parse HEAD)
N=$(find .data/snapshots -mindepth 2 -maxdepth 2 -name SUCCESS -type f | wc -l | tr -d ' ')
tree_sums() { find .data/snapshots -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1; }
BEFORE=$(tree_sums)

check "at least one collected day (.data/snapshots/*/SUCCESS) exists" test "$N" -ge 1
check_out "HEAD is a full commit sha" yes sh -c 'printf "%s" "$0" | '"$GREP"' -qxE "[0-9a-f]{40}" && echo yes' "$SHA"

for order in desc asc; do
  start=$(date +%s)
  $API php artisan pokeprices:prices:replay --fresh --order="$order" --code-sha "$SHA" > "$T/$order.json" 2> "$T/$order.err"
  printf '%s' "$?" > "$T/$order.exit"
  printf '# replay --order=%s: exit %s in %ss\n' "$order" "$(cat "$T/$order.exit")" "$(( $(date +%s) - start ))"
  printf '# %s\n' "$(cat "$T/$order.json")"
done

for order in desc asc; do
  J=$T/$order.json
  check_out "replay --order=$order exits 0" 0 cat "$T/$order.exit"
  check "replay --order=$order prints one JSON document" jq -e 'type == "object"' "$J"
  check "replay --order=$order says identical: true" jq -e '.identical == true' "$J"
  check "replay --order=$order ran in order $order" jq -e --arg o "$order" '.order == $o' "$J"
  check "replay --order=$order day_count equals the $N SUCCESS folders" jq -e --argjson n "$N" '.day_count == $n and (.days | length) == $n' "$J"
  check "replay --order=$order carries --code-sha HEAD" jq -e --arg s "$SHA" '.code_sha == $s' "$J"
  check "replay --order=$order records the map build id" jq -e '(.map_build_id | type) == "number" and .map_build_id >= 1' "$J"
  check "replay --order=$order has passes 1, 2 and 3" jq -e '[.passes[].pass] == [1, 2, 3]' "$J"
  check "replay --order=$order digests exactly price_points, price_daily and price_latest in every pass" jq -e \
    '[.passes[].digests | keys] == [["price_daily","price_latest","price_points"],["price_daily","price_latest","price_points"],["price_daily","price_latest","price_points"]]' "$J"
  check "replay --order=$order has rows > 0 in price_points, price_daily and price_latest in every pass" jq -e \
    '[.passes[].digests | .price_points.rows, .price_daily.rows, .price_latest.rows] | length == 9 and all(. > 0)' "$J"
  check "replay --order=$order passes 1, 2 and 3 have equal digests" jq -e \
    '.passes[0].digests == .passes[1].digests and .passes[1].digests == .passes[2].digests' "$J"
done

for table in price_points price_daily price_latest; do
  check "$table has equal rows and sha256 across the desc and asc replays" jq -n -e --arg t "$table" \
    --slurpfile d "$T/desc.json" --slurpfile a "$T/asc.json" \
    '$d[0].passes[2].digests[$t] == $a[0].passes[2].digests[$t] and ($a[0].passes[2].digests[$t].sha256 | test("^[0-9a-f]{64}$"))'
done
check "the desc and asc replays covered the same days and map build" jq -n -e \
  --slurpfile d "$T/desc.json" --slurpfile a "$T/asc.json" '$d[0].days == $a[0].days and $d[0].map_build_id == $a[0].map_build_id'
check_out "the raw snapshot files are unchanged by the replays" "$BEFORE" tree_sums
finish
