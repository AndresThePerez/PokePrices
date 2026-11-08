#!/bin/sh
# P6-DEPLOY-i1-T05: the nightly backup script and its two systemd user units (manual tier, not in FAST).
# Run from the repo root: TIER=task sh tests/p0/run.sh tests/p6/T05-backup.sh
# The script runs here against a PATH shim named docker that records its working directory and arguments
# and emits fake dump bytes (or fails on demand). No container, no database and no systemd is involved.
. tests/p0/lib.sh

ROOT=$(pwd)
SCRIPT=deploy/backup/pokeprices-backup.sh
SVC=deploy/backup/pokeprices-backup.service
TMR=deploy/backup/pokeprices-backup.timer
W=$ROOT/.gates/tmp/p6-T05
SHIMDIR=$W/shim
DEPLOY=$W/deploy
BK=$W/backups
LOG=$W/shim.log
OUT=$W/out.txt
RC=$W/rc.txt
mkdir -p "$SHIMDIR" "$DEPLOY" "$BK"

# The shim: logs "cwd|args" per call and, for a restore listing, the bytes it read on stdin.
cat > "$SHIMDIR/docker" <<'SHIM'
#!/bin/sh
printf '%s|%s\n' "$PWD" "$*" >> "$SHIM_LOG"
case " $* " in
  *" pg_dump "*)
    case "${SHIM_MODE:-ok}" in
      dump-fail) printf 'partial-bytes\n'; exit 1 ;;
      dump-empty) exit 0 ;;
    esac
    printf 'PGDMP fake custom-format bytes\n'
    ;;
  *" pg_restore "*)
    wc -c | tr -d ' ' >> "$SHIM_LOG.stdin"
    [ "${SHIM_MODE:-ok}" = restore-fail ] && exit 1
    printf '; fake listing\n'
    ;;
  *) exit 99 ;;
esac
exit 0
SHIM
chmod 755 "$SHIMDIR/docker"

# reset: empty the fake backup directory and the shim logs (files only; nothing recursive).
reset() {
  find "$BK" -mindepth 1 -maxdepth 1 -type f -delete
  : > "$LOG"
  : > "$LOG.stdin"
}
# seed N: N old dumps named pokeprices-2020MMDDT000000Z.dump, the oldest first, plus a file that is not a dump.
seed() {
  i=1
  while [ "$i" -le "$1" ]; do
    printf 'old\n' > "$BK/pokeprices-202001$(printf '%02d' "$i")T000000Z.dump"
    i=$((i + 1))
  done
  printf 'note\n' > "$BK/keep-me.txt"
}
# run MODE [KEEP]: the script's stdout+stderr go to $OUT and its exit code to $RC. KEEP unset means the default.
run() {
  : > "$LOG"
  : > "$LOG.stdin"
  if [ -n "${2:-}" ]; then
    env PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" SHIM_MODE="$1" DEPLOY_DIR="$DEPLOY" BACKUP_DIR="$BK" KEEP="$2" PGPASSWORD=00000000-example sh "$SCRIPT" > "$OUT" 2>&1
  else
    env -u KEEP PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" SHIM_MODE="$1" DEPLOY_DIR="$DEPLOY" BACKUP_DIR="$BK" PGPASSWORD=00000000-example sh "$SCRIPT" > "$OUT" 2>&1
  fi
  echo $? > "$RC"
}
dumps() { ls "$BK" | grep -Ec '^pokeprices-[0-9]{8}T[0-9]{6}Z[.]dump$'; }
parts() { ls "$BK" | grep -Ec '[.]part$'; }
rc() { cat "$RC"; }

# 1. Files exist, are executable by mode in the index, and parse
check "1 the script and both units exist" test -s "$SCRIPT" -a -s "$SVC" -a -s "$TMR"
check "1 the script passes sh -n" sh -n "$SCRIPT"
check "1 the script is POSIX sh with set -eu and umask 077" sh -c '
  head -n 1 '"$SCRIPT"' | grep -Fqx "#!/bin/sh" && grep -Fqx "set -eu" '"$SCRIPT"' && grep -Fqx "umask 077" '"$SCRIPT"
check "1 git index modes: 100755 for the script, 100644 for both units" sh -c '
  git ls-files -s '"$SCRIPT"' | grep -q "^100755 " &&
  git ls-files -s '"$SVC"' | grep -q "^100644 " &&
  git ls-files -s '"$TMR"' | grep -q "^100644 "'
check "1 the script never mentions an env file" sh -c '! grep -q "[.]env" '"$SCRIPT"

