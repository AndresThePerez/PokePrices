#!/bin/sh
# P0-i1-T27: each static gate is clean on the repo and catches each planted violation.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp
OUT=$(mktemp -d "$ROOT/.gates/tmp/static-XXXXXX")
for g in ag2.sh ag3.py t11.sh s6.sh; do
  case $g in *.py) cmd="python3 -I scripts/gates/$g" ;; *) cmd="sh scripts/gates/$g" ;; esac
  check "clean on the repo: $g" env GATES_OUT="$OUT" $cmd
done
base() { # make a minimal clean scratch repo and print its path
  d=$(mktemp -d "$ROOT/.gates/tmp/scratch-XXXXXX")
  ( cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t
    mkdir -p api/app api/tests/Feature web/src scripts docker/x
    printf '<?php\n\ndeclare(strict_types=1);\n\nit("works", function (): void {\n    expect(1 + 1)->toBe(2);\n});\n' > api/tests/Feature/OkTest.php
    printf '<?php\n\ndeclare(strict_types=1);\n\nfinal class A {}\n' > api/app/A.php
    printf "import { expect, it } from 'vitest';\n\nit('works', () => {\n  expect(1 + 1).toBe(2);\n});\n" > web/src/ok.test.ts
    printf 'export const a = 1;\n' > web/src/a.ts
    printf 'FROM alpine:3@sha256:%064d AS base\nFROM base\n' 0 > docker/x/Dockerfile
    printf 'name: s\nservices:\n  a:\n    image: alpine:3@sha256:%064d\n' 0 > compose.dev.yml
    printf 'gates:\n\tscripts/scorecard\n' > Makefile
    printf '#!/bin/sh\nset -u\n[ 1 -eq 1 ]\n' > scripts/ok.sh
    printf '{"config":{}}\n' > api/composer.json
    git add -A && git commit -qm base )
  printf '%s\n' "$d"
}
plant() { # GATE FILE CONTENT DESCRIPTION
  d=$(base); printf '%b' "$3" > "$d/$2"; ( cd "$d" && git add -A && git commit -qm plant )
  case $1 in *.py) cmd="python3 -I $ROOT/scripts/gates/$1" ;; *) cmd="sh $ROOT/scripts/gates/$1" ;; esac
  check "$1 catches $4" sh -c 'cd "$0" && ! env GATES_OUT="$1" $2' "$d" "$OUT" "$cmd"
}
d0=$(base)
for g in ag2.sh ag3.py t11.sh s6.sh; do
  case $g in *.py) cmd="python3 -I $ROOT/scripts/gates/$g" ;; *) cmd="sh $ROOT/scripts/gates/$g" ;; esac
  check "clean on the minimal scratch repo: $g" sh -c 'cd "$0" && env GATES_OUT="$1" $2' "$d0" "$OUT" "$cmd"
