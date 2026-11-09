#!/bin/sh
# Review tests for deploy/backup/pokeprices-restore-drill.sh and step 16 of docs/DEPLOY.md.
# Run from the repo root: TIER=task sh tests/p0/run.sh tests/p6/T07-restore-drill-review.sh
# Shim part (always): a PATH shim named docker, no container. LIVE=1 adds a leg against the dev stack's postgres that
# creates and drops only the scratch database pokeprices_restore_drill.
. tests/p0/lib.sh

ROOT=$(pwd)
SCRIPT=deploy/backup/pokeprices-restore-drill.sh
W=$ROOT/.gates/tmp/p6-T07r
SHIMDIR=$W/shim
STATE=$W/state
DEPLOY=$W/deploy
LOG=$W/shim.log
OUT=$W/out.txt
RC=$W/rc.txt
SCRATCH=pokeprices_restore_drill
mkdir -p "$SHIMDIR" "$STATE" "$DEPLOY" "$W/dumps with space"
DUMP=$W/dumps\ with\ space/pokeprices-20260101T000000Z.dump

cat > "$SHIMDIR/docker" <<'SHIM'
#!/bin/sh
printf '%s|%s\n' "$PWD" "$*" >> "$SHIM_LOG"
mode=${SHIM_MODE:-ok}
case " $* " in
  *" psql "*"pg_database"*)
    [ "$mode" = exist-fail ] && exit 1
    [ -f "$SHIM_STATE/drill-exists" ] && printf '1\n'
    exit 0
    ;;
  *" createdb "*) : > "$SHIM_STATE/drill-exists"; exit 0 ;;
  *" dropdb "*) rm -f "$SHIM_STATE/drill-exists"; exit 0 ;;
  *" pg_restore "*)
    cat > /dev/null
    [ "$mode" = restore-slow ] && sleep 3
    exit 0
    ;;
  *" psql "*"ingest_runs"*) printf '2026-01-02\n'; exit 0 ;;
  *" psql "*"query_to_xml"*)
    case " $* " in
      *" -d pokeprices_restore_drill "*)
        if [ "$mode" = restored-empty ]; then printf 'migrations 0\nusers 0\nwidgets 0\n'; exit 0; fi
        printf 'migrations 5\nusers 1\nwidgets 7\n'
        [ "$mode" = extra-in-dump ] && printf 'gadgets 3\n'
        ;;
      *" -d pokeprices "*) printf 'migrations 5\nusers 2\nwidgets 7\n' ;;
    esac
    exit 0
    ;;
  *) exit 99 ;;
esac
SHIM
chmod 755 "$SHIMDIR/docker"

reset() {
  rm -f "$STATE/drill-exists"
  : > "$LOG"
  printf 'PGDMP fake custom-format bytes\n' > "$DUMP"
}
# run MODE ARG...: stdout and stderr go to $OUT, the exit code to $RC.
run() {
  mode=$1
  shift
  : > "$LOG"
  env PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" SHIM_STATE="$STATE" SHIM_MODE="$mode" DEPLOY_DIR="$DEPLOY" sh "$SCRIPT" "$@" > "$OUT" 2>&1
  echo $? > "$RC"
}
calls() { cut -d'|' -f2- "$LOG"; }

# 1. A restore that brought back no rows at all is not a pass (the drill exists to prove the data came back).
reset
run restored-empty "$DUMP"
check "1 every restored table empty while the live ones are not: exits non-zero" sh -c 'test "$(cat '"$RC"')" != 0'
check "1 ... and never prints the ok line" sh -c '! grep -Fq "drill ok" '"$OUT"
check "1 ... and still drops the scratch database it made" sh -c '! test -f '"$STATE"'/drill-exists && grep -Fq "dropped" '"$OUT"

# 2. The table comparison is symmetric: a table only in the restored copy (a dump of a newer schema) fails too.
reset
run extra-in-dump "$DUMP"
check "2 a table only in the dump exits non-zero and is named, no ok line" sh -c 'test "$(cat '"$RC"')" != 0 && grep -Fq "gadgets" '"$OUT"' && ! grep -Fq "drill ok" '"$OUT"

# 3. A hostile dump file name never reaches a command line, a shell or a database name.
reset
HOSTILE="$W/dumps with space/pokeprices-a b;\$(touch PWNED-p6r)'\"q.dump"
cp "$DUMP" "$HOSTILE"
rm -f "$ROOT/PWNED-p6r" "$DEPLOY/PWNED-p6r"
run ok "$HOSTILE"
check "3 a dump named with spaces, a semicolon, a command substitution and both quotes restores (exit 0)" sh -c 'test "$(cat '"$RC"')" = 0'
check "3 nothing in that name was executed" sh -c '! test -e '"$ROOT"'/PWNED-p6r && ! test -e '"$DEPLOY"'/PWNED-p6r'
check "3 no docker argument carries any part of the dump's name or directory" sh -c '! cut -d"|" -f2- '"$LOG"' | grep -Eq "PWNED|a b|with space|20260101"'
rm -f "$HOSTILE"
reset
run ok "-pokeprices-x.dump"
check "3 a dump path that starts with a dash is refused before any docker call" sh -c 'test "$(cat '"$RC"')" != 0 && ! test -s '"$LOG"

