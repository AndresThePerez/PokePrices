#!/bin/sh
# P1-i1-T07b (R149): the P0 push-state checks in tests/p0/T01.sh and T01-edge.sh accept the P1 branch
# (p1-work) and the pushed main. Each scenario is a throwaway clone under .gates/tmp/P1-i1-T07b/ with
# the working tree's T01.sh, T01-edge.sh and lib.sh copied in; the real scripts run inside the clone
# and their check lines are read back (R96: the subject is the real script, never a parent function).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
ROOT=$(pwd)
BASE=0736223dac9730d5551e935a5a80b508529697b3
HEAD_SHA=$(git rev-parse HEAD)
mkdir -p .gates/tmp/P1-i1-T07b
D=$(mktemp -d "$ROOT/.gates/tmp/P1-i1-T07b/run.XXXXXX") || exit 1

# mkclone NAME: a plain clone on p1-work at this HEAD, local main at BASE (no tracking set up by git),
# origin/main at BASE and branch.main.remote=origin (the state the owner's push left).
mkclone() {
  c=$D/$1
  git clone -q "$ROOT" "$c" &&
    git -C "$c" checkout -q -B p1-work "$HEAD_SHA" &&
    git -C "$c" branch -q -f --no-track main "$BASE" &&
    git -C "$c" update-ref refs/remotes/origin/main "$BASE" &&
    git -C "$c" config branch.main.remote origin
}
# runin NAME: copy the scripts under test into the clone after its last checkout (a checkout of main
# would otherwise bring back the older committed copies), then run both inside the clone; output to
# $D/NAME.T01 and $D/NAME.edge.
runin() {
  cp tests/p0/T01.sh tests/p0/T01-edge.sh tests/p0/lib.sh "$D/$1/tests/p0/" &&
  (cd "$D/$1" && sh tests/p0/T01.sh > "$D/$1.T01" 2>/dev/null; TMPDIR=$D sh tests/p0/T01-edge.sh > "$D/$1.edge" 2>/dev/null)
}
# The descriptions of the six new check lines.
C1='current branch is main or p1-work'
C2A='origin is the only remote'
C2B='origin/main exists and is an ancestor of HEAD'
C3U="main's upstream, if set, is origin"
C3B='the local branches are exactly main and p1-work'
C3H='HEAD is a symbolic ref to refs/heads/main or refs/heads/p1-work'

# ---- positive: p1-work, origin/main at 0736223, FETCH_HEAD present, three remote-tracking refs ---------
mkclone ok && : > "$D/ok/.git/FETCH_HEAD" && runin ok
check "ok clone: setup is p1-work with origin/main at 0736223 and a FETCH_HEAD" sh -c \
  'test "$(git -C "$0" symbolic-ref --short HEAD)" = p1-work && test "$(git -C "$0" rev-parse refs/remotes/origin/main)" = "$1" && test -e "$0/.git/FETCH_HEAD"' "$D/ok" "$BASE"
check "ok clone: more than one remote-tracking ref exists (the count is not asserted)" sh -c \
  'test "$(git -C "$0" for-each-ref refs/remotes | wc -l)" -gt 1' "$D/ok"
check "ok clone: T01 passes '$C1'" $GREP -q -x -F "ok - $C1" "$D/ok.T01"
check "ok clone: T01 passes '$C2A'" $GREP -q -x -F "ok - $C2A" "$D/ok.T01"
check "ok clone: T01 passes '$C2B'" $GREP -q -x -F "ok - $C2B" "$D/ok.T01"
check "ok clone: T01-edge passes '$C3U'" $GREP -q -x -F "ok - $C3U" "$D/ok.edge"
check "ok clone: T01-edge passes '$C3B'" $GREP -q -x -F "ok - $C3B" "$D/ok.edge"
check "ok clone: T01-edge passes '$C3H'" $GREP -q -x -F "ok - $C3H" "$D/ok.edge"
check "ok clone: no T01-edge line mentions FETCH_HEAD" sh -c '! '"$GREP"' -q FETCH_HEAD "$0"' "$D/ok.edge"
check "the FETCH_HEAD check is gone from T01-edge.sh" sh -c '! '"$GREP"' -q FETCH_HEAD tests/p0/T01-edge.sh'
check "the old P0 push-state descriptions are gone" sh -c '! '"$GREP"' -q -e "no remote-tracking refs" -e "only the main branch exists" -e "main has no upstream configured" -e "\"current branch is main\"" tests/p0/T01.sh tests/p0/T01-edge.sh'

# ---- positive: HEAD on main (after the HG-19 fast-forward), main without an upstream -------------------
mkclone onmain && git -C "$D/onmain" checkout -q main && git -C "$D/onmain" config --unset branch.main.remote && runin onmain
check "onmain clone: T01 passes '$C1'" $GREP -q -x -F "ok - $C1" "$D/onmain.T01"
check "onmain clone: T01 passes '$C2B' (origin/main equals HEAD)" $GREP -q -x -F "ok - $C2B" "$D/onmain.T01"
check "onmain clone: T01-edge passes '$C3U' with no upstream set" $GREP -q -x -F "ok - $C3U" "$D/onmain.edge"
check "onmain clone: T01-edge passes '$C3H'" $GREP -q -x -F "ok - $C3H" "$D/onmain.edge"

# ---- negative: HEAD on a branch named other fails the three branch checks ------------------------------
mkclone other && git -C "$D/other" checkout -q -b other && runin other
check "other clone: T01 fails '$C1'" $GREP -q -x -F "not ok - $C1" "$D/other.T01"
check "other clone: T01-edge fails '$C3B'" $GREP -q -F "not ok - $C3B (" "$D/other.edge"
check "other clone: T01-edge fails '$C3H'" $GREP -q -x -F "not ok - $C3H" "$D/other.edge"

# ---- negative: origin/main is not an ancestor of HEAD (a side commit on top of 0736223) ----------------
mkclone notanc && SIDE=$(git -C "$D/notanc" -c user.name=t -c user.email=t@example.invalid commit-tree -p "$BASE" -m side "$BASE^{tree}") &&
  git -C "$D/notanc" update-ref refs/remotes/origin/main "$SIDE" && runin notanc
check "notanc clone: origin/main really is not an ancestor of HEAD" sh -c '! git -C "$0" merge-base --is-ancestor refs/remotes/origin/main HEAD' "$D/notanc"
check "notanc clone: T01 fails '$C2B'" $GREP -q -x -F "not ok - $C2B" "$D/notanc.T01"
check "notanc clone: T01 still passes '$C1'" $GREP -q -x -F "ok - $C1" "$D/notanc.T01"

# ---- negative: origin/main does not exist -------------------------------------------------------------
mkclone noref && git -C "$D/noref" update-ref -d refs/remotes/origin/main && runin noref
check "noref clone: T01 fails '$C2B'" $GREP -q -x -F "not ok - $C2B" "$D/noref.T01"

# ---- negative: a second remote fails 2(a) --------------------------------------------------------------
mkclone tworem && git -C "$D/tworem" remote add second https://example.invalid/second.git && runin tworem
check "tworem clone: T01 fails '$C2A'" $GREP -q -F "not ok - $C2A (" "$D/tworem.T01"
check "tworem clone: T01 still passes '$C2B'" $GREP -q -x -F "ok - $C2B" "$D/tworem.T01"

# ---- negative: main's upstream is a remote other than origin -------------------------------------------
mkclone upstream && git -C "$D/upstream" config branch.main.remote second && runin upstream
check "upstream clone: T01-edge fails '$C3U'" $GREP -q -x -F "not ok - $C3U" "$D/upstream.edge"

finish
