#!/bin/sh
# P1-i1-T31 edge cases (reviewer): tests/p0/Ti2-03-edge.sh must pin the P0-i2-T03 task commit (never HEAD),
# fail closed when that commit is missing, a root, or not the commit the base check expects, and must find the
# two queue-probe commands under their descriptions without absolute line numbers (F-P0-124, R114).
# Scenarios are throwaway repositories and plain clones under .gates/tmp/P1-i1-T31-review/ (no fetch, no compose).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
ROOT=$(pwd)
S=tests/p0/Ti2-03-edge.sh
mkdir -p .gates/tmp/P1-i1-T31-review
D=$(mktemp -d "$ROOT/.gates/tmp/P1-i1-T31-review/run.XXXXXX") || exit 1
G="git -c user.name=t -c user.email=t@example.invalid -c commit.gpgsign=false"
SUBJ='[P0-i2-T03] Make two PokéPrices edge tests say what they prove'

# the resolution snippet (TASK/BASE lines) and the locator function, cut out of the real script
sed -n '/^TASK=\$(git log/,/^BASE=\${BASE:-/p' "$S" > "$D/resolve.sh"
$GREP '^qcmd() ' "$S" > "$D/qcmd.sh"
check "the script defines the TASK/BASE resolution as four lines" test "$(wc -l < "$D/resolve.sh" | tr -d ' ')" -eq 4
check "the script defines qcmd on exactly one line" test "$(wc -l < "$D/qcmd.sh" | tr -d ' ')" -eq 1

# resolve DIR: print "TASK BASE" as the script computes them inside DIR
resolve() { (cd "$1" && . "$D/resolve.sh" && printf '%s %s\n' "$TASK" "$BASE"); }
commit() { $G -C "$1" commit -q --allow-empty -m "$2"; }

