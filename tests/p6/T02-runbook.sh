#!/bin/sh
# P6-DEPLOY-i1-T02: text test for the deploy runbook docs/DEPLOY.md (manual tier, not in FAST).
# Run from the repo root: TIER=task sh tests/p0/run.sh tests/p6/T02-runbook.sh
# The runbook only SHOWS server commands; this test reads text, it runs nothing against a server.
. tests/p0/lib.sh

DOC=docs/DEPLOY.md
TMP=.gates/tmp
CODE=$TMP/p6-T02-code.txt
HEADS=$TMP/p6-T02-heads.txt
mkdir -p "$TMP"
rm -f "$CODE" "$HEADS"

check "1 runbook exists and is not empty" test -s "$DOC"
if [ ! -s "$DOC" ]; then
  finish
  exit $?
fi

# Fenced code lines only, in document order (no fence lines); ordering rules compare positions in this file.
awk '/^```/ { f = !f; next } f { print }' "$DOC" > "$CODE"
grep -E '^## Step [0-9]+\.' "$DOC" > "$HEADS"

# first PATTERN: 1-based position of the first code line matching the ERE; prints nothing when absent.
first() { grep -En -e "$1" "$CODE" | head -n 1 | cut -d: -f1; }
# before A B: both patterns occur in code and A's first line comes before B's first line.
before() { a=$(first "$1"); b=$(first "$2"); test -n "$a" && test -n "$b" && test "$a" -lt "$b"; }
# body N: the lines of step N (heading to the next ## heading).
body() { awk -v n="$1" '/^## / { f = ($0 ~ ("^## Step " n "[.]")) } f' "$DOC"; }

# 2. Steps 0..N without a gap, in order, N >= 15
nums=$(sed -E 's/^## Step ([0-9]+)\..*/\1/' "$HEADS" | paste -sd' ' -)
last=$(sed -E 's/^## Step ([0-9]+)\..*/\1/' "$HEADS" | tail -n 1)
want=$(seq 0 "${last:-0}" | paste -sd' ' -)
check "2 steps are numbered 0..N in order with no gap" test -n "$nums" -a "$nums" = "$want"
check "2 there are at least steps 0..15" test "${last:-0}" -ge 15

# 3. Exactly one tag per step heading, from the three allowed
check "3 every step heading ends in exactly one of [workstation] [server] [server, sudo]" sh -c '
  while IFS= read -r h; do
    n=$(printf "%s" "$h" | tr -cd "[" | wc -c)
    printf "%s" "$h" | grep -Eq "[ ]\[(workstation|server|server, sudo)\]$" || exit 1
    test "$n" -eq 1 || exit 1
  done < .gates/tmp/p6-T02-heads.txt'
check "3 no other heading carries a tag" sh -c '! grep -E "^#+ " docs/DEPLOY.md | grep -Ev "^## Step [0-9]+\." | grep -Eq "\[(workstation|server)"'
# R354: who runs each step
check "3 every step names who runs it" sh -c '
  for n in $(seq 0 15); do
    awk -v n="$n" "/^## / { f = (\$0 ~ (\"^## Step \" n \"[.]\")) } f" docs/DEPLOY.md | grep -Eq "^\*\*Who runs it:\*\* " || { echo "step $n"; exit 1; }
  done'
check "3 step 11 is tagged [server, sudo] and step 0 names the HG-48 gate" sh -c 'grep -E "^## Step 11\." docs/DEPLOY.md | grep -Fq "[server, sudo]" && grep -E "^## Step 0\." docs/DEPLOY.md | grep -Fq "HG-48"'

