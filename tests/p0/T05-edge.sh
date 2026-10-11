#!/bin/sh
# P0-i1-T05 edge cases (reviewer): exact pins vs the plan table, lockfile consistency and
# provenance, reproducible npm ci, build hygiene (clean tree, type errors fail the build),
# node service hardening, scaffold leftovers. All npm commands run inside the node service.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
NODE="docker compose -f compose.dev.yml exec -T -w /workspace/web node"
J() { docker compose -f compose.dev.yml config --format json | jq -e "$1"; }
BAD=web/src/zz_t05_edge_bad.ts
cleanup() { rm -f "$BAD"; }
trap cleanup EXIT
P=web/package.json
L=web/package-lock.json

$DC up -d --wait node >/dev/null 2>&1

# --- 1. every package.json pin equals the plan table (and nothing extra) -------------------------
check "dependencies are exactly react and react-dom" jq -e '(.dependencies|keys) == ["react","react-dom"]' $P
# T09 added the six ESLint packages (exact pins checked in T09), T10 the five Vitest packages, T11 the two Tailwind packages, T12 Prettier, T21 the Spectral CLI; a later task that adds packages edits this list.
check "devDependencies are exactly the plan's six T05 packages, the six T09 ESLint packages, the five T10 Vitest packages, the two T11 Tailwind packages, the T12 Prettier package and the T21 Spectral CLI" jq -e '(.devDependencies|keys) == ["@eslint/js","@stoplight/spectral-cli","@tailwindcss/vite","@testing-library/dom","@testing-library/react","@types/node","@types/react","@types/react-dom","@vitejs/plugin-react","@vitest/coverage-v8","eslint","eslint-plugin-react-hooks","eslint-plugin-react-refresh","globals","jsdom","prettier","tailwindcss","typescript","typescript-eslint","vite","vitest"]' $P
check_out "T12 Prettier carries the plan's exact pin" 3.9.10 jq -r .devDependencies.prettier $P
check_out "T21 Spectral CLI carries the plan's exact pin" 6.17.0 jq -r '.devDependencies["@stoplight/spectral-cli"]' $P
check "T10 Vitest packages carry the plan's exact pins" jq -e '.devDependencies.vitest=="5.0.3" and .devDependencies["@vitest/coverage-v8"]=="5.0.3" and .devDependencies.jsdom=="30.1.2" and .devDependencies["@testing-library/react"]=="16.3.3"' $P
check_out "react-dom equals react" 19.3.0 jq -r '.dependencies["react-dom"]' $P
check "@types/node stays on the node 24 line" jq -e '.devDependencies["@types/node"] | test("^24\\.[0-9]+\\.[0-9]+$")' $P
check "every pin is strictly MAJOR.MINOR.PATCH (no range, tag, prerelease or url)" jq -e '[(.dependencies // {})[], (.devDependencies // {})[]] | all(test("^[0-9]+\\.[0-9]+\\.[0-9]+$"))' $P
check "no peer, optional or override dependency blocks sneak in" jq -e '[has("peerDependencies"), has("optionalDependencies"), has("overrides"), has("resolutions")] | any | not' $P
check "scripts are exactly dev, build, preview, typecheck, lint, test, format:check (T09 added typecheck and lint, T10 test, T12 format:check)" jq -e '(.scripts|keys) == ["build","dev","format:check","lint","preview","test","typecheck"]' $P
check_out "format:check script runs Prettier with explicit config and ignore path" 'prettier --config .prettierrc.json --ignore-path .prettierignore --check .' jq -r '.scripts["format:check"]' $P
check_out "test script runs Vitest with the frozen config" 'vitest run --config vitest.config.ts' jq -r .scripts.test $P
check_out "typecheck script is tsc -b" 'tsc -b' jq -r .scripts.typecheck $P
check_out "lint script runs ESLint with zero warnings allowed" 'eslint -c eslint.config.js --max-warnings=0 .' jq -r .scripts.lint $P
check "no install lifecycle hooks in package.json" jq -e '[.scripts|keys[]|select(test("^(pre|post)?(install|prepare|publish)$"))]|length==0' $P
check_out "build script type-checks before bundling" 'tsc -b && vite build' jq -r .scripts.build $P

