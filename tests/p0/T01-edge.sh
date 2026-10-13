#!/bin/sh
# P0-i1-T01 edge cases (reviewer): ignore boundaries, untracked scaffolding, remote hygiene,
# commit-message hygiene, compose invariants, runner and library behaviour, guard effectiveness.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
COMPOSE_JSON=$(docker compose -f compose.dev.yml config --format json 2>/dev/null)
cj() { printf '%s' "$COMPOSE_JSON" | jq "$@"; }

# --- 1. ignore boundaries: nested, lookalike and re-included paths -------------------------
for p in api/.env.local web/.env.production api/.env.backup .env.prod .data/a/b.json \
         .gates/AG1.json .superpowers/sdd/x.md api/vendor/autoload.php web/node_modules/.bin/vite \
         web/dist/index.html CLAUDE.md .mex/ROUTER.md; do
  check "ignored: $p" git check-ignore -q --no-index "$p"
done
for p in api/.env.example web/.env.example .env.example gates/manifest-P0.json gates/frozen.sha256 \
         scripts/gates/ag1.py docs/adr/drafts/x.md api/app/Models/User.php api/composer.lock \
         web/package-lock.json web/src/dist.ts web/src/dist/x.ts tests/p0/T01.sh compose.dev.yml \
         Makefile .dockerignore .spectral.yaml .github/workflows/gates.yml openapi/api.json \
         docs/CLAUDE.md api/storage/app/.gitignore; do
  check "not ignored: $p" sh -c '! git check-ignore -q --no-index "$1"' _ "$p"
done

# --- 2. the untracked scaffolding is excluded locally, never through the tracked .gitignore --
check ".gitignore does not mention .mex" sh -c "! $GREP -q 'mex' .gitignore"
check ".gitignore does not mention CLAUDE" sh -c "! $GREP -q 'CLAUDE' .gitignore"
check ".mex is ignored via .git/info/exclude" sh -c 'git check-ignore -v --no-index .mex/ROUTER.md | '"$GREP"' -q "info/exclude"'
check "root CLAUDE.md is ignored via .git/info/exclude" sh -c 'git check-ignore -v --no-index CLAUDE.md | '"$GREP"' -q "info/exclude"'
check ".superpowers is ignored via the tracked .gitignore" sh -c 'git check-ignore -v --no-index .superpowers/x | '"$GREP"' -q "^.gitignore:"'
check "no forbidden path is tracked" sh -c '! git ls-files | '"$GREP"' -E "^(\.mex/|\.superpowers/|\.data/|\.gates/|CLAUDE\.md$)|(^|/)\.env(\.[^/]*)?$" | '"$GREP"' -v -E "(^|/)\.env\.example$"'
check "no tracked file is a symlink" sh -c 'test -z "$(git ls-files -s | '"$GREP"' "^120000")"'

# --- 3. remote hygiene: one origin, one URL, nothing fetched, nothing pushed ----------------
check_out "git remote -v is exactly the fetch and push line of origin" \
  "origin git@github.com:AndresThePerez/PokePrices.git (fetch)|origin git@github.com:AndresThePerez/PokePrices.git (push)" \
  sh -c 'git remote -v | sed "s/[[:space:]]\{1,\}/ /g" | paste -sd"|" -'
