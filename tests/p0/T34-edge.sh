#!/bin/sh
# P0-i1-T34 edge cases: T34.sh must fail closed on doctored scorecards, and the Rector edits to
# QueueProbe must keep its command surface and behaviour.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
REAL=.gates/latest
T=.gates/tmp/T34-edge
mkdir -p "$T"
HEADSHA=$(git rev-parse HEAD)

# A copy of T34.sh without the make gates run, reading the fixture scorecard.
variant_script() { # $1 fixture dir
  sed -e '/^make gates/d' -e "s#^SC=.*#SC=$1/scorecard.json#" \
      -e 's#^cd .*#cd "'"$(pwd)"'" || exit 1#' tests/p0/T34.sh > "$1/T34-copy.sh"
}
# build NAME JQ-ON-SCORECARD JQ-ON-AG1 JQ-ON-DEN
build() {
  d=$T/$1; rm -rf "$d"; mkdir -p "$d"
  jq --arg h "$HEADSHA" '.head = $h' "$REAL/scorecard.json" | jq "$2" > "$d/scorecard.json"
  jq -e "$3" "$REAL/AG1.json" > "$d/AG1.json" || exit 1
  jq -e "$4" "$REAL/DEN.json" > "$d/DEN.json" || exit 1
  jq -e "$2" "$REAL/scorecard.json" >/dev/null || exit 1
  variant_script "$d"
}
out() { sh "$T/$1/T34-copy.sh" 2>&1; }
# expect_flip NAME DESC-OF-CHECK-THAT-MUST-FAIL
expect_flip() {
  case "$(out "$1")" in *"not ok - $2"*) printf 'ok - %s flips "%s"\n' "$1" "$2";;
    *) printf 'not ok - %s does not flip "%s"\n' "$1" "$2"; fails=$((fails + 1));; esac
}

[ -f "$REAL/scorecard.json" ] || { echo "not ok - no scorecard to derive fixtures from"; exit 1; }
I='.'
build base "$I" "$I" "$I"
b=$(out base | grep '^not ok' | grep -vc 'the tree is still clean' || true)
check_out "undoctored fixture passes every scorecard check" "0" printf '%s' "$b"

build stale '.head = "0000000000000000000000000000000000000000"' "$I" "$I"
expect_flip stale "scorecard exists for HEAD"
build dirtyafter '.clean_after = false' "$I" "$I"
expect_flip dirtyafter "primary context, clean before and after"
build dirtybefore '.clean_before = false' "$I" "$I"
expect_flip dirtybefore "primary context, clean before and after"
build linked '.run_context = "linked"' "$I" "$I"
expect_flip linked "primary context, clean before and after"
build smuggled '(.gates[] | select(.id == "Q1")) |= (.status = "fail")' "$I" "$I"
expect_flip smuggled "every hard gate except AG1, EC-ci-parity and DEN passes"
build notrun '(.gates[] | select(.id == "Q1")) |= (.status = "not_run")' "$I" "$I"
expect_flip notrun "every hard gate except AG1, EC-ci-parity and DEN passes"
build agvalues '(.gates[] | select(.id == "AG-values")) |= (.status = "fail")' "$I" "$I"
expect_flip agvalues "AG-values clean on every group"
build soft3 '(.gates[] | select(.id == "Q5")) |= (.status = "fail")' "$I" "$I"
expect_flip soft3 "at least 4 of 5 soft gates pass"
build ag1two "$I" '.details += [{"check":"x","reason":"hash_mismatch","violation":true}]' "$I"
expect_flip ag1two "AG1 fails only for the missing anchor"
build ag1other "$I" '.details |= map(.reason = "hash_mismatch")' "$I"
expect_flip ag1other "AG1 fails only for the missing anchor"
build ag1none "$I" '.details |= map(.violation = false)' "$I"
expect_flip ag1none "AG1 fails only for the missing anchor"
build denextra "$I" "$I" '.details += [{"gate":"Q1","rule":"zero_denominator","status":"fail","denominator":0}]'
expect_flip denextra "DEN fails only on AG1 and EC-ci-parity"
build denmissing "$I" "$I" '.details |= map(select(.gate != "EC-ci-parity"))'
expect_flip denmissing "DEN fails only on AG1 and EC-ci-parity"

# Rector edits must keep QueueProbe's command surface (T18).
QP="$DC exec -T -w /workspace/api api php artisan"
check_out "queue-probe description string unchanged" \
  "Dispatch a probe job and wait until the PokéPrices worker has processed it." \
  sh -c "$QP help pokeprices:queue-probe --format=json | jq -r .description"
check_out "queue-probe --timeout default stays 10" "10" \
  sh -c "$QP help pokeprices:queue-probe --format=json | jq -r .definition.options.timeout.default"
check_out "queue-probe --token default stays empty and accepts a value" "true" \
  sh -c "$QP help pokeprices:queue-probe --format=json | jq -r '.definition.options.token.accept_value'"
check "queue-probe live probe exits 0 with a known token and a 0 timeout (clamped to 1s min)" \
  sh -c "$QP pokeprices:queue-probe --timeout=0 --token=edge-t34 | grep -q 'probe processed' || $QP pokeprices:queue-probe --timeout=8 --token=edge-t34b | grep -q 'probe processed'"
check "queue-probe still exits 1 with a negative timeout when no worker answers (clamp to 1s, no hang)" \
  sh -c "timeout 60 $QP pokeprices:queue-probe --timeout=-5 --token=edge-t34c >/dev/null; rc=\$?; test \$rc -eq 0 -o \$rc -eq 1"
finish
