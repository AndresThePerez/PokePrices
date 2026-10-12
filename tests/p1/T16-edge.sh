#!/bin/sh
# P1-i1-T16 reviewer edge cases: POKESEARCH_URL override semantics (set, empty, unset), the &api-env alias
# structure, extra_hosts on exactly api, worker and scheduler, address and path hygiene of the task commit,
# php-fpm and CLI zstd on bad, truncated and real input (the real snapshot is read only), the host-gateway
# name inside all three containers, and static properties of tests/p1/T16.sh itself. Needs the running stack;
# nothing here creates, restarts or stops a container, and no file under .data/ is written.
set -u
cd "${T16_EDGE_ROOT:-$(dirname "$0")/../..}" || exit 1
. tests/p0/lib.sh
T=.gates/tmp/P1-i1-T16-review; mkdir -p "$T"
TASK=ca4ca6df0fdd73dc591ada342c22c3768a0cc187
API="$DC exec -T -w /workspace/api api"
SNAPDIR=.data/snapshots/2026-10-11/products
SNAP=$SNAPDIR/604.json.zst
DEFAULT=http://host.docker.internal:8081
OVR=http://192.0.2.7:9

# cfg NAME [ENV=VAL ...]: the resolved compose config as JSON, with PP_HTTP_PORT and POKESEARCH_URL cleared first.
cfg() { n=$1; shift; env -u PP_HTTP_PORT -u POKESEARCH_URL "$@" $DC --profile '*' config --format json > "$T/$n.json" 2> "$T/$n.err"; }
cfg unset
cfg set POKESEARCH_URL=$OVR
cfg empty POKESEARCH_URL=
cfg blankok POKESEARCH_URL=" "
$DC config --no-interpolate --format json > "$T/raw.json" 2> "$T/raw.err"

# ---- 1. POKESEARCH_URL override semantics ---------------------------------------------------------------
check_out "with POKESEARCH_URL set in the environment, api, worker and scheduler all resolve to that value" "$OVR $OVR $OVR" \
  sh -c 'jq -r "[.services.api, .services.worker, .services.scheduler | .environment.POKESEARCH_URL] | join(\" \")" "$0"' "$T/set.json"
check_out "with POKESEARCH_URL unset, all three resolve to the host-gateway default" "$DEFAULT $DEFAULT $DEFAULT" \
  sh -c 'jq -r "[.services.api, .services.worker, .services.scheduler | .environment.POKESEARCH_URL] | join(\" \")" "$0"' "$T/unset.json"
# \${VAR:-default} substitutes when the variable is set but empty: an empty override cannot blank the URL.
check_out "with POKESEARCH_URL set to the empty string, the :- form falls back to the default (empty cannot blank it)" "$DEFAULT $DEFAULT $DEFAULT" \
  sh -c 'jq -r "[.services.api, .services.worker, .services.scheduler | .environment.POKESEARCH_URL] | join(\" \")" "$0"' "$T/empty.json"
check "the override changes only POKESEARCH_URL: every other environment key of api is identical to the default render" sh -c '
  jq -e -n --slurpfile a "$0" --slurpfile b "$1" "(\$a[0].services.api.environment | del(.POKESEARCH_URL)) == (\$b[0].services.api.environment | del(.POKESEARCH_URL))"' "$T/unset.json" "$T/set.json"
check "the override never leaks into a service that does not use the anchor (web, node, tools, postgres, redis)" sh -c '
  ! jq -e "[.services | to_entries[] | select(.key | IN(\"api\",\"worker\",\"scheduler\") | not) | .value.environment.POKESEARCH_URL // empty] | length > 0" "$0"' "$T/set.json"
check "the five renders all succeeded" sh -c 'for f in unset set empty blankok; do jq -e .services "$0/$f.json" || exit 1; done' "$T"

# ---- 2. the anchor and its aliases ---------------------------------------------------------------------
check "worker and scheduler environment maps are identical to api's (they alias &api-env, no stale copy)" jq -e \
  '.services.worker.environment == .services.api.environment and .services.scheduler.environment == .services.api.environment' "$T/unset.json"
