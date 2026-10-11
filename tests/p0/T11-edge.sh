#!/bin/sh
# P0-i1-T11 edge cases (reviewer): the built CSS holds real Tailwind rules for exactly the used
# utilities, the pins and plugin order match the plan, no stray Tailwind/PostCSS config exists,
# the template CSS is gone, and removing the import really drops the utilities.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
NODE="docker compose -f compose.dev.yml exec -T -w /workspace/web node"
W=web
$DC up -d --wait node >/dev/null 2>&1
$NODE npm run build >/dev/null 2>&1
css() { cat $W/dist/assets/*.css; }

# --- 1. built CSS contains compiled rules, not just class-name strings ------------------------------
check "font-semibold is a rule with a font-weight declaration" sh -c "css() { cat $W/dist/assets/*.css; }; css | tr -d '\n' | grep -Eq '\.font-semibold\{[^}]*font-weight:'"
check "max-w-3xl is a rule with a max-width declaration" sh -c "cat $W/dist/assets/*.css | tr -d '\n' | grep -Eq '\.max-w-3xl\{[^}]*max-width:'"
check "text-3xl is a rule with a font-size declaration" sh -c "cat $W/dist/assets/*.css | tr -d '\n' | grep -Eq '\.text-3xl\{[^}]*font-size:'"
check "Tailwind v4.3.3 banner and @layer output are present (preflight/theme compiled)" sh -c "cat $W/dist/assets/*.css | grep -q 'tailwindcss v4.3.3' && cat $W/dist/assets/*.css | grep -q '@layer' && cat $W/dist/assets/*.css | grep -q -e '--tw-'"
check "unused utilities skew-x-12, rotate-45 and bg-red-500 are absent" sh -c "! cat $W/dist/assets/*.css | grep -Eq 'skew-x-12|rotate-45|bg-red-500'"
check "template CSS remnants (.counter, #root width hack) are absent from the built CSS" sh -c "! cat $W/dist/assets/*.css | grep -Eq '\.counter|#docs|#spacer'"

# --- 2. negative: without the import the utilities are not generated ----------------------------------
cat > .gates/tmp/t11edge-compile.mjs <<'JS'
import { compile } from '@tailwindcss/node';
const run = async (src) => (await compile(src, { base: process.cwd(), onDependency() {} })).build(['font-semibold', 'max-w-3xl', 'text-3xl']);
const withImport = await run('@import "tailwindcss";');
const without = await run('/* no import */');
const ok = /font-weight:/.test(withImport) && !/font-semibold/.test(without) && !/max-w-3xl/.test(without) && !/text-3xl/.test(without);
process.exit(ok ? 0 : 1);
JS
cp .gates/tmp/t11edge-compile.mjs $W/zz_t11_compile.mjs
check "compiling without @import \"tailwindcss\" drops the utilities (with it they exist)" $NODE node zz_t11_compile.mjs
rm -f $W/zz_t11_compile.mjs

# --- 3. pins, lock, config files, plugin order ------------------------------------------------------------
check "package.json has exact 4.3.3 for tailwindcss and @tailwindcss/vite (no range operator)" jq -e '.devDependencies.tailwindcss=="4.3.3" and .devDependencies["@tailwindcss/vite"]=="4.3.3"' $W/package.json
check "lockfile resolves tailwindcss, @tailwindcss/vite, @tailwindcss/node to 4.3.3" jq -e '.packages["node_modules/tailwindcss"].version=="4.3.3" and .packages["node_modules/@tailwindcss/vite"].version=="4.3.3" and .packages["node_modules/@tailwindcss/node"].version=="4.3.3"' $W/package-lock.json
check "lockfile root devDependencies mirror package.json for the Tailwind pins" jq -e '.packages[""].devDependencies.tailwindcss=="4.3.3" and .packages[""].devDependencies["@tailwindcss/vite"]=="4.3.3"' $W/package-lock.json
check "vite stays 8.3.4 and typescript 6.0.3 in package.json and lock" jq -e '.devDependencies.vite=="8.3.4" and .devDependencies.typescript=="6.0.3"' $W/package.json
check "lock vite 8.3.4 and typescript 6.0.3" jq -e '.packages["node_modules/vite"].version=="8.3.4" and .packages["node_modules/typescript"].version=="6.0.3"' $W/package-lock.json
check "no postcss or tailwind config file exists under web (outside node_modules, dist)" sh -c "test -z \"\$(find $W -path $W/node_modules -prune -o -path $W/dist -prune -o \( -name 'postcss.config.*' -o -name 'tailwind.config.*' -o -name '.postcssrc*' \) -print)\""
check "vite plugins are exactly react() then tailwindcss()" sh -c "tr -d ' \n' < $W/vite.config.ts | grep -q 'plugins:\[react(),tailwindcss()\]'"
check "index.css is exactly one line" sh -c "[ \"\$(wc -l < $W/src/index.css)\" -eq 1 ] && [ \"\$(wc -c < $W/src/index.css)\" -eq 23 ]"

# --- 4. shell page still honest, template leftovers gone ----------------------------------------------------
check "no :root, .counter, #docs or #spacer rules anywhere under web/src" sh -c "! grep -rEn ':root|\.counter|#docs|#spacer' $W/src"
check "App keeps the three Tailwind utilities it is meant to use" sh -c "grep -q 'font-semibold' $W/src/App.tsx && grep -q 'max-w-3xl' $W/src/App.tsx && grep -q 'text-3xl' $W/src/App.tsx"
check "App.tsx still has no real-time or live-price wording with classNames present" sh -c "! grep -qiE 'real-?time|live price' $W/src/App.tsx"
check "the changed commit touched no home paths or LAN addresses" sh -c "! git show HEAD:$W/vite.config.ts $W/src/index.css 2>/dev/null | grep -Eq '/hom[e]/|192\.168\.|100\.[0-9]+\.[0-9]+\.[0-9]+'"
finish
