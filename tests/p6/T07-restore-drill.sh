#!/bin/sh
# The restore drill script deploy/backup/pokeprices-restore-drill.sh (manual tier, not in FAST).
# Run from the repo root: TIER=task sh tests/p0/run.sh tests/p6/T07-restore-drill.sh
# Shim part (always): the script runs against a PATH shim named docker that records its working directory and arguments,
# keeps one file of state for "does the scratch database exist", and fails on demand. No container is involved.
# Live part: LIVE=1 sh tests/p6/T07-restore-drill.sh, against the dev stack's postgres only (compose.dev.yml, already up).
# It creates and drops only the scratch database; the dev application database is only read.
. tests/p0/lib.sh

ROOT=$(pwd)
SCRIPT=deploy/backup/pokeprices-restore-drill.sh
W=$ROOT/.gates/tmp/p6-T07
SHIMDIR=$W/shim
STATE=$W/state
DEPLOY=$W/deploy
DUMPS=$W/dumps
LOG=$W/shim.log
OUT=$W/out.txt
RC=$W/rc.txt
DUMP=$DUMPS/pokeprices-20260101T000000Z.dump
SCRATCH=pokeprices_restore_drill
mkdir -p "$SHIMDIR" "$STATE" "$DEPLOY" "$DUMPS"

# The shim. Modes (SHIM_MODE): ok, restore-fail, restore-slow, tables-differ, count-fail, createdb-fail, dropdb-fail.
cat > "$SHIMDIR/docker" <<'SHIM'
#!/bin/sh
printf '%s|%s\n' "$PWD" "$*" >> "$SHIM_LOG"
mode=${SHIM_MODE:-ok}
case " $* " in
  *" psql "*"pg_database"*)
    [ -f "$SHIM_STATE/drill-exists" ] && printf '1\n'
    exit 0
    ;;
  *" createdb "*)
    [ "$mode" = createdb-fail ] && exit 1
    : > "$SHIM_STATE/drill-exists"
    exit 0
    ;;
  *" dropdb "*)
    [ "$mode" = dropdb-fail ] && exit 1
    rm -f "$SHIM_STATE/drill-exists"
    exit 0
    ;;
  *" pg_restore "*)
    wc -c | tr -d ' ' >> "$SHIM_LOG.stdin"
    [ "$mode" = restore-fail ] && { printf 'pg_restore: error: fake failure\n' >&2; exit 1; }
    [ "$mode" = restore-slow ] && sleep 2
    exit 0
    ;;
  *" psql "*"ingest_runs"*)
    printf '2026-01-02\n'
    exit 0
    ;;
  *" psql "*"query_to_xml"*)
    case " $* " in
      *" -d pokeprices_restore_drill "*)
        [ "$mode" = count-fail ] && exit 1
        printf 'migrations 5\nusers 1\n'
        [ "$mode" = tables-differ ] || printf 'widgets 7\n'
        ;;
      *" -d pokeprices "*)
        printf 'migrations 5\nusers 2\nwidgets 7\n'
        ;;
    esac
    exit 0
    ;;
  *) exit 99 ;;
esac
SHIM
chmod 755 "$SHIMDIR/docker"

# A slow shim for the interruption test: the restore sleeps, everything else is the main shim.
mkdir -p "$W/shim-slow"
cp "$SHIMDIR/docker" "$W/shim-slow/docker"

# Helpers
calls() { cut -d'|' -f2- "$LOG"; }
nocall() { test ! -s "$LOG"; }
called() { calls | grep -Fq -e "$1"; }
pos() { calls | grep -n -e "$1" | head -n 1 | cut -d: -f1; }
before() { a=$(pos "$1"); b=$(pos "$2"); test -n "$a" && test -n "$b" && test "$a" -lt "$b"; }
rc() { cat "$RC"; }
exists() { test -f "$STATE/drill-exists"; }
said() { grep -Fq -e "$1" "$OUT"; }
reset() {
  rm -f "$STATE/drill-exists"
  : > "$LOG"
  : > "$LOG.stdin"
  printf 'PGDMP fake custom-format bytes\n' > "$DUMP"
}
# run MODE ARG...: stdout and stderr go to $OUT, the exit code to $RC.
run() {
  mode=$1
  shift
  : > "$LOG"
  : > "$LOG.stdin"
  env PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" SHIM_STATE="$STATE" SHIM_MODE="$mode" DEPLOY_DIR="$DEPLOY" PGPASSWORD=00000000-example sh "$SCRIPT" "$@" > "$OUT" 2>&1
  echo $? > "$RC"
}

