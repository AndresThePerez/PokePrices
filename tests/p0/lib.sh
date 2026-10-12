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
# pending_json [FILE]: the gate ids tests/pending-gates.txt lists ("<GATE-ID> <owner>" lines; blank lines
# and lines starting with # ignored) as a sorted JSON array. Prints nothing and fails on a missing file,
# any other line shape or a repeated id, so a check comparing against its output fails closed.
pending_json() {
  python3 -I -c '
import json, re, sys
ids = []
with open(sys.argv[1], encoding="utf-8") as fh:
    for line in fh:
        s = line.strip()
        if s == "" or s.startswith("#"):
            continue
        m = re.fullmatch(r"([A-Za-z0-9][A-Za-z0-9-]*)[ \t]+([A-Za-z0-9][A-Za-z0-9._/-]*)", s)
        if m is None or m.group(1) in ids:
            sys.exit(1)
        ids.append(m.group(1))
print(json.dumps(sorted(ids), separators=(",", ":")))
' "${1:-tests/pending-gates.txt}" 2>/dev/null
}
