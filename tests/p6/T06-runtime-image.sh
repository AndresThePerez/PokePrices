#!/bin/sh
# P6-DEPLOY-i1-T06: the api runtime image carries the www-data pool, the FastCGI allow-list and the php ini
# hardening (manual tier, not in FAST). Builds the image and one build-only probe layer; no container is run.
# Run from the repo root: TIER=task sh tests/p0/run.sh tests/p6/T06-runtime-image.sh
. tests/p0/lib.sh

DF=docker/api/Dockerfile
CONF=deploy/php/zz-runtime.conf
INI=deploy/php/zz-runtime.ini
TAG=pokeprices-api:p6-check
PROBE=pokeprices-api:p6-probe
TMP=.gates/tmp
mkdir -p "$TMP"
cleanup() { docker rmi "$PROBE" >/dev/null 2>&1; docker rmi "$TAG" >/dev/null 2>&1; }
trap cleanup EXIT

# The runtime stage only (from its FROM line to the end of the file).
RT=$TMP/p6-T06-runtime-stage.txt
awk '/^FROM base AS runtime/ {on=1} on {print}' "$DF" > "$RT"

# lineno PATTERN: first line number in the runtime stage matching the fixed string, empty when absent.
lineno() { grep -nF -- "$1" "$RT" | head -n 1 | cut -d: -f1; }
CC=$(lineno 'COPY deploy/php/zz-runtime.conf /usr/local/etc/php-fpm.d/zz-runtime.conf')
CI=$(lineno 'COPY deploy/php/zz-runtime.ini /usr/local/etc/php/conf.d/zz-runtime.ini')
US=$(lineno 'USER www-data')

check "runtime stage has the pool COPY" test -n "$CC"
check "runtime stage has the ini COPY" test -n "$CI"
check "runtime stage has USER www-data" test -n "$US"
check "pool COPY comes before USER" sh -c "[ -n '$CC' ] && [ -n '$US' ] && [ '$CC' -lt '$US' ]"
check "ini COPY comes before USER" sh -c "[ -n '$CI' ] && [ -n '$US' ] && [ '$CI' -lt '$US' ]"
check "runtime stage has exactly one USER line" sh -c "[ \"\$(grep -c '^USER ' $RT)\" = 1 ]"
check "runtime stage CMD is unchanged (php-fpm -F, no -R)" grep -Fxq 'CMD ["php-fpm", "-F"]' "$RT"

# Exact content of the two files (blank lines and ; comment lines ignored).
check "pool file holds exactly the four directives" sh -c "[ \"\$(grep -v -e '^[[:space:]]*\$' -e '^[[:space:]]*;' '$CONF' | tr '\n' '|')\" = '[www]|user = www-data|group = www-data|listen.allowed_clients = 127.0.0.1,198.18.40.10|' ]"
check "ini file holds exactly the three directives" sh -c "[ \"\$(grep -v -e '^[[:space:]]*\$' -e '^[[:space:]]*;' '$INI' | tr '\n' '|')\" = 'display_errors=Off|expose_php=Off|log_errors=On|' ]"

# Build the runtime image; no container.
rm -f "$TMP/p6-T06-build.log"
docker build -t "$TAG" --target runtime -f "$DF" . > "$TMP/p6-T06-build.log" 2>&1
check "runtime image builds" docker image inspect "$TAG"
check_out "image user stays www-data (hard gate S5)" www-data docker image inspect --format '{{.Config.User}}' "$TAG"

# probe CMD: a throwaway layer on the built image, run as root so php-fpm -tt may parse; build-only.
probe() { printf 'FROM %s\nUSER root\nRUN %s\n' "$TAG" "$1" | docker build -q --no-cache -t "$PROBE" - > "$TMP/p6-T06-probe.log" 2>&1; }
check "effective pool user is www-data" probe "php-fpm -tt 2>&1 | grep -E 'user = www-data'"
check "effective pool group is www-data" probe "php-fpm -tt 2>&1 | grep -E 'group = www-data'"
check "effective listen.allowed_clients is the allow-list" probe "php-fpm -tt 2>&1 | grep -F 'listen.allowed_clients = 127.0.0.1,198.18.40.10'"
check "pool is not the frozen root pool" probe "! php-fpm -tt 2>&1 | grep -E 'user = root'"
check "php display_errors is Off" probe "php -i | grep -E '^display_errors => Off'"
check "php expose_php is Off" probe "php -i | grep -E '^expose_php => Off'"
check "php log_errors is On" probe "php -i | grep -E '^log_errors => On'"

finish
