# shellcheck shell=sh
# gate_result ID VALUE DENOMINATOR DETAILS_JSONL: write $GATES_OUT/ID.json for scripts/scorecard.
# VALUE and DENOMINATOR must be finite JSON numbers; on any failure the status is non-zero and
# no $GATES_OUT/ID.json is left behind (a stale or partial artifact must never be read as a result).
gate_result() {
  : "${GATES_OUT:?GATES_OUT must be set}"
  if jq -en --argjson value "$2" --argjson denominator "$3" \
      '[$value, $denominator] | all(type == "number" and (isnan | not) and (isinfinite | not))' >/dev/null 2>&1 &&
    jq -n --arg id "$1" --argjson value "$2" --argjson denominator "$3" --slurpfile details "$4" \
      '{id: $id, value: $value, denominator: $denominator, details: $details}' > "$GATES_OUT/$1.json"; then
    return 0
  fi
  echo "gate_result: $1: value and denominator must be finite numbers and details valid JSON lines" >&2
  rm -f "$GATES_OUT/$1.json"
  return 1
}
