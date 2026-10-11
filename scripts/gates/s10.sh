#!/bin/sh
# S10 (soft): CRITICAL vulnerabilities in the two runtime images, no ignore file, no --ignore-unfixed.
set -u
: "${GATES_OUT:?GATES_OUT must be set}"
. "$(dirname "$0")/../lib/gate-result.sh"
p=${COMPOSE_PROJECT_NAME:-pokeprices}
details="$GATES_OUT/S10.details.jsonl"; : > "$details"
total=0; scanned=0
docker build -q -f docker/api/Dockerfile --target runtime -t "$p-api:gate" . >/dev/null || total=$((total + 1000))
docker build -q -f docker/web/Dockerfile --target runtime -t "$p-web:gate" . >/dev/null || total=$((total + 1000))
for img in api web; do
  tar="$GATES_OUT/S10.$img.tar"
  if docker save -o "$tar" "$p-$img:gate" \
     && docker compose -f compose.dev.yml exec -T tools trivy image --input "/repo/$tar" --severity CRITICAL --format json --exit-code 0 -o "/repo/$GATES_OUT/S10.$img.json"; then
    n=$(jq '[.Results[]?.Vulnerabilities[]?] | length' "$GATES_OUT/S10.$img.json")
    db=$(jq -r '.Metadata.DB.UpdatedAt // .Trivy.DB.UpdatedAt // "unknown"' "$GATES_OUT/S10.$img.json" 2>/dev/null)
    scanned=$((scanned + 1))
  else
    n=1000; db=unknown
  fi
  rm -f "$tar"
  total=$((total + n))
  jq -nc --arg img "$img" --argjson n "$n" --arg db "$db" '{image: $img, critical: $n, trivy_db: $db}' >> "$details"
done
gate_result S10 "$total" "$scanned" "$details"
printf 'S10: %s CRITICAL across %s images\n' "$total" "$scanned"
[ "$total" -eq 0 ]