check_out "origin has a single url value" 1 sh -c 'git config --get-all remote.origin.url | wc -l | tr -d " "'
check "origin has no separate pushurl" sh -c '! git config --get remote.origin.pushurl'
check "no remote url rewrite (insteadOf) config" sh -c 'test -z "$(git config --get-regexp "^url\\." )"'
check "main's upstream, if set, is origin" sh -c 'r=$(git config --get branch.main.remote); test -z "$r" || test "$r" = origin'
check "no packed remote refs" sh -c '! test -e .git/packed-refs || ! '"$GREP"' -q refs/remotes .git/packed-refs'
check "the local branches are main and p<N>-work branches only" sh -c 'n=0; m=0; for b in $(git for-each-ref --format="%(refname:short)" refs/heads); do if test "$b" = main; then m=1; elif printf "%s\n" "$b" | '"$GREP"' -q -E "^p[0-9]+-work$"; then n=$((n + 1)); else exit 1; fi; done; test "$m" -eq 1 && test "$n" -ge 1'
check "HEAD is a symbolic ref to refs/heads/main or a refs/heads/p<N>-work branch" sh -c 'r=$(git symbolic-ref HEAD) || exit 1; test "$r" = refs/heads/main || printf "%s\n" "$r" | '"$GREP"' -q -E "^refs/heads/p[0-9]+-work$"'
check_out "history has exactly one root commit" 1 sh -c 'git rev-list --max-parents=0 HEAD | wc -l | tr -d " "'
check_out "no tags" 0 sh -c 'git tag | wc -l | tr -d " "'

# --- 4. commit-message hygiene over the whole history --------------------------------------
check "first commit subject is exact" sh -c \
  'test "$(git log --reverse --format=%s | head -n 1)" = "[P0-i1-T01] Initialise the Pok'"$(printf '\303\251')"'Prices repository with ignores and a minimal dev compose file"'
check "every subject carries a [P<n>-i<k>-T<NN>] or [P<n>-i<k>-HG<nn>] prefix" sh -c \
  'test -z "$(git log --format=%s | '"$GREP"' -v -E "^\[P[0-9]+-i[0-9]+-(T[0-9]{2}[a-z]?|HG[0-9]{2})\] .+")"'
check "no commit has a trailer" sh -c 'for c in $(git rev-list HEAD); do test -z "$(git log -1 --format=%B $c | git interpret-trailers --parse)" || exit 1; done'
check "no commit message names a tool, vendor, session or co-author" sh -c \
  '! git log --format="%B" | '"$GREP"' -i -E "claude|anthropic|co-authored|generated with"'
check "no commit message mentions AI" sh -c '! git log --format="%B" | '"$GREP"' -E "(^|[^A-Za-z])AI([^A-Za-z]|$)"'
check "subject is NFC-precomposed (no e + combining acute)" sh -c \
  '! git log --format=%s | '"$GREP"' -q "e$(printf "\314\201")"'

# --- 5. machine-specific names must not appear in any tracked file (R35) --------------------
HP='/ho''me/'; REPOS='~/Repo''sitories'; DOCS='~/Docu''ments'; LAN='192[.]168[.]'
TS='(^|[^0-9.])100[.](6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])[.][0-9]+[.][0-9]+'
BR='(^|[^0-9.])172[.](1[6-9]|2[0-9]|3[01])[.][0-9]+[.][0-9]+'
HOSTS='[A-Za-z0-9-]+[.]ho[m]e([^A-Za-z0-9_]|$)'
check "no home path in tracked files" sh -c '! git ls-files -z | xargs -0 '"$GREP"' -I -n -F -e "$0" -e "$1" -e "$2" --' "$HP" "$REPOS" "$DOCS"
check "no LAN, Tailscale or Docker-bridge address in tracked files" sh -c \
  '! git ls-files -z | xargs -0 '"$GREP"' -I -n -E -e "$0" -e "$1" -e "$2" --' "$LAN" "$TS" "$BR"
check "no .home hostname in tracked files" sh -c '! git ls-files -z | xargs -0 '"$GREP"' -I -n -E -e "$0" --' "$HOSTS"
check "commit metadata has no home path or private address" sh -c \
  '! git log --format="%B%an%ae%cn%ce" | '"$GREP"' -E -e "$0" -e "$1"' "$LAN" "$HP"

# --- 6. compose invariants ------------------------------------------------------------------
check "the postgres service exists" cj -e '.services | has("postgres")'
check_out "postgres image is the exact pinned digest" \
  "postgres:17-alpine@sha256:b0f9560a2de083e2cc7382e75f808c7381a32852a7ec49117deedb300e552b24" cj -r .services.postgres.image