# ---- real repository: the pins resolve to the P0-i2-T03 task commit and its P0-i2-T02 parent -------------
got=$(resolve "$ROOT")
t=${got% *}; b=${got#* }
check "real repo: TASK is a full sha whose subject is the P0-i2-T03 task subject" test "$(git log -1 --format=%s "$t")" = "$SUBJ and keep C6 output under .gates"
check "real repo: BASE is the 7-character abbreviation of TASK's parent" test "$b" = "$(git rev-parse --short=7 "$t^")" -a "${#b}" -eq 7
check "real repo: TASK is an ancestor of HEAD" git merge-base --is-ancestor "$t" HEAD

# ---- static: no diff/log range in the script reads HEAD, and no frozen sha or line number is hard-coded ---
check "no git diff/log/show/rev-parse line of the script names HEAD" test "$($GREP -E '^[^#]*git (diff|log|show|rev-parse)' "$S" | $GREP -c 'HEAD')" -eq 0
check "no non-comment line of the script hard-codes the task or base sha" test "$($GREP -v '^[[:space:]]*#' "$S" | $GREP -cE '1e60d51|0f21557')" -eq 0
check "no sed -n NN p line read of T34-edge remains (87/89/86/88)" test "$($GREP -cE 'sed -n "?(86|87|88|89)p|for n in 87 89' "$S")" -eq 0

# ---- fail closed: no matching commit -----------------------------------------------------------------------
git init -q "$D/none" && commit "$D/none" 'one' && commit "$D/none" 'two'
got=$(resolve "$D/none"); t=${got% *}; b=${got#* }
check "no matching commit: TASK does not resolve to any object" test -n "$t" && ! git -C "$D/none" rev-parse --verify --quiet "$t^{commit}"
check "no matching commit: BASE does not resolve to any object" test -n "$b" && ! git -C "$D/none" rev-parse --verify --quiet "$b^{commit}"
check "no matching commit: the sentinel is not an ancestor of HEAD" sh -c '! git -C "$0" merge-base --is-ancestor "$1" HEAD' "$D/none" "$t"
check "no matching commit: a diff over the pinned range errors out (never an empty or HEAD range)" sh -c '! git -C "$0" diff -U0 "$1..$2" -- x' "$D/none" "$b" "$t"

# ---- fail closed: the task commit is the root (no parent) --------------------------------------------------
git init -q "$D/root" && commit "$D/root" "$SUBJ and keep C6 output under .gates" && commit "$D/root" 'later'
got=$(resolve "$D/root"); t=${got% *}; b=${got#* }
check "root task commit: TASK resolves" git -C "$D/root" rev-parse --verify --quiet "$t^{commit}"
check "root task commit: BASE is a sentinel that resolves to nothing, not to TASK or HEAD" test -n "$b" && ! git -C "$D/root" rev-parse --verify --quiet "$b^{commit}"

# ---- the task commit only on a side branch (not an ancestor of HEAD) is not found --------------------------
git init -q "$D/side" && commit "$D/side" '[P0-i2-T02] Make a thing' && git -C "$D/side" checkout -q -b other
commit "$D/side" "$SUBJ and keep C6 output under .gates" && git -C "$D/side" checkout -q -
commit "$D/side" 'main work'
got=$(resolve "$D/side"); t=${got% *}
check "task commit on an unmerged side branch: TASK is the sentinel, not the side commit" test "$t" != "$(git -C "$D/side" rev-parse other)" && ! git -C "$D/side" rev-parse --verify --quiet "$t^{commit}"

# ---- two matching commits: the newest wins, and the T02-parent check rejects a wrong parent -----------------
git init -q "$D/dup" && commit "$D/dup" '[P0-i2-T02] Make a thing' && commit "$D/dup" "$SUBJ and keep C6 output under .gates"
old=$(git -C "$D/dup" rev-parse HEAD); commit "$D/dup" 'unrelated' && commit "$D/dup" "$SUBJ and keep C6 output under .gates"
got=$(resolve "$D/dup"); t=${got% *}; b=${got#* }
check "two matching commits: TASK is the newest one" test "$t" = "$(git -C "$D/dup" rev-parse HEAD)" -a "$t" != "$old"
check "two matching commits: the ambiguous pick is caught, because its parent is not a P0-i2-T02 commit" sh -c '! git -C "$0" log -1 --format=%s "$1" | /usr/bin/grep -q "^\[P0-i2-T02\] "' "$D/dup" "$b"

# ---- qcmd: the locator returns a line number only for one description with an sh -c line directly under it --
q() { sh -c ". \"$D/qcmd.sh\"; qcmd \"\$0\" \"\$1\"" "$1" "$2"; }
printf '%s\n' 'check "a one" \' '  sh -c "x"' 'check "target thing" \' '  sh -c "y"' 'check "z" \' '  sh -c "w"' > "$D/q-ok.sh"
check_out "qcmd control: the sh -c line under the single matching description" "4" q "$D/q-ok.sh" '^check "target thing"'
printf '%s\n' 'check "a one" \' '  sh -c "x"' 'check "target thing" \' > "$D/q-last.sh"
check_out "qcmd: description is the last line of the file -> nothing" "" q "$D/q-last.sh" '^check "target thing"'
printf '%s\n' '  sh -c "decoy on line one"' 'check "other" \' '  sh -c "x"' 'check "target thing" \' > "$D/q-line1.sh"
check_out "qcmd: a decoy sh -c on line 1 and a description with no command under it -> nothing" "" q "$D/q-line1.sh" '^check "target thing"'
printf '%s\n' 'check "target thing" \' '' '  sh -c "y"' > "$D/q-blank.sh"
check_out "qcmd: a blank line between description and command -> nothing" "" q "$D/q-blank.sh" '^check "target thing"'
printf '%s\n' 'check "target thing" \' '  sh -c "x"' 'check "target thing" \' '  sh -c "y"' > "$D/q-twice.sh"
check_out "qcmd: the description twice, each with a command -> nothing" "" q "$D/q-twice.sh" '^check "target thing"'
printf '%s\n' 'check "other" \' '  sh -c "x"' 'check "target thing" \' '  true' '  sh -c "later"' > "$D/q-notdirect.sh"
check_out "qcmd: the sh -c line is two lines below the description -> nothing" "" q "$D/q-notdirect.sh" '^check "target thing"'
printf '%s\n' 'check "target thing" \' '    sh -c "indented too far"' > "$D/q-indent.sh"
check_out "qcmd: a command indented by four spaces is not the two-space sh -c line -> nothing" "" q "$D/q-indent.sh" '^check "target thing"'

# ---- real clone: later edits to T34-edge.sh keep section 1 green, a changed command turns it red ------------
# runner = the script's own section 1 (checks 30..), with the head of the script re-created for a clone dir
{ printf '%s\n' 'set -u' 'cd "$1" || exit 1' '. tests/p0/lib.sh' 'GREP=/usr/bin/grep'
  sed -n '/^TASK=\$(git log/,/^BASE=\${BASE:-/p' "$S"
  printf '%s\n' 'E=tests/p0/T34-edge.sh; C6E=tests/gates/test_c6_edge.sh; Q=api/tests/Feature/QueueProbeEdgeTest.php; ACC=tests/p0/Ti2-03.sh' 'OUT=.gates/tmp/Ti2-03-edge' 'mkdir -p "$OUT"'
  sed -n '/^# --- 1\. /,/^# --- 2\. /p' "$S"
  printf '%s\n' 'finish'; } > "$D/section1.sh"
check "the extracted section 1 holds the 11 section-1 checks and the locator checks" test "$($GREP -c "^check " "$D/section1.sh")" -ge 11 -a "$($GREP -c "^qcmd()" "$D/section1.sh")" -eq 1
mkclone() { git clone -q "$ROOT" "$D/$1" && git -C "$D/$1" checkout -q "$(git rev-parse HEAD)" && mkdir -p "$D/$1/.gates/tmp"; }
runc() { (cd "$D/$1" && sh "$D/section1.sh" "$D/$1" > "$D/$1.out" 2>&1); }

mkclone shift
{ printf '%s\n' '# a new header line' '# another' '# and a third'; cat "$D/shift/tests/p0/T34-edge.sh"; printf '%s\n' '# trailing note'; } > "$D/shift.t34" &&
  cp "$D/shift.t34" "$D/shift/tests/p0/T34-edge.sh" && $G -C "$D/shift" commit -q -am 'later edit of T34-edge'
runc shift; rc=$?
check "clone, T34-edge edited by a later commit: section 1 passes with zero failures" test "$rc" -eq 0 && $GREP -qx '0 failure(s)' "$D/shift.out"
check "clone, later edit: the locator checks printed ok" $GREP -qx 'ok - three lines inserted above and one appended: both commands are found 3 lines lower, unchanged' "$D/shift.out"

mkclone mutcmd
sed 's/timeout 60 /timeout 61 /' "$D/mutcmd/tests/p0/T34-edge.sh" > "$D/mutcmd.t34" && cp "$D/mutcmd.t34" "$D/mutcmd/tests/p0/T34-edge.sh" &&
  $G -C "$D/mutcmd" commit -q -am 'edit a probe command'
runc mutcmd; rc=$?
check "clone, negative-timeout command edited: section 1 fails" test "$rc" -ne 0
check "clone, edited command: the byte-identity check is the one that fails" $GREP -qx 'not ok - T34-edge negative-timeout command (found under its description) is byte-identical to 0f21557' "$D/mutcmd.out"

mkclone dupdesc
awk '{ print } /^check "queue-probe live probe reports/ { getline; print; print "check \"queue-probe live probe reports '\''probe processed'\'' again\" \\"; print "  sh -c \"true\"" }' "$D/dupdesc/tests/p0/T34-edge.sh" > "$D/dupdesc.t34"
cp "$D/dupdesc.t34" "$D/dupdesc/tests/p0/T34-edge.sh" && $G -C "$D/dupdesc" commit -q -am 'add a second live probe check'
runc dupdesc; rc=$?
check "clone, a second description with the live-probe prefix: the command is no longer found and section 1 fails" test "$rc" -ne 0 && $GREP -q '^not ok - both queue-probe commands are found' "$D/dupdesc.out"

# ---- hygiene of the task diff and its commit message ---------------------------------------------------------
extra=$(printf '%s|%s|%s|%s|%s|%s' '\b172\.(1[6-9]|2[0-9]|3[01])\.' '\b100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.' '\b192\.168\.' '\b10\.[0-9]+\.[0-9]+\.' "/$(printf ho)me/" "~/$(printf Repo)")
check "added lines of 1a408a4..e10eae8 carry no home path, bridge, Tailscale or LAN address" scripts/lib/nohits sh -c 'git diff -U0 1a408a4 e10eae8 | /usr/bin/grep "^+[^+]" | /usr/bin/grep -P "$0"' "$extra"
check "the task commit message is subject only and says PokéPrices with the accent" sh -c '[ "$(git log --format=%B 1a408a4..e10eae8 | sed "/^\$/d" | wc -l | tr -d " ")" -eq 1 ] && git log --format=%B 1a408a4..e10eae8 | /usr/bin/grep -q "PokéPrices"'
check "the task commit message has no trailer, Claude, Anthropic or AI reference" sh -c '! git log --format=%B 1a408a4..e10eae8 | /usr/bin/grep -qiE "co-authored|claude|anthropic|session|(^|[^a-z])ai([^a-z]|$)|agent"'
check "Ti2-03-edge.sh is still mode 100755" test "$(git ls-files -s "$S" | cut -c1-6)" = 100755
finish
