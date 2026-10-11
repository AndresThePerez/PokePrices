#!/bin/sh
# P0-i1-T24: tools service with pinned gitleaks and trivy; S4 clean; S10 measurable.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
J() { docker compose -f compose.dev.yml config --format json | jq -e "$1"; }
TOOLS="docker compose -f compose.dev.yml exec -T tools"
check "tools capped at 512m" J '(.services.tools.mem_limit|tonumber) == 536870912'
check "tools publishes no port" J '(.services.tools.ports // []) | length == 0'
check "repo mounted read-only" J '[.services.tools.volumes[] | select(.target == "/repo")][0].read_only == true'
mkdir -p .gates
check "tools healthy" $DC up -d --wait --build tools
check "gitleaks 8.30.1" sh -c "$TOOLS gitleaks version | /usr/bin/grep -q 8.30.1"
check "trivy 0.75.0" sh -c "$TOOLS trivy --version | /usr/bin/grep -q 0.75.0"
check "repo is not writable from tools" sh -c "! $TOOLS touch /repo/should-not-exist"
T=.gates/tmp/T24; mkdir -p "$T"
check "S4 manifest command finds nothing in history" sh -c 'docker compose -f compose.dev.yml exec -T -w /repo tools gitleaks git --redact --exit-code 1 --report-format json --report-path "/repo/$0/S4.gitleaks.json" /repo && jq -e "length == 0" "$0/S4.gitleaks.json"' "$T"
check "no gitleaks allowlist files exist" sh -c 'test ! -e .gitleaks.toml && test ! -e .gitleaksignore'
check "S10 produces a numeric value over two images" sh -c 'GATES_OUT="$0" sh scripts/gates/s10.sh; jq -e "(.value | type) == \"number\" and .denominator == 2" "$0/S10.json"' "$T"
check "S10 removed its image tarballs" sh -c 'test -z "$(ls "$0"/*.tar 2>/dev/null)"' "$T"
finish
