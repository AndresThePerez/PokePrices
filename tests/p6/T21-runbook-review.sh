#!/bin/sh
# Review tests for the deploy runbook's new commands and variables (docs/DEPLOY.md steps 2, 5 and 14, deploy/).
# Run from the repo root: TIER=task sh tests/p0/run.sh tests/p6/T21-runbook-review.sh
# Fake names and values only, in a scratch directory under .gates/tmp; nothing touches a real .env or a server.
. tests/p0/lib.sh

ROOT=$(pwd)
DOC=docs/DEPLOY.md
W=$ROOT/.gates/tmp/p6-T21r
rm -rf "$W"
mkdir -p "$W/shim"

# item4: the text of step 14 item 4 (from "4. Switch" to "5. Run"), joined.
item4() { awk '/^## / { f = ($0 ~ /^## Step 14[.]/) } f' "$DOC" | awk '/^4[.] Switch/ { f = 1 } /^5[.] Run/ { f = 0 } f'; }
# cmd PATTERN: the first fenced-code line of item 4 that contains the fixed string, indentation removed.
cmd() { item4 | grep -F -e "$1" | head -n 1 | sed 's/^ *//'; }

check "1 step 2 prints both image ids with one filtered docker images call, and no two-repository form is left anywhere" sh -c '
  grep -Fq "docker images --format '"'"'{{.Repository}}:{{.Tag}} {{.ID}}'"'"' --filter '"'"'reference=pokeprices-*:<tag>'"'"'" docs/DEPLOY.md &&
  ! grep -Fq "pokeprices-api pokeprices-web" docs/DEPLOY.md'

check "2 the recreate warning sits in step 14 item 4 and names all three stateful services, the dry run and the cache's empty start" sh -c '
  b=$(awk "/^## / { f = (\$0 ~ /^## Step 14[.]/) } f" docs/DEPLOY.md | awk "/^4[.] Switch/ { f = 1 } /^5[.] Run/ { f = 0 } f" | tr "\n" " " | tr -s " ")
  for p in "postgres, redis-queue and redis-cache" "--dry-run" "starts empty" "pgdata" "redisqueue"; do
    printf "%s\n" "$b" | grep -Fq -e "$p" || { echo "missing $p"; exit 1; }
  done'

check "3 each Discord variable is in the example once with an empty value and in x-app-env once with an empty default" sh -c '
  for k in DISCORD_TEST_WEBHOOK_URL PRICE_JOB_DISCORD_WEBHOOK_URL; do
    test "$(grep -c "^$k=" deploy/.env.example)" = 1 || { echo "example count $k"; exit 1; }
    test "$(grep -Fxc "$k=" deploy/.env.example)" = 1 || { echo "example not empty $k"; exit 1; }
    test "$(awk "/^services:/ { exit } { print }" deploy/stack.server.yml | grep -Fxc "  $k: \${$k:-}")" = 1 || { echo "stack $k"; exit 1; }
  done'

# 4. The runbook's own add command, taken from the text, run on a fake .env: idempotent, no duplicate, mode and other lines kept.
ADD=$(cmd 'grep -q "^$VAR=" .env || sed -i')
mkdir -p "$W/add"
cat > "$W/add/.env.example" <<'EOF'
IMAGE_TAG=1
FAKE_HOOK_URL=
FAKE_SECRET=change-me
EOF
printf 'IMAGE_TAG=deploy-x\nFAKE_HOOK_URL=sentinel-existing' > "$W/add/.env"
chmod 600 "$W/add/.env"
(
  cd "$W/add" || exit 1
  VAR=FAKE_NEW_URL
  printf 'FAKE_NEW_URL=\n' >> .env.example
  : > out.txt
  for v in FAKE_NEW_URL FAKE_NEW_URL FAKE_HOOK_URL FAKE_SECRET; do VAR=$v; eval "$ADD" >> out.txt 2>&1; done
  stat -c '%a' .env > mode.txt
)
check "4 the runbook's add command, run twice and on a name already present, adds each line once, keeps the existing value and prints nothing" sh -c '
  test -n "$0" || exit 1
  cd "$1/add" || exit 1
  test "$(grep -c "^FAKE_NEW_URL=" .env)" = 1 &&
  test "$(grep -c "^FAKE_HOOK_URL=" .env)" = 1 &&
  grep -Fxq "FAKE_HOOK_URL=sentinel-existing" .env &&
  grep -Fxq "IMAGE_TAG=deploy-x" .env &&
  test "$(grep -c "^FAKE_SECRET=change-me$" .env)" = 1 &&
  ! test -s out.txt &&
  test "$(cat mode.txt)" = 600 &&
  test "$(tail -c 1 .env | od -An -c | tr -d " ")" = "\\n"' "$ADD" "$W"

# 5. The runbook's listing command, taken from the text: names only, no value, even for a file that holds sentinel values.
LIST=$(item4 | grep -F "sed -n 's/^" | head -n 1 | sed 's/^ *//')
mkdir -p "$W/list"
cp "$W/add/.env.example" "$W/list/.env.example"
printf 'IMAGE_TAG=sentinel-value-1\nFAKE_HOOK_URL=sentinel-value-2\n' > "$W/list/.env"
(cd "$W/list" && eval "$LIST" > out.txt 2>&1)
check "5 the runbook's listing command names each missing variable and shows no value from the file" sh -c '
  test -n "$0" || exit 1
  cd "$1/list" || exit 1
  printf "missing: FAKE_SECRET\nmissing: FAKE_NEW_URL\n" | cmp -s - out.txt &&
  ! grep -Fq sentinel out.txt' "$LIST" "$W"

# 6. The runbook's presence check, taken from the text, with a shim for docker that runs the inner shell with a fake environment.
cat > "$W/shim/docker" <<'SHIM'
#!/bin/sh
while [ "$#" -gt 0 ] && [ "$1" != sh ]; do shift; done
exec "$@"
SHIM
chmod +x "$W/shim/docker"
LOOP=$(item4 | grep -F 'printenv "$0"' | head -n 1 | sed 's/^ *//')
check "6 the runbook's presence check prints only filled, empty or missing, one per service, and never the value" sh -c '
  test -n "$0" || exit 1
  PATH=$1/shim:$PATH
  export PATH
  a=$(PRICE_JOB_DISCORD_WEBHOOK_URL=sentinel-secret-value sh -c "$0" 2>&1)
  b=$(PRICE_JOB_DISCORD_WEBHOOK_URL= sh -c "$0" 2>&1)
  c=$(env -u PRICE_JOB_DISCORD_WEBHOOK_URL sh -c "$0" 2>&1)
  test "$a" = "filled
filled
filled" &&
  test "$b" = "empty
empty
empty" &&
  test "$c" = "missing
missing
missing"' "$LOOP" "$W"

# 7. No instruction in the new text shows the file or turns on shell tracing.
item4 > "$W/item4.txt"
check "7 step 14 item 4 never cats the .env and never traces the shell" sh -c '
  ! grep -Eq "^ +(sudo +)?(cat|less|more|head|tail|bat|source|[.]) .*[.]env" "$0/item4.txt" &&
  ! grep -Eq "set -x|sh -x|bash -x|xtrace" "$0/item4.txt"' "$W"
check "7 every grep or sed that reads .env in item 4 is quiet (grep -q or -c), edits in place, or only names variables" sh -c '
  ! grep -E "^ +(grep|sed)" "$0/item4.txt" | grep -F " .env" | grep -Fv -e "grep -q" -e "grep -c" -e "sed -i" -e "sed -n '"'"'s/^" | grep -q .' "$W"

rm -rf "$W"
finish