# 4. The caller's PG* and option variables change nothing the script sends.
reset
run ok "$DUMP"
cp "$LOG" "$W/clean.log"
reset
: > "$LOG"
env PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" SHIM_STATE="$STATE" SHIM_MODE=ok DEPLOY_DIR="$DEPLOY" \
  PGDATABASE=pokeprices PGUSER=nobody PGHOST=198.51.100.7 PGPORT=1 PGOPTIONS='-c default_transaction_read_only=off' PGSERVICE=app \
  sh "$SCRIPT" "$DUMP" > "$OUT" 2>&1
echo $? > "$RC"
check "4 with PGDATABASE=pokeprices and friends set the run still succeeds" test "$(cat "$RC")" = 0
check "4 every docker call is byte for byte the one of a clean environment" cmp -s "$LOG" "$W/clean.log"

# 5. A hangup (the ssh session dies) during the restore: scratch database dropped, non-zero exit, dump kept.
reset
env PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" SHIM_STATE="$STATE" SHIM_MODE=restore-slow DEPLOY_DIR="$DEPLOY" sh "$SCRIPT" "$DUMP" > "$OUT" 2>&1 &
pid=$!
sleep 1
kill -HUP "$pid" 2>/dev/null
wait "$pid"
echo $? > "$RC"
check "5 SIGHUP during the restore exits non-zero and drops the scratch database it made" sh -c 'test "$(cat '"$RC"')" != 0 && ! test -f '"$STATE"'/drill-exists'

# 6. When postgres cannot even say whether the scratch database exists, nothing is created or dropped, --replace included.
reset
: > "$STATE/drill-exists"
run exist-fail --replace "$DUMP"
check "6 a failing existence check with --replace: non-zero, no createdb, no dropdb, no restore, the database is still there" sh -c '
  test "$(cat '"$RC"')" != 0 && ! cut -d"|" -f2- '"$LOG"' | grep -Eq " (createdb|dropdb|pg_restore) " && test -f '"$STATE"'/drill-exists'