# --- 2. lockfile agrees with the manifest, has provenance and no oxlint -------------------------
check "lockfile root entry deps equal package.json deps" sh -c "jq -e --slurpfile p $P '.packages[\"\"] as \$r | (\$r.dependencies == \$p[0].dependencies) and (\$r.devDependencies == \$p[0].devDependencies) and (\$r.engines == \$p[0].engines)' $L"
check "lockfile installs the exact pinned versions" sh -c "jq -e '.packages[\"node_modules/react\"].version==\"19.3.0\" and .packages[\"node_modules/vite\"].version==\"8.3.4\" and .packages[\"node_modules/typescript\"].version==\"6.0.3\" and .packages[\"node_modules/@vitejs/plugin-react\"].version==\"6.1.2\"' $L"
check "no oxlint package anywhere in the lockfile (any depth, including @oxlint/*)" sh -c "! /usr/bin/grep -qi oxlint $L"
check "every locked package has an integrity hash" jq -e '[.packages | to_entries[] | select(.key != "")] as $all | ($all | length) > 0 and ([$all[] | select((.value.integrity // "") | startswith("sha512-"))] | length) == ($all | length)' $L
check "every resolved URL is https on the public npm registry" jq -e '[.packages[] | .resolved // empty] | length > 0 and all(startswith("https://registry.npmjs.org/"))' $L
check "lockfile and manifest carry no home path or private registry" sh -c "! /usr/bin/grep -Eq '/hom[e]/|192\\.168\\.|100\\.[0-9]+\\.[0-9]+\\.[0-9]+|localhost|verdaccio' $L $P"
check "no caret or tilde range in the lockfile root entry" jq -e '[.packages[""].dependencies[], .packages[""].devDependencies[]] | all(test("^[~^]")|not)' $L
check_out "lockfileVersion is 3" 3 jq -r .lockfileVersion $L

# --- 3. node service hardening -------------------------------------------------------------------
check "node service has no published port" J '(.services.node.ports // []) | length == 0'
check "node service is not privileged and not host-networked" J '((.services.node.privileged // false) == false) and ((.services.node.network_mode // "") != "host")'
check "node memory cap is really applied to the running container (cgroup via inspect)" sh -c '
  id=$(docker compose -f compose.dev.yml ps -q node); test -n "$id" &&
  test "$(docker inspect -f "{{.HostConfig.Memory}}" "$id")" = 1073741824'
check "node container publishes nothing on the host" sh -c '
  id=$(docker compose -f compose.dev.yml ps -q node); test -n "$id" &&
  test "$(docker inspect -f "{{len .NetworkSettings.Ports}}" "$id")" = 0 || test -z "$(docker port "$id" 2>/dev/null)"'
check "node service mounts only the repo at /workspace" J '(.services.node.volumes|length==1) and (.services.node.volumes[0].target=="/workspace")'
check "node image is the plan's digest" J '.services.node.image == "node:22-slim@sha256:c3de60bf2f9dd0ac6370e6117950ff62d6e339527e7472301c9c78a017978392"'
check "no compose service other than node runs a node:* image (single tooling image)" sh -c '
  docker compose -f compose.dev.yml config --format json | jq -e "[.services|to_entries[]|select(.value.image // \"\"|test(\"^node:\"))|.key]==[\"node\"]"'
check "node binary inside the service satisfies engines (>=22.13 <25)" $NODE node -e 'const [a,b]=process.versions.node.split(".").map(Number);process.exit(a===22&&b>=13||a===23||a===24?0:1)'
check ".nvmrc is one bare MAJOR.MINOR.PATCH line" sh -c 'test "$(wc -l < web/.nvmrc)" = 1 && /usr/bin/grep -Eq "^[0-9]+\.[0-9]+\.[0-9]+$" web/.nvmrc'