check "no service is privileged or host-networked" cj -e \
  '[.services[] | select(.privileged == true or .network_mode == "host")] | length == 0'
check "no service publishes a port on a non-loopback address" cj -e \
  '[.services[] | (.ports // [])[] | select((.host_ip // "") != "127.0.0.1")] | length == 0'
check "postgres publishes no port at all" cj -e '(.services.postgres.ports // []) | length == 0'
check "postgres sets no POSTGRES_PASSWORD" cj -e '.services.postgres.environment | has("POSTGRES_PASSWORD") | not'
check "postgres data directory is the named volume pgdata, not a bind mount" cj -e \
  '[.services.postgres.volumes[] | select(.target == "/var/lib/postgresql/data")] | length == 1 and .[0].type == "volume" and .[0].source == "pgdata"'
check "pgdata is declared as a top-level volume" cj -e '.volumes | has("pgdata")'
check "postgres healthcheck uses pg_isready against the project database" cj -e \
  '.services.postgres.healthcheck.test | join(" ") | test("pg_isready -U pokeprices -d pokeprices")'
check "postgres memory cap is exactly 1 GiB and shm exactly 256 MiB" cj -e \
  '(.services.postgres.mem_limit|tonumber) == 1073741824 and (.services.postgres.shm_size|tonumber) == 268435456'
check "compose.dev.yml has no tab or CR bytes" sh -c '! LC_ALL=C '"$GREP"' -q -P "[\t\r]" compose.dev.yml'
check "running postgres (if any) publishes no host port" sh -c '
  id=$(docker compose -f compose.dev.yml ps -q postgres 2>/dev/null)
  [ -z "$id" ] && exit 0
  docker compose -f compose.dev.yml ps --format json postgres | jq -e "([.Publishers[]? | select(.PublishedPort != 0)] | length) == 0"'

# --- 7. the guard is installed locally, configured by nothing, and actually blocks ----------
check "pre-commit hook is executable" test -x .git/hooks/pre-commit
check "hook runs agent-precommit and bypasses nothing" sh -c \
  "$GREP -q 'agent-precommit\" run' .git/hooks/pre-commit && ! $GREP -q -e 'no-verify' -e 'SKIP_TESTS' .git/hooks/pre-commit"
check "no hook is chained" sh -c '! test -e .git/hooks/pre-commit.chained'
check "no agent-precommit.* git config exists" sh -c 'test -z "$(git config --get-regexp "^agent-precommit\\.")"'
check "core.hooksPath is not set" sh -c '! git config --get core.hooksPath'
check "hook is not tracked" sh -c 'test -z "$(git ls-files .git)"'

tmp=$(mktemp -d "${TMPDIR:-/tmp}/pp-edge.XXXXXX") || exit 1
(
  cd "$tmp" && git init -q -b main . && cp "$OLDPWD/.git/hooks/pre-commit" .git/hooks/pre-commit
  AWS=$(printf 'AK%sA%s' I 0000000000000000)
  printf 'key=%s\n' "$AWS" > leak.txt && git add leak.txt
  out=$(git -c user.name=t -c user.email=t@example.invalid commit -q -m 'x' 2>&1); rc=$?
  printf '%s' "$AWS" > "$tmp/.sentinel"; printf '%s' "$out" > "$tmp/.out"; echo "$rc" > "$tmp/.rc"
  git reset -q; rm leak.txt; echo hello > ok.txt && git add ok.txt
  git -c user.name=t -c user.email=t@example.invalid commit -q -m 'ok' >/dev/null 2>&1; echo "$?" > "$tmp/.rc_ok"
)
check "guard blocks a commit that adds a key-shaped value" sh -c 'test "$(cat "$0/.rc")" -ne 0' "$tmp"
check "guard output names the rule and never prints the value" sh -c \
  "$GREP -q 'aws-access-key-id' \"\$0/.out\" && ! $GREP -q -F -f \"\$0/.sentinel\" \"\$0/.out\"" "$tmp"
