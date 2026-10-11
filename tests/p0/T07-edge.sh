#!/bin/sh
# P0-i1-T07 edge cases (reviewer): plan values verbatim, strict phpunit.xml really fails the run,
# the dev database survives an <env>-only configuration (R60), Feature-only RefreshDatabase.
# Throwaway files live in api/ (the only tree the api container mounts) and are removed on exit.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
API="docker compose -f compose.dev.yml exec -T -w /workspace/api api"
PEST="$API vendor/bin/pest"
X=api/phpunit.xml
T=api/tests/Unit/ZzT07EdgeThrowawayTest.php
ENVONLY=api/phpunit.edge-envonly.xml
cleanup() { rm -f "$T" "$ENVONLY"; }
trap cleanup EXIT

$DC up -d --wait api postgres >/dev/null 2>&1

# --- 1. plan values verbatim ------------------------------------------------------------------
for a in failOnEmptyTestSuite failOnRisky failOnWarning failOnSkipped failOnIncomplete beStrictAboutTestsThatDoNotTestAnything beStrictAboutOutputDuringTests; do
  check "phpunit.xml sets $a=\"true\"" grep -q "$a=\"true\"" $X
done
check_out "source include is exactly app" "1" sh -c "sed -n '/<include>/,/<\/include>/p' $X | grep -c '<directory>'"
check "source include directory is app" grep -q '<directory>app</directory>' $X
check_out "source exclude is exactly app/Providers" "app/Providers" sh -c "sed -n '/<exclude>/,/<\/exclude>/p' $X | sed -n 's|.*<directory>\(.*\)</directory>.*|\1|p'"
for pair in APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=pokeprices_test CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array MAIL_MAILER=array BCRYPT_ROUNDS=4; do
  n=${pair%%=*}; v=${pair#*=}
  check "phpunit.xml env $n=$v" grep -q "<env name=\"$n\" value=\"$v\" force=\"true\"/>" $X
  check "phpunit.xml server mirrors env $n=$v (R59)" grep -q "<server name=\"$n\" value=\"$v\" force=\"true\"/>" $X
done
check_out "exactly eight env lines and eight server lines" "8 8" sh -c "echo \$(grep -c '<env ' $X) \$(grep -c '<server ' $X)"
check "no sqlite anywhere in phpunit.xml" sh -c "! grep -qi sqlite $X"
check "both ExampleTest files are gone" sh -c '[ ! -e api/tests/Unit/ExampleTest.php ] && [ ! -e api/tests/Feature/ExampleTest.php ]'
check "composer require-dev has no phpunit/phpunit entry" jq -e '(.["require-dev"] | has("phpunit/phpunit")) | not' api/composer.json
check_out "paratest is pinned exactly (no caret)" "7.26.0" jq -r '.["require-dev"]["brianium/paratest"]' api/composer.json
check "Pest.php applies RefreshDatabase to Feature only" grep -qF -- "->in('Feature')" api/tests/Pest.php
check "Pest.php never extends Unit" sh -c "! grep -q \"in('Unit')\" api/tests/Pest.php"

# --- 2. strictness is real (each throwaway test must fail the run) -----------------------------
run_throwaway() { # body
  printf '<?php\n\ndeclare(strict_types=1);\n\n%s\n' "$1" > "$T"
  $PEST -c phpunit.xml --filter=ZzT07EdgeThrowaway >/dev/null 2>&1
  rc=$?; rm -f "$T"; return $rc
}
check "control: an asserting throwaway test passes" run_throwaway "it('zz control', function (): void { expect(true)->toBeTrue(); });"

refuses_run() { # desc body: the throwaway test must make pest exit non-zero
  if run_throwaway "$2"; then printf 'not ok - %s\n' "$1"; fails=$((fails + 1)); else printf 'ok - %s\n' "$1"; fi
}
refuses_run "a test with no assertions fails the run (risky)" "it('zz none', function (): void {});"
refuses_run "a skipped test fails the run" "it('zz skip', function (): void { expect(true)->toBeTrue(); })->skip('edge');"
refuses_run "output during a test fails the run" "it('zz echo', function (): void { echo 'noise'; expect(true)->toBeTrue(); });"
refuses_run "a todo/incomplete test fails the run" "it('zz todo')->todo();"
refuses_run "a PHP warning inside a test fails the run" "it('zz warn', function (): void { trigger_error('edge', E_USER_WARNING); expect(true)->toBeTrue(); });"
check "an empty selection fails the run (failOnEmptyTestSuite)" sh -c "! $PEST -c phpunit.xml --filter=NoSuchTestZzT07 >/dev/null 2>&1"
check "a Unit test gets no database (Pest.php is Feature-only)" sh -c "
  printf '<?php\n\ndeclare(strict_types=1);\n\nit(\"zz unit\", function (): void { expect(\$this)->not->toBeInstanceOf(Tests\\\\TestCase::class); });\n' > $T
  $PEST -c phpunit.xml --filter=ZzT07EdgeThrowaway >/dev/null 2>&1; rc=\$?; rm -f $T; [ \$rc -eq 0 ]"

# --- 3. the dev database survives an <env>-only configuration (the original wipe) --------------
sed '/<server /d' $X > $ENVONLY
count() { $DC exec -T postgres psql -U pokeprices -d pokeprices -tAc "SELECT (SELECT count(*) FROM information_schema.tables WHERE table_schema='public') || ':' || (SELECT count(*) FROM pg_stat_user_tables WHERE schemaname='public' AND (n_tup_ins + n_tup_del + n_tup_upd) > 0)"; }
before=$(count)
out=$($PEST -c phpunit.edge-envonly.xml 2>&1); rc=$?
after=$(count)
[ "$rc" -ne 0 ] && printf 'ok - an <env>-only phpunit.xml fails the run\n' || { printf 'not ok - an <env>-only phpunit.xml fails the run\n'; fails=$((fails + 1)); }
case $out in *"refuse to run against database pokeprices;"*) printf 'ok - the R60 guard names the refused database\n' ;; *) printf 'not ok - the R60 guard names the refused database\n'; fails=$((fails + 1)) ;; esac
[ "$before" = "$after" ] && printf 'ok - the pokeprices dev database is untouched by the refused run (%s)\n' "$before" || { printf 'not ok - the pokeprices dev database changed (%s -> %s)\n' "$before" "$after"; fails=$((fails + 1)); }

# --- 4. the real run, serial and parallel, in random order -------------------------------------
check "serial pest run is green" $PEST -c phpunit.xml
check "parallel random-order pest run is green" $PEST -c phpunit.xml --parallel --order-by=random
finish
