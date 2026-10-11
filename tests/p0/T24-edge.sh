#!/bin/sh
# P0-i1-T24 edge checks: tools is read-only, gitleaks really detects, S4 fails closed, S10 plumbing.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
J() { docker compose -f compose.dev.yml config --format json | jq -e "$1"; }
TOOLS="docker compose -f compose.dev.yml exec -T"
E=.gates/tmp/T24e
mkdir -p .gates "$E"
SR="$E/leak"
if [ ! -d "$SR/.git" ]; then
  mkdir -p "$SR" && git -C "$SR" init -q
  printf 'aws_access_key_id = %s%s\naws_secret_access_key = %s%s\n' AKIA Z7Q3M5N2P4R6T8V9 q8Zr3Lw0Vb7NxT5yHc2K dE9sGa4JmUo1PiRf6WtX > "$SR/cfg.txt"
  git -C "$SR" add cfg.txt
  git -C "$SR" -c user.name=t -c user.email=t@example.invalid commit -q -m add
  git -C "$SR" rm -q cfg.txt
  git -C "$SR" -c user.name=t -c user.email=t@example.invalid commit -q -m remove
fi
$DC up -d --wait tools >/dev/null 2>&1

check "gitleaks reports a secret that was committed then removed (exit 1)" sh -c '! '"$TOOLS"' tools gitleaks git --redact --exit-code 1 --no-banner --report-format json --report-path /repo/'"$E"'/leak.json /repo/'"$E"'/leak'
check "gitleaks finding count is at least 1 and value is redacted" sh -c 'jq -e "length >= 1 and all(.[]; (.Secret|test(\"REDACTED\")) and ((.Match|tostring)|contains(\"Z7Q3M5N2P4R6T8V9\")|not))" '"$E"'/leak.json'
check "S4 command fails closed on a missing path" sh -c '! '"$TOOLS"' tools gitleaks git --redact --exit-code 1 --no-banner /nonexistent'
check "repo root is read-only but .gates is writable" sh -c '! '"$TOOLS"' tools touch /repo/ro-probe && '"$TOOLS"' tools touch /repo/.gates/tmp/T24e/rw-probe && ! '"$TOOLS"' tools touch /repo/api/ro-probe'
check "S4 over /repo scans a non-empty history (not a vacuous pass)" sh -c '! '"$TOOLS"' -w /repo tools gitleaks git --redact --exit-code 1 --no-banner /repo 2>&1 | grep -q "INF.* 0 commits scanned"'
check "tools sees no host secrets file as env" sh -c '! '"$TOOLS"' tools printenv | grep -qi "APP_KEY\|PASSWORD"'
check "tools healthcheck is a real tool probe" J '(.services.tools.healthcheck.test|join(" ")) | test("gitleaks") and test("trivy")'
check "tools image digests are all pinned" sh -c '[ "$(grep -c "^FROM .*@sha256:[0-9a-f]\{64\}" docker/tools/Dockerfile)" = 3 ]'
check "no allowlist or ignore files tracked" sh -c 'test -z "$(git ls-files | grep -E "(^|/)(\.gitleaks\.toml|\.gitleaksignore|\.trivyignore)$")"'
check "s10.sh never ignores unfixed or reads an ignore file" sh -c '! grep -v "^#" scripts/gates/s10.sh | grep -Eqi "ignore-unfixed|trivyignore|ignorefile|--skip" && grep -q -- "--severity CRITICAL" scripts/gates/s10.sh'
check "s10 count expression counts two CRITICAL entries across results" sh -c 'printf "%s" "{\"Results\":[{\"Vulnerabilities\":[{\"VulnerabilityID\":\"a\"}]},{\"Target\":\"x\"},{\"Vulnerabilities\":[{\"VulnerabilityID\":\"b\"}]}]}" > '"$E"'/fake.json && [ "$(jq "[.Results[]?.Vulnerabilities[]?] | length" '"$E"'/fake.json)" = 2 ] && [ "$(printf "{}" | jq "[.Results[]?.Vulnerabilities[]?] | length")" = 0 ]'
check "every service still has a healthcheck" J '[.services[] | select(has("healthcheck") | not)] | length == 0'
finish
