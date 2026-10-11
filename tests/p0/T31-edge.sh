#!/bin/sh
# P0-i1-T31 review edge cases: recipe-lint escapes in a Makefile, verdict refusal, no-volume down,
# recipe order, pipefail in force, init-api-env.sh behaviour with a fake docker, freshness expression.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd); mkdir -p .gates/tmp
OUT=$(mktemp -d "$ROOT/.gates/tmp/t31e-XXXXXX")
GREP=/usr/bin/grep

# 1. AG2 fires on each Makefile escape planted in a scratch repo; the real Makefile stays clean.
mkrepo() { # prints a scratch repo holding the real Makefile
  d=$(mktemp -d "$ROOT/.gates/tmp/t31e-repo-XXXXXX")
  ( cd "$d" && git init -q -b main && git config user.email t@example.invalid && git config user.name t
    mkdir -p scripts api/app api/tests web/src
    printf '<?php\n\nfinal class A {}\n' > api/app/A.php
    printf 'export const a = 1;\n' > web/src/a.ts
    printf '{"config":{}}\n' > api/composer.json
    cp "$ROOT/Makefile" Makefile
    git add -A && git commit -qm base )
  printf '%s\n' "$d"
}
ag2_in() { ( cd "$1" && env GATES_OUT="$OUT" sh "$ROOT/scripts/gates/ag2.sh" ); }
d=$(mkrepo)
check "AG2 is clean on a scratch repo holding the real Makefile" ag2_in "$d"
plantmk() { # DESC LINE
  d=$(mkrepo); printf '%b\n' "$2" >> "$d/Makefile"; ( cd "$d" && git add -A && git commit -qm plant )
  check "AG2 catches $1 in the Makefile" sh -c '! ( cd "$0" && env GATES_OUT="$1" sh "$2/scripts/gates/ag2.sh" )' "$d" "$OUT" "$ROOT"
}
plantmk 'a dash-prefixed recipe line' 'extra:\n\t-scripts/scorecard'
plantmk 'an @- prefixed recipe line' 'extra:\n\t@-scripts/scorecard'
plantmk '.IGNORE' '.IGNORE: gates'
plantmk '|| true' 'extra:\n\tscripts/scorecard || true'
plantmk '|| :' 'extra:\n\tscripts/scorecard || :'
plantmk '; true' 'extra:\n\tscripts/scorecard; true'
plantmk 'exit 0' 'extra:\n\tscripts/scorecard; exit 0'
plantmk 'down -v' 'extra:\n\t$(DC) down -v'
plantmk 'down --volumes' 'extra:\n\t$(DC) down --volumes'
plantmk 'exec without -T via $(DC)' 'extra:\n\t$(DC) exec api php -v'
plantmk 'exec without -T via literal compose' 'extra:\n\tdocker compose -f compose.dev.yml exec api php -v'
plantmk 'bare npx' 'extra:\n\tnpx prettier .'
plantmk 'make -k' 'extra:\n\tmake -k gates'

# 2. verdict refuses without FINDINGS, and never exits 0 with a bogus one.
check "make verdict without FINDINGS refuses" sh -c '! make verdict >/dev/null 2>&1'
check "make verdict with an empty FINDINGS= refuses" sh -c '! make verdict FINDINGS= >/dev/null 2>&1'
check "make verdict says FINDINGS is required" sh -c 'make verdict 2>&1 | '$GREP' -q "FINDINGS=<path to findings.json> is required"'
check "make verdict with a nonexistent FINDINGS path fails" sh -c '! make verdict FINDINGS=.gates/tmp/does-not-exist.json >/dev/null 2>&1'
printf '{' > "$OUT/bad.json"
check "make verdict with a malformed findings file fails" sh -c '! make verdict FINDINGS="$0" >/dev/null 2>&1' ".gates/tmp/$(basename "$OUT")/bad.json"
check "make verdict does not run --gates-only (full mode)" sh -c '! make -n verdict FINDINGS=x | '$GREP' -q -- "--gates-only"'
check "make -n verdict passes FINDINGS through" sh -c 'make -n verdict FINDINGS=x | '$GREP' -q -- "--findings x"'

# 3. down never removes volumes; default target runs no docker.
check "make -n down is exactly compose down" sh -c 'test "$(make -n down)" = "docker compose -f compose.dev.yml down"'
check "the default goal only echoes (no docker)" sh -c '! make -n | '$GREP' -q docker'
check "every target is phony" sh -c 'for t in help up init gates verdict down; do make -p -n 2>/dev/null | '$GREP' -E "^\.PHONY:.*\b$t\b" >/dev/null || exit 1; done'

