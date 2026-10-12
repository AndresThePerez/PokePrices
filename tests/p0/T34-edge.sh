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

# A copy of T34.sh without the make gates run, reading the fixture scorecard and the ledger $PGF
# (the real tests/pending-gates.txt unless a case sets PGF).
PGF=tests/pending-gates.txt
variant_script() { # $1 fixture dir
  sed -e '/^make gates/d' -e "s#^SC=.*#SC=$1/scorecard.json#" -e "s#^PG=.*#PG=$PGF#" \
      -e 's#^cd .*#cd "'"$(pwd)"'" || exit 1#' tests/p0/T34.sh > "$1/T34-copy.sh"
}
# build NAME JQ-ON-SCORECARD JQ-ON-AG1 JQ-ON-DEN [JQ-ON-NESTED-SCORECARD]
build() {
  d=$T/$1; rm -rf "$d"; mkdir -p "$d"
  jq --arg h "$HEADSHA" '.head = $h' "$REAL/scorecard.json" | jq "$2" > "$d/scorecard.json"
  jq -e "$3" "$REAL/AG1.json" > "$d/AG1.json" || exit 1
  jq -e "$4" "$REAL/DEN.json" > "$d/DEN.json" || exit 1
  jq -e "${5:-.}" "$REAL/EC-ci-parity.nested.json" > "$d/EC-ci-parity.nested.json" || exit 1
  jq -e "$2" "$REAL/scorecard.json" >/dev/null || exit 1
  variant_script "$d"
}
out() { sh "$T/$1/T34-copy.sh" 2>&1; }
# expect_flip NAME DESC-OF-CHECK-THAT-MUST-FAIL
expect_flip() {
  case "$(out "$1")" in *"not ok - $2"*) printf 'ok - %s flips "%s"\n' "$1" "$2";;
    *) printf 'not ok - %s does not flip "%s"\n' "$1" "$2"; fails=$((fails + 1));; esac
}
# expect_hold NAME DESC-OF-CHECK-THAT-MUST-PASS
expect_hold() {
  case "$(out "$1")" in *"not ok - $2"*) printf 'not ok - %s flips "%s"\n' "$1" "$2"; fails=$((fails + 1));;
    *"ok - $2"*) printf 'ok - %s holds "%s"\n' "$1" "$2";;
    *) printf 'not ok - %s never ran "%s"\n' "$1" "$2"; fails=$((fails + 1));; esac
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
# The failing hard set must equal the ids tests/pending-gates.txt lists (P1-i1-T07); the doctored
# variants below fail one more gate each, or restore the pre-freeze failure set (AG1 without an
# anchor, DEN and EC-ci-parity through it), or list a gate that passes.
PRIM="the failing hard set matches tests/pending-gates.txt"
NEST="EC-ci-parity's nested run: the in-scope failing hard set matches tests/pending-gates.txt"
build smuggled '(.gates[] | select(.id == "Q1")) |= (.status = "fail")' "$I" "$I"
expect_flip smuggled "$PRIM"
build notrun '(.gates[] | select(.id == "Q1")) |= (.status = "not_run")' "$I" "$I"
expect_flip notrun "$PRIM"
build prefreeze '(.gates[] | select(.id == "AG1" or .id == "DEN" or .id == "EC-ci-parity")) |= (.status = "fail")' "$I" "$I"
expect_flip prefreeze "$PRIM"
build oosprimary '(.gates[] | select(.id == "Q1")) |= (.status = "out_of_scope")' "$I" "$I"
expect_flip oosprimary "$PRIM"
# Ledger fixtures: the real ledger plus Q1, and one malformed line.
{ cat tests/pending-gates.txt; printf 'Q1 P1-i1-T07-edge\n'; } > "$T/pg-plus-q1.txt"
{ cat tests/pending-gates.txt; printf 'Q1\n'; } > "$T/pg-bad.txt"
PGF=$T/pg-plus-q1.txt
build listedpassing "$I" "$I" "$I"
expect_flip listedpassing "$PRIM"
expect_flip listedpassing "$NEST"
build listedfailing '(.gates[] | select(.id == "Q1")) |= (.status = "fail")' "$I" "$I" '(.gates[] | select(.id == "Q1")) |= (.status = "fail")'
expect_hold listedfailing "$PRIM"
expect_hold listedfailing "$NEST"
PGF=$T/pg-bad.txt
build badledger "$I" "$I" "$I"
expect_flip badledger "$PRIM"
expect_flip badledger "$NEST"
PGF=tests/pending-gates.txt
build agvalues '(.gates[] | select(.id == "AG-values")) |= (.status = "fail")' "$I" "$I"
expect_flip agvalues "AG-values clean on every group"
build soft3 '(.gates[] | select(.id == "Q5")) |= (.status = "fail")' "$I" "$I"
expect_flip soft3 "at least 4 of 5 soft gates pass"
build ag1two "$I" '.details += [{"check":"x","reason":"hash_mismatch","violation":true}]' "$I"
expect_flip ag1two "AG1 shows no violations against the anchor"
build ag1value "$I" '.value = 1' "$I"
expect_flip ag1value "AG1 shows no violations against the anchor"
build ag1none "$I" '.value = 1 | .denominator = 0 | .details = [{"check":"ii","reason":"anchor_absent","path":"gates/frozen.sha256","violation":true}]' "$I"
expect_flip ag1none "AG1 shows no violations against the anchor"
build denextra "$I" "$I" '.details += [{"gate":"Q1","rule":"zero_denominator","status":"fail","denominator":0}] | .value = (.details | length)'
expect_flip denextra "DEN shows no failing details"
build denvalue "$I" "$I" '.value = 1'
expect_flip denvalue "DEN shows no failing details"
build denprefreeze "$I" "$I" '.details = [{"gate":"AG1","rule":"zero_denominator","status":"fail","denominator":0}] | .value = 1'
expect_flip denprefreeze "DEN shows no failing details"
build nestedextra "$I" "$I" "$I" '(.gates[] | select(.id == "Q1")) |= (.status = "fail")'
expect_flip nestedextra "$NEST"
build nestedprefreeze "$I" "$I" "$I" '(.gates[] | select(.id == "AG1" or .id == "DEN")) |= (.status = "fail")'
expect_flip nestedprefreeze "$NEST"

# Rector edits must keep QueueProbe's command surface (T18).
QP="$DC exec -T -w /workspace/api api php artisan"
check_out "queue-probe description string unchanged" \
  "Dispatch a probe job and wait until the PokéPrices worker has processed it." \
  sh -c "$QP help pokeprices:queue-probe --format=json | jq -r .description"
check_out "queue-probe --timeout default stays 10" "10" \
  sh -c "$QP help pokeprices:queue-probe --format=json | jq -r .definition.options.timeout.default"
check_out "queue-probe --token default stays empty and accepts a value" "true" \
  sh -c "$QP help pokeprices:queue-probe --format=json | jq -r '.definition.options.token.accept_value'"
check "queue-probe live probe reports 'probe processed' (a 0 timeout clamped to 1 s, one retry with an 8 s timeout)" \
  sh -c "$QP pokeprices:queue-probe --timeout=0 --token=edge-t34 | grep -q 'probe processed' || $QP pokeprices:queue-probe --timeout=8 --token=edge-t34b | grep -q 'probe processed'"
check "queue-probe with a negative timeout returns within 60 s with rc 0 or 1 (no hang; QueueProbeEdgeTest pins rc 1)" \
  sh -c "timeout 60 $QP pokeprices:queue-probe --timeout=-5 --token=edge-t34c >/dev/null; rc=\$?; test \$rc -eq 0 -o \$rc -eq 1"
finish