# --- 4. npm ci reproduces the tree from the lockfile ---------------------------------------------
check "npm ci --dry-run succeeds (lockfile and manifest agree)" $NODE npm ci --dry-run --ignore-scripts
before=$($NODE npm ls --all --parseable 2>/dev/null | sed 's#.*/node_modules/##' | sort | md5sum)
check "real npm ci succeeds from the lockfile" $NODE npm ci --ignore-scripts --no-audit --no-fund
after=$($NODE npm ls --all --parseable 2>/dev/null | sed 's#.*/node_modules/##' | sort | md5sum)
check "npm ci reproduces the same package set as before" test "$before" = "$after"
check "npm ls --all reports no problems after npm ci" $NODE npm ls --all
check "npm ci leaves package-lock.json byte-identical to HEAD" git diff --quiet HEAD -- web/package-lock.json web/package.json
check "installed react/vite/typescript versions equal the pins" sh -c '
  test "$(jq -r .version web/node_modules/react/package.json)" = 19.3.0 &&
  test "$(jq -r .version web/node_modules/vite/package.json)" = 8.3.4 &&
  test "$(jq -r .version web/node_modules/typescript/package.json)" = 6.0.3'
check "oxlint is not installed in node_modules" sh -c 'test ! -e web/node_modules/oxlint && test ! -e web/node_modules/.bin/oxlint && test -z "$(ls -d web/node_modules/@oxlint* 2>/dev/null)"'

# --- 5. S2: production advisories ----------------------------------------------------------------
check "npm audit --omit=dev --audit-level=high passes (S2)" $NODE npm audit --omit=dev --audit-level=high
check "npm audit reports zero production vulnerabilities (json)" sh -c "$NODE npm audit --omit=dev --json | jq -e '.metadata.vulnerabilities.total == 0'"

# --- 6. build hygiene ----------------------------------------------------------------------------
check "npm run build produces web/dist/index.html and an assets directory" sh -c "$NODE npm run build && test -s web/dist/index.html && ls web/dist/assets/*.js >/dev/null"
check "build leaves the git tree clean (nothing tracked, nothing untracked)" test -z "$(git status --porcelain --untracked-files=all -- . ':!tests/p0/T05-edge.sh')"
check "build output and node_modules are ignored by git" sh -c 'git check-ignore -q web/dist/index.html && git check-ignore -q web/node_modules/react/package.json'
check "dist is not tracked" test -z "$(git ls-files web/dist web/node_modules)"
printf 'export const zzT05: number = "not a number";\n' > "$BAD"
check "a type error makes npm run build fail (tsc -b really gates the build)" sh -c "! $NODE npm run build"
rm -f "$BAD"
check "build succeeds again once the type error is gone" $NODE npm run build

# --- 7. scaffold leftovers -----------------------------------------------------------------------
check "no oxlint config, template README or agent files tracked under web/" sh -c '
  ! git ls-files web | /usr/bin/grep -Eiq "(^|/)(\.?_?oxlintrc(\.json)?|README\.md|CLAUDE\.md|AGENTS\.md)$"'
check "no stray files at the repo root from the scaffolder" sh -c 'test ! -e package.json && test ! -e package-lock.json && test ! -e node_modules && test ! -e .oxlintrc.json'
check "no tracked web file mentions oxlint (text files)" sh -c '! git ls-files web | /usr/bin/grep -v -e package-lock.json -e hero.png | xargs /usr/bin/grep -il oxlint'
check "no .env file tracked or present under web/" sh -c 'test -z "$(git ls-files web | /usr/bin/grep -E "(^|/)\.env")" && test -z "$(find web -path web/node_modules -prune -o -name ".env*" -print)"'
check "web/.gitignore does not ignore the lockfile or manifest" sh -c '! git check-ignore -q --no-index web/package-lock.json && ! git check-ignore -q --no-index web/package.json && ! git check-ignore -q --no-index web/.nvmrc'
check "web/.gitignore still ignores logs/dist at web root and *.local" sh -c 'git check-ignore -q --no-index web/logs/a.log && git check-ignore -q --no-index web/dist-ssr/x && git check-ignore -q --no-index web/foo.local'
check "tracked source files under web/src are not world-writable in git (mode 100644/100755 only)" sh -c '! git ls-files -s web | /usr/bin/grep -Ev "^100(644|755) "'
check "index.html loads the app entry and has no external script origin" sh -c '/usr/bin/grep -q "/src/main.tsx" web/index.html && ! /usr/bin/grep -Eq "<script[^>]+src=\"https?://" web/index.html'
check "web/.dockerignore-equivalent: root .dockerignore excludes web/node_modules and web/dist" sh -c '/usr/bin/grep -qx "web/node_modules" .dockerignore && /usr/bin/grep -qx "web/dist" .dockerignore'
finish
