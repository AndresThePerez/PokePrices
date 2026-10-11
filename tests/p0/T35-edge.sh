#!/bin/sh
# P0-i1-T35 edge cases for scripts/gates/ec-ci-parity.sh, run in a scratch git repo under
# .gates/tmp/ with a stub docker on PATH (the real ppclean project is never touched).
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd)
T=$ROOT/.gates/tmp/T35-edge
rm -rf "$T"; mkdir -p "$T/bin"
cat > "$T/bin/docker" <<'STUB'
#!/bin/sh
echo "$*" >> "$STUB_LOG"
exit 0
STUB
chmod +x "$T/bin/docker"

# mkrepo NAME MAKEFILE-BODY: scratch repo with the script, lib and the given gates recipe.
mkrepo() {
  r=$T/$1; rm -rf "$r"; mkdir -p "$r/scripts/gates" "$r/scripts/lib"
  cp "$ROOT/scripts/gates/ec-ci-parity.sh" "$r/scripts/gates/"
  cp "$ROOT/scripts/lib/gate-result.sh" "$r/scripts/lib/"
  printf 'services: {}\n' > "$r/compose.dev.yml"
  printf '%s\n' "$2" > "$r/Makefile"
  ( cd "$r" && git init -q && git config user.email e@example.com && git config user.name e &&
    printf '.gates/\n' > .gitignore && git add -A && git commit -qm one )
}
run() { # repo outdir [env...]
  r=$1; o=$2; shift 2
  mkdir -p "$o"; : > "$o/stub.log"
  ( cd "$r" && env PATH="$T/bin:$PATH" STUB_LOG="$o/stub.log" GATES_OUT="$o" "$@" sh scripts/gates/ec-ci-parity.sh ) > "$o/stdout" 2>&1
}
GOOD='gates:
	@mkdir -p .gates/latest; printf "%s %s %s %s|" "$$COMPOSE_PROJECT_NAME" "$$PP_HTTP_PORT" "$$PP_RUN_CONTEXT" "$${GATES_OUT:-unset}" > .gates/env.txt; jq -n --arg h "$$(git rev-parse HEAD)" "{head:\$$h,gates:[{id:\"A\"},{id:\"B\"}]}" > .gates/latest/scorecard.json'
FAIL='gates:
	@exit 3'

# 1 refuses in a nested context
mkrepo r1 "$GOOD"; o=$T/o1
run "$T/r1" "$o" PP_RUN_CONTEXT=nested; rc=$?
check "nested context exits non-zero" test "$rc" -ne 0
check_out "nested context records value 1" "1" jq -r .value "$o/EC-ci-parity.json"
check_out "nested context reason not_primary" "not_primary" jq -r '.details[0].reason' "$o/EC-ci-parity.json"
check "nested context never calls docker" test ! -s "$o/stub.log"
check "nested context makes no clone" test ! -d "$T/r1/.gates/ci-parity"