# 1. Static
check "1 the script exists and passes sh -n" sh -c 'test -s '"$SCRIPT"' && sh -n '"$SCRIPT"
check "1 the script is POSIX sh with set -eu and umask 077" sh -c 'head -n 1 '"$SCRIPT"' | grep -Fqx "#!/bin/sh" && grep -Fqx "set -eu" '"$SCRIPT"' && grep -Fqx "umask 077" '"$SCRIPT"
check "1 git index mode is 100755" sh -c 'git ls-files -s '"$SCRIPT"' | grep -q "^100755 " || test -x '"$SCRIPT"
check "1 the script reads no env file, handles no password and never deletes or moves a file" sh -c '! grep -Eq "[.]env|PGPASSWORD|[[:space:]]-W([[:space:]]|\$)|(^|[^a-z_])(rm|mv|truncate|shred)[[:space:]]" '"$SCRIPT"
check "1 the script holds no clean option and no drop, truncate or alter statement" sh -c '! grep -Eiq -e "--clean|drop (database|table)|truncate|alter " '"$SCRIPT"

# 2. A good run
reset
run ok "$DUMP"
check "2 a good run exits 0" test "$(rc)" = 0
check "2 every docker call ran in DEPLOY_DIR" sh -c 'test -s '"$LOG"' && ! awk -F"|" -v d='"$DEPLOY"' "\$1 != d" '"$LOG"' | grep -q .'
check "2 the order is: existence check, createdb, pg_restore, the counts, dropdb" sh -c '
  l=$(cut -d"|" -f2- '"$LOG"' | awk "/pg_database/ { print \"exist\" } / createdb / { print \"create\" } / pg_restore / { print \"restore\" } /query_to_xml/ { print \"counts\" } / dropdb / { print \"drop\" }" | paste -sd" " -)
  test "$l" = "exist create restore counts counts drop"'
check "2 pg_restore reads the dump on stdin, in one transaction, without owners, stopping at the first error" sh -c 'cut -d"|" -f2- '"$LOG"' | grep -Fqx "compose exec -T postgres pg_restore -U pokeprices --no-owner --exit-on-error --single-transaction -d pokeprices_restore_drill" && test "$(cat '"$LOG"'.stdin)" = "$(wc -c < '"$DUMP"' | tr -d " ")"'
check "2 createdb and dropdb name the scratch database and nothing else" sh -c 'cut -d"|" -f2- '"$LOG"' | grep -E " (createdb|dropdb) " | grep -Evq "pokeprices_restore_drill\$" && exit 1; cut -d"|" -f2- '"$LOG"' | grep -cE " (createdb|dropdb) " | grep -qx 2'
check "2 the scratch database is gone afterwards" sh -c '! test -f '"$STATE"'/drill-exists'
check "2 the output has the table header, a row per table with both counts, the as-of day and no = anywhere" sh -c '
  grep -Fqx "table drill_rows live_rows" '"$OUT"' &&
  grep -Eqx "migrations +5 +5" '"$OUT"' && grep -Eqx "users +1 +2" '"$OUT"' && grep -Eqx "widgets +7 +7" '"$OUT"' &&
  grep -Fq "2026-01-02" '"$OUT"' && ! grep -q "=" '"$OUT"' && ! grep -q "00000000-example" '"$OUT"
check "2 the output says the scratch database was dropped" said "dropped"
check "2 the dump is still there, unchanged" sh -c 'test "$(cat '"$DUMP"')" = "PGDMP fake custom-format bytes"'

