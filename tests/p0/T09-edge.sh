#!/bin/sh
# P0-i1-T09 edge cases (reviewer): the strict TypeScript and ESLint gates must really fire on
# planted violations, inline directives must not be honoured, the AG-values eslint/tsconfig group
# must fail on config-gaming mutations, pins are exact, no oxlint. Node tooling runs in the node service.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
NODE="docker compose -f compose.dev.yml exec -T -w /workspace/web node"
W=web
T=.gates/tmp/t09edge
mkdir -p "$T"
cleanup() {
  rm -f "$W"/src/zz_t09_*.ts "$W"/src/zz_t09_*.tsx "$W"/zz_t09_*.config.js
}
trap cleanup EXIT
cleanup
$DC up -d --wait node >/dev/null 2>&1

# Q6 (manifest): tsc -b --pretty false + awk count of "error TS"
q6() { $NODE ./node_modules/.bin/tsc -b --pretty false > "$T/q6.txt" 2>&1; awk '/error TS[0-9]+/{n++} END{print n+0}' "$T/q6.txt"; }
# Q7 (manifest): eslint json + jq predicate; prints "pass" only when the predicate holds
q7() {
  $NODE ./node_modules/.bin/eslint -c eslint.config.js --max-warnings=0 -f json -o "/workspace/$T/q7.json" . >/dev/null 2>&1
  jq -e '([.[] | .errorCount + .warningCount + .fatalErrorCount] | add // 0) == 0 and length >= 1' "$T/q7.json" >/dev/null 2>&1 && echo pass || echo fail
}
rule_hit() { jq -r '[.[].messages[] | .ruleId] | map(select(. != null)) | .[]' "$T/q7.json" | sort -u | tr '\n' ' '; }

check_out "baseline: Q6 reports zero type errors on the clean tree" 0 q6
check_out "baseline: Q7 passes on the clean tree" pass q7

# --- 1. planted violations ----------------------------------------------------------------------
cat > $W/src/zz_t09_index.ts <<'EOS'
const xs: number[] = [1, 2, 3];
export const first: number = xs[0];
EOS
check_out "noUncheckedIndexedAccess: unchecked array index is a TS error (Q6 count 1)" 1 q6
cleanup
cat > $W/src/zz_t09_exact.ts <<'EOS'
interface Opt { a?: number }
export const o: Opt = { a: undefined };
EOS
check_out "exactOptionalPropertyTypes: optional prop set to undefined is a TS error (Q6 count 1)" 1 q6
cleanup
cat > $W/src/zz_t09_any.ts <<'EOS'
export const a = JSON.parse('1') as any;
export const b: number = a;
EOS
check_out "any usage: Q7 fails" fail q7
check "any usage: no-explicit-any is reported" sh -c "jq -e '[.[].messages[].ruleId] | index(\"@typescript-eslint/no-explicit-any\") != null' $T/q7.json"
cleanup
cat > $W/src/zz_t09_float.ts <<'EOS'
async function f(): Promise<void> { await Promise.resolve(); }
export function g(): void { f(); }
EOS
check_out "floating promise: Q7 fails" fail q7
check "floating promise: no-floating-promises is reported" sh -c "jq -e '[.[].messages[].ruleId] | index(\"@typescript-eslint/no-floating-promises\") != null' $T/q7.json"
cleanup
cat > $W/src/zz_t09_nonnull.ts <<'EOS'
export function h(x: string | null): number { return x!.length; }
EOS
check_out "non-null assertion: Q7 fails" fail q7
check "non-null assertion: no-non-null-assertion is reported" sh -c "jq -e '[.[].messages[].ruleId] | index(\"@typescript-eslint/no-non-null-assertion\") != null' $T/q7.json"
cleanup
cat > $W/src/zz_t09_inline.ts <<'EOS'
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export const z: any = 1;
EOS
check_out "inline eslint-disable does not make Q7 pass (noInlineConfig)" fail q7
check "inline eslint-disable: no-explicit-any still reported, directive not honoured" sh -c "jq -e '[.[].messages[].ruleId] | index(\"@typescript-eslint/no-explicit-any\") != null' $T/q7.json"
check "inline eslint-disable: ESLint itself says the directive comment is ignored" sh -c "jq -e '[.[].messages[].message] | any(test(\"inline|directive|disable\"; \"i\"))' $T/q7.json"
cleanup
check_out "tree is clean again after the planted files are removed (Q6 zero)" 0 q6

