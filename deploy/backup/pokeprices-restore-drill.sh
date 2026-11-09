#!/bin/sh
# PokéPrices restore drill: proves a nightly dump can be restored, without touching the live database.
#
# Usage: pokeprices-restore-drill.sh [--replace] <path to a pokeprices-*.dump>
# Environment: DEPLOY_DIR (the stack's directory, where compose.yml lives).
#
# Restores the dump into a scratch database named pokeprices_restore_drill inside the postgres container (socket
# authentication there: no password is handled here and no environment file is read), checks it against the live
# database, prints a table of row counts and the newest ingest day in the dump, and drops the scratch database on
# every exit, an interrupted one included.
#
# What it can never do: the scratch name is fixed (a DRILL_DB in the environment that says anything else is refused),
# createdb, dropdb and pg_restore only ever name that database, every psql call runs with a read-only session so the
# live database is only read, an existing scratch database is refused unless --replace is given, and the dump file is
# only read (nothing here deletes, moves or rewrites it).
set -eu
umask 077

created=0
attempted=0

say() {
  printf '%s\n' "$1"
}

fail() {
  printf 'pokeprices-restore-drill: %s\n' "$1" >&2
  exit 1
}

# Runs on every exit. Drops the scratch database only when this run made it, and says what became of it.
cleanup() {
  status=$?
  trap - EXIT HUP INT TERM
  if [ "$created" = 1 ]; then
    if docker compose exec -T postgres dropdb -U pokeprices --force --if-exists "$DRILL_DB" > /dev/null 2>&1; then
      say "scratch database $DRILL_DB dropped"
    else
      printf 'pokeprices-restore-drill: scratch database %s was left behind; remove it with: docker compose exec -T postgres dropdb -U pokeprices --force %s\n' "$DRILL_DB" "$DRILL_DB" >&2
      [ "$status" -ne 0 ] || status=1
    fi
  elif [ "$attempted" = 1 ]; then
    printf 'pokeprices-restore-drill: scratch database %s was not created\n' "$DRILL_DB" >&2
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' HUP INT TERM

# psql_ro DATABASE SQL: one read-only query, bare output. Every psql call of this script goes through here.
psql_ro() {
  docker compose exec -T -e 'PGOPTIONS=-c default_transaction_read_only=on' postgres psql -X -U pokeprices -d "$1" -Atc "$2"
}

# Arguments
replace=0
case ${1:-} in
  --replace)
    replace=1
    shift
    ;;
esac
[ "$#" -eq 1 ] || fail 'usage: pokeprices-restore-drill.sh [--replace] <path to a pokeprices-*.dump>'
dump=$1
case $dump in
  -*) fail 'the dump path must not start with a dash' ;;
esac

# The name is fixed: a DRILL_DB in the environment is refused unless it says the same thing.
[ "${DRILL_DB-pokeprices_restore_drill}" = pokeprices_restore_drill ] || fail 'the scratch database name is fixed: pokeprices_restore_drill'
DRILL_DB=pokeprices_restore_drill

: "${DEPLOY_DIR:?DEPLOY_DIR is not set}"
case $(basename "$dump") in
  pokeprices-*.dump) ;;
  *) fail 'the dump must be named pokeprices-<stamp>.dump' ;;
esac
[ -f "$dump" ] || fail 'the dump is not a regular file'
[ -r "$dump" ] || fail 'the dump is not readable'
[ -s "$dump" ] || fail 'the dump is empty'
case $dump in
  /*) ;;
  *) dump=$PWD/$dump ;;
esac

cd "$DEPLOY_DIR" || fail 'cannot enter DEPLOY_DIR'
attempted=1

# An existing scratch database is only replaced on request.
present=$(psql_ro postgres "select 1 from pg_database where datname = 'pokeprices_restore_drill'") || fail 'cannot ask postgres which databases exist'
if [ "$present" = 1 ]; then
  [ "$replace" = 1 ] || {
    attempted=0
    fail "scratch database $DRILL_DB already exists and was not touched; run again with --replace to drop and refill it"
  }
  docker compose exec -T postgres dropdb -U pokeprices --force --if-exists "$DRILL_DB" || fail 'cannot drop the old scratch database'
fi

docker compose exec -T postgres createdb -U pokeprices "$DRILL_DB" || fail 'createdb failed'
created=1

say "restoring $(basename "$dump") into the scratch database $DRILL_DB"
# One transaction, so a failure leaves nothing half restored. The dump arrives on stdin: a sequential read.
docker compose exec -T postgres pg_restore -U pokeprices --no-owner --exit-on-error --single-transaction -d "$DRILL_DB" < "$dump" || fail 'pg_restore failed'

# Exact row counts per public table, "name count" lines.
counts=$(cat <<'SQL'
select table_name || ' ' || (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from public.%I', table_name), false, true, '')))[1]::text
from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by table_name
SQL
)
counts=$(printf '%s' "$counts" | tr '\n' ' ')
drill_rows=$(psql_ro "$DRILL_DB" "$counts") || fail 'cannot count the rows of the restored tables'
live_rows=$(psql_ro pokeprices "$counts") || fail 'cannot count the rows of the live tables'

drill_tables=$(printf '%s\n' "$drill_rows" | awk 'NF { print $1 }' | LC_ALL=C sort)
live_tables=$(printf '%s\n' "$live_rows" | awk 'NF { print $1 }' | LC_ALL=C sort)
if [ "$drill_tables" != "$live_tables" ]; then
  say 'the restored database and the live database do not hold the same tables:'
  { printf '%s\n' "$drill_tables" | sed 's/^/restored /'; printf '%s\n' "$live_tables" | sed 's/^/live /'; } |
    awk '{ n[$2] = n[$2] " " $1 } END { for (t in n) if (n[t] !~ / restored/ || n[t] !~ / live/) print t, "only in" n[t] }' | LC_ALL=C sort
  fail 'the table sets differ'
fi

restored_total=$(printf '%s\n' "$drill_rows" | awk '{ n += $2 } END { print n + 0 }')
[ "$restored_total" -gt 0 ] || fail 'the restored database holds no rows: the dump has no data'

say 'table drill_rows live_rows'
{ printf '%s\n' "$drill_rows" | sed 's/^/D /'; printf '%s\n' "$live_rows" | sed 's/^/L /'; } |
  awk '$1 == "D" { d[$2] = $3 } $1 == "L" { l[$2] = $3 } END { for (t in d) print t, d[t], l[t] }' | LC_ALL=C sort

asof=$(psql_ro "$DRILL_DB" "select coalesce(max(day)::text, 'none') from ingest_runs") || fail 'cannot read the newest ingest day'
say "dump as of (newest ingest day): ${asof:-none}"
tables=$(printf '%s\n' "$drill_tables" | awk 'NF' | wc -l | tr -d ' ')
say "restore drill ok: $tables tables, the same set as the live database (live counts have moved on since the dump)"
