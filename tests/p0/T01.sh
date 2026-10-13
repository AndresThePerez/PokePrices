#!/bin/sh
# P0-i1-T01: repository initialised, ignores in place, guard installed, compose file valid.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
APC=$(command -v agent-precommit || printf '%s' "$HOME/.local/bin/agent-precommit")
check "inside a git work tree" git rev-parse --is-inside-work-tree
check "current branch is main or a p<N>-work branch" sh -c 'b=$(git symbolic-ref --short HEAD) || exit 1; test "$b" = main || printf "%s\n" "$b" | /usr/bin/grep -q -E "^p[0-9]+-work$"'
check_out "origin is the GitHub URL" git@github.com:AndresThePerez/PokePrices.git git remote get-url origin
check_out "exactly one remote" 1 sh -c 'git remote | wc -l | tr -d " "'
check_out "origin is the only remote" origin git remote
check "origin/main exists and is an ancestor of HEAD" sh -c 'git rev-parse -q --verify refs/remotes/origin/main && git merge-base --is-ancestor refs/remotes/origin/main HEAD'
check "at least one commit" sh -c 'test "$(git rev-list --count HEAD)" -ge 1'
for p in .superpowers/x .data/x .gates/x api/.env api/vendor/x web/node_modules/x web/dist/x .env .env.local api/.env.testing; do
  check "ignored: $p" git check-ignore -q --no-index "$p"
done
check "api/.env.example is not ignored" sh -c '! git check-ignore -q --no-index api/.env.example'
check ".mex/ listed in .git/info/exclude" grep -qx '.mex/' .git/info/exclude
check "/CLAUDE.md listed in .git/info/exclude" grep -qx '/CLAUDE.md' .git/info/exclude
check ".mex is ignored" git check-ignore -q --no-index .mex/ROUTER.md
check "nothing under .superpowers is tracked" sh -c 'test -z "$(git ls-files .superpowers)"'
check "compose file parses" docker compose -f compose.dev.yml config -q
check_out "compose project name" pokeprices sh -c 'docker compose -f compose.dev.yml config --format json | jq -r .name'
check "postgres image digest-pinned" sh -c 'docker compose -f compose.dev.yml config --format json | jq -e ".services.postgres.image | test(\"@sha256:[0-9a-f]{64}$\")"'
check "postgres publishes no port" sh -c 'docker compose -f compose.dev.yml config --format json | jq -e "(.services.postgres.ports // []) | length == 0"'
check "postgres capped at 1g with 256m shm" sh -c 'docker compose -f compose.dev.yml config --format json | jq -e "(.services.postgres.mem_limit|tonumber) == 1073741824 and (.services.postgres.shm_size|tonumber) == 268435456"'
check "pre-commit guard installed" sh -c '"$0" status . | grep -q "hook: *installed"' "$APC"
check "guard has no test command" sh -c '"$0" status . | grep -q "testcmd: *(unset)"' "$APC"
check "HEAD message has no trailer" sh -c 'test -z "$(git log -1 --format=%B | git interpret-trailers --parse)"'
finish