# 2. A good run: one dump, mode 600, stamped name, name and size printed
reset
run ok
check "2 a good run exits 0" test "$(rc)" = 0
check "2 exactly one stamped .dump and no .part remain" test "$(dumps)" = 1 -a "$(parts)" = 0
check "2 the dump has mode 600" sh -c 'test "$(stat -c %a '"$BK"'/pokeprices-*.dump)" = 600'
check "2 the dump holds the bytes the shim emitted" sh -c 'grep -Fq "PGDMP fake" '"$BK"'/pokeprices-*.dump'
check "2 the output is one line: the file name and its size, nothing else" sh -c 'test "$(wc -l < '"$OUT"')" = 1 && grep -Eq "^pokeprices-[0-9]{8}T[0-9]{6}Z[.]dump [0-9]+$" '"$OUT"
check "2 the printed size is the real size" sh -c 'f=$(ls '"$BK"'/pokeprices-*.dump); test "$(awk "{print \$2}" '"$OUT"')" = "$(wc -c < "$f" | tr -d " ")"'
check "2 the output has no = assignment and never shows an environment value" sh -c '! grep -q "=" '"$OUT"' && ! grep -q "00000000-example" '"$OUT"

# 3. The docker calls: cwd is DEPLOY_DIR, exact commands, the listing reads the dump on stdin
check "3 every docker call ran in DEPLOY_DIR" sh -c 'test -s '"$LOG"' && ! awk -F"|" -v d='"$DEPLOY"' "\$1 != d" '"$LOG"' | grep -q .'
check "3 pg_dump is compose exec -T postgres pg_dump -U pokeprices -d pokeprices -Fc" sh -c 'cut -d"|" -f2- '"$LOG"' | grep -Fqx "compose exec -T postgres pg_dump -U pokeprices -d pokeprices -Fc"'
check "3 the listing is compose exec -T postgres pg_restore --list and read the dump from stdin" sh -c 'cut -d"|" -f2- '"$LOG"' | grep -Fqx "compose exec -T postgres pg_restore --list" && test "$(cat '"$LOG"'.stdin)" -gt 0'
check "3 no docker call other than the dump and the listing" sh -c 'test "$(wc -l < '"$LOG"')" = 2'

# 4. Retention: 15 old + the new one = 16 dumps, KEEP=14 (default and explicit) -> the two oldest go
reset
seed 15
run ok
check "4 default KEEP (14): 16 dumps become 14" test "$(rc)" = 0 -a "$(dumps)" = 14
check "4 the two oldest are gone and the third oldest stays" sh -c 'cd '"$BK"' && ! test -e pokeprices-20200101T000000Z.dump && ! test -e pokeprices-20200102T000000Z.dump && test -e pokeprices-20200103T000000Z.dump && test -e pokeprices-20200115T000000Z.dump'
check "4 the new dump survives and a file that is not a dump is untouched" sh -c 'ls '"$BK"' | grep -Eq "^pokeprices-20[2-9][0-9][0-9]{4}T[0-9]{6}Z[.]dump$" && test -e '"$BK"'/keep-me.txt'
reset
seed 15
run ok 14
check "4 explicit KEEP=14 gives the same result" test "$(rc)" = 0 -a "$(dumps)" = 14
reset
seed 5
run ok 3
check "4 KEEP=3 keeps the newest three, the new one among them" sh -c 'test "$(cd '"$BK"' && ls pokeprices-*.dump | wc -l)" = 3 && test -e '"$BK"'/pokeprices-20200105T000000Z.dump && test -e '"$BK"'/pokeprices-20200104T000000Z.dump && ! test -e '"$BK"'/pokeprices-20200103T000000Z.dump'
for k in 0 abc -1 ""; do
  reset
  seed 3
  run ok "$k"
  check "4 KEEP='$k' is refused or defaulted without deleting anything" sh -c 'test "$(cd '"$BK"' && ls pokeprices-*.dump | wc -l)" -ge 3 && test -e '"$BK"'/pokeprices-20200101T000000Z.dump'
done
reset
seed 3
run ok 0
check "4 KEEP=0 exits non-zero (it would delete every backup)" test "$(rc)" != 0
check "4 KEEP=0 leaves no new dump either" test "$(dumps)" = 3

# 5. Failures: no .dump from this run, no .part, non-zero exit, old dumps kept
for m in dump-fail dump-empty restore-fail; do
  reset
  seed 2
  run "$m"
  check "5 $m reached pg_dump through the shim" sh -c 'cut -d"|" -f2- '"$LOG"' | grep -Fq "pg_dump"'
  check "5 $m exits non-zero" test "$(rc)" != 0
  check "5 $m leaves no .part" test "$(parts)" = 0
  check "5 $m leaves no new .dump and keeps the older ones" test "$(dumps)" = 2
  check "5 $m never prints an = assignment" sh -c '! grep -q "=" '"$OUT"