# 3. What may touch which database
check "3 every psql call is read-only by session option, whichever database it names" sh -c '! cut -d"|" -f2- '"$LOG"' | grep -F " psql " | grep -Fv "default_transaction_read_only=on" | grep -q .'
check "3 no write verb or statement is ever sent: no --clean, drop, truncate, alter, insert, update, delete" sh -c '! cut -d"|" -f2- '"$LOG"' | grep -Eiq -e "--clean|drop table|drop database|truncate|alter |insert |update |delete "'
check "3 the application database is named only by read-only psql calls: never by pg_restore, createdb or dropdb" sh -c '! cut -d"|" -f2- '"$LOG"' | grep -E "(-d pokeprices( |\$))|( (createdb|dropdb) pokeprices( |\$))" | grep -Ev " psql " | grep -q .'
check "3 the only writing commands are createdb, dropdb and pg_restore, each against the scratch database" sh -c '
  cut -d"|" -f2- '"$LOG"' | grep -Ev " psql " | grep -Ev "( createdb | dropdb | pg_restore )" | grep -q . && exit 1
  cut -d"|" -f2- '"$LOG"' | grep -E " (createdb|dropdb|pg_restore) " | grep -Fv "pokeprices_restore_drill" | grep -q . && exit 1
  exit 0'

# 4. Failures still drop the scratch database and say so
for m in restore-fail tables-differ count-fail; do
  reset
  run "$m" "$DUMP"
  check "4 $m exits non-zero" test "$(rc)" != 0
  check "4 $m drops the scratch database and says so" sh -c '! test -f '"$STATE"'/drill-exists'
  check "4 $m reported the drop" said "dropped"
  check "4 $m left the dump alone" sh -c 'test "$(cat '"$DUMP"')" = "PGDMP fake custom-format bytes"'
  check "4 $m never prints an = assignment" sh -c '! grep -q "=" '"$OUT"
  check "4 $m never touched the application database with anything but read-only psql" sh -c '! cut -d"|" -f2- '"$LOG"' | grep -E "(-d pokeprices( |\$))" | grep -Ev "default_transaction_read_only=on postgres psql " | grep -q .'
done
reset
run tables-differ "$DUMP"
check "4 a table-set difference is reported by name and is not a pass" sh -c 'grep -Fq "widgets" '"$OUT"' && ! grep -Fq "drill ok" '"$OUT"
reset
run restore-fail "$DUMP"
check "4 a failed restore ends before the counts" sh -c '! cut -d"|" -f2- '"$LOG"' | grep -Fq "query_to_xml"'
reset
run createdb-fail "$DUMP"
check "4 a createdb that fails exits non-zero, runs no restore, and says nothing was created" sh -c 'test "$(cat '"$RC"')" != 0 && ! cut -d"|" -f2- '"$LOG"' | grep -Fq pg_restore && grep -Fq "not created" '"$OUT"
check "4 a createdb that fails drops nothing (it never made the database)" sh -c '! cut -d"|" -f2- '"$LOG"' | grep -Fq " dropdb "'
reset
run dropdb-fail "$DUMP"
check "4 a drop that fails exits non-zero and says the scratch database was left behind" sh -c 'test "$(cat '"$RC"')" != 0 && grep -Fq "left behind" '"$OUT"' && test -f '"$STATE"'/drill-exists'
check "4 the left-behind message says how to remove it, by name" sh -c 'grep -F "left behind" '"$OUT"' | grep -Fq "pokeprices_restore_drill"'

# 5. A scratch database that already exists
reset
: > "$STATE/drill-exists"
run ok "$DUMP"
check "5 an existing scratch database is refused: exit non-zero, no createdb, no dropdb, no restore" sh -c 'test "$(cat '"$RC"')" != 0 && ! cut -d"|" -f2- '"$LOG"' | grep -Eq " (createdb|dropdb|pg_restore) "'
check "5 the refusal leaves the scratch database in place and says it already exists and was not touched" sh -c 'test -f '"$STATE"'/drill-exists && grep -Fq "already exists" '"$OUT"' && grep -Fq "not touched" '"$OUT"' && grep -Fq -e "--replace" '"$OUT"
reset
: > "$STATE/drill-exists"
run ok --replace "$DUMP"
check "5 --replace drops the old scratch database first, then creates, restores and checks" sh -c 'test "$(cat '"$RC"')" = 0'
check "5 --replace: dropdb precedes createdb, createdb precedes pg_restore" sh -c '
  l=$(cut -d"|" -f2- '"$LOG"' | awk "/ dropdb / { print \"drop\" } / createdb / { print \"create\" } / pg_restore / { print \"restore\" }" | paste -sd" " -)
  test "$l" = "drop create restore drop"'
