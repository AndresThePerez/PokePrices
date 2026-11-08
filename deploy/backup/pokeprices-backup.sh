#!/bin/sh
# Nightly PokéPrices database backup, run by pokeprices-backup.service (a systemd user unit).
#
# Environment: DEPLOY_DIR (the stack's directory, where compose.yml lives), BACKUP_DIR (an absolute directory for
# the dumps), KEEP (how many dumps to keep, default 14).
# Dumps with pg_dump in custom format inside the postgres container (socket authentication there: no password
# is handled here), checks that the dump is not empty and that pg_restore can list it, then renames it into
# place and prunes the oldest. Prints the final file name and size and nothing else.
set -eu
umask 077

fail() {
  printf 'pokeprices-backup: %s\n' "$1" >&2
  exit 1
}

: "${DEPLOY_DIR:?DEPLOY_DIR is not set}"
: "${BACKUP_DIR:?BACKUP_DIR is not set}"
KEEP=${KEEP:-14}
case $KEEP in
  '' | *[!0-9]* | 0*) fail 'KEEP must be a positive whole number without leading zeros' ;;
esac
case $BACKUP_DIR in
  /*) ;;
  *) fail 'BACKUP_DIR must be an absolute path' ;;
esac

cd "$DEPLOY_DIR" || fail 'cannot enter DEPLOY_DIR'
mkdir -p "$BACKUP_DIR"

stamp=$(date -u +%Y%m%dT%H%M%SZ)
name=pokeprices-$stamp.dump
part=$BACKUP_DIR/$name.part
final=$BACKUP_DIR/$name

# Whatever happens from here on, a half-written file never stays behind.
trap 'rm -f "$part"' EXIT HUP INT TERM

docker compose exec -T postgres pg_dump -U pokeprices -d pokeprices -Fc > "$part" || fail 'pg_dump failed'
[ -s "$part" ] || fail 'the dump is empty'
docker compose exec -T postgres pg_restore --list < "$part" > /dev/null || fail 'pg_restore --list rejected the dump'

mv "$part" "$final"

# Keep the newest KEEP dumps (the stamp sorts in time order); anything else in the directory is left alone.
ls -1 "$BACKUP_DIR" | grep -E '^pokeprices-[0-9]{8}T[0-9]{6}Z[.]dump$' | sort -r | tail -n +"$((KEEP + 1))" |
  while IFS= read -r old; do
    rm -f "$BACKUP_DIR/$old"
  done

printf '%s %s\n' "$name" "$(wc -c < "$final" | tr -d ' ')"