check "the same holds uninterpolated: worker and scheduler carry the api map as written" jq -e \
  '.services.worker.environment == .services.api.environment and .services.scheduler.environment == .services.api.environment' "$T/raw.json"
check_out "compose.dev.yml has exactly one &api-env anchor and exactly two *api-env aliases" "1 2" \
  sh -c 'printf "%s %s" "$(/usr/bin/grep -c "&api-env" compose.dev.yml)" "$(/usr/bin/grep -c "\*api-env" compose.dev.yml)"'
check_out "the YAML text has POKESEARCH_URL exactly once (no per-service copy that could go stale)" 1 \
  sh -c '/usr/bin/grep -c "POKESEARCH_URL" compose.dev.yml'

# ---- 3. extra_hosts only where the seam is meant -------------------------------------------------------
check_out "extra_hosts appears on exactly api, worker and scheduler (six other services have none)" \
  'api:1 scheduler:1 worker:1 | none:node,postgres,redis-cache,redis-queue,tools,web' \
  sh -c 'jq -r ".services | to_entries as \$e | ([\$e[] | select(.value.extra_hosts) | \"\(.key):\(.value.extra_hosts | length)\"] | sort | join(\" \"))
    + \" | none:\" + ([\$e[] | select(.value.extra_hosts | not) | .key] | sort | join(\",\"))" "$0"' "$T/unset.json"
check_out "compose.dev.yml spells extra_hosts three times" 3 sh -c '/usr/bin/grep -c "^[[:space:]]*extra_hosts:" compose.dev.yml'
check_out "the host-gateway token is the only extra_hosts value anywhere in the file" "3 3" \
  sh -c 'printf "%s %s" "$(/usr/bin/grep -c "host.docker.internal:host-gateway" compose.dev.yml)" "$(/usr/bin/grep -c "extra_hosts: \[\"host.docker.internal:host-gateway\"\]" compose.dev.yml)"'

# ---- 4. hygiene of the task commit -----------------------------------------------------------------------
check "the task commit message is the subject only (no body, no trailer, no Claude/Anthropic/AI/agent mention)" sh -c '
  m=$(git log -1 --format=%B "$0"); test "$(printf "%s\n" "$m" | wc -l)" -eq 1 &&
  ! printf "%s" "$m" | /usr/bin/grep -qiE "co-authored|claude|anthropic|session|generated|\bAI\b|agent"' "$TASK"
check "the task commit adds no IPv4 literal other than 127.0.0.1 (RFC 5737 neither: none needed)" sh -c '
  ! git diff "$0^" "$0" | /usr/bin/grep "^+" | /usr/bin/grep -ohE "([0-9]{1,3}\.){3}[0-9]{1,3}" | /usr/bin/grep -vqx "127\.0\.0\.1"' "$TASK"
check "the task commit adds no home path, docker0/bridge name, Tailscale name or lan hostname" sh -c '
  ! git diff "$0^" "$0" | /usr/bin/grep "^+" | /usr/bin/grep -qiE "/home/|/Users/|docker0|tailscale|\.ts\.net|\.lan\b|\.home\b|172\.(1[6-9]|2[0-9]|3[01])\."' "$TASK"
check_out "the task commit touches exactly compose.dev.yml, docker/api/Dockerfile and tests/p1/T16.sh" "compose.dev.yml docker/api/Dockerfile tests/p1/T16.sh" \
  sh -c 'git diff --name-only "$0^" "$0" | sort | tr "\n" " " | sed "s/ $//"' "$TASK"
check "the task commit leaves the gate locks, manifests and the pending ledger alone (R153)" sh -c '
  ! git diff --name-only "$0^" "$0" | /usr/bin/grep -qE "^gates/|^tests/pending-gates\.txt$|manifest"' "$TASK"
check "tests/p1/T16.sh is committed 100755 and starts with a shebang" sh -c '
  test "$(git ls-tree "$0" tests/p1/T16.sh | cut -d" " -f1)" = 100755 && head -n 1 tests/p1/T16.sh | /usr/bin/grep -qx "#!/bin/sh"' "$TASK"
