#!/bin/sh
# P0-i1-T36 edge cases: the freeze must be complete, tamper-evident and fail closed, and the tightened
# T31/T35 hard-gate checks must flip on doctored scorecards. Works on a throwaway clone and on copies
# under .gates/tmp; no make gates run, no frozen file is touched in the real tree.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
mkdir -p .gates/tmp
T=.gates/tmp/T36-edge; mkdir -p "$T"
M=gates/manifest-P0.json
LOCK=gates/frozen.sha256
FZ='python3 -I scripts/lib/freeze.py --manifest'

# ---- the live lock -------------------------------------------------------------------------------
check "live lock: every line is '<64 hex>  <safe path>' (no blank, no stray line)" sh -c 'test -s "$0" && ! /usr/bin/grep -Eqv "^[0-9a-f]{64}  [A-Za-z0-9._@+/#-]+\$" "$0"' "$LOCK"
check "live lock: no key is listed twice" sh -c 'test -z "$(cut -c67- "$0" | sort | uniq -d)"' "$LOCK"
check "live lock: exactly one #subtree line per #entry in the manifest" sh -c 'test "$(jq "[.frozen_files.list[] | select(contains(\"#\"))] | length" "$0")" -eq "$(/usr/bin/grep -c "#" "$1")"' "$M" "$LOCK"
check "live lock: every #subtree hash is the sha256 of jq -S of that subtree" sh -c '
  bad=0
  while read -r h k; do
    case "$k" in *"#"*) f=${k%%#*}; s=${k#*#}; g=$(jq -S "$s" "$f" | sha256sum | cut -d" " -f1); [ "$g" = "$h" ] || bad=1;; esac
  done < "$0"
  test "$bad" -eq 0' "$LOCK"
check "live lock: every path is a tracked regular file (no symlink, no gitlink)" sh -c '
  bad=0
  for p in $(grep -v "#" "$0" | cut -c67-); do
    m=$(git ls-files -s -- "$p" | cut -d" " -f1)
    case "$m" in 100644|100755) ;; *) bad=1;; esac
  done
  test "$bad" -eq 0' "$LOCK"
# An implementation independent of freeze.py: git pathspec magic with glob semantics.
check "live lock: lists exactly what git itself expands every manifest glob to (nothing missing, nothing extra)" sh -c '
  jq -r ".frozen_files.list[] | select(contains(\"#\") | not)" "$0" | while read -r g; do git ls-files ":(glob)$g"; done | grep -vx "$1" | sort -u > "$2/expect.txt"
  grep -v "#" "$1" | cut -c67- | sort -u > "$2/actual.txt"
  cmp -s "$2/expect.txt" "$2/actual.txt"' "$M" "$LOCK" "$T"
check "live lock: freeze.py is itself frozen (scripts/lib/* covers it)" /usr/bin/grep -q "  scripts/lib/freeze.py\$" "$LOCK"
check "live lock: jitter-P0.json is frozen (gates/* covers it)" /usr/bin/grep -q "  gates/jitter-P0.json\$" "$LOCK"
check "live lock: regeneration is deterministic across locales" sh -c 'a=$(LC_ALL=C python3 -I scripts/lib/freeze.py --manifest "$0" | sha256sum); b=$(LC_ALL=C.UTF-8 python3 -I scripts/lib/freeze.py --manifest "$0" | sha256sum); test "$a" = "$b"' "$M"
check "jitter file: exactly the five keys, every tolerance is the number 0 (not false, null or \"0\")" jq -e '(.tolerance | to_entries | map(.value | type == "number" and . == 0) | all) and (.tolerance | length == 5)' gates/jitter-P0.json

# ---- tamper evidence, on a throwaway clone of HEAD ---------------------------------------------
C="$T/clone-$(git rev-parse --short HEAD)"
if [ ! -d "$C/.git" ]; then git clone -q "$PWD" "$C" 2>/dev/null; fi
fresh() { git -C "$C" reset -q --hard HEAD 2>/dev/null; }
fz() { ( cd "$C" && $FZ "${1:-$M}" ); } # prints the regeneration
fz_rc() { ( cd "$C" && $FZ "${1:-$M}" > "$PWD/.fz.out" 2> "$PWD/.fz.err"; echo $? ); }
[ -d "$C/.git" ] || { echo "not ok - could not clone HEAD for the tamper tests"; exit 1; }
fresh
check "clone baseline: regeneration equals the committed lock" sh -c 'cd "$0" && python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | cmp -s - gates/frozen.sha256' "$C"

