#!/bin/sh
# P0-i1-T18 edge cases (reviewer): worker/scheduler compose contract, healthcheck self-match, live probe.
set -u
cd "$(dirname "$0")/../.." || exit 1
. tests/p0/lib.sh
CJ=$(docker compose -f compose.dev.yml config --format json 2>/dev/null)
cj() { printf '%s' "$CJ" | jq -r "$@"; }

check_out "worker mem_limit is 384m in bytes" 402653184 cj '.services.worker.mem_limit'
check_out "scheduler mem_limit is 128m in bytes" 134217728 cj '.services.scheduler.mem_limit'
for s in worker scheduler; do
  check_out "$s restarts unless-stopped" unless-stopped cj ".services.$s.restart"
  check_out "$s builds the api dev target" "$(cj '.services.api.build.target')" cj ".services.$s.build.target"
  check_out "$s builds the api dockerfile" "$(cj '.services.api.build.dockerfile')" cj ".services.$s.build.dockerfile"
  check_out "$s inherits LOG_CHANNEL" stderr_json cj ".services.$s.environment.LOG_CHANNEL"
  check_out "$s inherits QUEUE_CONNECTION" redis cj ".services.$s.environment.QUEUE_CONNECTION"
  check_out "$s healthcheck is a CMD-SHELL pgrep" CMD-SHELL cj ".services.$s.healthcheck.test[0]"
done
check_out "worker command is the contract" \
  "php artisan queue:work redis --queue=default --sleep=1 --tries=3 --max-time=3600 --memory=256" \
  sh -c "printf '%s' '$CJ' | jq -r '.services.worker.command | join(\" \")'"
check_out "scheduler command is schedule:work" "php artisan schedule:work" \
  sh -c "printf '%s' '$CJ' | jq -r '.services.scheduler.command | join(\" \")'"

# The healthcheck test string, with its pattern swapped for a bogus one, must NOT pass: a self-matching
# pgrep would keep a dead worker healthy.
for pair in "worker:queue:work" "scheduler:schedule:work"; do
  s=${pair%%:*}; pat=${pair#*:}
  t=$(cj ".services.$s.healthcheck.test[1]" | sed "s/artisan $pat/artisan zzbogus:work/")
  check "$s healthcheck does not self-match (bogus pattern fails)" sh -c "! $DC exec -T $s sh -c \"\$1\"" _ "$t"
  check "$s healthcheck passes for the real process" $DC exec -T "$s" sh -c "$(cj ".services.$s.healthcheck.test[1]")"
done

check "live probe is processed by the worker" $DC exec -T -w /workspace/api api php artisan pokeprices:queue-probe --timeout=15
probe_on_cache_only() {
  k=$($DC exec -T redis-cache redis-cli -n 1 --scan --pattern '*pokeprices:probe:*' | head -1 | tr -d '\r')
  [ -n "$k" ] || return 1
  t=$($DC exec -T redis-cache redis-cli -n 1 ttl "$k" | tr -d '\r')
  [ "$t" -gt 0 ] && [ "$t" -le 300 ] || return 1
  q=$($DC exec -T redis-queue sh -c 'for d in 0 1 2 3; do redis-cli -n $d --scan --pattern "*pokeprices:probe:*"; done')
  [ -z "$q" ]
}
check "probe key is on redis-cache with a TTL, not on redis-queue" probe_on_cache_only
check "inspire is gone" sh -c "! $DC exec -T -w /workspace/api api php artisan list 2>/dev/null | grep -q inspire"
check "schedule:list shows pokeprices-probe once" sh -c "[ \"\$($DC exec -T -w /workspace/api api php artisan schedule:list 2>/dev/null | grep -c pokeprices-probe)\" = 1 ]"
finish