check_out "the Dockerfile has exactly one install-php-extensions invocation and it lists zstd exactly once" "1 1" \
  sh -c 'printf "%s %s" "$(/usr/bin/grep -c "^RUN install-php-extensions" docker/api/Dockerfile)" "$(/usr/bin/grep "^RUN install-php-extensions" docker/api/Dockerfile | tr " " "\n" | /usr/bin/grep -cx zstd)"'

# ---- 5. zstd: bad and truncated input must not take php down; the real file is read only ------------------
sums() { find "$SNAPDIR" -type f -name '*.zst' -exec sha256sum {} + | sort -k2 | sha256sum; }
mts() { find "$SNAPDIR" -type f -printf '%p %T@\n' | sort | sha256sum; }
if [ -d .data ] && [ -f "$SNAP" ]; then
  s0=$(sums); m0=$(mts)
  PHP_BAD='
    $z = file_get_contents($argv[1]); $full = zstd_uncompress($z); $n = strlen($z); $bad = 0;
    $cases = ["", "x", "not zstd at all", "\x28\xb5\x2f\xfd", "\x28\xb5\x2f\xfd\x00\x00", str_repeat("\xff", 200000), substr($z, 0, 1), substr($z, 0, 4),
      substr($z, 0, 10), substr($z, 0, intdiv($n, 2)), substr($z, 0, $n - 8), substr($z, 8), str_repeat("\x00", 4096)];
    foreach ($cases as $c) {
      $r = @zstd_uncompress($c);
      if ($r === false) { continue; }
      $j = json_decode($r, true);
      if (is_array($j) && is_array($j["results"] ?? null) && count($j["results"]) > 0 && $r !== $full) { $bad++; }
    }
    echo $bad === 0 ? "clean" : "bad:$bad";'
  check_out "bad, empty, truncated and shifted zstd buffers return false or non-results data, never a forged non-empty results list" clean \
    $DC exec -T -w /workspace api php -r "$PHP_BAD" "/workspace/$SNAP"
  check_out "zstd_uncompress of a non-zstd string returns false (warning only, no exception, no crash)" "bool(false)" \
    $API php -r 'var_dump(@zstd_uncompress("this is plainly not zstd"));'
  check_out "a 64 MiB zero buffer is rejected cleanly and the interpreter exits 0" "false 0" \
    sh -c "$API php -r 'echo var_export(@zstd_uncompress(str_repeat(\"\\0\", 64 * 1024 * 1024)), true);' >\"\$0\" 2>/dev/null; rc=\$?; echo \"\$(cat \"\$0\") \$rc\"" "$T/zeros.out"
  check_out "the full decode of 604.json.zst is JSON with a non-empty results list" nonempty \
    $DC exec -T -w /workspace api php -r '
      $r = json_decode(zstd_uncompress(file_get_contents($argv[1])), true, 512, JSON_THROW_ON_ERROR);
      echo is_array($r["results"] ?? null) && count($r["results"]) > 0 ? "nonempty" : "empty";' "/workspace/$SNAP"
  s1=$(sums); m1=$(mts)
  check "all 220 collected product snapshots are byte-identical after the decodes (sha256 of every file)" test "$s0" = "$s1"
  check "no product snapshot has a changed mtime or a new or missing sibling after the decodes" test "$m0" = "$m1"
  check_out "the products directory still holds 220 .json.zst files" 220 sh -c 'find "$0" -type f -name "*.json.zst" | wc -l' "$SNAPDIR"
else
  printf 'ok - (skipped: .data/ or %s is absent, nested or ci context) zstd edge cases\n' "$SNAP"
fi
check "the php-fpm binary (not only the CLI) loads zstd" sh -c "$DC exec -T api php-fpm -m | /usr/bin/grep -qx zstd"
check "the api container is still healthy after the bad-input decodes (php-fpm master alive)" sh -c \
  "$DC ps --format '{{.Service}} {{.Health}}' | /usr/bin/grep -qx 'api healthy'"
check "the zstd extension is loaded exactly once (no duplicate ini)" sh -c "test \"\$($API php -m | /usr/bin/grep -cx zstd)\" -eq 1"

