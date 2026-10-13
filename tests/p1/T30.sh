#!/bin/sh
# T30: ADR 12 draft for Andres (docs only). PokéSearch docs/DECISIONS.md must stay untouched (R133).
set -eu
cd "$(dirname "$0")/../.."

f=docs/adr/drafts/pokesearch/0012-versioned-card-api.md
d=${POKESEARCH_DIR:-../pokesearch}
fail=0
ok() { printf 'ok   %s\n' "$1"; }
no() { printf 'FAIL %s\n' "$1"; fail=1; }

if test -s "$f"; then ok "draft exists and is non-empty"; else no "draft exists and is non-empty"; fi

if test -s "$f"; then
  n=$(/usr/bin/grep -cE '^## (Part [123]: |ADR 7 addendum|Pointer lines)' "$f" || true)
  if test "$n" -eq 5; then ok "five ADR headings (EC-P1-13 regex)"; else no "five ADR headings (EC-P1-13 regex), got $n"; fi

  all=$(/usr/bin/grep -c '^## ' "$f" || true)
  if test "$all" -eq 5; then ok "exactly five ## headings in total"; else no "exactly five ## headings in total, got $all"; fi

  if /usr/bin/grep -qx '# ADR 12 — A versioned card API for a second independent client' "$f"; then ok "title"; else no "title"; fi

  for s in '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11' '20,635' 'not_found' 'unauthorized' 'Courier'; do
    if /usr/bin/grep -qF -- "$s" "$f"; then ok "contains $s"; else no "contains $s"; fi
  done
fi

if git -C "$d" diff --quiet 8c63e222950cd8eacf1ba80b42986e1e4d9f387b -- docs/DECISIONS.md; then
  ok "PokéSearch docs/DECISIONS.md unchanged since 8c63e22"
else
  no "PokéSearch docs/DECISIONS.md unchanged since 8c63e22"
fi

exit "$fail"