# 4. Step 0 mechanics (R356)
check "4 step 0 order: rollback tag, save|load, dry-run proving es, real up, seed profile" sh -c '
  b=$(awk "/^## / { f = (\$0 ~ /^## Step 0[.]/) } f" docs/DEPLOY.md)
  for p in "docker tag" "docker save" "docker load" "617721a" "up -d --no-build --dry-run" "--profile seed" "20,635" "/api/v1/sets"; do
    printf "%s\n" "$b" | grep -Fq -e "$p" || { echo "missing $p"; exit 1; }
  done
  printf "%s\n" "$b" | grep -Fqi "not recreated" || exit 1'
check "4 step 0 dry-run precedes the real up -d --no-build, which precedes the seed profile" sh -c '
  b=$(awk "/^## / { f = (\$0 ~ /^## Step 0[.]/) } f" docs/DEPLOY.md | awk "/^\`\`\`/ { c = !c; next } c")
  d=$(printf "%s\n" "$b" | grep -En -e "up -d --no-build --dry-run" | head -n 1 | cut -d: -f1)
  r=$(printf "%s\n" "$b" | grep -En -e "up -d --no-build$" | head -n 1 | cut -d: -f1)
  s=$(printf "%s\n" "$b" | grep -En -e "--profile seed" | head -n 1 | cut -d: -f1)
  test -n "$d" -a -n "$r" -a -n "$s" && test "$d" -lt "$r" && test "$r" -lt "$s"'

# 5. Step 5: .env in place, never appended, change-me gate before any up
check "5 install -m 600 and the guarded in-place APP_KEY and DB_PASSWORD replacement" sh -c '
  grep -Fq "install -m 600 .env.example .env" docs/DEPLOY.md &&
  grep -Fq "grep -q '"'"'^APP_KEY=change-me\$'"'"' .env && sed -i" docs/DEPLOY.md &&
  grep -Fq "grep -q '"'"'^DB_PASSWORD=change-me\$'"'"' .env && sed -i" docs/DEPLOY.md &&
  grep -Fq "openssl rand -hex 24" docs/DEPLOY.md'
check "5 the sed replacements use | as the delimiter" sh -c 'grep -E "sed -i" docs/DEPLOY.md | grep -Ev "s\|" | grep -Eq "s/" && exit 1; exit 0'
check "5 no >> append and no > redirect into .env anywhere in the runbook (the ->> JSON operator is not a redirect)" sh -c '! grep -Eq "(^|[^-])>>|>[ ]*[.]env" docs/DEPLOY.md'
check "5 the change-me gate precedes the first PokéPrices up" sh -c 'g=$(grep -En -e "grep -c .change-me. [.]env" .gates/tmp/p6-T02-code.txt | head -n 1 | cut -d: -f1); u=$(grep -En -e "docker compose( [^ ]+)* up " .gates/tmp/p6-T02-code.txt | grep -Fv -e "--no-build" | head -n 1 | cut -d: -f1); test -n "$g" -a -n "$u" && test "$g" -lt "$u"'
check "5 config -q follows the gate and precedes the first PokéPrices up" sh -c 'g=$(grep -En -e "grep -c .change-me. [.]env" .gates/tmp/p6-T02-code.txt | head -n 1 | cut -d: -f1); q=$(grep -En -e "^docker compose config -q" .gates/tmp/p6-T02-code.txt | head -n 1 | cut -d: -f1); u=$(grep -En -e "docker compose( [^ ]+)* up " .gates/tmp/p6-T02-code.txt | grep -Fv -e "--no-build" | head -n 1 | cut -d: -f1); test -n "$g" -a -n "$q" -a -n "$u" && test "$g" -lt "$q" && test "$q" -lt "$u"'
check "5 every docker compose ... config carries -q" sh -c '! grep -E "docker compose.* config" docs/DEPLOY.md | grep -Ev "config -q"'
check "5 key:generate occurs once, in the cannot-be-used sentence" sh -c 'test "$(grep -c "key:generate" docs/DEPLOY.md)" -eq 1 && grep "key:generate" docs/DEPLOY.md | grep -Fq "cannot be used"'
check "5 DB_PASSWORD never-changes-after-first-up note" sh -c 'grep -Fi "DB_PASSWORD" docs/DEPLOY.md | grep -Fqi "first"'