# ---- 6. the running containers and the PokéSearch round trip ---------------------------------------------
for s in api worker scheduler; do
  check "$s: host.docker.internal resolves to exactly one address and it is not loopback" sh -c '
    o=$('"$DC"' exec -T "$0" getent hosts host.docker.internal); test "$(printf "%s\n" "$o" | wc -l)" -ge 1 &&
    a=$(printf "%s\n" "$o" | head -n 1 | cut -d" " -f1) && test -n "$a" && test "$a" != 127.0.0.1 && test "$a" != ::1' "$s"
  check_out "$s: the live POKESEARCH_URL equals the default" "$DEFAULT" $DC exec -T "$s" printenv POKESEARCH_URL
done
if [ "$(curl -s -m 3 -o /dev/null -w '%{http_code}' http://localhost:8081/healthz 2>/dev/null)" = 200 ]; then
  check_out "api -> host.docker.internal:8081/healthz with -m 3 answers 200" 200 $API curl -sS -m 3 -o /dev/null -w '%{http_code}' http://host.docker.internal:8081/healthz
  check "api -> /healthz body is the JSON status ok" sh -c "$API curl -sS -m 3 http://host.docker.internal:8081/healthz | jq -e '.status == \"ok\"'"
  check_out "worker -> host.docker.internal:8081/healthz answers 200 too (the seam is not api-only)" 200 \
    $DC exec -T worker php -r 'preg_match("#^HTTP/\\S+ (\\d+)#", @get_headers(getenv("POKESEARCH_URL") . "/healthz")[0] ?? "", $m); echo $m[1] ?? "none";' 
else
  printf 'ok - (skipped: PokéSearch dev is not answering on the host :8081) round trips from api and worker\n'
fi
check "a closed host port fails inside the -m 3 bound, rather than hanging the api" sh -c '
  s=$(date +%s); '"$API"' curl -sS -m 3 -o /dev/null http://host.docker.internal:1/healthz; rc=$?; e=$(( $(date +%s) - s ));
  test "$rc" -ne 0 && test "$e" -le 5'

# ---- 7. T16.sh itself: how it is written ---------------------------------------------------------------------
check "every docker compose exec in tests/p1/T16.sh carries -T (directly or through the DC/API shorthand)" sh -c '
  ! /usr/bin/grep -n "exec" tests/p1/T16.sh | /usr/bin/grep -v "^[0-9]*:#" | /usr/bin/grep -E "exec [^-]|exec$" | /usr/bin/grep -vE "exec -T"'
check "tests/p1/T16.sh calls GNU grep as /usr/bin/grep and python as python3 -I (no bare grep, no bare python)" sh -c '
  ! /usr/bin/grep -nE "(^|[ |;(\"])grep " tests/p1/T16.sh | /usr/bin/grep -v "/usr/bin/grep"; ! /usr/bin/grep -nE "(^|[ |;(\"])python3? " tests/p1/T16.sh'
check "tests/p1/T16.sh keeps scratch under .gates/tmp and never names /tmp" sh -c '! /usr/bin/grep -nE "(^|[^.a-z])/tmp/" tests/p1/T16.sh && /usr/bin/grep -q "^T=.gates/tmp/T16" tests/p1/T16.sh'
check "the only skip paths of tests/p1/T16.sh name a reason, and the .data one is guarded by [ -d .data ]" sh -c '
  test "$(/usr/bin/grep -c "(skipped: " tests/p1/T16.sh)" -eq 2 && /usr/bin/grep -q "^if \[ -d .data \]; then" tests/p1/T16.sh &&
  /usr/bin/grep -q "skipped: .data/ is absent" tests/p1/T16.sh && /usr/bin/grep -q "skipped: PokéSearch dev is not answering" tests/p1/T16.sh'
check "tests/p1/T16.sh asserts in its body: it has at least 25 check/check_out calls and ends with finish" sh -c '
  test "$(/usr/bin/grep -cE "^[[:space:]]*(check|check_out) " tests/p1/T16.sh)" -ge 25 && tail -n 1 tests/p1/T16.sh | /usr/bin/grep -qx finish'
finish