# Edit each kind of frozen file after the freeze: the regeneration must differ and the hash check must fail.
tamper() { # $1 path
  fresh
  printf '\n# edited after the freeze\n' >> "$C/$1"
  if fz | cmp -s - "$C/$LOCK"; then printf 'not ok - editing %s after the freeze is NOT caught by regeneration\n' "$1"; fails=$((fails + 1))
  else printf 'ok - editing %s after the freeze is caught by regeneration\n' "$1"; fi
  if ( cd "$C" && /usr/bin/grep -v "#" "$LOCK" | sha256sum -c --status ) 2>/dev/null; then
    printf 'not ok - editing %s after the freeze is NOT caught by sha256sum -c\n' "$1"; fails=$((fails + 1))
  else printf 'ok - editing %s after the freeze is caught by sha256sum -c\n' "$1"; fi
}
deep=$(git ls-files 'gates/fixtures/**' | tail -1)
for p in Makefile scripts/lib/freeze.py scripts/gates/ag1.py gates/jitter-P0.json gates/ratchet.json .github/workflows/gates.yml "$deep"; do tamper "$p"; done

fresh
printf 'x\n' > "$C/scripts/gates/zz-new-gate.sh"; git -C "$C" add scripts/gates/zz-new-gate.sh
check "a new tracked file under a frozen glob changes the regeneration (an unfrozen new config is visible)" sh -c '! ( cd "$0" && python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | cmp -s - gates/frozen.sha256 )' "$C"
fresh
printf 'x\n' > "$C/scripts/gates/zz-untracked.sh"
check "an untracked file under a frozen glob is ignored by the regeneration" sh -c 'cd "$0" && python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | cmp -s - gates/frozen.sha256' "$C"
rm -f "$C/scripts/gates/zz-untracked.sh"

# Glob semantics: scripts/lib/* stays one level deep, gates/fixtures/** reaches any depth.
fresh
mkdir -p "$C/scripts/lib/sub" "$C/gates/fixtures/zz/yy"
printf 'x\n' > "$C/scripts/lib/sub/deep.txt"; printf 'x\n' > "$C/gates/fixtures/zz/yy/deep.txt"
git -C "$C" add scripts/lib/sub/deep.txt gates/fixtures/zz/yy/deep.txt
check "scripts/lib/* does not reach into a subdirectory" sh -c 'cd "$0" && ! python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | /usr/bin/grep -q "  scripts/lib/sub/deep.txt\$"' "$C"
check "gates/fixtures/** reaches a file three directories deep" sh -c 'cd "$0" && python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | /usr/bin/grep -q "  gates/fixtures/zz/yy/deep.txt\$"' "$C"

# Subtree hashing: key order and formatting are irrelevant, a value change is not, other keys are not frozen.
fresh
n=$(jq '.scripts | length' "$C/web/package.json")
check "web/package.json has at least two scripts (the reorder test is meaningful)" test "$n" -ge 2
jq '.scripts |= (to_entries | reverse | from_entries)' "$C/web/package.json" > "$C/web/package.json.new" && mv "$C/web/package.json.new" "$C/web/package.json"
check "the reordered package.json really differs byte for byte" sh -c '! git -C "$0" diff --quiet -- web/package.json' "$C"
check "reordering the keys of a frozen subtree does not change the lock" sh -c 'cd "$0" && python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | cmp -s - gates/frozen.sha256' "$C"
fresh
jq -c . "$C/web/package.json" > "$C/web/package.json.new" && mv "$C/web/package.json.new" "$C/web/package.json"
check "reformatting (minified) a frozen subtree's file does not change the lock" sh -c 'cd "$0" && python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | cmp -s - gates/frozen.sha256' "$C"
fresh
jq '.scripts |= with_entries(.value += " ")' "$C/web/package.json" > "$C/web/package.json.new" && mv "$C/web/package.json.new" "$C/web/package.json"
check "changing a value inside a frozen subtree changes exactly the #.scripts line" sh -c 'cd "$0" && d=$(python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | diff - gates/frozen.sha256 | /usr/bin/grep -c "^[<>]"); test "$d" -eq 2 && python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | diff - gates/frozen.sha256 | /usr/bin/grep -q "web/package.json#.scripts\$"' "$C"
fresh
jq '.scripts += {"zz-added": "true"}' "$C/api/composer.json" > "$C/api/composer.json.new" && mv "$C/api/composer.json.new" "$C/api/composer.json"
check "a composer.json scripts change is caught too" sh -c 'cd "$0" && ! python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | cmp -s - gates/frozen.sha256' "$C"
fresh
jq '.dependencies = ((.dependencies // {}) + {"zz-unfrozen": "1.0.0"})' "$C/web/package.json" > "$C/web/package.json.new" && mv "$C/web/package.json.new" "$C/web/package.json"
check "a change outside the frozen subtrees (dependencies) does not change the lock" sh -c 'cd "$0" && python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | cmp -s - gates/frozen.sha256' "$C"
fresh
jq '.engines = ((.engines // {}) + {"zz": ">=1"})' "$C/web/package.json" > "$C/web/package.json.new" && mv "$C/web/package.json.new" "$C/web/package.json"
check "adding an engines key changes the lock (the #.engines subtree is frozen)" sh -c 'cd "$0" && ! python3 -I scripts/lib/freeze.py --manifest gates/manifest-P0.json | cmp -s - gates/frozen.sha256' "$C"

