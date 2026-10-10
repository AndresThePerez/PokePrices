#!/bin/sh
# P0-i1-T04 edge cases (reviewer): digest pins, runtime image contents and boot, compose
# hardening, scaffold hygiene, composer lock consistency, .dockerignore semantics.
# Runtime-image checks use `docker build` of a throwaway Dockerfile (RUN executes as the image
# USER); no `docker run` is needed.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
API="docker compose -f compose.dev.yml exec -T -w /workspace/api api"
J() { docker compose -f compose.dev.yml config --format json | jq -e "$1"; }
TAG=pokeprices-api:t04-edge
tmp=$(mktemp -d)
cleanup() { docker rmi "$TAG" >/dev/null 2>&1; for d in api/storage/logs api/storage/framework/cache api/storage/framework/sessions api/storage/framework/views; do rm -f "$d/t04edge-marker.txt"; done; rm -f "$tmp"/ctx/.env "$tmp"/ctx/a/.env "$tmp"/ctx/a/b/.env "$tmp"/ctx/a/b/keep.txt "$tmp"/ctx/.dockerignore "$tmp"/df; rmdir "$tmp"/ctx/a/b "$tmp"/ctx/a "$tmp"/ctx "$tmp" 2>/dev/null; }
trap cleanup EXIT
# probe SCRIPT: build a throwaway layer on the runtime image and run SCRIPT as its USER
probe() { printf 'FROM %s\nRUN %s\n' "$TAG" "$1" | docker build -q --no-cache - >/dev/null; }

$DC up -d --wait api >/dev/null 2>&1
docker build -q -f docker/api/Dockerfile --target runtime -t "$TAG" . >/dev/null 2>&1

# --- 1. digest pins exactly as the plan's table --------------------------------------------
check "every external Dockerfile FROM is pinned by sha256 digest (stage refs excepted)" sh -c '
  awk "toupper(\$1)==\"FROM\" {print \$2}" docker/api/Dockerfile | while read -r ref; do
    case "$ref" in base|composer|extinstaller|vendor) continue ;; esac
    printf "%s\n" "$ref" | /usr/bin/grep -Eq "@sha256:[0-9a-f]{64}\$" || exit 1
  done'
for pin in \
  'php:8.5-fpm-alpine@sha256:fa01fb1645cd0fc566a5f146b099adace33b906571f972f71f2182a7c12d1cd7' \
  'mlocati/php-extension-installer:2@sha256:1afade3e29cfc97362cf5885e5ac333bf2faab1146cb28ebbb59b17e68f87e88' \
  'composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac'; do
  check "Dockerfile pins ${pin%%@*} to the plan digest" sh -c "/usr/bin/grep -q '^FROM $pin AS ' docker/api/Dockerfile"
done
check "no Dockerfile FROM uses :latest" sh -c '! /usr/bin/grep -E "^FROM .*:latest" docker/api/Dockerfile'

# --- 2. compose api service hardening ------------------------------------------------------
check "api container has no host port bindings at runtime" sh -c '
  test "$(docker inspect --format "{{json .HostConfig.PortBindings}}" "$(docker compose -f compose.dev.yml ps -q api)")" = "{}"'
check "api container memory cap enforced by the runtime (402653184)" sh -c '
  test "$(docker inspect --format "{{.HostConfig.Memory}}" "$(docker compose -f compose.dev.yml ps -q api)")" = 402653184'
check "api container is not privileged and adds no capabilities" sh -c '
  id=$(docker compose -f compose.dev.yml ps -q api)
  test "$(docker inspect --format "{{.HostConfig.Privileged}}" "$id")" = false &&
  test "$(docker inspect --format "{{json .HostConfig.CapAdd}}" "$id")" = null'
check "api waits for postgres, redis-queue and redis-cache to be healthy" J '
  [.services.api.depends_on[].condition] == ["service_healthy","service_healthy","service_healthy"] and
  (.services.api.depends_on | keys) == ["postgres","redis-cache","redis-queue"]'
check "api environment carries no secret-shaped variable (APP_KEY, passwords, tokens)" J '
  [.services.api.environment | keys[] | select(test("KEY|SECRET|TOKEN"))] == [] and .services.api.environment.DB_PASSWORD == ""'
