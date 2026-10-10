#!/bin/sh
# PokéPrices full suite: infrastructure acceptance tests, then Pest and Vitest when present.
set -u
cd "$(dirname "$0")/../.." || exit 1
rc=0
for t in tests/p0/T*.sh tests/gates/test_*.sh tests/scorecard/test_*.sh tests/verdict/test_*.sh; do
  [ -f "$t" ] || continue
  printf '# %s\n' "$t"
  sh "$t" || rc=1
done
if [ -x api/vendor/bin/pest ] && [ -f api/phpunit.xml ]; then
  docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pest -c phpunit.xml || rc=1
fi
if [ -f web/vitest.config.ts ]; then
  docker compose -f compose.dev.yml exec -T -w /workspace/web node ./node_modules/.bin/vitest run --config vitest.config.ts || rc=1
fi
[ "$rc" -eq 0 ]
