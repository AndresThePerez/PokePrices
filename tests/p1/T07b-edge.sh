#!/bin/sh
# P1-i1-T07b edge cases (reviewer): the retired P0 push-state checks in tests/p0/T01.sh and T01-edge.sh must
# still reject near-misses (detached HEAD, prefix-trap branch names, a third local branch, origin/main ahead
# of HEAD, a remote named Origin, an upstream with trailing whitespace) and must not over-reject (origin/main
# equal to HEAD, a refs/remotes/origin/HEAD symref). Every scenario is a plain clone under
# .gates/tmp/P1-i1-T07b-review/ and the real scripts run inside it (R96: no parent-shell function).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
GREP=/usr/bin/grep
ROOT=$(pwd)
BASE=0736223dac9730d5551e935a5a80b508529697b3
HEAD_SHA=$(git rev-parse HEAD)
mkdir -p .gates/tmp/P1-i1-T07b-review
D=$(mktemp -d "$ROOT/.gates/tmp/P1-i1-T07b-review/run.XXXXXX") || exit 1
G="git -c user.name=t -c user.email=t@example.invalid"

mkclone() {
  c=$D/$1
  git clone -q "$ROOT" "$c" &&
    git -C "$c" checkout -q -B p1-work "$HEAD_SHA" &&
    for b in $(git -C "$c" for-each-ref --format='%(refname:short)' refs/heads); do
      case $b in p1-work) ;; *) git -C "$c" branch -q -D "$b" || return 1 ;; esac
    done &&
    git -C "$c" branch -q -f --no-track main "$BASE" &&
    git -C "$c" update-ref refs/remotes/origin/main "$BASE" &&
    git -C "$c" config branch.main.remote origin
}
runin() {
  cp tests/p0/T01.sh tests/p0/T01-edge.sh tests/p0/lib.sh "$D/$1/tests/p0/" &&
  (cd "$D/$1" && sh tests/p0/T01.sh > "$D/$1.T01" 2>/dev/null; TMPDIR=$D sh tests/p0/T01-edge.sh > "$D/$1.edge" 2>/dev/null)
}
C1='current branch is main or a p<N>-work branch'
C2A='origin is the only remote'
C2B='origin/main exists and is an ancestor of HEAD'
C3U="main's upstream, if set, is origin"
C3B='the local branches are main and p<N>-work branches only'
C3H='HEAD is a symbolic ref to refs/heads/main or a refs/heads/p<N>-work branch'
okl()  { $GREP -q -x -F "ok - $2" "$D/$1"; }
badl() { $GREP -q -F "not ok - $2" "$D/$1"; }

# ---- detached HEAD: neither branch check nor the symref check may pass ---------------------------------
mkclone detached && git -C "$D/detached" checkout -q --detach && runin detached
check "detached HEAD: T01 fails '$C1'" badl detached.T01 "$C1"
check "detached HEAD: T01-edge fails '$C3H'" badl detached.edge "$C3H"
check "detached HEAD: the branch-list check does not depend on HEAD and still passes" okl detached.edge "$C3B"

# ---- prefix traps: HEAD on main2 / p1-work-old must not match main or p<N>-work ------------------------------
mkclone main2 && git -C "$D/main2" checkout -q -b main2 && runin main2
check "main2 clone: T01 fails '$C1'" badl main2.T01 "$C1"
check "main2 clone: T01-edge fails '$C3H'" badl main2.edge "$C3H"
check "main2 clone: T01-edge fails '$C3B' (a third branch exists)" badl main2.edge "$C3B"
mkclone old && git -C "$D/old" checkout -q -b p1-work-old && runin old
check "p1-work-old clone: T01 fails '$C1'" badl old.T01 "$C1"
check "p1-work-old clone: T01-edge fails '$C3H'" badl old.edge "$C3H"

# ---- P2-i1-T01 prefix traps: p2-work-x, p-work and p2work must not match ^p[0-9]+-work$ -------------------
mkclone p2x && git -C "$D/p2x" checkout -q -b p2-work-x && runin p2x
check "p2-work-x clone: T01 fails '$C1'" badl p2x.T01 "$C1"
check "p2-work-x clone: T01-edge fails '$C3H'" badl p2x.edge "$C3H"
check "p2-work-x clone: T01-edge fails '$C3B'" badl p2x.edge "$C3B"
mkclone pnone && git -C "$D/pnone" checkout -q -b p-work && runin pnone
check "p-work clone: T01 fails '$C1'" badl pnone.T01 "$C1"
check "p-work clone: T01-edge fails '$C3H'" badl pnone.edge "$C3H"
mkclone p2nodash && git -C "$D/p2nodash" checkout -q -b p2work && runin p2nodash
check "p2work clone: T01 fails '$C1'" badl p2nodash.T01 "$C1"
check "p2work clone: T01-edge fails '$C3B'" badl p2nodash.edge "$C3B"
# ---- a second phase branch next to p1-work is allowed, and HEAD on it passes all three -------------------
mkclone p2ok && git -C "$D/p2ok" checkout -q -b p2-work && runin p2ok
check "p2-work clone: T01 passes '$C1'" okl p2ok.T01 "$C1"
check "p2-work clone: T01-edge passes '$C3B'" okl p2ok.edge "$C3B"
check "p2-work clone: T01-edge passes '$C3H'" okl p2ok.edge "$C3H"