check "api APP_NAME keeps the é as UTF-8" sh -c '
  test "$(docker compose -f compose.dev.yml config --format json | jq -j .services.api.environment.APP_NAME | od -An -tx1 | tr -d " \n")" = 506f6bc3a9507269636573'
check "api uses the build, not a floating image tag" J '.services.api.build.target == "dev" and (.services.api.image // "" | test("latest$") | not)'
check "api healthcheck is healthy per docker" sh -c '
  test "$(docker inspect --format "{{.State.Health.Status}}" "$(docker compose -f compose.dev.yml ps -q api)")" = healthy'
check "api healthcheck command fails when nothing listens (grep pong must not pass vacuously)" sh -c '
  ! docker compose -f compose.dev.yml exec -T api sh -c "SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9999 | grep -q pong"'
check "php-fpm answers /ping with pong on 9000" sh -c '
  docker compose -f compose.dev.yml exec -T api sh -c "SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000" | /usr/bin/grep -q pong'
check "files created in the bind mount by the dev container are owned by the host user" sh -c '
  docker compose -f compose.dev.yml exec -T api sh -c "touch /workspace/api/storage/.t04edge" &&
  o=$(stat -c %u api/storage/.t04edge); docker compose -f compose.dev.yml exec -T api rm -f /workspace/api/storage/.t04edge
  test "$o" = "$(id -u)"'

# --- 3. runtime image: non-root, no dev dependencies, boots --------------------------------
check "runtime image USER is www-data, not root" sh -c 'test "$(docker inspect --format "{{.Config.User}}" '"$TAG"')" = www-data'
check "runtime RUN executes as a non-root uid" probe 'test "$(id -u)" != 0'
check "runtime vendor has no dev dependencies (phpunit, mockery, pint, collision, pail, faker)" probe 'cd /workspace/api && for p in phpunit/phpunit mockery/mockery laravel/pint nunomaduro/collision laravel/pail fakerphp/faker; do test ! -e vendor/$p || { echo "dev dep present: $p"; exit 1; }; done'
check "runtime vendor has the framework and an optimized classmap autoloader" probe 'cd /workspace/api && test -f vendor/laravel/framework/composer.json && test -s vendor/composer/autoload_classmap.php'
check "runtime php-fpm configuration test passes as www-data" probe 'php-fpm -t'
check "runtime has the required extensions" probe 'for e in pdo_pgsql redis pcov intl zip pcntl; do php -m | grep -qix $e || exit 1; done'
check "runtime image bakes in no .env or sqlite file" probe 'test -z "$(find /workspace -name .env -o -name "*.sqlite" -o -name "*.sqlite3" | head -1)"'
check "runtime laravel application boots (artisan --version) as www-data" probe 'cd /workspace/api && php artisan --version'
check "runtime storage and bootstrap/cache are writable by www-data" probe 'cd /workspace/api && touch storage/framework/views/.w bootstrap/cache/.w && rm -f storage/framework/views/.w bootstrap/cache/.w'
check "runtime storage/logs and storage/framework/{cache,sessions,views} exist and are writable by www-data" probe 'cd /workspace/api && for d in storage/logs storage/framework/cache storage/framework/sessions storage/framework/views; do test -d $d && test -w $d || { echo "missing or read-only: $d"; exit 1; }; done'
LOGPROBE="cd /workspace/api && test -d storage/logs && php artisan tinker --execute='logger()->channel(\"single\")->info(\"t04-probe\");' && grep -q t04-probe storage/logs/laravel.log"
check "runtime application writes a log line to storage/logs as www-data" probe "$LOGPROBE"
# Host-written runtime state (logs, file cache, sessions, compiled views) must never reach the
# runtime image. Plant a git-ignored marker in each host directory, build, look for it, remove it.
MARK_DIRS="api/storage/logs api/storage/framework/cache api/storage/framework/sessions api/storage/framework/views"
MARK_TAG=pokeprices-api:t04-edge-mark
unmark() { for d in $MARK_DIRS; do rm -f "$d/t04edge-marker.txt"; done; docker rmi "$MARK_TAG" >/dev/null 2>&1; }
check "markers planted in host storage dirs are git-ignored (git status unchanged)" sh -c '
  before=$(git status --porcelain --untracked-files=all)
  for d in '"$MARK_DIRS"'; do echo t04edge > "$d/t04edge-marker.txt" && git check-ignore -q "$d/t04edge-marker.txt" || exit 1; done
  test "$(git status --porcelain --untracked-files=all)" = "$before"'