# 7. LIVE: the real-restore sequence of step 16b (dropdb, createdb, pg_restore) must work on a real dump. It is run exactly as
# the runbook prints it, with the database name replaced by the scratch name: the application database is never named.
if [ "${LIVE:-}" = 1 ]; then
  LD=$W/live-dumps
  mkdir -p "$LD"
  find "$LD" -mindepth 1 -maxdepth 1 -type f -name 'pokeprices-*.dump' -delete
  dpsql() { COMPOSE_FILE=compose.dev.yml docker compose exec -T postgres psql -X -U pokeprices -Atc "$2" -d "$1"; }
  scratch_count() { dpsql postgres "select count(*) from pg_database where datname = '$SCRATCH'"; }
  fingerprint() { dpsql pokeprices "select coalesce(sum(n_tup_ins + n_tup_upd + n_tup_del), 0) || ' ' || (select count(*) from migrations) from pg_stat_user_tables"; }
  # "table count" lines for every public table of the scratch database, exact counts.
  scratch_counts() {
    dpsql "$SCRATCH" "select table_name || ' ' || (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from public.%I', table_name), false, true, '')))[1]::text from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by table_name"
  }
  # unsafe LINE: succeeds when the line would still name the application database as the target.
  unsafe() { printf '%s\n' "$1" | grep -Eq -e '-d pokeprices( |$)|(dropdb|createdb) .* pokeprices$'; }

  if [ "$(scratch_count)" != 0 ]; then
    check "7 live: the scratch database does not exist before the leg starts (one that is there is not this test's to drop: aborted)" false
  else
    before_fp=$(fingerprint)
    env COMPOSE_FILE=compose.dev.yml DEPLOY_DIR="$ROOT" BACKUP_DIR="$LD" sh deploy/backup/pokeprices-backup.sh > "$W/live-backup.txt" 2>&1
    LDUMP=$(ls "$LD"/pokeprices-*.dump | head -n 1)
    stamp=${LDUMP##*/pokeprices-}
    stamp=${stamp%.dump}

    # The three lines of 16b, taken from the runbook's own code block, with the database name swapped for the scratch name.
    b16=$(awk '/^16b[.]/ { f = 1 } /^## / && f { exit } f' docs/DEPLOY.md)
    code=$(printf '%s\n' "$b16" | awk '/^```/ { c = !c; next } c')
    drop=$(printf '%s\n' "$code" | grep -E '^docker compose exec -T postgres dropdb ' | head -n 1)
    mk=$(printf '%s\n' "$code" | grep -E '^docker compose exec -T postgres createdb ' | head -n 1)
    rest=$(printf '%s\n' "$code" | grep -E '^docker compose exec -T postgres pg_restore ' | head -n 1)
    check "7 live: step 16b prints a dropdb, a createdb and a pg_restore line, in that order" sh -c '
      c=$1; d=$(printf "%s\n" "$c" | grep -n "dropdb " | head -n 1 | cut -d: -f1); e=$(printf "%s\n" "$c" | grep -n "createdb " | head -n 1 | cut -d: -f1); r=$(printf "%s\n" "$c" | grep -n "pg_restore " | head -n 1 | cut -d: -f1)
      test -n "$d" -a -n "$e" -a -n "$r" && test "$d" -lt "$e" && test "$e" -lt "$r"' sh "$code"
    drop_s=$(printf '%s\n' "$drop" | sed -E "s/ pokeprices\$/ $SCRATCH/")
    mk_s=$(printf '%s\n' "$mk" | sed -E "s/ pokeprices\$/ $SCRATCH/")
    rest_s=$(printf '%s\n' "$rest" | sed -E "s/ -d pokeprices </ -d $SCRATCH </; s/<stamp>/$stamp/")
    safe=1
    for line in "$drop_s" "$mk_s" "$rest_s"; do
      [ -n "$line" ] || safe=0
      if unsafe "$line"; then safe=0; fi
    done
    check "7 live: the substituted lines are all present and none of them still names the application database (guard)" test "$safe" = 1

    if [ "$safe" = 1 ]; then
      # The state the real restore starts from: a populated scratch database made from the dump, plus one table the dump lacks.
      COMPOSE_FILE=compose.dev.yml docker compose exec -T postgres createdb -U pokeprices "$SCRATCH"
      COMPOSE_FILE=compose.dev.yml docker compose exec -T postgres pg_restore -U pokeprices --no-owner --exit-on-error --single-transaction -d "$SCRATCH" < "$LDUMP"
      echo $? > "$W/live-first.rc"
      check "7 live: the populated scratch database was made from the dump (the state the real restore starts from)" test "$(cat "$W/live-first.rc")" = 0
      scratch_counts > "$W/live-counts-before.txt"
      check "7 live: that scratch database holds tables with rows" sh -c 'test "$(wc -l < '"$W"'/live-counts-before.txt)" -gt 10 && awk "{ n += \$2 } END { exit !(n > 0) }" '"$W"'/live-counts-before.txt'
      dpsql "$SCRATCH" "create table drill_extra_table (id int)" > /dev/null

      env COMPOSE_FILE=compose.dev.yml BACKUP_DIR="$LD" sh -c "$drop_s" > "$W/live-16b.txt" 2>&1; rc1=$?
      env COMPOSE_FILE=compose.dev.yml BACKUP_DIR="$LD" sh -c "$mk_s" >> "$W/live-16b.txt" 2>&1; rc2=$?
      env COMPOSE_FILE=compose.dev.yml BACKUP_DIR="$LD" sh -c "$rest_s" >> "$W/live-16b.txt" 2>&1; rc3=$?
      echo "$rc1 $rc2 $rc3" > "$W/live-16b.rc"
      check "7 live: the three lines of step 16b, as printed with the scratch name, all exit 0 over a populated database" test "$(cat "$W/live-16b.rc")" = "0 0 0"
      printf 'live 16b output (scratch database only):\n'
      head -n 5 "$W/live-16b.txt"
      scratch_counts > "$W/live-counts-after.txt"
      check "7 live: the restored table set and every row count equal the dump's (the table the dump lacks is gone)" cmp -s "$W/live-counts-before.txt" "$W/live-counts-after.txt"
    fi
    COMPOSE_FILE=compose.dev.yml docker compose exec -T postgres dropdb -U pokeprices --force --if-exists "$SCRATCH"
    check "7 live: no scratch database remains" test "$(scratch_count)" = 0
    after_fp=$(fingerprint)
    check "7 live: the dev application database took no write (its write counters and migrations count are as before)" test "$before_fp" = "$after_fp"
    printf 'live fingerprint before: %s\nlive fingerprint after: %s\n' "$before_fp" "$after_fp"
    find "$LD" -mindepth 1 -maxdepth 1 -type f -name 'pokeprices-*.dump' -delete
  fi
else
  printf 'skip - live leg (LIVE=1 runs it against the dev stack)\n'
fi

finish