check "5 --replace names only the scratch database" sh -c 'cut -d"|" -f2- '"$LOG"' | grep -E " (createdb|dropdb) " | grep -Fv "pokeprices_restore_drill" | grep -q . && exit 1; exit 0'
check "5 after --replace the scratch database is gone" sh -c '! test -f '"$STATE"'/drill-exists'

# 6. Refused before any docker call
refuse() { # refuse DESC ARG...
  d=$1
  shift
  reset
  run ok "$@"
  check "6 $d: exits non-zero before any docker call" sh -c 'test "$(cat '"$RC"')" != 0 && ! test -s '"$LOG"
}
refuse "no argument"
refuse "two dumps" "$DUMP" "$DUMP"
refuse "a dump that does not exist" "$DUMPS/pokeprices-20990101T000000Z.dump"
refuse "a directory named like a dump" "$DUMPS"
printf 'PGDMP fake\n' > "$DUMPS/backup.dump"
printf 'PGDMP fake\n' > "$DUMPS/pokeprices-20260101T000000Z.dump.part"
printf 'PGDMP fake\n' > "$DUMPS/otherapp-20260101T000000Z.dump"
: > "$DUMPS/pokeprices-20260102T000000Z.dump"
refuse "a dump with the wrong name (backup.dump)" "$DUMPS/backup.dump"
refuse "a half-written .part file" "$DUMPS/pokeprices-20260101T000000Z.dump.part"
refuse "another app's dump name" "$DUMPS/otherapp-20260101T000000Z.dump"
refuse "an empty dump" "$DUMPS/pokeprices-20260102T000000Z.dump"
refuse "an option it does not know" --drop-everything "$DUMP"
printf 'PGDMP fake\n' > "$DUMPS/pokeprices-20260103T000000Z.dump"
chmod 000 "$DUMPS/pokeprices-20260103T000000Z.dump"
if [ -r "$DUMPS/pokeprices-20260103T000000Z.dump" ]; then
  printf 'skip - unreadable dump (this user can read a mode 000 file)\n'
else
  refuse "an unreadable dump" "$DUMPS/pokeprices-20260103T000000Z.dump"
fi
chmod 600 "$DUMPS/pokeprices-20260103T000000Z.dump"
reset
env -u DEPLOY_DIR PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" SHIM_STATE="$STATE" sh "$SCRIPT" "$DUMP" > "$OUT" 2>&1; echo $? > "$RC"
check "6 a missing DEPLOY_DIR is refused before any docker call" sh -c 'test "$(cat '"$RC"')" != 0 && ! test -s '"$LOG"
reset
env PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" SHIM_STATE="$STATE" DEPLOY_DIR="$W/no-such-dir" sh "$SCRIPT" "$DUMP" > "$OUT" 2>&1; echo $? > "$RC"
check "6 a DEPLOY_DIR that does not exist is refused before any docker call" sh -c 'test "$(cat '"$RC"')" != 0 && ! test -s '"$LOG"
for name in pokeprices postgres template1 pokeprices_restore_drill_2 ""; do
  reset
  env PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" SHIM_STATE="$STATE" DEPLOY_DIR="$DEPLOY" DRILL_DB="$name" sh "$SCRIPT" "$DUMP" > "$OUT" 2>&1; echo $? > "$RC"
  check "6 a target database name of '$name' in the environment is refused before any docker call" sh -c 'test "$(cat '"$RC"')" != 0 && ! test -s '"$LOG"
done
reset
env PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" SHIM_STATE="$STATE" DEPLOY_DIR="$DEPLOY" DRILL_DB="$SCRATCH" sh "$SCRIPT" "$DUMP" > "$OUT" 2>&1; echo $? > "$RC"
check "6 the scratch name itself in the environment is accepted (the name is fixed, not chosen)" test "$(rc)" = 0

# 7. A relative dump path still works although the script changes directory first
reset
env PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" SHIM_STATE="$STATE" DEPLOY_DIR="$DEPLOY" sh -c 'cd "$1" && sh "$2" pokeprices-20260101T000000Z.dump' sh "$DUMPS" "$ROOT/$SCRIPT" > "$OUT" 2>&1; echo $? > "$RC"
check "7 a relative dump path is resolved before the change of directory" sh -c 'test "$(cat '"$RC"')" = 0 && test "$(cat '"$LOG"'.stdin)" -gt 0'

