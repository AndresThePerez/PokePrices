#!/bin/sh
# P6-DEPLOY-i1-T01: shape test for the production stack file and its env template (manual tier, not in FAST).
# Run from the repo root: TIER=task sh tests/p0/run.sh tests/p6/T01-server-compose.sh
. tests/p0/lib.sh

STACK=deploy/stack.server.yml
EX=deploy/.env.example
TMP=.gates/tmp
SN=$(sed -n 's/^SHARED_NET=//p' "$EX" 2>/dev/null)
J=$TMP/p6-T01-stack.json
DJ=$TMP/p6-T01-dev.json
mkdir -p "$TMP"
rm -f "$J" "$DJ"

check "stack file and env template exist" test -f "$STACK" -a -f "$EX"
docker compose -f "$STACK" --env-file "$EX" config --format json > "$J" 2>/dev/null
check "stack file renders with the env template" test -s "$J"
docker compose -f compose.dev.yml config --format json > "$DJ" 2>/dev/null
check "dev file renders (reference for healthchecks and redis commands)" test -s "$DJ"

# pq EXPR: python (isolated) evaluates EXPR with d (stack JSON), s (its services), dev (dev services) and mem().
pq() {
  python3 -I -c '
import json, re, sys
d = json.load(open(sys.argv[1]))
s = d["services"]
dev = json.load(open(sys.argv[3]))["services"]
def mem(x):
    if isinstance(x, int):
        return x
    m = re.fullmatch(r"([0-9]+)([bkmg]?)", str(x).lower())
    return int(m.group(1)) * {"": 1, "b": 1, "k": 1024, "m": 1024**2, "g": 1024**3}[m.group(2)]
sys.exit(0 if eval(sys.argv[2]) else 1)
' "$J" "$1" "$DJ"
}

# 1. Standalone, name, exact service set, per-service basics
check "1 no include or extends" sh -c '! grep -Eq "^[[:space:]]*(include|extends):" deploy/stack.server.yml'
check "1 name is pokeprices" pq 'd["name"] == "pokeprices"'
check "1 services are exactly the seven" pq 'sorted(s) == sorted(["postgres","redis-queue","redis-cache","api","worker","scheduler","web"])'
check "1 every service has image, no build, restart unless-stopped" pq 'all("image" in v and "build" not in v and v.get("restart") == "unless-stopped" for v in s.values())'
check "1 mem_limit caps" pq 'all(mem(s[k]["mem_limit"]) == mem(v) for k, v in {"postgres":"1g","redis-queue":"64m","redis-cache":"128m","api":"384m","worker":"384m","scheduler":"128m","web":"64m"}.items())'
check "1 postgres shm_size 256m" pq 'mem(s["postgres"]["shm_size"]) == mem("256m")'
check "1 logging json-file 10m x 3 on every service" pq 'all(v.get("logging") == {"driver":"json-file","options":{"max-size":"10m","max-file":"3"}} for v in s.values())'
check "1 no extra_hosts anywhere" pq 'all("extra_hosts" not in v for v in s.values())'
check "1 no node or tools service" pq '"node" not in s and "tools" not in s'
check "1 postgres and redis images are the dev digest-pinned refs" pq 's["postgres"]["image"] == dev["postgres"]["image"] and s["redis-queue"]["image"] == dev["redis-queue"]["image"] and s["redis-cache"]["image"] == dev["redis-cache"]["image"] and "@sha256:" in s["postgres"]["image"] and "@sha256:" in s["redis-queue"]["image"]'
check "1 app images are tagged from the required IMAGE_TAG" sh -c 'grep -Fq "pokeprices-api:\${IMAGE_TAG:?" deploy/stack.server.yml && grep -Fq "pokeprices-web:\${IMAGE_TAG:?" deploy/stack.server.yml'
check "1 rendered app images pokeprices-api / pokeprices-web" pq 'all(s[k]["image"].startswith("pokeprices-api:") for k in ("api","worker","scheduler")) and s["web"]["image"].startswith("pokeprices-web:")'

# 2. Users and api command
check "2 api user 0:0" pq 's["api"].get("user") == "0:0"'
check "2 worker and scheduler user www-data" pq 's["worker"].get("user") == "www-data" and s["scheduler"].get("user") == "www-data"'
check "2 postgres, redis and web set no user" pq 'all("user" not in s[k] for k in ("postgres","redis-queue","redis-cache","web"))'
check "2 api command is plain php-fpm -F" pq 's["api"]["command"] == ["php-fpm", "-F"]'