done
reset
run dump-fail
check "5 dump-fail on an empty directory leaves it empty" test "$(ls "$BK" | wc -l)" = 0
reset
run restore-fail
check "5 restore-fail did run pg_restore --list" sh -c 'cut -d"|" -f2- '"$LOG"' | grep -Fq "pg_restore --list"'

# 6. Missing or bad environment
reset
env -u DEPLOY_DIR PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" BACKUP_DIR="$BK" sh "$SCRIPT" > "$OUT" 2>&1; echo $? > "$RC"
check "6 a missing DEPLOY_DIR is refused" test "$(rc)" != 0 -a "$(dumps)" = 0
reset
env -u BACKUP_DIR PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" DEPLOY_DIR="$DEPLOY" sh "$SCRIPT" > "$OUT" 2>&1; echo $? > "$RC"
check "6 a missing BACKUP_DIR is refused" test "$(rc)" != 0 -a ! -s "$LOG"
reset
env PATH="$SHIMDIR:$PATH" SHIM_LOG="$LOG" DEPLOY_DIR="$W/no-such-dir" BACKUP_DIR="$BK" sh "$SCRIPT" > "$OUT" 2>&1; echo $? > "$RC"
check "6 a DEPLOY_DIR that does not exist is refused before any docker call" test "$(rc)" != 0 -a ! -s "$LOG" -a "$(dumps)" = 0

# 7. Units
check "7 service is oneshot with the docker socket, both paths and ExecStart as tokens" sh -c '
  grep -Fqx "Type=oneshot" '"$SVC"' &&
  grep -Fqx "Environment=DOCKER_HOST=unix://%t/docker.sock" '"$SVC"' &&
  grep -Fqx "Environment=DEPLOY_DIR=@DEPLOY_DIR@" '"$SVC"' &&
  grep -Fqx "Environment=BACKUP_DIR=@BACKUP_DIR@" '"$SVC"' &&
  grep -Fqx "ExecStart=@DEPLOY_DIR@/pokeprices-backup.sh" '"$SVC"
check "7 timer fires at 05:17 UTC daily, persistent, wanted by timers.target" sh -c '
  grep -Fqx "OnCalendar=*-*-* 05:17:00 UTC" '"$TMR"' &&
  grep -Fqx "Persistent=true" '"$TMR"' &&
  grep -Fqx "WantedBy=timers.target" '"$TMR"
check "7 three token lines across both units, and only the two known tokens" sh -c '
  test "$(cat '"$SVC"' '"$TMR"' | grep -c "@[A-Z_]*@")" = 3 &&
  test "$(cat '"$SVC"' '"$TMR"' | grep -o "@[A-Z_]*@" | sort -u | paste -sd" " -)" = "@BACKUP_DIR@ @DEPLOY_DIR@"'
check "7 the units hold no home path" sh -c '! grep -Eq "/home/|/Users/|~/|%h|%S" '"$SVC"' '"$TMR"
check "7 the | delimited sed leaves no token in either unit" sh -c '
  for u in '"$SVC"' '"$TMR"'; do
    sed "s|@DEPLOY_DIR@|/srv/example/pp|g; s|@BACKUP_DIR@|/srv/example/pp-backups|g" "$u" | grep -q "@" && exit 1
  done
  sed "s|@DEPLOY_DIR@|/srv/example/pp|g; s|@BACKUP_DIR@|/srv/example/pp-backups|g" '"$SVC"' | grep -Fqx "ExecStart=/srv/example/pp/pokeprices-backup.sh"'
check "7 the units name no .env file and no secret" sh -c '! grep -Eqi "[.]env|EnvironmentFile|password|secret|token" '"$SVC"' '"$TMR"

# 8. Hygiene on the files this task adds (tracked patterns, then the machine-specific ones at run time)
check "8 tracked hygiene patterns find nothing in the backup files or this test" sh -c '
  p=$(sed -e "s/\r\$//" -e "/^[[:space:]]*#/d" -e "/^[[:space:]]*\$/d" gates/hygiene.patterns | sed "s/.*/(?:&)/" | paste -sd"|" -)
  test -n "$p"; grep -nIP -e "$p" '"$SCRIPT $SVC $TMR"' tests/p6/T05-backup.sh; test $? -eq 1'
LOCALPAT=$(git rev-parse --git-path info/hygiene.local 2>/dev/null)
if [ -n "$LOCALPAT" ] && [ -s "$LOCALPAT" ]; then
  sed -e 's/\r$//' -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' "$LOCALPAT" | sed 's/.*/(?:&)/' | paste -sd'|' - > "$W/localpat.txt"
  check "8 local hygiene patterns find nothing in the backup files or this test" sh -c 'grep -nIP -f '"$W"'/localpat.txt '"$SCRIPT $SVC $TMR"' tests/p6/T05-backup.sh; test $? -eq 1'
else
  printf 'skip - local hygiene patterns (no .git/info/hygiene.local here)\n'
fi

finish
