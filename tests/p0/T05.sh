#!/bin/sh
# P0-i1-T05: node tooling service, Vite react-ts scaffold, exact pins, no oxlint.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
J() { docker compose -f compose.dev.yml config --format json | jq -e "$1"; }
NODE="docker compose -f compose.dev.yml exec -T -w /workspace/web node"
check "node service capped at 1g" J '(.services.node.mem_limit|tonumber) == 1073741824'
check "node image digest-pinned" J '.services.node.image | test("@sha256:[0-9a-f]{64}$")'
check "node publishes no port" J '(.services.node.ports // []) | length == 0'
check "node service healthy" $DC up -d --wait node
P=web/package.json
check_out "package name" pokeprices-web jq -r .name $P
check_out "react pinned" 19.3.0 jq -r .dependencies.react $P
check_out "react-dom pinned" 19.3.0 jq -r '.dependencies["react-dom"]' $P
check_out "vite pinned" 8.3.4 jq -r .devDependencies.vite $P
check_out "typescript pinned" 6.0.3 jq -r .devDependencies.typescript $P
check_out "plugin-react pinned" 6.1.2 jq -r '.devDependencies["@vitejs/plugin-react"]' $P
check "every dependency is an exact version" jq -e '[(.dependencies // {})[], (.devDependencies // {})[]] | all(test("^[0-9]"))' $P
check "no oxlint anywhere" sh -c 'test -f web/package.json && ! jq -e "[.dependencies, .devDependencies, .scripts] | tostring | test(\"oxlint\")" web/package.json && test ! -e web/.oxlintrc.json && test ! -e web/_oxlintrc.json'
check_out "engines" '>=22.13 <25' jq -r .engines.node $P
# R47: .nvmrc names the version the pinned node image actually runs.
NODE_V=$($NODE node --version 2>/dev/null | sed 's/^v//')
check "node service reports a version" test -n "$NODE_V"
check_out ".nvmrc matches node --version in the node service" "$NODE_V" cat web/.nvmrc
check "lockfile tracked" git ls-files --error-unmatch web/package-lock.json
check "installed tree matches the lockfile" $NODE npm ls --depth=0
check "type-check and build" $NODE npm run build
check "no high or critical production advisories" $NODE npm audit --omit=dev --audit-level=high
check "every file under web/ is owned by the host user" sh -c 'test -z "$(find web -path web/node_modules -prune -o -not -user "$(id -u)" -print -quit)"'
# R42: scaffolder-generated agent instruction files never stay in the tree.
check "no agent instruction files from the scaffold in web/" sh -c 'test ! -e web/CLAUDE.md && test ! -e web/AGENTS.md'
# The template's unanchored dist/logs ignores would silently untrack source dirs of those names.
check "web/.gitignore keeps source dirs named dist or logs tracked" sh -c '! git check-ignore -q --no-index web/src/dist/x.ts && ! git check-ignore -q --no-index web/src/logs/x.ts && git check-ignore -q --no-index web/dist/x && git check-ignore -q --no-index web/node_modules/x'
finish