# --- 2. AG-values gaming mutations on a temp copy of the config --------------------------------
EVAL='const flat=(x)=>Array.isArray(x)?x.flatMap(flat):[x]; const entries=flat(await (await import("./MUT")).default); const {ESLint}=await import("eslint"); const eff=await new ESLint({overrideConfigFile:"MUT"}).calculateConfigForFile("src/App.tsx"); console.log(JSON.stringify({config_names:entries.map((c)=>(c&&typeof c.name==="string")?c.name:null),linterOptions:(eff&&eff.linterOptions)??null}))'
# gate_with <name> <sed-expr>: build a mutated config, collect the eslint inputs the way the gate does, run AG-values on them
gate_with() {
  name=$1; expr=$2; m=zz_t09_$name.config.js
  sed "$expr" $W/eslint.config.js > $W/$m
  ev=$($NODE node --input-type=module -e "$(printf '%s' "$EVAL" | sed "s/MUT/.\\/$m/; s/MUT/$m/")" 2>"$T/$name.ev.err") || ev=
  rules=$($NODE ./node_modules/.bin/eslint -c $m --print-config src/App.tsx 2>"$T/$name.rules.err" | jq -c .rules 2>/dev/null) || rules=
  [ -n "$ev" ] || ev=null; [ -n "$rules" ] || rules=null
  printf '{"eslint_evaluated":%s,"eslint_rules":%s}\n' "$ev" "$rules" > "$T/$name.inputs.json"
  mkdir -p "$T/out-$name"
  GATES_OUT="$T/out-$name" python3 -I scripts/gates/ag-values.py --only eslint --inputs "$T/$name.inputs.json" > "$T/$name.gate.txt" 2>&1
  echo $?
}
gate_failed() { jq -r '[.details[] | select(.ok|not) | .check] | join(",")' "$T/out-$1/AG-values.json"; }

check_out "control: unmutated copy passes AG-values eslint group" 0 gate_with control 's/^$//'
check_out "gaming: reportUnusedDisableDirectives 'warn' fails AG-values" 1 gate_with unusedwarn "s/reportUnusedDisableDirectives: 'error'/reportUnusedDisableDirectives: 'warn'/"
check_out "  ... failing check is eslint.reportUnusedDisableDirectives" eslint.reportUnusedDisableDirectives gate_failed unusedwarn
check_out "gaming: noInlineConfig removed fails AG-values" 1 gate_with noinline "s/noInlineConfig: true, //"
check_out "  ... failing check is eslint.noInlineConfig" eslint.noInlineConfig gate_failed noinline
check_out "gaming: noInlineConfig false fails AG-values" 1 gate_with noinlinefalse "s/noInlineConfig: true/noInlineConfig: false/"
check_out "gaming: no-floating-promises off in rules fails AG-values" 1 gate_with ruleoff "s#'react-refresh/only-export-components'#'@typescript-eslint/no-floating-promises': 'off', 'react-refresh/only-export-components'#"
check_out "  ... failing check is eslint.strict_rules" eslint.strict_rules gate_failed ruleoff
check_out "gaming: no-non-null-assertion downgraded to warn fails AG-values" 1 gate_with rulewarn "s#'react-refresh/only-export-components'#'@typescript-eslint/no-non-null-assertion': 'warn', 'react-refresh/only-export-components'#"
check_out "gaming: strictTypeChecked swapped for recommendedTypeChecked fails AG-values" 1 gate_with recommended 's/tseslint.configs.strictTypeChecked/tseslint.configs.recommendedTypeChecked/'
check_out "gaming: ignores src/** fails AG-values" 1 gate_with ignoresrc "s#ignores: \['dist'#ignores: ['src/**', 'dist'#"