check "host-written logs, cache, sessions and compiled views never reach the runtime image" sh -c '
  docker build -q -f docker/api/Dockerfile --target runtime -t '"$MARK_TAG"' . >/dev/null &&
  printf "FROM %s\nRUN test -z \"\$(find /workspace/api/storage -name t04edge-marker.txt)\"\n" '"$MARK_TAG"' | docker build -q --no-cache - >/dev/null'
unmark

# --- 4. scaffold hygiene (stays true after later tasks) --------------------------------------
check "no api/.env is tracked" sh -c 'test -z "$(git ls-files -- "api/.env" "api/**/.env" | head -1)"'
check "api/.env is git-ignored" git check-ignore -q api/.env
check "no sqlite database file is tracked or untracked-unignored under api/" sh -c '
  test -z "$( (git ls-files api; git ls-files --others --exclude-standard api) | /usr/bin/grep -Ei "\.sqlite3?$" | head -1)"'
check "api/vendor is not tracked" sh -c 'test -z "$(git ls-files api/vendor | head -1)"'
check "api/.env.example (the template) is tracked" sh -c 'git ls-files --error-unmatch api/.env.example'
check "scaffold left no untracked, unignored file under api/" sh -c 'test -z "$(git ls-files --others --exclude-standard api | head -1)"'
check "no installer artefacts are tracked (compiled cache, phpunit cache, key files)" sh -c '
  test -z "$(git ls-files api | /usr/bin/grep -E "bootstrap/cache/[^.]|\.phpunit\.result\.cache|storage/.*\.key$|auth\.json|\.log$" | head -1)"'
check "api/composer.json config only gained platform.php over the skeleton config keys" sh -c '
  jq -e "(.config | keys | sort) == [\"allow-plugins\",\"optimize-autoloader\",\"platform\",\"preferred-install\",\"sort-packages\"] and (.config.platform | keys) == [\"php\"]" api/composer.json'
check "no front-end build leftovers in api/ (package.json, vite config, resources/css, resources/js)" sh -c '
  test ! -e api/package.json && test ! -e api/vite.config.js && test ! -e api/package-lock.json && test ! -e api/resources/js/app.js && test ! -e api/resources/css/app.css'

# --- 5. composer lock consistency inside the container ---------------------------------------
check "composer validate passes (lock matches composer.json content-hash)" $API composer validate --no-check-publish --no-interaction
check "composer install --dry-run finds nothing to change (lock and vendor agree)" sh -c "$API composer install --dry-run --no-interaction --no-scripts 2>&1 | /usr/bin/grep -q 'Nothing to install, update or remove'"
check "composer.lock platform php constraint does not exceed the container PHP" sh -c "
  test \"\$($API php -r 'echo version_compare(PHP_VERSION, \"8.3.0\", \">=\") ? 1 : 0;')\" = 1"
check "composer.lock has no dev-branch (unpinned) packages" jq -e '[.packages[], .["packages-dev"][] | select(.version | startswith("dev-"))] == []' api/composer.lock

# --- 6. .dockerignore semantics: env files never reach any build context ----------------------
mkdir -p "$tmp/ctx/a/b"
cp .dockerignore "$tmp/ctx/.dockerignore"
: > "$tmp/ctx/.env"; : > "$tmp/ctx/a/.env"; : > "$tmp/ctx/a/b/.env"; echo ok > "$tmp/ctx/a/b/keep.txt"
printf 'FROM php:8.5-fpm-alpine@sha256:fa01fb1645cd0fc566a5f146b099adace33b906571f972f71f2182a7c12d1cd7\nCOPY . /ctx\nRUN test -z "$(find /ctx -name .env)" && test -f /ctx/a/b/keep.txt\n' > "$tmp/df"
check ".dockerignore drops .env at depth 0, 1 and 2 but keeps ordinary files" sh -c "docker build -q --no-cache -f '$tmp/df' '$tmp/ctx' >/dev/null"
check ".dockerignore keeps host dependency and cache dirs out of the build context" sh -c '
  for p in api/vendor api/storage/logs web/node_modules web/dist .git .gates .data .superpowers .mex; do /usr/bin/grep -qxF "$p" .dockerignore || exit 1; done'
finish