# 3. Only web publishes, one port
check "3 only web publishes ports" pq '[k for k, v in s.items() if v.get("ports")] == ["web"]'
check "3 web port: 127.0.0.1 default bind, published from env, target 8095" pq 'len(s["web"]["ports"]) == 1 and s["web"]["ports"][0]["host_ip"] == "127.0.0.1" and s["web"]["ports"][0]["target"] == 8095 and str(s["web"]["ports"][0]["published"]) == "8099"'
check "3 port syntax uses APP_BIND default and required APP_PORT" sh -c 'grep -Fq "\${APP_BIND:-127.0.0.1}" deploy/stack.server.yml && grep -Fq "\${APP_PORT:?" deploy/stack.server.yml'

# 4. Networks and aliases
check "4 default network ipam subnet 198.18.40.0/24" pq 'd["networks"]["default"]["ipam"]["config"][0]["subnet"] == "198.18.40.0/24" and len(d["networks"]["default"]["ipam"]["config"]) == 1'
check "4 web ipv4_address 198.18.40.10 on default only" pq 'list(s["web"]["networks"]) == ["default"] and s["web"]["networks"]["default"]["ipv4_address"] == "198.18.40.10"'
check "4 SHARED_NET is set in the template" test -n "$SN"
check "4 shared network is external and named by SHARED_NET" pq 'd["networks"]["shared"].get("external") is True and d["networks"]["shared"]["name"] == "'"$SN"'" and sorted(d["networks"]) == ["default", "shared"]'
check "4 api, worker, scheduler on default and shared" pq 'all(sorted(s[k]["networks"]) == ["default", "shared"] for k in ("api","worker","scheduler"))'
check "4 postgres and redis on default only" pq 'all(list(s[k]["networks"]) == ["default"] for k in ("postgres","redis-queue","redis-cache"))'
check "4 data-store aliases" pq 'all(("pokeprices-" + k) in s[k]["networks"]["default"]["aliases"] for k in ("postgres","redis-queue","redis-cache"))'
check "4 api env uses the aliases" pq 'all(s[k]["environment"]["DB_HOST"] == "pokeprices-postgres" and s[k]["environment"]["REDIS_HOST"] == "pokeprices-redis-queue" and s[k]["environment"]["REDIS_CACHE_HOST"] == "pokeprices-redis-cache" for k in ("api","worker","scheduler"))'

# 5. DNS options and read-only mounts
check "5 dns_opt timeout:1 attempts:1 on api, worker, scheduler" pq 'all(sorted(s[k]["dns_opt"]) == ["attempts:1", "timeout:1"] for k in ("api","worker","scheduler"))'
check "5 snapshots bind read-only at /snapshots on api, worker, scheduler" pq 'all(any(m["type"] == "bind" and m["source"] == "/srv/example/snapshots" and m["target"] == "/snapshots" and m.get("read_only") is True for m in s[k]["volumes"]) for k in ("api","worker","scheduler"))'
check "5 TCGCSV_SNAPSHOTS_DIR=/snapshots" pq 'all(s[k]["environment"]["TCGCSV_SNAPSHOTS_DIR"] == "/snapshots" for k in ("api","worker","scheduler"))'
check "5 dataset bind read-only at /dataset on api only" pq 'any(m["type"] == "bind" and m["source"] == "/srv/example/dataset" and m["target"] == "/dataset" and m.get("read_only") is True for m in s["api"]["volumes"]) and all(not any(m["target"] == "/dataset" for m in s[k].get("volumes", [])) for k in s if k != "api") and s["api"]["environment"]["DATASET_DIR"] == "/dataset" and all("DATASET_DIR" not in s[k].get("environment", {}) for k in ("worker","scheduler"))'
check "5 no ORACLE_DIR, no other bind mount on the app services" pq 'all("ORACLE_DIR" not in s[k]["environment"] and all(m["target"] in ("/snapshots", "/dataset") for m in s[k]["volumes"]) for k in ("api","worker","scheduler"))'
check "5 required host dirs use :?" sh -c 'grep -Fq "\${SNAPSHOTS_HOST_DIR:?" deploy/stack.server.yml && grep -Fq "\${DATASET_HOST_DIR:?" deploy/stack.server.yml'

