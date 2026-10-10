# PokéPrices P0 acceptance helpers (POSIX sh). Source from a test script.
fails=0
check() {
  desc=$1; shift
  if "$@" >/dev/null 2>&1; then printf 'ok - %s\n' "$desc"
  else printf 'not ok - %s\n' "$desc"; fails=$((fails + 1)); fi
}
check_out() {
  desc=$1; want=$2; shift 2
  got=$("$@" 2>/dev/null)
  if [ "$got" = "$want" ]; then printf 'ok - %s\n' "$desc"
  else printf 'not ok - %s (want [%s], got [%s])\n' "$desc" "$want" "$got"; fails=$((fails + 1)); fi
}
finish() { printf '%s failure(s)\n' "$fails"; [ "$fails" -eq 0 ]; }
DC="docker compose -f compose.dev.yml"
