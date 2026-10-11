#!/bin/sh
# P0-i1-T32 edge cases: the gate is exercised in a scratch clone so the live .mex is never touched.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
mkdir -p .gates/tmp
S=$(mktemp -d .gates/tmp/T32e.XXXXXX); S=$(cd "$S" && pwd)
trap 'rm -rf "$S"' EXIT
REPO=$(pwd)
mk() { # NAME: a fresh clone of HEAD plus the live untracked scaffold and git-dir files
  rm -rf "$S/$1"; git clone -q --local "$REPO" "$S/$1" || return 1
  cp -r .mex "$S/$1/.mex"
  cp "$(git rev-parse --git-path info/exclude)" "$S/$1/.git/info/exclude"
  cp "$(git rev-parse --git-path info/hygiene.local)" "$S/$1/.git/info/hygiene.local" 2>/dev/null
  mkdir -p "$S/$1/out"
}
cat > "$S/gate.sh" <<'GEOF'
#!/bin/sh
# usage: gate.sh CLONEDIR [VAR=val ...]
n=$1; shift
cd "$n" && exec env GATES_OUT="$n/out" PP_RUN_CONTEXT=primary "$@" sh scripts/gates/ec-mex.sh
GEOF
gate() { n=$1; shift; sh "$S/gate.sh" "$S/$n" "$@"; }
not() { ! "$@"; }
val() { jq -r '.value' "$S/$1/out/EC-mex.json"; }

mk base
check "baseline scratch clone passes" sh "$S/gate.sh" "$S/base"
check "baseline value 0" sh -c '[ "$(jq -r .value "$0")" = 0 ]' "$S/base/out/EC-mex.json"

# 1-3 hygiene sweep catches planted violations (home path, LAN address, forbidden port)
i=0
for plant in '/hom''e/someone/x' 'host 192.168''.1.20 here' 'api :80''84 here'; do
  i=$((i+1)); mk h$i
  printf '%s\n' "$plant" >> "$S/h$i/.mex/patterns/planted.md" 2>/dev/null || { mkdir -p "$S/h$i/.mex/patterns"; printf '%s\n' "$plant" >> "$S/h$i/.mex/patterns/planted.md"; }
  check "hygiene plant $i fails the gate" not gate h$i
  check "hygiene plant $i value >= 1" sh -c '[ "$(jq -r .value "$0")" -ge 1 ]' "$S/h$i/out/EC-mex.json"
  check "hygiene plant $i reports location, not the content" sh -c '! grep -qF -e "$1" "$0"' "$S/h$i/out/EC-mex.json" "$(printf %s "$plant" | cut -c1-9)"
  check "hygiene plant $i names planted.md" sh -c 'grep -q planted.md "$0"' "$S/h$i/out/EC-mex.json"
done

# 4 tracked file under .mex fails the untracked check
mk t; ( cd "$S/t" && git add -f .mex/ROUTER.md && git -c user.name=x -c user.email=x@example.com commit -qm x --no-verify )
check "tracked .mex file fails the gate" not gate t
check "tracked .mex file recorded untracked=false" jq -e '[.details[]|select(.check=="untracked" and .ok==false)]|length==1' "$S/t/out/EC-mex.json"

# 5-7 content floor, each token alone
for tok in compose.dev.yml 'make gates' 8095; do
  n=f$(printf %s "$tok" | tr -c 'a-z0-9\n' _); mk "$n"
  /usr/bin/grep -vF -e "$tok" .mex/ROUTER.md > "$S/$n/.mex/ROUTER.md"
  check "ROUTER without '$tok' fails" not gate "$n"
  check "ROUTER without '$tok' fails only that check" jq -e --arg c "router_names:$tok" '[.details[]|select(.ok==false)|.check]==[$c]' "$S/$n/out/EC-mex.json"
done
# empty ROUTER.md
mk e; : > "$S/e/.mex/ROUTER.md"
check "empty ROUTER.md fails" not gate e

# 8 MEX_BIN resolution: a failing fake wins over PATH and glob; a passing fake is honoured
mk m; printf '#!/bin/sh\necho boom\nexit 1\n' > "$S/m/fakemex"; chmod +x "$S/m/fakemex"
check "failing MEX_BIN fails the gate" not gate m MEX_BIN="$S/m/fakemex"
check "failing MEX_BIN recorded mex_check rc=1" jq -e '[.details[]|select(.check=="mex_check" and .ok==false and (.note|test("rc=1")))]|length==1' "$S/m/out/EC-mex.json"
mk m2; printf '#!/bin/sh\nexit 0\n' > "$S/m2/okmex"; chmod +x "$S/m2/okmex"
check "passing MEX_BIN honoured" sh "$S/gate.sh" "$S/m2" MEX_BIN="$S/m2/okmex"
mk m3
check "nonexistent MEX_BIN fails (no silent fallback)" not gate m3 MEX_BIN="$S/m3/nope"
# mex found on PATH wins over the glob
mk m4; mkdir "$S/m4/bin"; printf '#!/bin/sh\nexit 1\n' > "$S/m4/bin/mex"; chmod +x "$S/m4/bin/mex"
check "mex on PATH beats the nvm glob" not gate m4 PATH="$S/m4/bin:$PATH"

# 9 GATES_OUT unset refuses
check "unset GATES_OUT refuses" sh -c 'cd "$0" && ! env -u GATES_OUT sh scripts/gates/ec-mex.sh' "$S/base"

# 10 nested context
mk n
check "nested context fails" sh -c '! env GATES_OUT="$0/out" PP_RUN_CONTEXT=nested sh "$0/scripts/gates/ec-mex.sh"' "$S/n"
check "nested context value 1 reason not_primary" jq -e '.value==1 and ([.details[]|select(.reason=="not_primary")]|length==1)' "$S/n/out/EC-mex.json"

# 11 missing .mex entirely
mk g; rm -rf "$S/g/.mex"
check "missing .mex fails" not gate g
check "missing .mex denominator 0" jq -e '.denominator==0 and .value>=1' "$S/g/out/EC-mex.json"

# 12 schema and sha256 correctness
check "details sha256 entries are 64 hex and match the files" sh -c '
  cd "$0" && jq -r "select(.sha256!=null)|.file+\" \"+.sha256" out/EC-mex.details.jsonl | while read f s; do [ "$(sha256sum "$f"|cut -d" " -f1)" = "$s" ] || exit 1; done' "$S/base"
check "EC-mex.json has value/denominator/details" jq -e 'has("value") and has("denominator") and has("details")' "$S/base/out/EC-mex.json"
check "denominator equals live .mex file count" sh -c '[ "$(jq -r .denominator "$0")" = "$(find .mex -type f | wc -l)" ]' "$S/base/out/EC-mex.json"

# 13 R35/R98: tracked script has no absolute home path, no root CLAUDE.md anywhere
check "gate script has no absolute home path" sh -c '! grep -nE "/hom[e]/|/Users/" scripts/gates/ec-mex.sh'
check "no root CLAUDE.md in tree or index" sh -c 'test ! -e CLAUDE.md && test -z "$(git ls-files CLAUDE.md)"'
check "live .mex config has empty aiTools" jq -e '.aiTools==[]' .mex/config.json
check "live tree has no change besides this test file" sh -c 'test -z "$(git status --porcelain --untracked-files=all | grep -v "tests/p0/T32-edge.sh")"'
finish