# 6. One shared environment anchor, no env_file
check "6 exactly one x- extension block and no env_file" sh -c 'test "$(grep -Ec "^x-[A-Za-z0-9_-]+:" deploy/stack.server.yml)" -eq 1 && ! grep -Eq "^[[:space:]]*env_file:" deploy/stack.server.yml'
check "6 worker and scheduler share one environment, api adds only DATASET_DIR" pq 's["worker"]["environment"] == s["scheduler"]["environment"] and {k: v for k, v in s["api"]["environment"].items() if k != "DATASET_DIR"} == s["worker"]["environment"]'
check "6 fixed values" pq 'all(all(e.get(k) == v for k, v in {"APP_NAME":"PokéPrices","APP_ENV":"production","APP_DEBUG":"false","APP_URL":"https://pokeprices.andrestheperez.com","DB_CONNECTION":"pgsql","DB_PORT":"5432","DB_DATABASE":"pokeprices","DB_USERNAME":"pokeprices","REDIS_CLIENT":"phpredis","REDIS_PORT":"6379","QUEUE_CONNECTION":"redis","CACHE_STORE":"redis","SESSION_DRIVER":"database","SESSION_SECURE_COOKIE":"true","SANCTUM_STATEFUL_DOMAINS":"pokeprices.andrestheperez.com","LOG_CHANNEL":"stderr_json","POKESEARCH_URL":"http://pokesearch:8080","TRUSTED_PROXIES":"REMOTE_ADDR"}.items()) for e in (s[x]["environment"] for x in ("api","worker","scheduler")))'
check "6 env-driven values (defaults and required)" pq 'all(e["SESSION_LIFETIME"] == "120" and e["LOG_LEVEL"] == "info" and e["MAIL_MAILER"] == "log" and e["APP_KEY"] == "change-me" and e["DB_PASSWORD"] == "change-me" and e["INVITE_CODE"] == "change-me" and e.get("DEMO_PASSWORD", "") == "" and e.get("POKESEARCH_API_KEY", "") == "" for e in (s[x]["environment"] for x in ("api","worker","scheduler")))'
check "6 no SESSION_DOMAIN and no WEBHOOK_PRIVATE_ALLOW" pq 'all("SESSION_DOMAIN" not in s[k]["environment"] and "WEBHOOK_PRIVATE_ALLOW" not in s[k]["environment"] for k in ("api","worker","scheduler"))'
check "6 required secrets use :?" sh -c 'for v in APP_KEY DB_PASSWORD INVITE_CODE IMAGE_TAG; do grep -Fq "\${$v:?" deploy/stack.server.yml || exit 1; done'

# 7. postgres and redis
check "7 postgres env" pq 'all(e["POSTGRES_USER"] == "pokeprices" and e["POSTGRES_DB"] == "pokeprices" and e["POSTGRES_PASSWORD"] == "change-me" and "POSTGRES_HOST_AUTH_METHOD" not in e for e in [s["postgres"]["environment"]])'
check "7 postgres password interpolates DB_PASSWORD" sh -c 'grep -Fq "POSTGRES_PASSWORD: \${DB_PASSWORD:?" deploy/stack.server.yml'
check "7 named volumes pgdata and redisqueue" pq 'sorted(k for k in d.get("volumes", {})) == ["pgdata", "redisqueue"] and any(m["source"] == "pgdata" and m["target"] == "/var/lib/postgresql/data" for m in s["postgres"]["volumes"]) and any(m["source"] == "redisqueue" and m["target"] == "/data" for m in s["redis-queue"]["volumes"])'
check "7 redis commands identical to dev" pq 's["redis-queue"]["command"] == dev["redis-queue"]["command"] and s["redis-cache"]["command"] == dev["redis-cache"]["command"]'

# 8. worker, scheduler, healthchecks, depends_on
Q=$(sed -n "s/^[[:space:]]*'queue'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" api/config/pricing.php | head -n 1)
check "8 queue name read from api/config/pricing.php" test -n "$Q"
check "8 worker command" pq 's["worker"]["command"] == ["php","artisan","queue:work","redis","--queue='"$Q"'","--sleep=1","--tries=3","--max-time=3600","--memory=256"]'
check "8 scheduler command" pq 's["scheduler"]["command"] == ["php","artisan","schedule:work"]'
check "8 healthchecks identical to dev" pq 'all(s[k]["healthcheck"] == dev[k]["healthcheck"] for k in ("postgres","redis-queue","redis-cache","api","worker","scheduler","web"))'
check "8 worker and scheduler depend on api healthy" pq 'all(s[k]["depends_on"]["api"]["condition"] == "service_healthy" for k in ("worker","scheduler"))'
check "8 api depends on postgres and both redis healthy" pq 'all(s["api"]["depends_on"][k]["condition"] == "service_healthy" for k in ("postgres","redis-queue","redis-cache"))'

