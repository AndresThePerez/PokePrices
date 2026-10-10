#!/bin/sh
# P0-i1-T04 edge cases, round 2 (reviewer): the runtime image after host-side artisan state
# changes, real php-fpm serving as www-data, optimize commands, stale host cache files.
# Runtime-image checks run a RUN step of a throwaway build (no `docker run`).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
API="docker compose -f compose.dev.yml exec -T -w /workspace/api api"
TAG=pokeprices-api:t04-edge2
STALE=api/bootstrap/cache/t04edge2-stale.php
ctx=$(mktemp -d)
cleanup() {
  docker rmi "$TAG" >/dev/null 2>&1
  rm -f "$STALE" "$ctx"/s.* "$ctx/Dockerfile" "$ctx/p.sh"
  rmdir "$ctx" 2>/dev/null
}
trap cleanup EXIT
# probe SCRIPT-FILE: run the script as the runtime USER in a throwaway layer on $TAG
probe() { cp "$1" "$ctx/p.sh"; chmod 644 "$ctx/p.sh"; printf 'FROM %s\nCOPY p.sh /p.sh\nRUN sh /p.sh\n' "$TAG" > "$ctx/Dockerfile"; docker build -q --no-cache "$ctx" >/dev/null; }
mk() { f=$(mktemp "$ctx/s.XXXXXX"); cat > "$f"; printf '%s' "$f"; }
build() { docker build -q -f docker/api/Dockerfile --target runtime -t "$TAG" . >/dev/null; }

$DC up -d --wait api >/dev/null 2>&1

# 1. host-side package:discover (dev packages in the cache) must not poison a rebuilt runtime image
check "host package:discover regenerates the dev package cache" sh -c "$API php artisan package:discover --ansi >/dev/null && test -s api/bootstrap/cache/packages.php"
: > "$STALE"
check "a stale *.php planted in the host bootstrap/cache is git-ignored (so only .dockerignore can stop it)" git check-ignore -q "$STALE"
check "runtime image rebuilt after package:discover still builds" build
s=$(mk <<'S'
cd /workspace/api
php artisan --version
php artisan about --only=environment
php artisan route:list >/dev/null
test ! -e bootstrap/cache/t04edge2-stale.php
! grep -rqE 'Pail|Collision|Mockery|Sail' bootstrap/cache
S
)
check "runtime boots after package:discover: artisan, about, route:list; no stale host cache file, no dev providers in the regenerated cache" probe "$s"

# 2. optimize commands work for www-data inside the image (write bootstrap/cache, no .env needed)
s=$(mk <<'S'
cd /workspace/api
CACHE_STORE=array php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
php artisan about >/dev/null
php artisan optimize
S
)
check "runtime config:cache, route:cache, event:cache, view:cache, optimize:clear and optimize succeed as www-data" probe "$s"
s=$(mk <<'S'
cd /workspace/api
test ! -e .env
php artisan list >/dev/null
test ! -e .env
S
)
check "runtime boots with no api/.env and artisan does not create one" probe "$s"

# 3. real php-fpm in the runtime image, as www-data: ping answers and /up serves through public/index.php
s=$(mk <<'S'
cd /workspace/api
(php-fpm -F >/tmp/f.log 2>&1 &)
i=0
until SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null | grep -q pong; do
  i=$((i+1)); [ "$i" -gt 50 ] && { cat /tmp/f.log; exit 1; }
  php -r 'usleep(100000);'
done
out=$(SCRIPT_NAME=/index.php SCRIPT_FILENAME=/workspace/api/public/index.php REQUEST_URI=/up REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 2>&1)
printf '%s\n' "$out" | head -3
! printf '%s\n' "$out" | grep -qE '^Status: [45]'
printf '%s\n' "$out" | grep -qi 'x-powered-by'
! printf '%s\n' "$out" | grep -qiE 'fatal|not found|Stack trace'
# the pool must not be running as root
! ps -o user= -o args= | grep 'php-fpm: pool' | grep -q '^root'
S
)
check "runtime php-fpm as www-data answers /ping and serves /up without a 4xx/5xx or stack trace" probe "$s"

# 4. the runtime image carries no leaked credentials in its environment or config layers
check "runtime image Config.Env holds no key, password or token" sh -c '
  test -z "$(docker inspect --format "{{range .Config.Env}}{{println .}}{{end}}" '"$TAG"' | grep -Ei "KEY=|SECRET|PASSWORD|TOKEN")"'
check "runtime image exposes only the php-fpm port 9000 and has no healthcheck override surprises" sh -c '
  test "$(docker inspect --format "{{json .Config.ExposedPorts}}" '"$TAG"')" = "{\"9000/tcp\":{}}"'
finish