# ---- fail closed: exit 2 and an empty stdout ------------------------------------------------------
expect2() { # $1 desc; the clone's current state and manifest $2 (default real)
  fresh_state_rc=$(fz_rc "${2:-$M}")
  if [ "$fresh_state_rc" = 2 ] && [ ! -s "$C/.fz.out" ]; then printf 'ok - %s exits 2 with an empty stdout\n' "$1"
  else printf 'not ok - %s (rc=%s, stdout bytes=%s)\n' "$1" "$fresh_state_rc" "$(wc -c < "$C/.fz.out")"; fails=$((fails + 1)); fi
}
fresh
cp "$C/Makefile" "$T/Makefile.real"
rm -f "$C/Makefile"; ln -s "$PWD/$T/Makefile.real" "$C/Makefile"; git -C "$C" add Makefile
expect2 "a frozen file replaced by a symlink (glob entry)"
fresh
cp "$C/web/package.json" "$T/package.json.real"
rm -f "$C/web/package.json"; ln -s "$PWD/$T/package.json.real" "$C/web/package.json"; git -C "$C" add web/package.json
expect2 "a #subtree file replaced by a symlink"
fresh
printf 'x\n' > "$C/scripts/lib/has space.txt"; git -C "$C" add "scripts/lib/has space.txt"
expect2 "a tracked path with a space (the lock format cannot carry it)"
fresh
printf 'x\n' > "$C/scripts/lib/a#b"; git -C "$C" add 'scripts/lib/a#b'
expect2 "a tracked path containing # (it would forge a #subtree line)"
fresh
rm -f "$C/Makefile"
expect2 "a tracked file deleted from the working tree"
fresh
jq '.frozen_files.list += ["scripts/dev/nothing-*.sh"]' "$C/$M" > "$C/m.json"
expect2 "a glob entry matching nothing" m.json
check "...and names the offending entry on stderr" /usr/bin/grep -qF "scripts/dev/nothing-*.sh" "$C/.fz.err"
jq '.frozen_files.list += ["Makefile#.x"]' "$C/$M" > "$C/m.json"
expect2 "a #entry on a file that is not JSON" m.json
jq '.frozen_files.list += ["nosuch/file.json#.scripts"]' "$C/$M" > "$C/m.json"
expect2 "a #entry on an untracked file" m.json
jq '.frozen_files.list += ["web/package.json#.scripts | env"]' "$C/$M" > "$C/m.json"
expect2 "a #entry with a jq filter beyond a plain key path" m.json
jq '.frozen_files.list += ["web/package.json#.scripts[0]"]' "$C/$M" > "$C/m.json"
expect2 "a #entry indexing into an object (jq null result)" m.json
jq '.frozen_files.list += [""]' "$C/$M" > "$C/m.json"
expect2 "an empty string entry" m.json
jq '.frozen_files.list += [7]' "$C/$M" > "$C/m.json"
expect2 "a non-string entry" m.json
jq '.frozen_files.list = []' "$C/$M" > "$C/m.json"
expect2 "an empty list" m.json
jq '.frozen_files.list = "Makefile"' "$C/$M" > "$C/m.json"
expect2 "a list that is a string" m.json
jq 'del(.frozen_files)' "$C/$M" > "$C/m.json"
expect2 "a manifest without frozen_files" m.json
printf '{"frozen_files": {"list": ["Makefile"' > "$C/m.json"
expect2 "a truncated manifest" m.json
expect2 "a manifest path that does not exist" no-such-manifest.json
printf '{"frozen_files":{"list":["web/package.json#.scripts"]}}' > "$C/m.json"
jq '.scripts = null' "$C/web/package.json" > "$C/web/package.json.new" && mv "$C/web/package.json.new" "$C/web/package.json"
expect2 "a #subtree that is present but null"
fresh
printf 'x\n' > "$C/zz.json"
rc=$( cd "$C" && $FZ >/dev/null 2>&1; echo $? ); check_out "no arguments is a usage error (2)" 2 printf '%s' "$rc"
rc=$( cd "$C" && python3 -I scripts/lib/freeze.py --manifest "$M" extra >/dev/null 2>&1; echo $? ); check_out "a stray extra argument is a usage error (2)" 2 printf '%s' "$rc"
rc=$( cd "$C" && python3 -I scripts/lib/freeze.py --mainfest "$M" >/dev/null 2>&1; echo $? ); check_out "a misspelled flag is a usage error (2)" 2 printf '%s' "$rc"
# Listing the lock file itself must never put it in the lock.
fresh
jq '.frozen_files.list += ["gates/frozen.sha256", "gates/*"]' "$C/$M" > "$C/m.json"
check "listing gates/frozen.sha256 explicitly never makes it list itself" sh -c 'cd "$0" && ! python3 -I scripts/lib/freeze.py --manifest m.json 2>/dev/null | /usr/bin/grep -q "  gates/frozen.sha256\$"' "$C"
# Overlapping entries do not duplicate lines.
jq '.frozen_files.list += ["gates/manifest-P0.json", "Makefile", "gates/*", "scripts/lib/*"]' "$C/$M" > "$C/m.json"
check "overlapping and repeated entries produce the same lock as the real manifest" sh -c 'cd "$0" && python3 -I scripts/lib/freeze.py --manifest m.json | cmp -s - gates/frozen.sha256' "$C"
# Run from a subdirectory: the paths no longer match, so it must not print a (wrong) lock.
check "run from a subdirectory it never prints a lock that differs from the real one" sh -c 'cd "$0/scripts" && o=$(python3 -I lib/freeze.py --manifest ../gates/manifest-P0.json 2>/dev/null); test -z "$o" || test "$o" = "$(cat ../gates/frozen.sha256)"' "$C"
fresh