# --- 3. tsconfig ---------------------------------------------------------------------------------
for f in app node; do
  check "tsconfig.$f.json writes the three flags as true (JSONC, text check)" sh -c "[ \$(/usr/bin/grep -cE '^ *\"(strict|noUncheckedIndexedAccess|exactOptionalPropertyTypes)\": true,' $W/tsconfig.$f.json) = 3 ]"
  check "tsc --showConfig tsconfig.$f.json prints the three flags true" sh -c "$NODE ./node_modules/.bin/tsc -p tsconfig.$f.json --showConfig | jq -e '.compilerOptions | (.strict==true and .noUncheckedIndexedAccess==true and .exactOptionalPropertyTypes==true)'"
done
check "tsconfig.node include is vite.config.ts and vitest.config.ts" /usr/bin/grep -qF '"include": ["vite.config.ts", "vitest.config.ts"]' $W/tsconfig.node.json

# --- 4. pins -------------------------------------------------------------------------------------
P=$W/package.json; L=$W/package-lock.json
check_out "six ESLint packages exact at plan versions" '@eslint/js=10.0.1 eslint=10.12.0 eslint-plugin-react-hooks=7.1.1 eslint-plugin-react-refresh=0.5.7 globals=17.13.0 typescript-eslint=8.71.1' sh -c "jq -r '.devDependencies | [\"@eslint/js\",\"eslint\",\"eslint-plugin-react-hooks\",\"eslint-plugin-react-refresh\",\"globals\",\"typescript-eslint\"] as \$k | [\$k[] as \$n | \"\(\$n)=\(.[\$n])\"] | join(\" \")' $P"
check "lockfile resolves the six ESLint packages to the same exact versions" jq -e '.packages as $p | ($p["node_modules/eslint"].version=="10.12.0") and ($p["node_modules/@eslint/js"].version=="10.0.1") and ($p["node_modules/typescript-eslint"].version=="8.71.1") and ($p["node_modules/eslint-plugin-react-hooks"].version=="7.1.1") and ($p["node_modules/eslint-plugin-react-refresh"].version=="0.5.7") and ($p["node_modules/globals"].version=="17.13.0")' $L
check "no range operator on any devDependency or dependency" jq -e '[.dependencies[], .devDependencies[]] | all(test("^[~^<>=*]")|not)' $P
check "typescript still 6.0.3 in manifest and lock" jq -e '.devDependencies.typescript=="6.0.3"' $P
check "no oxlint in package.json or in the lock root dependencies" sh -c "! /usr/bin/grep -qi oxlint $P && jq -e '[.packages[\"\"].dependencies, .packages[\"\"].devDependencies] | tostring | test(\"oxlint\"; \"i\") | not' $L"

# --- 5. source changes ---------------------------------------------------------------------------
check "main.tsx has the explicit null check and the exact PokéPrices message" sh -c "/usr/bin/grep -qF \"if (rootElement === null) {\" $W/src/main.tsx && /usr/bin/grep -qF \"throw new Error('PokéPrices: #root element missing from index.html');\" $W/src/main.tsx"
check "main.tsx has no non-null assertion left" sh -c "! /usr/bin/grep -q \"getElementById('root')!\" $W/src/main.tsx"
check "no eslint-disable, ts-ignore, ts-expect-error or any in shipped web sources" sh -c "! /usr/bin/grep -rEq 'eslint-disable|@ts-ignore|@ts-expect-error|as any|: any' $W/src $W/eslint.config.js"
check "eslint config ignores exactly dist, coverage, node_modules (src never ignored)" sh -c "/usr/bin/grep -qF \"ignores: ['dist', 'coverage', 'node_modules']\" $W/eslint.config.js"
check "no home path, server name or LAN/Tailscale address in the eslint config or tsconfigs" sh -c "! /usr/bin/grep -Eq '/hom[e]/|192\\.168\\.|100\\.[0-9]+\\.[0-9]+\\.[0-9]+' $W/eslint.config.js $W/tsconfig.app.json $W/tsconfig.node.json $W/src/main.tsx"

finish