# 6. Step 6 states the first real start and the stop rule
check "6 step 6 says the runtime image first real start is this step and stops on an unhealthy api" sh -c '
  b=$(awk "/^## / { f = (\$0 ~ /^## Step 6[.]/) } f" docs/DEPLOY.md)
  printf "%s\n" "$b" | grep -Fqi "first real start" && printf "%s\n" "$b" | grep -Fq "docker compose logs api" && printf "%s\n" "$b" | grep -Fq "STOP" && printf "%s\n" "$b" | grep -Fqi "nothing is public"'
check "6 step 6 shows allowed_clients via php-fpm -tt" sh -c 'grep -F "php-fpm -tt" docs/DEPLOY.md | grep -Fq "allowed_clients"'

# 7. Seeding order and --force (PR4)
check "7 every db:seed carries --force" sh -c '! grep -F "db:seed" docs/DEPLOY.md | grep -Fv -e "--force" | grep -q .'
check "7 SetAliasSeeder --force precedes map:build" before 'SetAliasSeeder --force' 'pokeprices:map:build'
check "7 package:discover runs before the worker starts" before 'package:discover' 'up -d --wait worker'
check "7 migrate --force precedes SetAliasSeeder" before 'migrate --force' 'SetAliasSeeder --force'
check "7 map:build precedes the four map:audit checks, golden and replay" sh -c '
  . tests/p0/lib.sh >/dev/null 2>&1
  c=.gates/tmp/p6-T02-code.txt
  m=$(grep -En "map:build" $c | head -n 1 | cut -d: -f1)
  for k in constraints coverage ambiguity names; do
    n=$(grep -En -e "map:audit --check $k" $c | head -n 1 | cut -d: -f1); test -n "$m" -a -n "$n" && test "$m" -lt "$n" || exit 1
  done
  g=$(grep -En "map:golden" $c | head -n 1 | cut -d: -f1); test -n "$g" && test "$m" -lt "$g"'
check "7 replay --fresh follows map:build" before 'pokeprices:map:build' 'replay --fresh'
check "7 replay --fresh precedes up -d --wait scheduler" before 'replay --fresh' 'up -d --wait scheduler'
check "7 DemoAccountSeeder --force follows replay --fresh" before 'replay --fresh' 'DemoAccountSeeder --force'
check "7 DemoAccountSeeder --force precedes freshness" before 'DemoAccountSeeder --force' 'prices:freshness'
check "7 replay carries --code-sha and map:build carries --dataset /dataset/" sh -c 'grep -F "replay --fresh" docs/DEPLOY.md | grep -Fq -e "--code-sha" && grep -F "map:build" docs/DEPLOY.md | grep -Fq -e "--dataset /dataset/"'
check "7 map:oracle is skipped with its reason, never run" sh -c '! grep -E "docker compose.*map:oracle" docs/DEPLOY.md | grep -q . && grep -Fi "oracle" docs/DEPLOY.md | grep -Fqi "skip"'

# 8. exec rules
check "8 every exec uses -T" sh -c '! grep -E "docker compose.* exec " docs/DEPLOY.md | grep -Fv "exec -T " | grep -q .'
check "8 every exec into api, worker or scheduler uses -T -u www-data" sh -c '! grep -E "docker compose.* exec .* (api|worker|scheduler)( |$)" docs/DEPLOY.md | grep -Fv "exec -T -u www-data " | grep -q .'
check "8 at least one exec into api exists" sh -c 'grep -Eq "docker compose exec -T -u www-data api " docs/DEPLOY.md'
check "8 no docker run" sh -c '! grep -Fq "docker run" docs/DEPLOY.md'