# ---- doctored scorecards against the tightened T31 / T35 checks (R101, R102) ---------------------
REAL=.gates/latest
if [ -f "$REAL/scorecard.json" ] && [ -f "$REAL/EC-ci-parity.nested.json" ]; then
  D=$T/sc; mkdir -p "$D"
  # T31 lines: only the final "make gates exits 0 ... matches tests/pending-gates.txt" block (P1-i1-T07),
  # rc injected, scorecard path swapped, the real ledger.
  t31() { # $1 name, $2 jq on scorecard, $3 rc
    jq "$2" "$REAL/scorecard.json" > "$D/$1.json" || return 1
    { printf '#!/bin/sh\nset -u\ncd "%s" || exit 1\n. tests/p0/lib.sh\nPG=tests/pending-gates.txt\nrc=%s\n' "$PWD" "$3"
      sed -n '/^P=\$(pending_json/,/matches tests\/pending-gates\.txt/p' tests/p0/T31.sh | sed "s#\\.gates/latest/scorecard\\.json#$D/$1.json#g"
      echo finish; } > "$D/$1.t31.sh"
    sh "$D/$1.t31.sh" 2>&1
  }
  case "$(t31 base . 0)" in *"not ok"*) echo "not ok - T31 hard-gate check fails on the undoctored scorecard"; fails=$((fails + 1));; *"ok - make gates exits 0"*) echo "ok - T31 hard-gate check passes on the undoctored scorecard";; *) echo "not ok - T31 hard-gate check was not extracted"; fails=$((fails + 1));; esac
  for v in 'q1fail|(.gates[] | select(.id == "Q1")) |= (.status = "fail")|0' \
           'q1notrun|(.gates[] | select(.id == "Q1")) |= (.status = "not_run")|0' \
           'q1oos|(.gates[] | select(.id == "Q1")) |= (.status = "out_of_scope")|0' \
           'ag1fail|(.gates[] | select(.id == "AG1")) |= (.status = "fail")|0' \
           'cifail|(.gates[] | select(.id == "EC-ci-parity")) |= (.status = "fail")|0' \
           'rc1|.|1' 'rc2|.|2' ; do
    nm=${v%%|*}; rest=${v#*|}; f=${rest%|*}; rc=${rest##*|}
    case "$(t31 "$nm" "$f" "$rc")" in *"not ok - make gates exits 0"*) printf 'ok - T31 flips on %s\n' "$nm";;
      *) printf 'not ok - T31 does not flip on %s\n' "$nm"; fails=$((fails + 1));; esac
  done
  # T35 lines: the nested checks only, N and T pointed at doctored copies.
  NDEN="$REAL/EC-ci-parity.nested.DEN.json"; [ -f "$NDEN" ] || NDEN=.gates/tmp/T35/EC-ci-parity.nested.DEN.json
  if [ -f "$NDEN" ]; then
    REALDEN=$NDEN
    t35() { # $1 name, $2 jq on nested scorecard, $3 jq on nested DEN
      jq "$2" "$REAL/EC-ci-parity.nested.json" > "$D/$1.nested.json" || return 1
      mkdir -p "$D/$1.d"; jq "$3" "$REALDEN" > "$D/$1.d/EC-ci-parity.nested.DEN.json" || return 1
      { printf '#!/bin/sh\nset -u\ncd "%s" || exit 1\n. tests/p0/lib.sh\nPG=tests/pending-gates.txt\nN=%s\nT=%s\n' "$PWD" "$D/$1.nested.json" "$D/$1.d"
        /usr/bin/grep -E '^P=\$\(pending_json|in-scope failing hard set matches tests/pending-gates\.txt|the nested DEN has no failing details' tests/p0/T35.sh
        echo finish; } > "$D/$1.t35.sh"
      sh "$D/$1.t35.sh" 2>&1
    }
    case "$(t35 base . .)" in *"not ok"*) echo "not ok - T35 nested checks fail on the undoctored artifacts"; fails=$((fails + 1));; *"ok - the nested run's in-scope failing hard set matches"*"ok - the nested DEN"*) echo "ok - T35 nested checks pass on the undoctored artifacts";; *) echo "not ok - T35 nested checks were not extracted"; fails=$((fails + 1));; esac
    for v in 'n-q1fail@@(.gates[] | select(.id == "Q1")) |= (.status = "fail")@@.@@in-scope failing hard set matches tests/pending-gates.txt' \
             'n-ag1fail@@(.gates[] | select(.id == "AG1")) |= (.status = "fail")@@.@@in-scope failing hard set matches tests/pending-gates.txt' \
             'n-q1notrun@@(.gates[] | select(.id == "Q1")) |= (.status = "not_run")@@.@@in-scope failing hard set matches tests/pending-gates.txt' \
             'n-denval@@.@@.value = 1@@the nested DEN has no failing details' \
             'n-dendetail@@.@@.details += [{"gate":"AG1","rule":"zero_denominator","status":"fail","denominator":0}]@@the nested DEN has no failing details' \
             'n-denpass@@.@@.details += [{"gate":"Q1","status":"pass"}]@@the nested DEN has no failing details'; do
      nm=${v%%@@*}; r=${v#*@@}; f1=${r%%@@*}; r=${r#*@@}; f2=${r%%@@*}; want=${r#*@@}
      case "$(t35 "$nm" "$f1" "$f2")" in *"not ok - "*"$want"*) printf 'ok - T35 flips on %s\n' "$nm";;
        *) printf 'not ok - T35 does not flip on %s\n' "$nm"; fails=$((fails + 1));; esac
    done
  else
    echo "ok - (skipped T35 doctoring: no nested DEN artifact present)"
  fi
  # The scorecard generator, not the test, decides what is out of scope: pin it on the real nested run.
  check "the nested run puts exactly EC-mex and EC-ci-parity out of scope (32 in-scope hard gates, all pass)" jq -e '([.gates[] | select(.status == "out_of_scope") | .id] | sort) == ["EC-ci-parity","EC-mex"] and ([.gates[] | select(.hard and .status == "pass")] | length == 32)' "$REAL/EC-ci-parity.nested.json"
  check "the primary run has all 34 hard gates passing (not 33 plus something skipped)" jq -e '[.gates[] | select(.hard)] | length == 34 and all(.status == "pass")' "$REAL/scorecard.json"
else
  echo "not ok - no scorecard artifacts to doctor (run make gates first)"; fails=$((fails + 1))
fi
check "the real tree is untouched by these tests (no frozen file modified)" sh -c 'test -z "$(git status --porcelain --untracked-files=all -- . ":!tests/p0/T36-edge.sh")"'
finish