# 4. recipe order: the full stack comes up after init's composer/env/npm steps, scorecard before verdict.
check "make -n gates orders composer < init-api-env < npm < full up < scorecard < verdict" sh -c '
  make -n gates | '$GREP' -nE "composer install|init-api-env|npm ci|up -d --wait --build$|scripts/scorecard|--gates-only" | cut -d: -f1 | paste -sd" " > "$0"
  test "$(wc -w < "$0")" -eq 6 && test "$(tr " " "\n" < "$0" | sort -n | paste -sd" ")" = "$(cat "$0")"' "$OUT/order.txt"
check "make -n up builds only CORE services" sh -c 'make -n up | '$GREP' -q "up -d --wait --build postgres redis-queue redis-cache api node tools$"'
check "gates never builds the full stack before init (init is a prerequisite)" sh -c 'make -n gates | '$GREP' -n "up -d --wait --build" | head -1 | '$GREP' -q "postgres"'

# 5. pipefail is in force for recipes.
cat > "$OUT/pipe.mk" <<'MK'
pipefail-probe:
	false | true
pipefail-ok:
	true | true
MK
check "a failing command inside a pipe in a recipe fails the target" sh -c '! make -f Makefile -f "$0" pipefail-probe >/dev/null 2>&1' "$OUT/pipe.mk"
check "a passing pipe still passes" make -f Makefile -f "$OUT/pipe.mk" pipefail-ok

# 6. init-api-env.sh: static text and behaviour with a fake docker.
S=scripts/dev/init-api-env.sh
check "init-api-env.sh never reads api/.env (no cat/grep/source/dot/head/tail/sed/awk/< on it)" sh -c '! '$GREP' -E "(cat|grep|head|tail|sed|awk|source|^\.)[^#]*\.env|<[^#]*\.env" '$S
check "init-api-env.sh is executable" test -x "$S"
fake=$(mktemp -d "$ROOT/.gates/tmp/t31e-fake-XXXXXX"); mkdir -p "$fake/bin" "$fake/api"
printf '#!/bin/sh\nprintf "%%s\\n" "$*" >> "%s/docker.log"\n' "$fake" > "$fake/bin/docker"; chmod +x "$fake/bin/docker"
printf 'APP_KEY=\n' > "$fake/api/.env.example"
check "init creates api/.env from the example when absent" sh -c '( cd "$0" && PATH="$0/bin:$PATH" sh "$1/$2" ) && cmp -s "$0/api/.env" "$0/api/.env.example"' "$fake" "$ROOT" "$S"
check "init ran key:generate exactly once with -T and --force" sh -c 'test "$(wc -l < "$0/docker.log")" -eq 1 && '$GREP' -q -- "exec -T .*key:generate --force" "$0/docker.log"' "$fake"
printf 'SENTINEL_KEEP_ME=0000\n' > "$fake/api/.env"
check "init leaves an existing api/.env byte-identical and calls no docker" sh -c '( cd "$0" && PATH="$0/bin:$PATH" sh "$1/$2" ) && test "$(cat "$0/api/.env")" = "SENTINEL_KEEP_ME=0000" && test "$(wc -l < "$0/docker.log")" -eq 1' "$fake" "$ROOT" "$S"
check "init fails when docker key:generate fails (no masking)" sh -c 'f=$(mktemp -d "$3/.gates/tmp/t31e-fail-XXXXXX"); mkdir -p "$f/bin" "$f/api"; printf "#!/bin/sh\nexit 3\n" > "$f/bin/docker"; chmod +x "$f/bin/docker"; : > "$f/api/.env.example"; ! ( cd "$f" && PATH="$f/bin:$PATH" sh "$0/$1" )' "$ROOT" "$S" x "$ROOT"

# 7. R97 freshness expression: stale scorecard is not newer than the marker; equal or older fails, newer passes.
touch -d '2020-01-01 00:00:00' "$OUT/old.json"; : > "$OUT/marker"
check "a pre-existing scorecard fails the freshness test" sh -c '! test "$0" -nt "$1"' "$OUT/old.json" "$OUT/marker"
touch -d '2030-01-01 00:00:00' "$OUT/new.json"
check "a scorecard written after the marker passes the freshness test" test "$OUT/new.json" -nt "$OUT/marker"
check "T31.sh creates the marker after its own scorecard-independent steps and before make gates" sh -c 'a=$('$GREP' -n "T31-start.marker$" tests/p0/T31.sh | head -1 | cut -d: -f1); b=$('$GREP' -n "^make gates" tests/p0/T31.sh | cut -d: -f1); test -n "$a" && test "$a" -lt "$b"'
finish
