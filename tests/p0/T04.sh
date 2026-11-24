#!/bin/sh
# P0-i1-T04: api image with the needed extensions, Laravel 13 scaffolded in a container.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
J() { docker compose -f compose.dev.yml config --format json | jq -e "$1"; }
API="docker compose -f compose.dev.yml exec -T -w /workspace/api api"
check "api runs as container root in dev" J '.services.api.user == "0:0"'
check "api capped at 512m" J '(.services.api.mem_limit|tonumber) == 536870912'
check "api publishes no port" J '(.services.api.ports // []) | length == 0'
check "api builds and is healthy" $DC up -d --wait --build api
check_out "Laravel 13" 1 sh -c "$API php artisan --version | grep -c '^Laravel Framework 13\.'"
for ext in pdo_pgsql redis pcov intl zip pcntl; do
  check "php extension $ext" sh -c "$API php -m | grep -qix $ext"
done
check "pcov is off unless a gate enables it" sh -c "test \"\$($API php -r 'echo ini_get(\"pcov.enabled\");')\" = 0"
check "platform requirements satisfied" $API composer check-platform-reqs --no-interaction
check "lock pins laravel/framework v13" jq -e '[.packages[] | select(.name == "laravel/framework") | .version | startswith("v13.")] == [true]' api/composer.lock
check "config.platform.php equals the container PHP" sh -c "test \"\$(jq -r .config.platform.php api/composer.json)\" = \"\$($API php -r 'echo PHP_VERSION;')\""
check "no front-end build files in api/" sh -c 'test ! -e api/package.json && test ! -e api/vite.config.js'
check "every file under api/ is owned by the host user" sh -c 'test -z "$(find api -not -user "$(id -u)" -print -quit)"'
check ".dockerignore excludes env files" grep -qx '\*\*/\.env' .dockerignore
check "runtime target builds and runs as www-data" sh -c 'docker build -q -f docker/api/Dockerfile --target runtime -t pokeprices-api:t04 . >/dev/null && test "$(docker inspect --format "{{.Config.User}}" pokeprices-api:t04)" = www-data'
check "no agent instruction files from the skeleton in api/" sh -c 'test ! -e api/CLAUDE.md && test ! -e api/AGENTS.md'
finish
