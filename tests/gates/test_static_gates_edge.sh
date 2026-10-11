#!/bin/sh
# P0-i1-T27 review edge cases: routes an agent could use to hide an ignore, a skipped or
# assertion-free test, or an unpinned image from AG2, AG3, T11 and S6.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp
OUT=$(mktemp -d "$ROOT/.gates/tmp/edge-XXXXXX")
D64=$(printf '%064d' 0)
base() {
  d=$(mktemp -d "$ROOT/.gates/tmp/edge-scratch-XXXXXX")
  ( cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t
    mkdir -p api/app api/tests/Feature web/src scripts docker/x
    printf '<?php\n\nit("works", function (): void {\n    expect(1 + 1)->toBe(2);\n});\n' > api/tests/Feature/OkTest.php
    printf '<?php\n\nfinal class A {}\n' > api/app/A.php
    printf "import { expect, it } from 'vitest';\n\nit('works', () => {\n  expect(1 + 1).toBe(2);\n});\n" > web/src/ok.test.ts
    printf 'export const a = 1;\n' > web/src/a.ts
    printf 'FROM alpine:3@sha256:%s AS base\nFROM base\n' "$D64" > docker/x/Dockerfile
    printf 'name: s\nservices:\n  a:\n    image: alpine:3@sha256:%s\n' "$D64" > compose.dev.yml
    printf 'gates:\n\tscripts/scorecard\n' > Makefile
    printf '#!/bin/sh\nset -u\n[ 1 -eq 1 ]\n' > scripts/ok.sh
    printf '{"config":{}}\n' > api/composer.json
    git add -A && git commit -qm base )
  printf '%s\n' "$d"
}
gatecmd() { case $1 in *.py) printf 'python3 -I %s/scripts/gates/%s' "$ROOT" "$1" ;; *) printf 'sh %s/scripts/gates/%s' "$ROOT" "$1" ;; esac; }
# plant GATE EXPECT FILE CONTENT DESC: EXPECT is catch (gate must fail) or pass (gate must stay clean)
plant() {
  d=$(base); mkdir -p "$d/$(dirname "$3")"; printf '%b' "$4" > "$d/$3"; ( cd "$d" && git add -A && git commit -qm plant )
  cmd=$(gatecmd "$1")
  if [ "$2" = catch ]; then
    check "$1 catches $5" sh -c 'cd "$0" && ! env GATES_OUT="$1" $2' "$d" "$OUT" "$cmd"
  else
    check "$1 stays clean on $5" sh -c 'cd "$0" && env GATES_OUT="$1" $2' "$d" "$OUT" "$cmd"
  fi
}
PEST='<?php\nit("x", function () { expect(1)->toBe(1); })'
VT="import { expect, it, test } from 'vitest';\n"
# AG2: reasons must cite a real ruling or finding id.
plant ag2.sh catch api/app/B.php '<?php\n// @phpstan-ignore argument.type (see R for details)\n' 'an ignore reason citing a bare R with no number'
plant ag2.sh catch api/app/B.php '<?php\n// @phpstan-ignore argument.type (R1)\n' 'an ignore reason shorter than 8 characters'
plant ag2.sh pass api/app/B.php '<?php\n// @phpstan-ignore argument.type (see R12 for the reason)\n' 'an ignore citing R12 with a reason'
plant ag2.sh catch api/app/B.php '<?php\n// @phpstan-ignore-line\n' '@phpstan-ignore-line'
plant ag2.sh catch web/src/b.ts '// @ts-expect-error\nexport const b = 1;\n' '@ts-expect-error without a ruling'
plant ag2.sh pass web/src/b.ts '// @ts-expect-error: see R12\nexport const b = 1;\n' '@ts-expect-error citing R12'
plant ag2.sh catch web/src/b.ts 'export const b = x as unknown as any;\n' 'as unknown as any'
plant ag2.sh catch web/.gitleaks.toml '[allowlist]\n' 'a nested .gitleaks.toml'
plant ag2.sh catch scripts/x.sh '#!/bin/sh\n# agent-precommit:allow\n' 'the agent-precommit allow marker'
plant ag2.sh catch Makefile 'gates:\n\tscripts/scorecard || true\n' '|| true at the end of a recipe line'
plant ag2.sh catch Makefile 'gates:\n\tscripts/scorecard\n\tmake -ik other\n' 'make -ik'
plant ag2.sh catch Makefile 'down:\n\tdocker compose down --volumes\n' 'down --volumes'
plant ag2.sh catch Makefile 'x:\n\t$(DC) exec api true\n' 'a $(DC) exec without -T'
plant ag2.sh pass Makefile 'x:\n\t$(DC) exec -T api true\n' 'a $(DC) exec with -T'
plant ag2.sh catch web/vitest.config.ts 'export default { test: { passWithNoTests: true } };\n' 'passWithNoTests: true'
plant ag2.sh pass web/vitest.config.ts 'export default { test: { passWithNoTests: false } };\n' 'passWithNoTests: false'
plant ag2.sh catch api/composer.json '{"config":{"audit":{"ignore":{"CVE-0000-0000":"x"}}}}\n' 'a composer audit ignore object'
# AG2 reads the manifest's executable fields through jq.
check "ag2.sh catches || true in a manifest gate command" sh -c '
  d=$(mktemp -d "$0/.gates/tmp/edge-mf-XXXXXX"); cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t
  mkdir -p gates api/tests web/src
  printf "{\"gates\":[{\"command\":\"scripts/x || true\",\"banner_cmd\":\"\",\"value_parser\":\"\",\"denominator_source\":\"\"}]}\n" > gates/manifest-P0.json
  printf "<?php\nit(\"a\", function () { expect(1)->toBe(1); });\n" > api/tests/ATest.php; printf "export const a = 1;\n" > web/src/a.ts
  git add -A && git commit -qm m && ! env GATES_OUT="$1" sh "$0/scripts/gates/ag2.sh"' "$ROOT" "$OUT"