# 9. Env template
check "9 every stack variable is defined in the template and nothing else" sh -c 'a=$(grep -o "\${[A-Z_][A-Z0-9_]*" deploy/stack.server.yml | tr -d "\${" | sort -u); b=$(grep -E "^[A-Z_][A-Z0-9_]*=" deploy/.env.example | cut -d= -f1 | sort -u); test -n "$a" && test "$a" = "$b"'
check "9 no duplicate key in the template" sh -c 'test -z "$(grep -E "^[A-Z_][A-Z0-9_]*=" deploy/.env.example | cut -d= -f1 | sort | uniq -d)"'
check "9 APP_PORT=8099 with the pick-a-port comment" sh -c 'grep -Fxq "APP_PORT=8099" deploy/.env.example && grep -Fq "# pick a free port >= 8087; the real value lives only in the server .env" deploy/.env.example'
check "9 SESSION_LIFETIME=120 and example host dirs" sh -c 'grep -Fxq "SESSION_LIFETIME=120" deploy/.env.example && grep -Fxq "SNAPSHOTS_HOST_DIR=/srv/example/snapshots" deploy/.env.example && grep -Fxq "DATASET_HOST_DIR=/srv/example/dataset" deploy/.env.example'
check "9 secrets are change-me, each preceded by a how-to-generate comment" sh -c 'for k in APP_KEY DB_PASSWORD INVITE_CODE; do grep -Fxq "$k=change-me" deploy/.env.example || exit 1; n=$(grep -n "^$k=" deploy/.env.example | cut -d: -f1); n=$((n - 1)); sed -n "${n}p" deploy/.env.example | grep -Eq "^# .*(generate|openssl|artisan)" || exit 1; done'
check "9 DEMO_PASSWORD and POKESEARCH_API_KEY are empty in the template" sh -c 'grep -Fxq "DEMO_PASSWORD=" deploy/.env.example && grep -Fxq "POKESEARCH_API_KEY=" deploy/.env.example'
check "9 TRUSTED_PROXIES comment explains REMOTE_ADDR versus *" sh -c 'grep -E "^#.*TRUSTED_PROXIES" deploy/.env.example | grep -q "REMOTE_ADDR" && grep -E "^#" deploy/.env.example | grep -Fq "X-Forwarded-For" && grep -E "^#" deploy/.env.example | grep -Fq "never in production"'
check "9 template is not git-ignored" sh -c '! git check-ignore -q --no-index deploy/.env.example'
check "9 hygiene patterns find nothing in the new files" sh -c '! grep -nP -f gates/hygiene.patterns deploy/stack.server.yml deploy/.env.example tests/p6/T01-server-compose.sh'
check "9 the server port is not written in the stack file" sh -c '! grep -Eq "8099|:808[7-9]|:809[0-46-9]" deploy/stack.server.yml'

# Machine-specific names: no tracked file under deploy/ or tests/p6/ may match the untracked local patterns.
# The patterns are read at run time and never written here; CI has no such file, so the check is skipped there.
LOCALPAT=$(git rev-parse --git-path info/hygiene.local 2>/dev/null)
if [ -n "$LOCALPAT" ] && [ -s "$LOCALPAT" ]; then
  grep -v '^#' "$LOCALPAT" | grep -v '^[[:space:]]*$' > "$TMP/p6-T01-localpat.txt"
  check "local hygiene patterns find nothing under deploy/ or tests/p6/" sh -c '! git ls-files deploy tests/p6 | xargs grep -nIP -f .gates/tmp/p6-T01-localpat.txt'
else
  printf 'skip - local hygiene patterns (no .git/info/hygiene.local here)\n'
fi

# 10. Discovery globs
check "10 no deploy/*.yml basename starts with compose or docker-compose" sh -c 'test -z "$(git ls-files deploy | grep -E "^deploy/(docker-)?compose[^/]*\.ya?ml$")" && test -z "$(ls deploy | grep -E "^(docker-)?compose")"'

finish