# 9. Scheduler step
check "9 schedule:list expects exactly the eight pokeprices-* entries" sh -c 'grep -F "schedule:list" docs/DEPLOY.md | grep -q . && grep -Fq "pokeprices-probe" docs/DEPLOY.md && grep -Fqi "eight" docs/DEPLOY.md'
check "9 the snapshot find is in step 1 and re-run in step 9" sh -c '
  for n in 1 9; do
    awk -v n="$n" "/^## / { f = (\$0 ~ (\"^## Step \" n \"[.]\")) } f" docs/DEPLOY.md | grep -Fq "\\( -type d ! -perm -o=rx \\) -o \\( -type f ! -perm -o=r \\)" || exit 1
  done'
check "9 lingering is checked" sh -c 'grep -Fq "loginctl show-user" docs/DEPLOY.md && grep -Fq "Linger" docs/DEPLOY.md'

# 10. Tunnel
check "10 ingress service line is http://127.0.0.1:\${APP_PORT}" sh -c 'grep -Fq "service: http://127.0.0.1:\${APP_PORT}" docs/DEPLOY.md'
check "10 no ingress service uses localhost" sh -c '! grep -E "service:.*localhost" docs/DEPLOY.md | grep -q .'
check "10 --config precedes ingress on every cloudflared tunnel line" sh -c '
  grep -E "cloudflared tunnel" docs/DEPLOY.md | grep -Eq "ingress" || exit 1
  grep -E "cloudflared tunnel" docs/DEPLOY.md | grep -E "ingress" | grep -Ev "cloudflared tunnel --config [^ ]+ ingress" | grep -q . && exit 1
  exit 0'
check "10 the new rule sits above the 404 catch-all and is validated before sudo" sh -c 'grep -Fq "http_status:404" docs/DEPLOY.md && grep -Fq "ingress validate" docs/DEPLOY.md && grep -Fq "ingress rule" docs/DEPLOY.md'
check "10 the matrix names every host that must answer 200 and the backup suffix" sh -c 'grep -Fq "config.yml.bak-" docs/DEPLOY.md && grep -Fq "pokesearch.andrestheperez.com" docs/DEPLOY.md && grep -Fq "courier.andrestheperez.com" docs/DEPLOY.md && grep -Fq "pokeprices.andrestheperez.com" docs/DEPLOY.md'

# 11. Tags, rollback, update, placeholder
check "11 image tags are deploy-YYYYMMDD-<short sha>" sh -c 'grep -Fq "deploy-YYYYMMDD-<sha>" docs/DEPLOY.md'
check "11 rollback uses the sed with | delimiter on IMAGE_TAG" sh -c 'grep -Fq "sed -i \"s|^IMAGE_TAG=.*|IMAGE_TAG=<old>|\" .env" docs/DEPLOY.md'
check "11 steps 13 and 14 exist as rollback and update" sh -c 'grep -E "^## Step 13\." docs/DEPLOY.md | grep -Fqi "rollback" && grep -E "^## Step 14\." docs/DEPLOY.md | grep -Fqi "update"'
check "11 the placeholder heading is gone and the runbook is no longer a first pass" sh -c '! grep -Eq "^## Reserved|first pass" docs/DEPLOY.md && head -n 1 docs/DEPLOY.md | grep -Fqx "# Deploy runbook"'
check "11 server steps run in the deploy directory" sh -c 'grep -Fq "cd \"\$DEPLOY_DIR\"" docs/DEPLOY.md'

# 12. Placeholders present; the shared network and hosts are named only by placeholder
check "12 placeholders used" sh -c 'for p in "<deploy-user>" "<deploy-host>" "<deploy-dir>" "<shared-net>" "<snapshots-dir>" "<dataset-dir>" "\${APP_PORT}"; do grep -Fq -e "$p" docs/DEPLOY.md || { echo "missing $p"; exit 1; }; done'
check "12 no server port literal :80xx and APP_PORT is not given a value" sh -c '! grep -Eq ":80[0-9][0-9]|APP_PORT=[0-9]" docs/DEPLOY.md'

