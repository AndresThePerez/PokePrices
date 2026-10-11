#!/bin/sh
# P0-i1-T08 edge cases (reviewer): the quality gates really fire, configs are verbatim and strict,
# the composer lock keeps its pins. Throwaway files live in api/app (mounted in the api container)
# and are removed on exit.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
API="docker compose -f compose.dev.yml exec -T -w /workspace/api api"
S=.gates/tmp/T08-edge
mkdir -p "$S"
T=api/app/ZzT08Edge.php
cleanup() { rm -f "$T"; }
trap cleanup EXIT

$DC up -d --wait api postgres >/dev/null 2>&1

# --- 1. config verbatim --------------------------------------------------------------------
N=api/phpstan.neon
check "phpstan.neon includes the larastan extension" grep -qF 'vendor/larastan/larastan/extension.neon' $N
check "phpstan.neon level is 8" grep -qE '^    level: 8$' $N
for p in app bootstrap/app.php config database routes; do
  check "phpstan.neon analyses $p" grep -qE "^        - $p\$" $N
done
check_out "phpstan.neon lists exactly five paths" "5" sh -c "sed -n '/paths:/,/tmpDir:/p' $N | grep -c '^        - '"
check "phpstan.neon reportUnmatchedIgnoredErrors true" grep -qE '^    reportUnmatchedIgnoredErrors: true$' $N
check "phpstan.neon treatPhpDocTypesAsCertain false" grep -qE '^    treatPhpDocTypesAsCertain: false$' $N
check "phpstan.neon has no ignoreErrors, excludePaths or baseline" sh -c "! grep -qiE 'ignoreErrors|excludePaths|baseline' $N"
check_out "no phpstan baseline file anywhere under api (outside vendor)" "0" sh -c "find api -name '*baseline*' -not -path 'api/vendor/*' | wc -l"
check_out "pint.json is exactly the laravel preset" '{"preset":"laravel"}' sh -c "jq -c . api/pint.json"
check "rector.php enables deadCode and UP_TO_LARAVEL_130" sh -c "grep -qF 'withPreparedSets(deadCode: true)' api/rector.php && grep -qF 'LaravelLevelSetList::UP_TO_LARAVEL_130' api/rector.php"
check_out "rector.php scans five paths" "5" sh -c "grep -c \"__DIR__.'/\" api/rector.php"

# --- 2. composer pins ------------------------------------------------------------------------
check_out "composer.json pins paratest exactly" "7.26.0" jq -r '.["require-dev"]["brianium/paratest"]' api/composer.json
check_out "composer.lock pins paratest 7.26.0" "v7.26.0" jq -r '[.packages[],.["packages-dev"][]] | map(select(.name=="brianium/paratest"))[0].version' api/composer.lock
check "no direct phpunit/phpunit require" jq -e '((.require // {}) + (.["require-dev"] // {})) | has("phpunit/phpunit") | not' api/composer.json
check "composer validate --strict passes" $API composer validate --no-check-publish --strict --no-interaction
$API composer audit --locked --abandoned=report --format=json --no-interaction > $S/audit.json 2>/dev/null
check "audit reports zero advisories" jq -e '[.advisories[]?[]?] | length == 0' $S/audit.json
check_out "only abandoned package is symplify/rule-doc-generator-contracts" "symplify/rule-doc-generator-contracts" sh -c "jq -r '.abandoned | keys[]' $S/audit.json"

# --- 3. the gates are real (planted violations must be caught) ---------------------------------
printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class ZzT08Edge\n{\n    public function maybe(): ?string\n    {\n        return null;\n    }\n\n    public function use(): int\n    {\n        return strlen($this->maybe());\n    }\n}\n' > $T
$API vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress --no-interaction --error-format=json > $S/q1.json 2>/dev/null
check "Q1 phpstan reports the planted nullable misuse (file_errors > 0)" jq -e '.totals.file_errors > 0' $S/q1.json
check "Q1 error is attributed to the planted file" jq -e '.files | keys | map(test("ZzT08Edge")) | any' $S/q1.json
rm -f $T

printf '<?php\ndeclare(strict_types=1);\nnamespace App;\nfinal class ZzT08Edge {\n  public function a( int $x ):int{ return $x+1 ;}\n}\n' > $T
$API vendor/bin/pint --test --config pint.json --output-to-file=/workspace/$S/q3.json --output-format=json >/dev/null 2>&1
rc=$?
check "Q3 pint --test exits non-zero on an unformatted file" test "$rc" -ne 0
check "Q3 pint lists the unformatted file" jq -e '.files | length >= 1' $S/q3.json
rm -f $T

printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class ZzT08Edge\n{\n    public function used(): int\n    {\n        return 1;\n    }\n\n    private function dead(): int\n    {\n        return 2;\n    }\n}\n' > $T
$API vendor/bin/rector process --dry-run --no-progress-bar --output-format=json --config rector.php > $S/q5.json 2>/dev/null
check "Q5 rector proposes a dead-code change on a planted unused private method" jq -e '.totals.changed_files >= 1' $S/q5.json
rm -f $T

# Type coverage drops below 100 with an untyped method.
printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class ZzT08Edge\n{\n    public function untyped($a)\n    {\n        return $a;\n    }\n}\n' > $T
$API vendor/bin/pest -c phpunit.xml --type-coverage --min=100 --type-coverage-json=/workspace/$S/q4.json >/dev/null 2>&1
rc=$?
check "Q4 type coverage run fails (non-zero) with an untyped method" test "$rc" -ne 0
check "Q4 total is below 100 with an untyped method" jq -e '.total < 100' $S/q4.json
rm -f $T
$API vendor/bin/pest -c phpunit.xml --type-coverage --min=100 --type-coverage-json=/workspace/$S/q4ok.json >/dev/null 2>&1
check "Q4 control: clean tree is 100 with at least one result" jq -e '.total >= 100 and (.result | length) >= 1' $S/q4ok.json

# --- 4. AG-values rejects weakened phpstan/pint inputs -----------------------------------------
$API vendor/bin/phpstan dump-parameters -c phpstan.neon --json > $S/params.json 2>/dev/null
mk() { # name jq-filter-for-params neon-text-suffix baseline_exists
  jq -n --slurpfile p $S/params.json --arg neon "$(cat api/phpstan.neon)$3" --argjson be "$4" \
    "{phpstan_params: (\$p[0] | $2), phpstan_neon_text: \$neon, baseline_exists: \$be, pint: {preset: \"laravel\"}}" > $S/in-$1.json
}
mk good '.' '' false
mk lvl7 '.level = 7' '' false
mk ignore '.ignoreErrors = [{"message":"x"}]' '' false
mk base '.' "
includes:
    - phpstan-baseline.neon" false
mk basefile '.' '' true
agv() { GATES_OUT=$S python3 -I scripts/gates/ag-values.py --only phpstan,pint --inputs "$S/in-$1.json"; }
check "AG-values passes the unmutated inputs" agv good
for m in lvl7 ignore base basefile; do
  if agv $m >/dev/null 2>&1; then printf 'not ok - AG-values rejects mutation %s\n' "$m"; fails=$((fails + 1)); else printf 'ok - AG-values rejects mutation %s\n' "$m"; fi
done
check "AG-values passes live on HEAD" sh -c "GATES_OUT=$S python3 -I scripts/gates/ag-values.py --only phpstan,pint"

# --- 5. nothing left behind ----------------------------------------------------------------------
check "no throwaway file left in the tree" test ! -e $T
check_out "api tree clean" "" git status --porcelain -- api
finish
