#!/bin/sh
# P0-i2-T02 (F-P0-030): api/README.md is a short PokéPrices README, not Laravel's stock one.
# Negative checks go through scripts/lib/nohits, so a grep error (bad pattern, missing file)
# fails the check instead of passing it as "no match".
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
NOHITS=scripts/lib/nohits
F=api/README.md
API="$DC exec -T -w /workspace/api api"
check "tracked: $F" git ls-files --error-unmatch "$F"
check "names PokéPrices with the accent" $GREP -q 'PokéPrices' "$F"
check "no unaccented PokePrices" $NOHITS $GREP -q 'PokePrices' "$F"
check "none of Laravel's stock README text" $NOHITS $GREP -qiE 'About Laravel|Learning Laravel|Laravel Sponsors|laracasts|laravel\.com/docs|logolockup|Code of Conduct|Security Vulnerabilities|Agentic Development|boost:install' "$F"
check "no real-time, live-price or broadcasting claim" $NOHITS $GREP -qiE 'real-time|realtime|real time|live prices|intraday|websocket|broadcast' "$F"
check "names no not-built package" $NOHITS $GREP -qiE 'sanctum|fortify|horizon|octane|reverb|socialite|elasticsearch|timescale|mysql|kafka' "$F"
check "names the readiness route GET /v1/ready" $GREP -qE 'GET /v1/ready([^[:alnum:]/_.-]|$)' "$F"
check "names the liveness route GET /up" $GREP -qE 'GET /up([^[:alnum:]/_.-]|$)' "$F"
check "links the root README (../README.md)" $GREP -qF '../README.md' "$F"
check "short: at most 60 lines" sh -c '[ "$(wc -l < "$0")" -le 60 ]' "$F"
pat=$(sed -e 's/\r$//' -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' gates/hygiene.patterns | sed 's/.*/(?:&)/' | paste -sd'|' -)
lf=$(git rev-parse --git-path info/hygiene.local)
[ -s "$lf" ] && pat="$pat|$(sed -e 's/\r$//' -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' "$lf" | sed 's/.*/(?:&)/' | paste -sd'|' -)"
check "hygiene pattern set is not empty" test -n "$pat"
check "no home path, server name, LAN or Tailscale address (hygiene patterns)" $NOHITS $GREP -qP "$pat" "$F"
artisan_ok() {
  names=$($API php artisan list --format=json | jq -r '.commands[].name') || return 1
  [ -n "$names" ] || return 1
  cmds=$($GREP -oE 'php artisan [^[:space:]`]+' "$F" | awk '{print $3}' | sed 's/[.,;)]*$//' | sort -u)
  [ -n "$cmds" ] || return 1
  for c in $cmds; do
    printf '%s\n' "$names" | $GREP -qxF "$c" || return 1
  done
}
check "every php artisan command the README names exists (and it names at least one)" artisan_ok
finish