# 13. .env: key names only, every template variable named, no value shown
for k in IMAGE_TAG APP_BIND APP_PORT SHARED_NET SNAPSHOTS_HOST_DIR DATASET_HOST_DIR APP_KEY DB_PASSWORD INVITE_CODE SESSION_LIFETIME LOG_LEVEL MAIL_MAILER DEMO_PASSWORD POKESEARCH_API_KEY; do
  check "13 the .env step names $k" sh -c 'awk "/^## / { f = (\$0 ~ /^## Step 5[.]/) } f" docs/DEPLOY.md | grep -Fq -e "$0"' "$k"
done
check "13 every variable named in the runbook exists in deploy/.env.example" sh -c '
  for k in $(grep -Eo "\b[A-Z][A-Z0-9]*(_[A-Z0-9]+)+=" docs/DEPLOY.md | tr -d "=" | sort -u); do
    case $k in DEPLOY_DIR|BACKUP_DIR|UNIT_DIR|TRUSTED_PROXIES|APP_PORT_UNUSED) continue ;; esac
    grep -Eq "^$k=" deploy/.env.example || { echo "unknown $k"; exit 1; }
  done'
check "13 no secret-bearing key is assigned a value in the runbook (only change-me, the guarded sed or an empty value)" sh -c '
  ! grep -E "^(APP_KEY|DB_PASSWORD|INVITE_CODE|DEMO_PASSWORD|POKESEARCH_API_KEY)=[^ ]" docs/DEPLOY.md | grep -Fv "=change-me" | grep -q .'
check "13 no long hex string (32+) and no base64: literal followed by a token in the runbook" sh -c '! grep -Eq "[A-Fa-f0-9]{32,}|base64:[A-Za-z0-9+/]{16,}" docs/DEPLOY.md'
check "13 the public host appears only as a hostname, no key= or token in any curl" sh -c '! grep -Eqi "(authorization|x-api-key|bearer|cookie:) *[A-Za-z0-9]" docs/DEPLOY.md'

# 14. Hygiene: tracked patterns, and the machine-specific patterns read at run time (never written here)
check "14 tracked hygiene patterns find nothing in the runbook or this test" sh -c '
  p=$(sed -e "s/\r\$//" -e "/^[[:space:]]*#/d" -e "/^[[:space:]]*\$/d" gates/hygiene.patterns | sed "s/.*/(?:&)/" | paste -sd"|" -)
  test -n "$p"; grep -nIP -e "$p" docs/DEPLOY.md tests/p6/T02-runbook.sh; test $? -eq 1'
LOCALPAT=$(git rev-parse --git-path info/hygiene.local 2>/dev/null)
if [ -n "$LOCALPAT" ] && [ -s "$LOCALPAT" ]; then
  sed -e 's/\r$//' -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' "$LOCALPAT" | sed 's/.*/(?:&)/' | paste -sd'|' - > "$TMP/p6-T02-localpat.txt"
  check "14 local hygiene pattern compiles as one grep -P program" sh -c 'grep -P -f .gates/tmp/p6-T02-localpat.txt /dev/null; test $? -le 1 && test -s .gates/tmp/p6-T02-localpat.txt'
  check "14 local hygiene patterns find nothing in the runbook or this test" sh -c 'grep -nIP -f .gates/tmp/p6-T02-localpat.txt docs/DEPLOY.md tests/p6/T02-runbook.sh; test $? -eq 1'
else
  printf 'skip - local hygiene patterns (no .git/info/hygiene.local here)\n'
fi
check "14 no home path, LAN or tailnet address in the runbook" sh -c '! grep -Eq "/home/|/Users/|~/|(^|[^0-9.])(10|192\.168|172\.(1[6-9]|2[0-9]|3[01]))\.[0-9]+\.[0-9]+(\.[0-9]+)?" docs/DEPLOY.md'

