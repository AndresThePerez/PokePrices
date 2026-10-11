#!/bin/sh
# P0-i1-T20 reviewer round 1: C1 gate fails closed, the export is host-independent, the spec has no host strings.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
ROOT=$(pwd)
T=.gates/tmp/test-c1-edge; rm -rf "$T"; mkdir -p "$T/bin"
# A stand-in docker: "exec ... scramble:export --path=P" copies the committed spec to P (or fails when FAKE_EXPORT_FAIL=1).
cat > "$T/bin/docker" <<'SH'
#!/bin/sh
[ "${FAKE_EXPORT_FAIL:-0}" = 1 ] && exit 1
for a in "$@"; do case $a in --path=/workspace/*) p=${a#--path=/workspace/} ;; esac; done
cp "$FAKE_SPEC" "$p"
SH
chmod +x "$T/bin/docker"
PATHX="$ROOT/$T/bin:$PATH"

mkclone() { # mkclone NAME
  rm -rf "$T/$1"; git clone -q "$ROOT" "$T/$1" && cp "$ROOT/openapi/api.json" "$T/$1/openapi/api.json" 2>/dev/null
  git -C "$T/$1" config user.email x@example.test; git -C "$T/$1" config user.name x
}
gate() { # gate NAME [ENV...] -> rc; runs the committed-in-clone gate script from inside the clone
  name=$1; shift
  ( cd "$T/$name" && mkdir -p .gates/o && env PATH="$PATHX" FAKE_SPEC="$ROOT/openapi/api.json" GATES_OUT=.gates/o "$@" sh scripts/gates/c1.sh >/dev/null 2>&1 )
}
failing() { jq -r '[.details[] | select(.ok == false) | .check] | join(",")' "$T/$1/.gates/o/C1.json" 2>/dev/null; }

mkclone clean
gate clean; check "clean clone: gate passes" test $? -eq 0
check_out "clean clone: C1.json value 0, denominator 1" "0 1" jq -r '"\(.value) \(.denominator)"' "$T/clean/.gates/o/C1.json"
check_out "clean clone: only files under GATES_OUT and nothing tracked changed" "" git -C "$T/clean" status --porcelain

mkclone bytes; printf '\n' >> "$T/bytes/openapi/api.json"; git -C "$T/bytes" commit -qam planted
gate bytes; rc=$?; check "one planted trailing newline: gate exits non-zero" test $rc -ne 0
check_out "one planted trailing newline: identical check fails" "identical" failing bytes

mkclone untracked; git -C "$T/untracked" rm -q --cached openapi/api.json
gate untracked; rc=$?; check "untracked spec: gate exits non-zero" test $rc -ne 0
check_out "untracked spec: tracked check fails" "tracked" failing untracked

mkclone title; jq '.info.title = "PokeSearch"' "$ROOT/openapi/api.json" > "$T/title/openapi/api.json"; git -C "$T/title" commit -qam t
gate title; rc=$?; check "wrong title: gate exits non-zero" test $rc -ne 0
check_out "wrong title: identical and title checks fail" "identical,title" failing title

mkclone nofail
gate nofail FAKE_EXPORT_FAIL=1; rc=$?; check "export failure: gate exits non-zero" test $rc -ne 0
check_out "export failure: export check recorded" "export,identical" failing nofail

# The committed spec carries no host, port or home path, and no /api path.
S=openapi/api.json
check "spec has no localhost, port, or scheme-qualified host" sh -c "! grep -qE 'localhost|8095|18095|https?://' $S"
check "spec has no home path or server name" sh -c "! grep -qE '/ho''me/|/Us''ers/|tails''cale' $S"
check "spec has exactly the server /v1" jq -e '.servers == [{"url":"/v1"}]' $S
check "spec lists only /ready and no /api path" jq -e '(.paths | keys) == ["/ready"]' $S
check "spec 200 schema types status and checks" jq -e '.paths["/ready"].get.responses["200"].content["application/json"].schema | .properties.status and .properties.checks' $S
check "spec 503 is application/problem+json only" jq -e '.paths["/ready"].get.responses["503"].content | keys == ["application/problem+json"]' $S

# A live export under a different APP_URL is byte-identical to the committed file.
out=.gates/tmp/test-c1-edge/live.json
$DC exec -T -e APP_URL=http://example.test:9999 -w /workspace/api api php artisan scramble:export --path=/workspace/$out >/dev/null 2>&1
check "live export under APP_URL=example.test:9999 equals the committed spec" cmp -s "$out" $S
check_out "no docs UI routes registered" "0" sh -c "$DC exec -T -w /workspace/api api php artisan route:list --json 2>/dev/null | jq '[.[] | select(.uri | test(\"docs|_scramble\"))] | length'"
check_out "only /v1 route is v1/ready" "v1/ready" sh -c "$DC exec -T -w /workspace/api api php artisan route:list --json 2>/dev/null | jq -r '[.[] | select(.uri | startswith(\"v1\")) | .uri] | join(\",\")'"
finish