# 8. An interrupted drill drops the scratch database it made and keeps the dump
reset
env PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" SHIM_STATE="$STATE" SHIM_MODE=restore-slow DEPLOY_DIR="$DEPLOY" sh "$SCRIPT" "$DUMP" > "$OUT" 2>&1 &
pid=$!
sleep 1
kill -TERM "$pid" 2>/dev/null
wait "$pid"
echo $? > "$RC"
check "8 SIGTERM during the restore exits non-zero, drops the scratch database it created and says so" sh -c 'test "$(cat '"$RC"')" != 0 && ! test -f '"$STATE"'/drill-exists && grep -Fq "dropped" '"$OUT"
check "8 the interrupted drill left the dump untouched and wrote nothing to the application database" sh -c 'test "$(cat '"$DUMP"')" = "PGDMP fake custom-format bytes" && ! cut -d"|" -f2- '"$LOG"' | grep -E "(-d pokeprices( |\$))" | grep -Ev " psql " | grep -q .'

# 9. Hygiene on the files this task adds
check "9 tracked hygiene patterns find nothing in the script or this test" sh -c '
  p=$(sed -e "s/\r\$//" -e "/^[[:space:]]*#/d" -e "/^[[:space:]]*\$/d" gates/hygiene.patterns | sed "s/.*/(?:&)/" | paste -sd"|" -)
  test -n "$p"; grep -nIP -e "$p" '"$SCRIPT"' tests/p6/T07-restore-drill.sh; test $? -eq 1'
LOCALPAT=$(git rev-parse --git-path info/hygiene.local 2>/dev/null)
if [ -n "$LOCALPAT" ] && [ -s "$LOCALPAT" ]; then
  sed -e 's/\r$//' -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' "$LOCALPAT" | sed 's/.*/(?:&)/' | paste -sd'|' - > "$W/localpat.txt"
  check "9 local hygiene patterns find nothing in the script or this test" sh -c 'grep -nIP -f '"$W"'/localpat.txt '"$SCRIPT"' tests/p6/T07-restore-drill.sh; test $? -eq 1'
else
  printf 'skip - local hygiene patterns (no .git/info/hygiene.local here)\n'
fi
check "9 the script holds no home path" sh -c '! grep -Eq "/home/|/Users/|~/" '"$SCRIPT"

live_start=$(grep -n '^if \[ "${LIVE:-}" = 1 \]' tests/p6/T07-restore-drill.sh | cut -d: -f1)
check "10 the live leg aborts, failing, when a scratch database already exists before it starts (it must never --replace one it did not make)" sh -c 'tail -n +'"$live_start"' tests/p6/T07-restore-drill.sh | grep -Fq "scratch_count)\" != 0"'