done
plant ag2.sh api/app/B.php '<?php\n// @phpstan-ignore-next-line\n$a = 1;\n' 'a bare @phpstan-ignore-next-line'
plant ag2.sh api/app/C.php '<?php\n// @phpstan-ignore argument.type (fine)\n$a = 1;\n' 'an ignore whose reason cites no ruling or finding'
plant ag2.sh web/src/b.ts '// @ts-nocheck\nexport const b = 1;\n' '@ts-nocheck'
plant ag2.sh web/src/c.ts 'export const c = (1 as any);\n' 'as any'
plant ag2.sh web/src/d.ts '/* eslint-disable */\nexport const d = 1;\n' 'eslint-disable'
plant ag2.sh web/src/e.ts '/* v8 ignore next */\nexport const e = 1;\n' 'a v8 coverage ignore'
plant ag2.sh api/app/D.php '<?php\n/** @codeCoverageIgnore */\nfinal class D {}\n' '@codeCoverageIgnore'
plant ag2.sh web/src/f.ts '// prettier-ignore\nexport const f = 1;\n' 'prettier-ignore'
plant ag2.sh .gitleaksignore 'abc\n' 'a gitleaks ignore file'
plant ag2.sh .agent-precommit-allow 'x\n' 'a pre-commit allow file'
plant ag2.sh Makefile 'gates:\n\t-scripts/scorecard\n' 'a dash-prefixed recipe'
plant ag2.sh Makefile '.IGNORE:\ngates:\n\tscripts/scorecard\n' '.IGNORE'
plant ag2.sh scripts/bad.sh '#!/bin/sh\nfalse || true\n' '|| true in scripts/'
plant ag2.sh scripts/bad2.sh '#!/bin/sh\nfalse\nexit 0\n' 'exit 0 in scripts/'
plant ag2.sh scripts/bad3.sh '#!/bin/sh\ndocker compose -f compose.dev.yml exec api true\n' 'exec without -T'
plant ag2.sh scripts/bad4.sh '#!/bin/sh\nnpx tsc\n' 'bare npx'
plant ag2.sh Makefile 'down:\n\tdocker compose -f compose.dev.yml down -v\n' 'down -v'
plant ag2.sh web/package.json '{"scripts":{"test":"vitest run --passWithNoTests"}}\n' '--passWithNoTests'
plant ag2.sh api/composer.json '{"config":{"audit":{"ignore":["CVE-0000-0000"]}}}\n' 'a composer audit ignore'
plant ag2.sh api/.env 'APP_KEY=\n' 'a tracked .env'
plant ag3.py api/tests/Feature/EmptyTest.php '<?php\n\nit("does nothing", function (): void {\n    $x = 1;\n});\n' 'a Pest test with no assertion'
plant ag3.py api/tests/Feature/TrueTest.php '<?php\n\nit("is true", function (): void {\n    $this->assertTrue(true);\n});\n' 'assertTrue(true)'
plant ag3.py web/src/empty.test.ts "import { it } from 'vitest';\n\nit('nothing', () => {\n  const x = 1;\n});\n" 'a Vitest test with no expect'
plant t11.sh api/tests/Feature/SkipTest.php '<?php\n\nit("later", function (): void {\n    expect(1)->toBe(1);\n})->skip();\n' '->skip()'
plant t11.sh web/src/only.test.ts "import { expect, it } from 'vitest';\n\nit.only('x', () => {\n  expect(1).toBe(1);\n});\n" 'it.only'
plant t11.sh api/tests/Feature/IncTest.php '<?php\n\nit("x", function (): void {\n    $this->markTestIncomplete();\n});\n' 'markTestIncomplete'
plant s6.sh docker/x/Dockerfile 'FROM alpine:3\n' 'an unpinned FROM'
plant s6.sh docker/x/Dockerfile 'ARG IMG=alpine\nFROM ${IMG}\n' 'a variable FROM'
plant s6.sh compose.dev.yml 'name: s\nservices:\n  a:\n    image: alpine:3\n' 'an unpinned compose image'
check "a FROM of an earlier stage alias is allowed" sh -c 'cd "$0" && env GATES_OUT="$1" sh "$2/scripts/gates/s6.sh"' "$d0" "$OUT" "$ROOT"
check "the real manifest prose does not trip AG2 (only its executable fields are scanned)" sh -c 'd=$(mktemp -d "$0/.gates/tmp/mf-XXXXXX"); cp -r "$0/gates" "$d/" && cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t && mkdir -p api/tests web/src && printf "<?php\nit(\"a\", function (): void { expect(1)->toBe(1); });\n" > api/tests/ATest.php && printf "export const a = 1;\n" > web/src/a.ts && git add -A && git commit -qm m && env GATES_OUT="$1" sh "$0/scripts/gates/ag2.sh"' "$ROOT" "$OUT"
check "a missing scan directory is a finder error, not clean" sh -c 'd=$(mktemp -d "$0/.gates/tmp/empty-XXXXXX"); cd "$d" && git init -q && ! env GATES_OUT="$1" sh "$0/scripts/gates/t11.sh"' "$ROOT" "$OUT"
finish