# AG3: assertion-free tests cannot hide behind a modifier, a file name or a trivial assertion.
plant ag3.py catch api/tests/Feature/A.php '<?php\nit("x", function () { $this->assertTrue( true ); });\n' 'assertTrue( true ) with spaces'
plant ag3.py catch api/tests/Feature/A.php '<?php\nclass T { public function testX(): void {} }\n' 'an empty PHPUnit test method'
plant ag3.py pass api/tests/Feature/A.php '<?php\nclass T { public function testX(): void { self::assertSame(1, 1); } }\n' 'a PHPUnit method calling self::assertSame'
plant ag3.py catch api/tests/Feature/A.php '<?php\ndescribe("g", function () { it("x", function () { $a = 1; }); });\n' 'an assertion-free it() inside describe()'
plant ag3.py catch api/tests/Feature/A.php '<?php\nit("x", function () { $a = 1; })->throws(Exception::class);\n' 'an assertion-free it() chained with throws()'
plant ag3.py catch web/src/b.test.ts "${VT}it('x', () => { const a = 1; // expect(1)\n});\n" 'an expect( named only in a comment'
plant ag3.py catch web/src/b.test.ts "${VT}test.concurrent('x', async () => { const a = 1; });\n" 'an assertion-free test.concurrent'
plant ag3.py catch web/src/b.test.ts "${VT}it.each([1, 2])('x %s', (v) => { const a = v; });\n" 'an assertion-free it.each table'
plant ag3.py catch web/src/b.spec.ts "${VT}it('x', () => { const a = 1; });\n" 'an assertion-free test in a .spec.ts file'
plant ag3.py catch web/src/b.test.tsx "${VT}it('x', () => { const a = 1; });\n" 'an assertion-free test in a .test.tsx file'
# T11: every way to skip, focus or defer a test.
plant t11.sh catch api/tests/Feature/S.php "$PEST->skip();\n" '->skip()'
plant t11.sh catch api/tests/Feature/S.php "$PEST->only();\n" '->only()'
plant t11.sh catch api/tests/Feature/S.php "$PEST->todo();\n" '->todo()'
plant t11.sh catch api/tests/Feature/S.php "$PEST->skipOnLinux();\n" '->skipOnLinux() (skips every test on the Linux host)'
plant t11.sh catch api/tests/Feature/S.php "$PEST->skipLocally();\n" '->skipLocally()'
plant t11.sh catch web/src/s.test.ts "${VT}it.skipIf(true)('x', () => { expect(1).toBe(1); });\n" 'it.skipIf(true)'
plant t11.sh catch web/src/s.test.ts "${VT}it.runIf(false)('x', () => { expect(1).toBe(1); });\n" 'it.runIf(false)'
plant t11.sh catch web/src/s.test.ts "${VT}describe.only('x', () => { it('y', () => { expect(1).toBe(1); }); });\n" 'describe.only'
plant t11.sh catch web/src/s.test.ts "${VT}fdescribe('x', () => {});\n" 'fdescribe('
plant t11.sh pass web/src/s.test.ts "${VT}it('skips nothing', () => { expect('x.skip(').toBe('x.skip('); });\n" 'a plain test'
# S6: digest pins in Dockerfiles and compose.
plant s6.sh catch docker/x/Dockerfile "FROM alpine:3@sha256:$(printf '%063d' 0)\n" 'a 63-hex digest'
plant s6.sh catch docker/x/Dockerfile "FROM alpine:3@sha256:$(printf '%065d' 0)\n" 'a 65-hex digest'
plant s6.sh catch docker/x/Dockerfile "FROM alpine:3@sha256:$D64 AS build\nFROM build2\n" 'an undefined stage alias'
plant s6.sh pass docker/x/Dockerfile "FROM --platform=\$BUILDPLATFORM alpine:3@sha256:$D64 AS build\nFROM build AS runtime\nFROM scratch\n" 'platform flag, stage alias and scratch'
plant s6.sh catch docker/x/Dockerfile "FROM alpine:3@sha256:$D64 AS base\nfrom alpine:3\n" 'a lowercase from'
plant s6.sh catch docker/x/Dockerfile.dev 'FROM alpine:3\n' 'an unpinned Dockerfile.dev'
plant s6.sh catch compose.dev.yml 'name: s\nservices:\n  a:\n    image: alpine\n' 'an unpinned compose image'
plant s6.sh pass compose.dev.yml "name: s\nservices:\n  a:\n    build: .\n    image: local-name\n" 'a compose service with build and a local image name'
plant s6.sh catch compose.dev.yml "name: s\nservices:\n  a:\n    image: alpine:3@sha256:$D64\n  b:\n    image: busybox\n    profiles: [extra]\n" 'an unpinned image hidden behind a compose profile'
plant s6.sh catch compose.dev.yml 'name: s\nservices:\n  a:\n    image: [\n' 'a compose file that fails config'
check "t11.sh treats a missing scan directory as a finder error" sh -c 'd=$(mktemp -d "$0/.gates/tmp/edge-empty-XXXXXX"); cd "$d" && git init -q && ! env GATES_OUT="$1" sh "$0/scripts/gates/t11.sh"' "$ROOT" "$OUT"
check "ag3.py adds no_tests for a tree with zero test files" sh -c 'd=$(mktemp -d "$0/.gates/tmp/edge-nt-XXXXXX"); cd "$d" && git init -q && mkdir -p api/tests web/src && ! env GATES_OUT="$1" python3 -I "$0/scripts/gates/ag3.py"' "$ROOT" "$OUT"
finish
