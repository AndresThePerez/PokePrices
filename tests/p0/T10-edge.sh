#!/bin/sh
# P0-i1-T10 edge cases (reviewer): the coverage floors really fail on uncovered source, the
# Vitest group of AG-values rejects config gaming, the shell page stays honest and ASCII-safe,
# and the suite is order-independent (shuffled sequences must all pass).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
NODE="docker compose -f compose.dev.yml exec -T -w /workspace/web node"
W=web
T=.gates/tmp/t10edge
mkdir -p "$T"
cleanup() { rm -f "$W"/src/zz_t10_*.ts "$W"/src/zz_t10_*.tsx; }
trap cleanup EXIT
cleanup
$DC up -d --wait node >/dev/null 2>&1

# --- 1. coverage gate is real ---------------------------------------------------------------------
cat > $W/src/zz_t10_uncovered.ts <<'EOF'
export function uncovered(n: number): number {
  if (n > 1) {
    return n * 2;
  }
  if (n < 0) {
    return -n;
  }
  return n + 1;
}
EOF
cov() { # $1 = tag; extra args pass through to vitest
  tag=$1; shift
  $NODE ./node_modules/.bin/vitest run --config vitest.config.ts --coverage "--coverage.reportsDirectory=/workspace/$T/$tag" "$@" > "$T/$tag.log" 2>&1
  echo $?
}
check_out "an uncovered source file under src/ fails the frozen floors (exit 1)" 1 cov uncov
check "the manifest jq predicate reads the real summary shape and is false for the uncovered file" sh -c "! jq -e '.total.lines.pct >= 80 and .total.branches.pct >= 70' $T/uncov/coverage-summary.json"
check "the failure names the lines floor" /usr/bin/grep -q 'Coverage for lines' "$T/uncov.log"
cleanup
check_out "the clean tree passes the frozen floors (exit 0)" 0 cov clean
check "the manifest jq predicate is true on the clean tree" jq -e '.total.lines.pct >= 80 and .total.branches.pct >= 70' "$T/clean/coverage-summary.json"
check_out "a 100 lines floor passes on the clean tree (the shell is fully covered)" 0 cov full --coverage.thresholds.lines=100 --coverage.thresholds.branches=100

# --- 2. AG-values vitest group rejects config gaming ------------------------------------------------
$NODE node --input-type=module -e "const c = (await import('./vitest.config.ts')).default; console.log(JSON.stringify(c.test ?? {}))" > "$T/live.json" 2>/dev/null
ag() { # $1 = jq filter applied to the live test object; prints the exit status of AG-values
  jq "{vitest_test: (. | $1)}" "$T/live.json" > "$T/in.json"
  GATES_OUT="$T" python3 -I scripts/gates/ag-values.py --only vitest --inputs "$T/in.json" >/dev/null 2>&1
  echo $?
}
check_out "AG-values: the live Vitest config is accepted" 0 ag '.'
check_out "AG-values: passWithNoTests true is rejected" 1 ag '.passWithNoTests=true'
check_out "AG-values: passWithNoTests 1 is rejected" 1 ag '.passWithNoTests=1'
check_out "AG-values: passWithNoTests \"false\" (string) is rejected" 1 ag '.passWithNoTests="false"'
check_out "AG-values: coverage.include narrowed to src/lib/** is rejected" 1 ag '.coverage.include=["src/lib/**"]'
check_out "AG-values: coverage.include widened beyond src/** is rejected" 1 ag '.coverage.include=["src/**","x/**"]'
check_out "AG-values: floors lowered to 79/69 are rejected" 1 ag '.coverage.thresholds.lines=79 | .coverage.thresholds.branches=69'
check_out "AG-values: a string floor \"80\" is rejected" 1 ag '.coverage.thresholds.lines="80"'
check_out "AG-values: a missing thresholds block is rejected" 1 ag 'del(.coverage.thresholds)'
check_out "AG-values: a missing test object is rejected" 1 ag 'null'