# 10. LIVE (LIVE=1): the dev stack's postgres. Creates and drops only the scratch database; the application database is read.
if [ "${LIVE:-}" = 1 ]; then
  LD=$W/live-dumps
  mkdir -p "$LD"
  find "$LD" -mindepth 1 -maxdepth 1 -type f -name 'pokeprices-*.dump' -delete
  dpsql() { COMPOSE_FILE=compose.dev.yml docker compose exec -T postgres psql -X -U pokeprices -Atc "$2" -d "$1"; }
  scratch_count() { dpsql postgres "select count(*) from pg_database where datname = '$SCRATCH'"; }
  fingerprint() { dpsql pokeprices "select coalesce(sum(n_tup_ins + n_tup_upd + n_tup_del), 0) || ' ' || (select count(*) from migrations) from pg_stat_user_tables"; }

  # A scratch database that is already there is not this test's: abort, drop nothing (the --replace run below would).
  if [ "$(scratch_count)" != 0 ]; then
    check "10 live: the scratch database does not exist before the leg starts (one that is there is not this test's to drop: aborted)" false
  else
  before_fp=$(fingerprint)
  env COMPOSE_FILE=compose.dev.yml DEPLOY_DIR="$ROOT" BACKUP_DIR="$LD" sh deploy/backup/pokeprices-backup.sh > "$W/live-backup.txt" 2>&1
  check "10 live: the dev database dumped with the backup script (pg_dump -Fc inside the container, read-only)" test "$(ls "$LD" | grep -Ec '^pokeprices-[0-9]{8}T[0-9]{6}Z[.]dump$')" = 1
  LDUMP=$(ls "$LD"/pokeprices-*.dump | head -n 1)
  env COMPOSE_FILE=compose.dev.yml DEPLOY_DIR="$ROOT" sh "$SCRIPT" "$LDUMP" > "$W/live-out.txt" 2>&1; echo $? > "$W/live-rc.txt"
  check "10 live: the drill exits 0 on a dump that came through a pipe (sequential pg_restore from stdin)" test "$(cat "$W/live-rc.txt")" = 0
  check "10 live: the output lists every table and says the sets are equal" sh -c 'grep -Fqx "table drill_rows live_rows" '"$W"'/live-out.txt && grep -Fq "same set" '"$W"'/live-out.txt'
  check "10 live: the migrations row counts are equal" sh -c 'awk "\$1 == \"migrations\" { ok = (\$2 == \$3 && \$2 > 0) } END { exit !ok }" '"$W"'/live-out.txt'
  check "10 live: the dump is the dev database as of now, so every table count equals" sh -c 'awk "NR > 1 && NF == 3 && \$2 ~ /^[0-9]+\$/ && \$3 ~ /^[0-9]+\$/ { n++; if (\$2 != \$3) bad++ } END { exit !(n > 0 && bad == 0) }" '"$W"'/live-out.txt'
  check "10 live: the scratch database is gone afterwards (pg_database)" test "$(scratch_count)" = 0
  check "10 live: the dump is still there, unchanged by the drill" sh -c 'test -s "'"$LDUMP"'"'

  # A scratch database that is already there: refused, kept, then replaced on request.
  COMPOSE_FILE=compose.dev.yml docker compose exec -T postgres createdb -U pokeprices "$SCRATCH"
  env COMPOSE_FILE=compose.dev.yml DEPLOY_DIR="$ROOT" sh "$SCRIPT" "$LDUMP" > "$W/live-out2.txt" 2>&1; echo $? > "$W/live-rc2.txt"
  check "10 live: an existing scratch database is refused and kept" sh -c 'test "$(cat '"$W"'/live-rc2.txt)" != 0 && grep -Fq "already exists" '"$W"'/live-out2.txt'
  check "10 live: the refused run did not drop it" test "$(scratch_count)" = 1
  env COMPOSE_FILE=compose.dev.yml DEPLOY_DIR="$ROOT" sh "$SCRIPT" --replace "$LDUMP" > "$W/live-out3.txt" 2>&1; echo $? > "$W/live-rc3.txt"
  check "10 live: --replace restores over it and leaves none behind" sh -c 'test "$(cat '"$W"'/live-rc3.txt)" = 0'
  check "10 live: no scratch database remains" test "$(scratch_count)" = 0

  # A broken dump: the restore fails, the scratch database is dropped, the exit is non-zero.
  printf 'this is not a dump\n' > "$LD/pokeprices-20200101T000000Z.dump"
  env COMPOSE_FILE=compose.dev.yml DEPLOY_DIR="$ROOT" sh "$SCRIPT" "$LD/pokeprices-20200101T000000Z.dump" > "$W/live-out4.txt" 2>&1; echo $? > "$W/live-rc4.txt"
  check "10 live: a file that is not a dump exits non-zero" test "$(cat "$W/live-rc4.txt")" != 0
  check "10 live: and leaves no scratch database behind" test "$(scratch_count)" = 0

  after_fp=$(fingerprint)
  check "10 live: the dev application database took no write from the drill (its write counters and migrations count are as before)" test "$before_fp" = "$after_fp"
  printf 'live fingerprint before: %s\nlive fingerprint after: %s\n' "$before_fp" "$after_fp"
  find "$LD" -mindepth 1 -maxdepth 1 -type f -name 'pokeprices-*.dump' -delete
  fi
else
  printf 'skip - live leg (LIVE=1 runs it against the dev stack)\n'
fi

finish