# ---- a third local branch with HEAD on p1-work: only the branch-list check objects ---------------------
mkclone third && git -C "$D/third" branch p1-work-old && runin third
check "third-branch clone: T01-edge fails '$C3B'" badl third.edge "$C3B"
check "third-branch clone: T01 still passes '$C1'" okl third.T01 "$C1"
check "third-branch clone: T01-edge still passes '$C3H'" okl third.edge "$C3H"
# ---- no phase branch at all: main alone is not main plus a p<N>-work branch, so it fails ---------------------------
mkclone onlymain && git -C "$D/onlymain" checkout -q main && git -C "$D/onlymain" branch -q -D p1-work && runin onlymain
check "main-only clone: T01-edge fails '$C3B'" badl onlymain.edge "$C3B"
check "main-only clone: T01 passes '$C1' and '$C2B'" sh -c '/usr/bin/grep -q -x -F "ok - $1" "$0/onlymain.T01" && /usr/bin/grep -q -x -F "ok - $2" "$0/onlymain.T01"' "$D" "$C1" "$C2B"

# ---- origin/main equal to HEAD passes the ancestor check; ahead of HEAD fails it ------------------------
mkclone equal && git -C "$D/equal" update-ref refs/remotes/origin/main "$HEAD_SHA" && runin equal
check "equal clone: origin/main really equals HEAD" sh -c 'test "$(git -C "$0" rev-parse refs/remotes/origin/main)" = "$(git -C "$0" rev-parse HEAD)"' "$D/equal"
check "equal clone: T01 passes '$C2B'" okl equal.T01 "$C2B"
mkclone ahead && AHEAD=$($G -C "$D/ahead" commit-tree -p "$HEAD_SHA" -m ahead "$HEAD_SHA^{tree}") &&
  git -C "$D/ahead" update-ref refs/remotes/origin/main "$AHEAD" && runin ahead
check "ahead clone: origin/main really is a strict descendant of HEAD" sh -c 'git -C "$0" merge-base --is-ancestor HEAD refs/remotes/origin/main && ! git -C "$0" merge-base --is-ancestor refs/remotes/origin/main HEAD' "$D/ahead"
check "ahead clone: T01 fails '$C2B'" badl ahead.T01 "$C2B"

# ---- remote naming: Origin instead of origin, and Origin next to origin ---------------------------------
mkclone renamed && git -C "$D/renamed" remote rename origin Origin >/dev/null 2>&1 && runin renamed
check "renamed clone: the only remote is really Origin" sh -c 'test "$(git -C "$0" remote)" = Origin' "$D/renamed"
check "renamed clone: T01 fails '$C2A'" badl renamed.T01 "$C2A"
mkclone upper && git -C "$D/upper" remote add Origin https://example.invalid/upper.git && runin upper
check "upper clone: T01 fails '$C2A' with Origin next to origin" badl upper.T01 "$C2A"

# ---- refs/remotes/origin/HEAD must not count as a second remote or break any check ---------------------
mkclone symref && git -C "$D/symref" symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/main && runin symref
check "symref clone: refs/remotes/origin/HEAD exists" git -C "$D/symref" rev-parse -q --verify refs/remotes/origin/HEAD
check "symref clone: T01 passes '$C2A'" okl symref.T01 "$C2A"
check "symref clone: T01 passes '$C2B'" okl symref.T01 "$C2B"

# ---- upstream value traps: trailing space and wrong case are not origin ---------------------------------
mkclone trail && git -C "$D/trail" config branch.main.remote "origin " && runin trail
check "trail clone: the stored value really has a trailing space" sh -c 'test "$(git -C "$0" config --get branch.main.remote | od -An -c | tr -d " \n")" = "origin\\n"' "$D/trail"
check "trail clone: T01-edge fails '$C3U'" badl trail.edge "$C3U"
mkclone upcase && git -C "$D/upcase" config branch.main.remote Origin && runin upcase
check "upcase clone: T01-edge fails '$C3U'" badl upcase.edge "$C3U"

# ---- criterion 4 on the task commit: only the six old checks are removed, FETCH_HEAD is not re-asserted -
TC=$(git log --format=%H --fixed-strings --grep='[P1-i1-T07b] Let the Pok' -1)
check "the task commit is found" test -n "$TC"
git diff -U0 "$TC^" "$TC" -- tests/p0/T01.sh tests/p0/T01-edge.sh > "$D/p0.diff" 2>/dev/null
check "the P0 scripts changed in exactly four hunks" sh -c 'test "$(/usr/bin/grep -c "^@@ " "$0")" -eq 4' "$D/p0.diff"
check "exactly six lines were removed from the P0 scripts" sh -c 'test "$(/usr/bin/grep -c "^-[^-]" "$0")" -eq 6' "$D/p0.diff"
check "every removed line is one of the six retired checks" sh -c \
  '/usr/bin/grep "^-[^-]" "$0" | /usr/bin/grep -v -c -e "current branch is main\"" -e "no remote-tracking refs" -e "main has no upstream configured" -e "no FETCH_HEAD exists" -e "only the main branch exists" -e "HEAD is a symbolic ref to refs/heads/main\"" | /usr/bin/grep -qx 0' "$D/p0.diff"
check "no added line asserts FETCH_HEAD" sh -c '! /usr/bin/grep "^+[^+]" "$0" | /usr/bin/grep -q FETCH_HEAD' "$D/p0.diff"
check "no added line counts remote-tracking refs" sh -c '! /usr/bin/grep "^+[^+]" "$0" | /usr/bin/grep -q "for-each-ref refs/remotes"' "$D/p0.diff"
check "both P0 scripts keep mode 100755 and parse under bash --posix" sh -c \
  'test -z "$(git ls-files -s tests/p0/T01.sh tests/p0/T01-edge.sh | /usr/bin/grep -v "^100755")" && bash --posix -n tests/p0/T01.sh && bash --posix -n tests/p0/T01-edge.sh'

finish