# --- 3. order independence (R65: the missing-#root test must not poison the mount test) ---------------
shuffled() { # $1 = seed
  $NODE ./node_modules/.bin/vitest run --config vitest.config.ts --sequence.shuffle "--sequence.seed=$1" > "$T/shuffle-$1.log" 2>&1
  echo $?
}
for seed in 1 2 3 4 5 6 7 8; do
  check_out "shuffled order, seed $seed: every Vitest test passes" 0 shuffled "$seed"
done

# --- 4. shell page honesty and encoding ---------------------------------------------------------------
check "index.html title carries the accent as raw UTF-8 bytes C3 A9" sh -c "grep '<title>' $W/index.html | od -An -tx1 | tr -d ' \n' | grep -q '506f6bc3a9507269636573'"
check "App.tsx h1 carries the accent as raw UTF-8 bytes C3 A9" sh -c "grep '<h1' $W/src/App.tsx | od -An -tx1 | tr -d ' \n' | grep -q '506f6bc3a9507269636573'"
check "no combining acute accent (CC 81) anywhere in the shell sources" sh -c "! od -An -tx1 -v $W/index.html $W/src/App.tsx | tr -d ' \n' | grep -q 'cc81'"
check "App.tsx never says real-time, realtime or live price (any case)" sh -c "! grep -qiE 'real-?time|live price' $W/src/App.tsx $W/index.html"
# T12: Prettier wraps JSX text, so the copy is whitespace-normalised before matching (rendered text is unchanged).
check "App.tsx says daily close and mentions source and as-of" sh -c "tr -s '[:space:]' ' ' < $W/src/App.tsx | grep -qi 'daily close' && grep -qi 'source' $W/src/App.tsx && tr -s '[:space:]' ' ' < $W/src/App.tsx | grep -qi 'as of'"
check "no Vite or React template logos or assets remain under web/src, web/public or index.html" sh -c "[ ! -e $W/src/assets ] && [ ! -e $W/src/App.css ] && [ ! -e $W/public/vite.svg ] && ! grep -qiE 'vite\\.svg|react\\.svg|hero\\.png|Vite \\+ React|count is' $W/index.html $W/src/*.tsx $W/src/*.css"
check "production build still succeeds without the removed assets" sh -c "$NODE npm run build >$T/build.log 2>&1"

# --- 5. plan values ---------------------------------------------------------------------------------
check "vitest.config.ts carries the plan values verbatim" sh -c "grep -qF \"environment: 'jsdom'\" $W/vitest.config.ts && grep -qF \"include: ['src/**/*.test.{ts,tsx}']\" $W/vitest.config.ts && grep -qF 'passWithNoTests: false' $W/vitest.config.ts && grep -qF \"provider: 'v8'\" $W/vitest.config.ts && grep -qF \"include: ['src/**']\" $W/vitest.config.ts && grep -qF \"reporter: ['text', 'json-summary']\" $W/vitest.config.ts && grep -qF 'thresholds: { lines: 80, branches: 70 }' $W/vitest.config.ts"
check "all five T10 packages are exact pins at the plan versions" jq -e '.devDependencies.vitest=="5.0.3" and .devDependencies["@vitest/coverage-v8"]=="5.0.3" and .devDependencies.jsdom=="30.1.2" and .devDependencies["@testing-library/react"]=="16.3.3" and (.devDependencies["@testing-library/dom"]|test("^[0-9]+\\.[0-9]+\\.[0-9]+$"))' $W/package.json
check "lockfile resolves vitest and the coverage provider to the same version" sh -c "[ \"\$(jq -r '.packages[\"node_modules/vitest\"].version' $W/package-lock.json)\" = \"\$(jq -r '.packages[\"node_modules/@vitest/coverage-v8\"].version' $W/package-lock.json)\" ]"
check "no home path, server name or LAN/Tailscale address in the T10 sources" sh -c "! grep -Eq '/hom[e]/|192\\.168\\.|100\\.[0-9]+\\.[0-9]+\\.[0-9]+' $W/vitest.config.ts $W/src/App.tsx $W/src/App.test.tsx $W/index.html $W/package.json"
check "no eslint-disable, ts-ignore, ts-expect-error or any in the web sources" sh -c "! grep -rEq 'eslint-disable|@ts-ignore|@ts-expect-error|as any|: any' $W/src $W/vitest.config.ts"

finish