# 2 happy path: env, detached at HEAD, nested scorecard copied, teardown without volumes
head=$(git -C "$T/r1" rev-parse HEAD)
o=$T/o2; run "$T/r1" "$o"; rc=$?
check "primary happy path exits 0" test "$rc" -eq 0
check_out "value 0" "0" jq -r .value "$o/EC-ci-parity.json"
check_out "denominator counts nested gate records" "2" jq -r .denominator "$o/EC-ci-parity.json"
cl=$(ls -d "$T"/r1/.gates/ci-parity/* | head -1)
check_out "clone is detached" "HEAD" git -C "$cl" rev-parse --abbrev-ref HEAD
check_out "nested env project/port/context, GATES_OUT unset" "ppclean 18095 nested unset|" cat "$cl/.gates/env.txt"
check_out "nested scorecard copy carries our head" "$head" jq -r .head "$o/EC-ci-parity.nested.json"
check "teardown ran with project ppclean" grep -q -- '-p ppclean .*down' "$o/stub.log"
check "teardown never passes -v/--volumes" sh -c "! grep -Eq -- ' -v|--volumes' '$o/stub.log'"

# 3 reuse: same head and clean tree is reused, not recloned
run "$T/r1" "$T/o3"; check_out "clean clone reused" "1" sh -c "ls -d $T/r1/.gates/ci-parity/* | wc -l | tr -d ' '"
check "reuse is recorded" grep -q reused "$T/o3/EC-ci-parity.details.jsonl"

# 4 dirty clone (untracked file) is not reused and is left untouched
mkrepo r4 "$GOOD"; h12=$(git -C "$T/r4" rev-parse HEAD | cut -c1-12)
git -C "$T/r4" clone -q --no-hardlinks . ".gates/ci-parity/$h12" 2>/dev/null || ( cd "$T/r4" && mkdir -p .gates/ci-parity && git clone -q --no-hardlinks . ".gates/ci-parity/$h12" )
echo junk > "$T/r4/.gates/ci-parity/$h12/untracked.txt"
run "$T/r4" "$T/o4"
check "dirty clone left in place" test -f "$T/r4/.gates/ci-parity/$h12/untracked.txt"
check_out "a second clone was made beside the dirty one" "2" sh -c "ls -d $T/r4/.gates/ci-parity/* | wc -l | tr -d ' '"
check "nested run did not use the dirty clone" test ! -f "$T/r4/.gates/ci-parity/$h12/.gates/env.txt"

# 5 stale clone at a different head, same dir name, is not reused
mkrepo r5 "$GOOD"; h12=$(git -C "$T/r5" rev-parse HEAD | cut -c1-12)
( cd "$T/r5" && mkdir -p .gates/ci-parity && git clone -q --no-hardlinks . ".gates/ci-parity/$h12" &&
  cd ".gates/ci-parity/$h12" && git config user.email e@example.com && git config user.name e &&
  git commit -q --allow-empty -m other )
run "$T/r5" "$T/o5"
check "stale-head clone was not run" test ! -f "$T/r5/.gates/ci-parity/$h12/.gates/env.txt"
check_out "scorecard of the stale clone not used; nested copy has real head" "$(git -C "$T/r5" rev-parse HEAD)" jq -r .head "$T/o5/EC-ci-parity.nested.json"

# 6 detached outer HEAD behind a branch tip: the clone sits at HEAD, not the tip
mkrepo r6 "$GOOD"; ( cd "$T/r6" && git commit -q --allow-empty -m two && git checkout -q --detach HEAD~1 )
run "$T/r6" "$T/o6"
cl=$(ls -d "$T"/r6/.gates/ci-parity/* | head -1)
check_out "clone HEAD equals outer detached HEAD, not branch tip" "$(git -C "$T/r6" rev-parse HEAD)" git -C "$cl" rev-parse HEAD

# 7 failing nested make: value 1, non-zero exit, teardown still runs, no scorecard copy
mkrepo r7 "$FAIL"; o=$T/o7
run "$T/r7" "$o"; rc=$?
check "failing nested make exits non-zero" test "$rc" -ne 0
check_out "failing nested make records value 1" "1" jq -r .value "$o/EC-ci-parity.json"
check "teardown ran after failure" grep -q -- '-p ppclean .*down' "$o/stub.log"
check "no nested scorecard after failure" test ! -e "$o/EC-ci-parity.nested.json"
check "log records exit code" grep -q 'EXIT [1-9]' "$o/EC-ci-parity.nested.log"

# 8 teardown failure turns the gate red even when make passed
mkrepo r8 "$GOOD"; printf '#!/bin/sh\necho "$*" >> "$STUB_LOG"\nexit 1\n' > "$T/bin2docker"; mkdir -p "$T/bin2"; mv "$T/bin2docker" "$T/bin2/docker"; chmod +x "$T/bin2/docker"
o=$T/o8; mkdir -p "$o"; : > "$o/stub.log"
( cd "$T/r8" && env PATH="$T/bin2:$PATH" STUB_LOG="$o/stub.log" GATES_OUT="$o" sh scripts/gates/ec-ci-parity.sh ) >/dev/null 2>&1; rc=$?
check "teardown failure exits non-zero" test "$rc" -ne 0
check_out "teardown failure records value 1" "1" jq -r .value "$o/EC-ci-parity.json"

# 9 a pre-existing nested scorecard from an earlier run is not kept when the new run fails
mkrepo r9 "$FAIL"; o=$T/o9; mkdir -p "$o"; echo '{"stale":true}' > "$o/EC-ci-parity.nested.json"
run "$T/r9" "$o"
check "stale nested scorecard removed" test ! -e "$o/EC-ci-parity.nested.json"

# 10 recipe has no volume removal
check "script never uses down -v" sh -c "! grep -Eq 'down[^|]* (-v|--volumes)' scripts/gates/ec-ci-parity.sh"

rm -rf "$T"
finish