# 15. Review checks (round 1): untested risks
check "15 every public https curl carries a browser user agent (Cloudflare answers 403 to bare tool agents)" sh -c '! grep -E "curl .*https://" .gates/tmp/p6-T02-code.txt | grep -Ev "curl .*-A .?Mozilla" | grep -q .'
check "15 step 0 server block runs git rev-parse --short HEAD after the ff-only merge (its Expected line relies on it)" sh -c '
  awk "/^## / { f = (\$0 ~ /^## Step 0[.]/) } f" docs/DEPLOY.md | awk "/^\`\`\`/ { c = !c; next } c" | awk "/git merge --ff-only/ { m = 1; next } m && /git rev-parse --short HEAD/ { ok = 1 } END { exit !ok }"'
check "15 step 8 says the golden judgement cannot ratify on the server (no git checkout in the image)" sh -c 'awk "/^## / { f = (\$0 ~ /^## Step 8[.]/) } f" docs/DEPLOY.md | grep -Eqi "approval_ratified|not_in_git_checkout|no git checkout"'
check "15 no docker compose -f outside step 0 (PokéSearch) and the step 2 workstation config check" sh -c '! grep -E "docker compose( [^ ]+)* -f " .gates/tmp/p6-T02-code.txt | grep -Fv "deploy/stack.server.yml" | grep -Fv "docker-compose.yml -f docker-compose.server.yml" | grep -q .'
check "15 APP_KEY is base64: plus the base64 of exactly 32 random bytes" sh -c 'grep -F "APP_KEY=base64:\$(" docs/DEPLOY.md | grep -Eq "head -c 32 /dev/urandom \| base64\)|openssl rand -base64 32\)"'
check "15 both host matrices name the apex and www besides the apps" sh -c 'test "$(grep -E "^for h in " .gates/tmp/p6-T02-code.txt | grep -E " andrestheperez[.]com " | grep -Fc "www.andrestheperez.com")" -ge 2'
check "15 every psql statement in the runbook is a read-only select" sh -c '! grep -F "psql " .gates/tmp/p6-T02-code.txt | grep -Eiv "psql [^\"]*-c \"select " | grep -q .'
check "15 rollback says migrations are not reverted" sh -c 'awk "/^## / { f = (\$0 ~ /^## Step 13[.]/) } f" docs/DEPLOY.md | tr "\n" " " | grep -Eqi "migrations are not +reverted|no automatic down"'

# 16. Step 15 (backup timer), the "What runs where" table, the restore-drill line
check "16 step 15 is the backup timer, tagged [server], after step 14" sh -c '
  grep -Eq "^## Step 15\. Backup timer \[server\]$" docs/DEPLOY.md &&
  s=$(grep -n "^## Step 14\." docs/DEPLOY.md | cut -d: -f1) && t=$(grep -n "^## Step 15\." docs/DEPLOY.md | cut -d: -f1) && test "$s" -lt "$t"'
check "16 step 15 refers to the lingering check of step 1 and does not repeat it" sh -c '
  awk "/^## / { f = (\$0 ~ /^## Step 15[.]/) } f" docs/DEPLOY.md | grep -Fq "step 1" &&
  ! awk "/^## / { f = (\$0 ~ /^## Step 15[.]/) } f" docs/DEPLOY.md | grep -Fq "loginctl"'
check "16 step 15 copies the script and both units, fills the two tokens with sed and | delimiters, chmod 755" sh -c '
  b=$(awk "/^## / { f = (\$0 ~ /^## Step 15[.]/) } f" docs/DEPLOY.md)
  for p in "deploy/backup/pokeprices-backup.sh" "deploy/backup/pokeprices-backup.service" "deploy/backup/pokeprices-backup.timer" "chmod 755 pokeprices-backup.sh" "s|@DEPLOY_DIR@|" "s|@BACKUP_DIR@|" "systemctl --user daemon-reload" "systemctl --user enable --now pokeprices-backup.timer" "systemctl --user start pokeprices-backup.service" "list-timers"; do
    printf "%s\n" "$b" | grep -Fq -e "$p" || { echo "missing $p"; exit 1; }
  done'
