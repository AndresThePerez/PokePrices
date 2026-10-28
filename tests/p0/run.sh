#!/bin/sh
# PokéPrices suite runner (R135). TIER=full (the default) runs every acceptance, gate, scorecard and
# verdict test, then Pest and Vitest. TIER=fast runs the self-contained scripts in FAST, then a scorecard
# of the quick gates in FAST_GATES; it never runs make gates, test_freeze.sh, a compose stop/restart or
# EC-ci-parity, and scorecard refuses a dirty tree, so commit first. TIER=task runs the scripts named as
# arguments, then the fast tier; with PS=1 it adds the PokéSearch Go checks in ${POKESEARCH_DIR:-../pokesearch}.
set -u
cd "$(dirname "$0")/../.." || exit 1
TIER=${TIER:-full}
PS=${PS:-0}
# The 18 self-contained scripts of review-2 §4; later self-contained P1 scripts are appended in order.
# AG1's live self-checks (test_ag1.sh) are expected red under R153/R155 while frozen files differ; the gate phase that lands the bundle restores the line.
FAST="tests/scorecard/test_scorecard.sh tests/scorecard/test_scorecard_edge.sh
tests/verdict/test_verdict.sh tests/verdict/test_verdict_edge.sh
tests/gates/test_ag1_edge.sh tests/gates/test_ci_docs.sh tests/gates/test_ci_docs_edge.sh
tests/gates/test_gate_libs.sh tests/gates/test_gate_libs_edge.sh tests/gates/test_gate_libs_edge2.sh
tests/gates/test_gate_libs_edge3.sh tests/gates/test_gate_libs_edge4.sh tests/gates/test_gate_libs_edge5.sh
tests/p0/T02.sh tests/p0/T02-edge.sh tests/p0/T19-edge.sh tests/p0/T29b-edge.sh
tests/p1/T07.sh"
# AG1 stays out while tests/pending-gates.txt expects it red; T29 adds it back.
FAST_GATES=Q1,Q3,Q4,Q6,Q7,Q8,T1,T8,T11,C1,C2,C6,S6,S7,D4,AG-values,AG2,AG3,HY-hygiene,HY-trailers,VER-selftest

usage() {
  printf 'usage: [TIER=full|fast] sh tests/p0/run.sh | TIER=task [PS=1] sh tests/p0/run.sh SCRIPT...\n' >&2
  [ -n "${1:-}" ] && printf 'run.sh: %s\n' "$1" >&2
  exit 2
}
case $TIER in
  full|fast) [ "$#" -eq 0 ] || usage "TIER=$TIER takes no script arguments" ;;
  task) [ "$#" -gt 0 ] || usage "TIER=task needs at least one script" ;;
  *) usage "unknown TIER '$TIER'" ;;
esac
case $PS in 0) ;; 1) [ "$TIER" = task ] || usage "PS=1 needs TIER=task" ;; *) usage "PS must be 0 or 1" ;; esac
for t in "$@"; do [ -f "$t" ] || usage "no such script: $t"; done

rc=0
run_scripts() {
  for t in "$@"; do
    [ -f "$t" ] || continue
    printf '# %s\n' "$t"
    sh "$t" || rc=1
  done
}
fast_tier() {
  # shellcheck disable=SC2086 # FAST is a whitespace-separated list of paths without spaces
  for t in $FAST; do [ -f "$t" ] || { printf 'not ok - fast-tier script %s is missing\n' "$t"; rc=1; }; done
  # shellcheck disable=SC2086 # as above
  run_scripts $FAST
  printf '# scorecard --only %s\n' "$FAST_GATES"
  scripts/scorecard --manifest "$(head -n1 gates/current-manifest 2>/dev/null || echo gates/manifest-P0.json)" --only "$FAST_GATES" || rc=1
}

case $TIER in
  full)
    # p2 (HG-40 #12): the read-only scripts that passed the Q1-i4 T07 dry run. tests/p2/T07.sh never joins: it runs
    # replay --fresh on the dev database. T13.sh stays out (a newly pulled movement_volume day turns it red), and so
    # does T01.sh (it pins HEAD to p2-work; no p0/p1 check covers that pin).
    run_scripts tests/p0/T*.sh tests/p1/T*.sh tests/p2/T03.sh tests/p2/T14.sh \
      tests/gates/test_*.sh tests/scorecard/test_*.sh tests/verdict/test_*.sh
    if [ -x api/vendor/bin/pest ] && [ -f api/phpunit.xml ]; then
      docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pest -c phpunit.xml || rc=1
    fi
    if [ -f web/vitest.config.ts ]; then
      docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/vitest run --config vitest.config.ts || rc=1
    fi
    ;;
  fast) fast_tier ;;
  task)
    run_scripts "$@"
    fast_tier
    if [ "$PS" = 1 ]; then
      printf '# PokéSearch Go checks\n'
      (cd "${POKESEARCH_DIR:-../pokesearch}" && go build ./... && go vet ./... &&
        go vet -tags acceptance ./internal/acceptance && go vet -tags relevance ./internal/relevance &&
        go test -race ./... && "$(go env GOPATH)/bin/golangci-lint" run) || rc=1
    fi
    ;;
esac
[ "$rc" -eq 0 ]