check "guard lets a clean commit through" sh -c 'test "$(cat "$0/.rc_ok")" -eq 0' "$tmp"

# --- 8. runner and library behave as a test harness must -----------------------------------
mkdir -p "$tmp/r/tests/p0" && cp tests/p0/run.sh tests/p0/lib.sh "$tmp/r/tests/p0/"
printf '%s\n' '. tests/p0/lib.sh' 'check "boom" false' 'finish' > "$tmp/r/tests/p0/T90.sh"
check "runner exits non-zero when one acceptance script fails" sh -c '! sh "$0/r/tests/p0/run.sh"' "$tmp"
check "runner names each script it runs" sh -c 'sh "$0/r/tests/p0/run.sh" | '"$GREP"' -qx "# tests/p0/T90.sh"' "$tmp"
printf '%s\n' 'exit 3' > "$tmp/r/tests/p0/T91.sh"
check "runner counts a crashing script (exit 3) as failure" sh -c '! sh "$0/r/tests/p0/run.sh"' "$tmp"
printf '%s\n' '. tests/p0/lib.sh' 'check "fine" true' 'finish' > "$tmp/r/tests/p0/T90.sh"
printf '%s\n' '. tests/p0/lib.sh' 'check "fine" true' 'finish' > "$tmp/r/tests/p0/T91.sh"
check "runner exits zero when every script passes" sh "$tmp/r/tests/p0/run.sh"
check "runner works from an unrelated working directory" sh -c 'cd / && sh "$0/r/tests/p0/run.sh"' "$tmp"
printf '%s\n' 'syntax error (((' > "$tmp/r/tests/p0/T92.sh"
check "runner counts an unparsable script as failure" sh -c '! sh "$0/r/tests/p0/run.sh"' "$tmp"
# library
check_out "check on a failing command increments the failure counter" "not ok - d|1 failure(s)" \
  sh -c '. tests/p0/lib.sh; { check d false; finish; } | paste -sd"|" -'
check_out "check preserves arguments containing spaces and globs" "ok - d|0 failure(s)" \
  sh -c '. tests/p0/lib.sh; { check d test "a b*" = "a b*"; finish; } | paste -sd"|" -'
check_out "check_out reports want and got on mismatch" "not ok - d (want [a b], got [a  b])" \
  sh -c '. tests/p0/lib.sh; check_out d "a b" echo "a  b" | head -n 1'
check "finish returns non-zero after a failure" sh -c '. tests/p0/lib.sh; check d false >/dev/null; ! finish >/dev/null'
check "finish returns zero when nothing failed" sh -c '. tests/p0/lib.sh; check d true >/dev/null; finish >/dev/null'

# --- 9. script hygiene ----------------------------------------------------------------------
check "acceptance scripts and runner are executable in the index" sh -c \
  'test -z "$(git ls-files -s "tests/p0/run.sh" "tests/p0/T*.sh" | '"$GREP"' -v "^100755")"'
check "every tests/p0 script parses under bash --posix" sh -c 'for f in tests/p0/*.sh; do bash --posix -n "$f" || exit 1; done'
check "tracked text files have no CR bytes" sh -c '! git ls-files -z | xargs -0 '"$GREP"' -I -l "$(printf "\r")" --'
check "the T01 files end with a newline" sh -c \
  'for f in .gitignore compose.dev.yml tests/p0/*; do [ -z "$(tail -c1 "$f")" ] || exit 1; done'
check "the accent in tracked text is precomposed UTF-8 (c3 a9)" sh -c \
  '! git ls-files -z | xargs -0 '"$GREP"' -I -l "e$(printf "\314\201")" --'

case $tmp in "${TMPDIR:-/tmp}"/pp-edge.*) find "$tmp" -depth -delete ;; esac
finish