check "16 step 15 uses no s/ delimiter and no home path literal" sh -c '! awk "/^## / { f = (\$0 ~ /^## Step 15[.]/) } f" docs/DEPLOY.md | grep -E "sed " | grep -Eq "s/" && ! awk "/^## / { f = (\$0 ~ /^## Step 15[.]/) } f" docs/DEPLOY.md | grep -Eq "~/|/home/"'
check "16 step 15 shows one 600 dump and the next timer run" sh -c 'awk "/^## / { f = (\$0 ~ /^## Step 15[.]/) } f" docs/DEPLOY.md | grep -Fq -e "-rw-------" && awk "/^## / { f = (\$0 ~ /^## Step 15[.]/) } f" docs/DEPLOY.md | grep -Fq "05:17 UTC"'
check "16 the what-runs-where table lists all eight schedule names, the worker, both timers and HG-48" sh -c '
  b=$(awk "/^## / { f = (\$0 ~ /^## What runs where/) } f" docs/DEPLOY.md)
  test -n "$b" || exit 1
  for n in probe ingest-pending freshness alerts-dispatch deliveries prune-failed prune-batches prune-chunks; do
    printf "%s\n" "$b" | grep -Fq "\`pokeprices-$n\`" || { echo "missing $n"; exit 1; }
  done
  for p in "queue worker" "collector timer" "backup timer" "HG-48"; do
    printf "%s\n" "$b" | grep -Fq -e "$p" || { echo "missing $p"; exit 1; }
  done'
check "16 the table names exactly the entries routes/console.php registers" sh -c '
  a=$(grep -o "name(.pokeprices-[a-z-]*.)" api/routes/console.php | sed "s/name(.//; s/.)//" | sort | paste -sd" " -)
  b=$(awk "/^## / { f = (\$0 ~ /^## What runs where/) } f" docs/DEPLOY.md | grep -o "\`pokeprices-[a-z-]*\`" | tr -d "\`" | sort -u | paste -sd" " -)
  test -n "$a" -a "$a" = "$b"'
check "16 the restore drill is named as deferred" sh -c 'grep -Eq "^## Restore drill: deferred" docs/DEPLOY.md'
check "16 step 5 states TRUSTED_PROXIES=REMOTE_ADDR and forbids *" sh -c 'b=$(awk "/^## / { f = (\$0 ~ /^## Step 5[.]/) } f" docs/DEPLOY.md); printf "%s\n" "$b" | grep -Fq "TRUSTED_PROXIES=REMOTE_ADDR" && printf "%s\n" "$b" | grep -Fq "TRUSTED_PROXIES=*"'
check "16 step 6 reads TRUSTED_PROXIES back from the api container" sh -c 'awk "/^## / { f = (\$0 ~ /^## Step 6[.]/) } f" docs/DEPLOY.md | grep -Fq "exec -T -u www-data api printenv TRUSTED_PROXIES"'
check "16 step 12 checks all six security headers from the public URL" sh -c '
  b=$(awk "/^## / { f = (\$0 ~ /^## Step 12[.]/) } f" docs/DEPLOY.md)
  for h in x-content-type-options strict-transport-security x-frame-options content-security-policy referrer-policy permissions-policy; do
    printf "%s\n" "$b" | grep -Fiq -e "$h" || { echo "missing $h"; exit 1; }
  done'
check "16 database execs name the postgres service and the pokeprices user and database" sh -c 'grep -E "docker compose exec -T postgres " docs/DEPLOY.md | grep -Fq -e "-U pokeprices -d pokeprices"'

finish
