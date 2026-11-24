# Deploy runbook

PokéPrices goes public at `https://pokeprices.andrestheperez.com` on Andres's home server, behind the existing
cloudflared tunnel, as one added service beside the ones already there (PokéSearch, Courier, the portfolio site).
Nothing on the server builds an image: the server has no registry access, so images are built on the workstation and
shipped with `docker save | ssh … docker load`.

The last step installs the nightly backup timer, and the "What runs where" table after it lists everything that runs
once the stack is up.

Every step below has a number, exactly one tag in its heading and a "Who runs it" line.

| Tag | Where the commands run | Who |
|---|---|---|
| `[workstation]` | Andres's workstation, from the repository root | the orchestrator, or Andres |
| `[server]` | the server, in `<deploy-dir>`, over ssh | Andres, or the orchestrator for a step Andres approved |
| `[server, sudo]` | the server, with `sudo` | Andres, or the orchestrator when Andres orders that run (sudo is his) |

Nothing here is run by an agent on its own: every server step is a human gate or an approved step, and every secret
value is Andres's.

## Conventions

Placeholders (the real values live only in the server's git-ignored `.env`, in Andres's private notes and in the
vault; they never enter this repository):

| Placeholder | Meaning |
|---|---|
| `<deploy-user>`, `<deploy-host>` | the ssh account and host name of the server |
| `<deploy-dir>` | the PokéPrices directory on the server: holds `compose.yml`, `.env.example`, the 600-mode `.env` and, from step 15, the backup script and its two unit templates |
| `<backup-dir>` | the directory for the nightly database dumps: an absolute path outside `<deploy-dir>`, created at step 15 |
| `<shared-net>` | the existing external Docker network the catalog service (PokéSearch, alias `pokesearch`) is attached to |
| `<snapshots-dir>` | the collector's day directories on the server; mounted read-only at `/snapshots` |
| `<dataset-dir>` | the dataset checkouts on the server; mounted read-only at `/dataset` |
| `<pokesearch-dir>`, `<pokesearch-repo>` | PokéSearch's checkout on the server, and on the workstation |
| `${APP_PORT}` | the published port of the web container: one free port from 8087 up, chosen at step 1, written only to the server's `.env` |
| `<sha>`, `<tag>`, `<old>`, `<day>`, `<ref>` | the short commit, the image tag `deploy-YYYYMMDD-<sha>`, the previous tag, a collected day, a dataset checkout name |

Rules for every server command:

- Run server commands in the deploy directory. Start each ssh session with:

  ```sh
  cd "$DEPLOY_DIR"
  ```

  (set `DEPLOY_DIR` to `<deploy-dir>`). The stack file is stored there as `compose.yml`, so plain `docker compose`
  finds it and the `.env` next to it.
- Read the published port once per session, without showing anything else from the file:

  ```sh
  APP_PORT=$(sed -n 's/^APP_PORT=//p' .env)
  ```

- Never print, `cat`, source or paste the `.env`. The plain render form of the compose config command prints every
  secret, so on the server it is always `docker compose config -q` (quiet: it prints nothing and exits 0 when the
  file renders).
- Every `docker compose exec` into `api`, `worker` or `scheduler` is `exec -T -u www-data`: the api service's own user
  is root (the php-fpm master only), and artisan must run as the same user as the pool and the worker.
- Image tags are `deploy-YYYYMMDD-<sha>` (UTC date, short commit). The old tag stays on the server until the new one
  has proved itself.
- A step that does not give the stated result is a STOP: do not go on, report the command and its output.
- Every `curl` to a public https URL carries `-A 'Mozilla/5.0'`: Cloudflare answers 403 to bare tool user agents. Loopback checks do not need it.

## Step 0. PokéSearch v1 on the server (Andres's gate HG-48) [server]

**Who runs it:** Andres's gate. Andres, or the orchestrator after his go; the reseed is Andres or the orchestrator only.

The catalog PokéPrices reads (`POKESEARCH_URL`, over `<shared-net>`) is PokéSearch. The server runs the pre-v1 build
(today 20,324 documents); the v1 branch `p1-api-v1`, commit `617721a`, is pushed and the server's `master` is its
ancestor, so a fast-forward is possible. Taste call 10: fast-forward `master` (below) or check out the branch; either
way the server ends on `617721a`. Elasticsearch (`es`) must not be recreated. The Courier pins no document count: the
reseed is a coordination note for it, nothing more.

Server, before anything changes: keep the running image under a rollback tag and write down the document count.

```sh
cd <pokesearch-dir>
docker tag pokesearch-app:latest pokesearch-app:rollback-YYYYMMDD
docker compose -f docker-compose.yml -f docker-compose.server.yml images
curl -sS -A 'Mozilla/5.0' https://pokesearch.andrestheperez.com/healthz
```

Workstation: build the v1 image, stamped with its identity, and ship it.

```sh
cd <pokesearch-repo>
git rev-parse --short HEAD
docker build --build-arg VERSION="$(git describe --tags --always)" --build-arg COMMIT="$(git rev-parse --short HEAD)" --build-arg BUILT="$(date -u +%FT%TZ)" -t pokesearch-app:deploy-YYYYMMDD-617721a .
docker save pokesearch-app:deploy-YYYYMMDD-617721a | gzip | ssh <deploy-user>@<deploy-host> 'gunzip | docker load'
```

Expected: `git rev-parse` prints `617721a`; the load prints `Loaded image: pokesearch-app:deploy-YYYYMMDD-617721a`.

Server: check out the commit, give the loaded image the names the compose files use (the `seed` service is built from
the same Dockerfile), and prove with a dry run that only `app` changes.

```sh
cd <pokesearch-dir>
git fetch origin
git checkout master
git merge --ff-only 617721a
git rev-parse --short HEAD
docker tag pokesearch-app:deploy-YYYYMMDD-617721a pokesearch-app:latest
docker tag pokesearch-app:deploy-YYYYMMDD-617721a pokesearch-seed:latest
docker compose -f docker-compose.yml -f docker-compose.server.yml config -q
docker compose -f docker-compose.yml -f docker-compose.server.yml up -d --no-build --dry-run
```

Expected: `git rev-parse --short HEAD` is `617721a`; the dry run shows `app` being recreated and `es` NOT recreated
(it may list `es` as running or healthy; a `Recreate` line for `es` is a STOP). Only then:

```sh
docker compose -f docker-compose.yml -f docker-compose.server.yml up -d --no-build
```

Reseed with PokéSearch's compose `seed` profile at the pinned ref (the one in the `seed` service of its
`docker-compose.yml`, `<seed-ref>`). The index is populated, so `-force` is required; search is empty for the minutes
the seed takes. The seed downloads the pinned data tarball: if the server cannot fetch it, STOP and tell Andres.

```sh
docker compose -f docker-compose.yml -f docker-compose.server.yml --profile seed run --rm seed -es http://es:9200 -ref <seed-ref> -force
```

`run` does not build here: the `seed` image was tagged just before from the loaded one, so the command only starts it.
`run` has no `--no-build` flag in this Compose version (it answers `unknown flag: --no-build`), which is why the flag
stays on the two `up` lines only.

Verify (public URLs, from anywhere):

```sh
curl -sS -A 'Mozilla/5.0' https://pokesearch.andrestheperez.com/healthz
curl -sS -A 'Mozilla/5.0' -o /dev/null -w '%{http_code}\n' https://pokesearch.andrestheperez.com/api/v1/sets
curl -sS -A 'Mozilla/5.0' -o /dev/null -w '%{http_code}\n' https://pokesearch.andrestheperez.com/
curl -sS -A 'Mozilla/5.0' -o /dev/null -w '%{http_code}\n' https://courier.andrestheperez.com/
```

Expected: `/healthz` answers `{"docs":20635,"status":"ok"}`, `/api/v1/sets` 200, the PokéSearch root 200, the Courier 200.
Tell the Courier's owner that the document count moved from 20,324 to 20,635.

API key, decided here: the default is none (`POKESEARCH_API_KEY` stays empty in step 5). If v1 on the server was
configured to require a key, that is Andres's to set, in the `.env` only.

## Step 1. Preflight [server]

**Who runs it:** Andres, or the orchestrator.

Read-only checks; nothing is changed. Run them in a session that has not touched PokéPrices yet.

```sh
free -h
df -h .
ss -tln
```

Pick `APP_PORT`: one port from 8087 up that `ss -tln` does not list. Write it down for step 5 (it goes only into the
server's `.env`). The ports below 8087 belong to the apps already there.

```sh
docker network inspect <shared-net> --format '{{range .Containers}}{{.Name}}{{"\n"}}{{end}}'
docker ps -q | xargs docker inspect --format '{{.Name}} {{with index .NetworkSettings.Networks "<shared-net>"}}{{.Aliases}}{{end}}'
```

Expected today: the Courier and PokéSearch containers only, with the aliases `pokesearch-app-1`, `app` and `pokesearch`.
STOP when a container name or alias is `api`, `worker`, `scheduler`, `web` or begins `pokeprices-`: the new services join
that network under their service names and a duplicate would split traffic.

The stack's private network uses `198.18.40.0/24`; nothing may use it already:

```sh
ip route
docker network ls
docker network ls -q | xargs docker network inspect --format '{{.Name}} {{range .IPAM.Config}}{{.Subnet}} {{end}}'
```

Expected: no line mentions `198.18.40.0/24`.

The collector's day directories are read by the containers' `www-data` user, which under rootless Docker is another
uid than the collector's, so everything must be world-readable:

```sh
ls -ld <snapshots-dir>
find <snapshots-dir> \( -type d ! -perm -o=rx \) -o \( -type f ! -perm -o=r \) | head -n 1
```

Expected: the `find` prints nothing. The collector timer's service must keep a umask of 022: read its unit with
`systemctl --user cat` on the collector's service (find its name with `systemctl --user list-timers`) and confirm no
`UMask=` other than `0022`. Names only, nothing else from the unit.

The user services outlive the ssh session only with lingering (rootless Docker and the collector timer both need it):

```sh
loginctl show-user "$USER" -p Linger
```

Expected: `Linger=yes`. Anything else is a STOP: enabling it needs sudo, which is Andres's.

## Step 2. Build the images [workstation]

**Who runs it:** the orchestrator, or Andres.

From the repository root, on a clean tree at the commit to deploy.

```sh
git status --short
git rev-parse --short HEAD
git rev-parse HEAD
docker build -t pokeprices-api:<tag> --target runtime -f docker/api/Dockerfile .
docker build -t pokeprices-web:<tag> --target runtime -f docker/web/Dockerfile .
docker compose -f deploy/stack.server.yml --env-file deploy/.env.example config -q
docker images --format '{{.Repository}}:{{.Tag}} {{.ID}}' pokeprices-api pokeprices-web
```

`<tag>` is `deploy-YYYYMMDD-<sha>` with `<sha>` the short commit printed above. Keep the full commit: step 8 stamps it
into the replay (`--code-sha`). Expected: `git status --short` prints nothing, both builds succeed, the config check
prints nothing and exits 0, and both images are listed under the new tag. Write down both image IDs.

## Step 3. Ship the images and the stack file [workstation]

**Who runs it:** the orchestrator, or Andres (it writes to the server).

```sh
docker save pokeprices-api:<tag> pokeprices-web:<tag> | gzip | ssh <deploy-user>@<deploy-host> 'gunzip | docker load'
ssh <deploy-user>@<deploy-host> 'mkdir -p <deploy-dir>'
scp deploy/stack.server.yml <deploy-user>@<deploy-host>:<deploy-dir>/compose.yml
scp deploy/.env.example <deploy-user>@<deploy-host>:<deploy-dir>/.env.example
ssh <deploy-user>@<deploy-host> 'docker images --format "{{.Repository}}:{{.Tag}} {{.ID}}" | grep pokeprices'
```

Expected: the load prints `Loaded image: pokeprices-api:<tag>` and `Loaded image: pokeprices-web:<tag>`; the last command
lists both with the IDs written down in step 2. At this point `compose.yml` and `.env.example` are the only files in `<deploy-dir>`.

## Step 4. Copy the dataset checkout [workstation]

**Who runs it:** the orchestrator, or Andres, after its gate (Andres's go for copying the dataset).

The map build reads a dataset checkout (about 26 MB). Copy it world-readable, because the containers' `www-data` is
another uid than the one that owns the files:

```sh
rsync -a --chmod=Da+rx,Fa+r .data/dataset/<ref>/ <deploy-user>@<deploy-host>:<dataset-dir>/<ref>/
ssh <deploy-user>@<deploy-host> 'ls <dataset-dir>/<ref> | head -n 5'
```

Expected: the checkout's top-level entries are listed. It is mounted read-only; nothing in it is ever written.

## Step 5. Create the `.env` [server]

**Who runs it:** Andres (the secret values are his); the orchestrator may run the non-secret edits and the checks.

```sh
cd "$DEPLOY_DIR"
install -m 600 .env.example .env
ls -l .env
```

Expected: mode `-rw-------`. Edit the non-secret values by hand (`${EDITOR:-vi} .env`), one variable per line, no quotes,
no spaces, nothing appended:

- `IMAGE_TAG`: the step 2 tag. `APP_BIND`: leave `127.0.0.1`, because the tunnel runs on the host itself.
- `APP_PORT`: the port picked at step 1. `SHARED_NET`: `<shared-net>`.
- `SNAPSHOTS_HOST_DIR`: `<snapshots-dir>`. `DATASET_HOST_DIR`: `<dataset-dir>`. Both must be absolute and must exist
  (the stack never creates them).
- `SESSION_LIFETIME` (the idle lifetime, in minutes) and `LOG_LEVEL`: the template's values are fine.
- `TRUSTED_PROXIES` is not in the `.env`: the stack file fixes it to `TRUSTED_PROXIES=REMOTE_ADDR` (only the immediate peer, nginx,
  is trusted, so the rightmost `X-Forwarded-For` entry is the client). Never add `TRUSTED_PROXIES=*`: it would make a
  client-sent header the client address.
- `MAIL_MAILER`: leave `log`. A real mail provider and Turnstile are human gates, not part of this runbook;
  invite-only registration keeps sign-up closed meanwhile.
- `DEMO_PASSWORD`: leave empty, so the demo is reachable only through its button.
- `POKESEARCH_API_KEY`: leave empty unless step 0 decided the catalog needs a key.

Then the three secrets. `APP_KEY` and `DB_PASSWORD` are replaced in place, printing nothing, each guarded so that a
value already set is never overwritten and a missing line is never created:

```sh
grep -q '^APP_KEY=change-me$' .env && sed -i "s|^APP_KEY=change-me\$|APP_KEY=base64:$(head -c 32 /dev/urandom | base64)|" .env
grep -q '^DB_PASSWORD=change-me$' .env && sed -i "s|^DB_PASSWORD=change-me\$|DB_PASSWORD=$(openssl rand -hex 24)|" .env
```

`INVITE_CODE` is set by editor, in Andres's own terminal: at least 16 random characters (HG-03, see
[ACCOUNTS.md](ACCOUNTS.md#invite-code)). Never append a key to the file with a redirect; edit the line that exists.
`key:generate` cannot be used here: the image holds no `.env` for it to write.

`DB_PASSWORD` never changes after the first `up`: Postgres applies `POSTGRES_PASSWORD` only when it initialises its
data directory, so a later edit of the file would lock the app out of the database.

Gate: no placeholder may remain, and the file must render.

```sh
grep -c 'change-me' .env
docker compose config -q
```

Expected: the count prints `0` (its exit status is 1 when nothing matches, which is the good case) and the config check prints
nothing and exits 0. A message naming a variable means that line is missing or empty: fix it and repeat. Do not go to
step 6 before both hold.

## Step 6. Start the data stores, the api and the web server [server]

**Who runs it:** Andres, or the orchestrator.

This is the first real start of the runtime image: it has only been built and probed, never run. If `api` does not
become healthy, run `docker compose logs api` and STOP: nothing is public yet, so nothing is lost by waiting.

```sh
docker compose up -d --wait postgres redis-queue redis-cache api web
docker compose ps
docker compose top api
docker compose exec -T -u www-data api php-fpm -tt 2>&1 | grep allowed_clients
docker compose exec -T -u www-data api printenv TRUSTED_PROXIES
```

Expected: five services healthy; in `top`, the php-fpm master runs as root (the unprivileged host user under rootless
Docker) and its pool workers as `www-data`; the last line shows `listen.allowed_clients = 127.0.0.1,198.18.40.10` (only
loopback and the web container may speak FastCGI to the api); `printenv` prints `REMOTE_ADDR`.

## Step 7. Migrate, seed the set aliases, start the worker [server]

**Who runs it:** Andres, or the orchestrator.

```sh
docker compose exec -T -u www-data api php artisan migrate --force
docker compose exec -T -u www-data api php artisan db:seed --class=SetAliasSeeder --force --no-interaction
docker compose exec -T -u www-data api php artisan package:discover
docker compose up -d --wait worker
```

Why in this order: the product map build (step 8) reads the set aliases, so the alias seeder runs before it.
The image carries no package manifest cache, so `package:discover` runs once, as `www-data`, before the worker and the
scheduler start. `--force` is required: the app runs in production mode and refuses to seed or migrate without it.

First deploy: the database is empty, so the `price_movers` migration `2026_10_28_000002_…` cannot find a row without
a real close and is trivially satisfied. On a later deploy, when `docker compose exec -T -u www-data api php artisan migrate:status`
lists that migration as Pending, run this read-only count first and STOP if it is not `0` (such a row would make the
migration's `NOT NULL` step fail):

```sh
docker compose exec -T postgres psql -U pokeprices -d pokeprices -c "select count(*) from price_movers m where not exists (select 1 from price_daily d where d.source = m.source and d.day = m.day and not d.is_filled)"
```

Expected: the migrations run to the end, the alias seeder finishes without an error, `package:discover` lists the
discovered packages, and `worker` is healthy. The scheduler stays down until step 9.

## Step 8. Bootstrap the price data [server]

**Who runs it:** Andres, or the orchestrator; run it after step 0, because the map build and the catalog checks need
PokéSearch v1. The replay takes minutes per collected day, and the server has no tmux or screen: it runs detached
with `nohup setsid` (below), so a dropped ssh does not interrupt it.

Pick the newest collected day that has its `SUCCESS` marker, and the dataset checkout copied in step 4. These two
commands read through the containers, so they also prove the mounts and the `www-data` access:

```sh
docker compose exec -T -u www-data api ls /snapshots
docker compose exec -T -u www-data api ls /snapshots/<day>/SUCCESS
docker compose exec -T -u www-data api ls /dataset/<ref>
```

Then the sequence of [OPERATIONS.md](OPERATIONS.md#map-rebuild-then-replay), on the server's paths:

```sh
docker compose exec -T -u www-data api php artisan pokeprices:map:build --day <day> --dataset /dataset/<ref>
docker compose exec -T -u www-data api php artisan pokeprices:map:audit --check constraints
docker compose exec -T -u www-data api php artisan pokeprices:map:audit --check coverage
docker compose exec -T -u www-data api php artisan pokeprices:map:audit --check ambiguity
docker compose exec -T -u www-data api php artisan pokeprices:map:audit --check names
docker compose exec -T -u www-data api php artisan pokeprices:map:golden
```

Read the audit and golden values before the replay, as OPERATIONS says: a worse `coverage` or a new `ambiguity` is a
STOP. Then start the replay detached. `<full-sha>` is the full commit from step 2. The `echo` writes the exit status
into the log, and nothing is read from the terminal:

```sh
nohup setsid sh -c 'docker compose exec -T -u www-data api php artisan pokeprices:prices:replay --fresh --code-sha <full-sha>; echo REPLAY_EXIT $?' > replay.log 2>&1 < /dev/null &
```

From a second ssh session, poll it. The command's own output is buffered until it ends, so `replay.log` stays empty
while it runs and the progress is read from the `ingest_runs` table (the newest `replay` runs show which day is being ingested):

```sh
tail -n 5 replay.log
docker compose exec -T postgres psql -U pokeprices -d pokeprices -c "select id, day, mode, status from ingest_runs order by id desc limit 5"
```

It is finished when `replay.log` ends with `REPLAY_EXIT 0`; the line before it is the JSON summary and must hold
`"identical":true`. A different exit status, or `"identical":false`, is a STOP. Three passes over 31 collected days took
about 35 minutes on the server (roughly 9, 8 and 19 minutes). Then:

```sh
docker compose exec -T -u www-data api php artisan db:seed --class=DemoAccountSeeder --force --no-interaction
curl -sS -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:${APP_PORT}/v1/demo/session
docker compose exec -T -u www-data api php artisan pokeprices:prices:freshness
```

The oracle comparison (`pokeprices:map:oracle`) is skipped on purpose: its
input, the recorded oracle files, is not on the server and nothing mounts it; the other checks still gate the replay.

`pokeprices:map:golden` runs in an image that holds no git checkout, so its approval and baseline parts cannot be judged
there: expect `approval_ratified 0` and `mapped_cards_regression 1`, and a `value` of the mismatches plus the forbidden hits
plus 1, so `1` when nothing else is wrong. That is not a STOP. What gates the replay is the `summary` part of its
`--json` artefact: `mismatches` and `forbidden_hits` must both be 0. An image built before the δ Rainbow Energy mapping
fix reports rows 35 and 36 as its only two mismatches (`value` 3); the map rebuild of step 14 clears them. Any other
mismatch, and any forbidden hit, is a STOP.

The demo account is seeded after the replay (it points at price variants the replay's map defines), and the seeder
must print no "skipped" line: a skipped variant means the map lacks it, so STOP. The `POST` is only a presence probe:
anything but `404` means the demo is seeded (`404` is the `demo-unavailable` answer); the button itself is tried
in step 12. `pokeprices:prices:freshness` shows the newest day's `as_of` and a `last_run` that is the replay with status
`ok`. A `within_slo` that is false only means the newest collected day is old; step 9 shows it clearing.

The scheduler stays down until here, so nothing ingests while the replay runs.

## Step 9. Start the scheduler [server]

**Who runs it:** Andres, or the orchestrator.

```sh
docker compose up -d --wait scheduler
docker compose exec -T -u www-data scheduler php artisan schedule:list
docker compose logs --since 2m worker
```

Expected: `schedule:list` shows exactly ten entries, each with its cron expression. Five show by name:
`pokeprices-probe`, `pokeprices-ingest-pending`, `pokeprices-freshness`, `pokeprices-alerts-dispatch` and
`pokeprices-deliveries`. The four daily prunes and the hourly price-job report show as the commands they run, not by
name (the "What runs where" table names them): `php artisan queue:prune-failed --hours=720`,
`php artisan queue:prune-batches --hours=720`,
`php artisan model:prune --model='App\Domain\Pricing\Models\IngestRunChunk'`, `php artisan auth:clear-resets` and
`php artisan pokeprices:prices:report-problems`.
Within two minutes the worker log shows
`pokeprices-probe` running.

After the collector timer next adds a day (the :40 scan ingests it in place), check that the real collector's files
are readable and the run is `ok`:

```sh
docker compose exec -T postgres psql -U pokeprices -d pokeprices -c "select id, day, mode, status, counts->>'mapped' as mapped, left(error, 80) as error from ingest_runs order by id desc limit 10"
find <snapshots-dir> \( -type d ! -perm -o=rx \) -o \( -type f ! -perm -o=r \) | head -n 1
docker compose logs worker | grep -c 'Permission denied'
```

Expected: the newest run is `ok` for the new day; the `find` prints nothing; the count is `0`.

## Step 10. Verify on the server's own port [server]

**Who runs it:** Andres, or the orchestrator.

```sh
curl -sS http://127.0.0.1:${APP_PORT}/v1/ready
curl -sSI http://127.0.0.1:${APP_PORT}/
docker stats --no-stream
```

Expected: `/v1/ready` reports every component ok, including `pokesearch` (reached over `<shared-net>` as
`http://pokesearch:…`; a failure there means step 0 or the network, so STOP); the root answers 200; `docker stats` shows the
memory limits of the stack file (postgres 1 GiB, redis-queue 64 MiB, redis-cache 128 MiB, api 384 MiB, worker 384 MiB,
scheduler 128 MiB, web 64 MiB). Limits that show as the whole host are a finding to report, not to ignore.

Optional, Andres only: from a container of another app on `<shared-net>`, a TCP connection to the `api` service's
FastCGI port must not be answered (the pool's allow-list drops it). Nobody touches another app's container otherwise.

## Step 11. Add the tunnel rule [server, sudo]

**Who runs it:** 11a (staging, no sudo): Andres, or the orchestrator. 11b (sudo): Andres, or the orchestrator when
Andres orders that run. The rule is one added ingress entry; every existing hostname must still answer 200 afterwards.

11a. Stage a copy and validate it (no sudo; the file is world-readable):

```sh
cp /etc/cloudflared/config.yml "$HOME/cloudflared-config.staged.yml"
${EDITOR:-vi} "$HOME/cloudflared-config.staged.yml"
```

Add exactly one rule above the final `http_status:404` entry, the port being the number from the server's `.env`
(`${APP_PORT}` below stands for it). The service is the literal `127.0.0.1`, never `localhost`, which can resolve to
`::1` while the container's port is published on IPv4 only:

```yaml
  - hostname: pokeprices.andrestheperez.com
    service: http://127.0.0.1:${APP_PORT}
```

```sh
cloudflared tunnel --config "$HOME/cloudflared-config.staged.yml" ingress validate
cloudflared tunnel --config "$HOME/cloudflared-config.staged.yml" ingress rule https://pokeprices.andrestheperez.com/
cloudflared tunnel --config "$HOME/cloudflared-config.staged.yml" ingress rule https://pokesearch.andrestheperez.com/
cloudflared tunnel --config "$HOME/cloudflared-config.staged.yml" ingress rule https://courier.andrestheperez.com/
```

Expected: `OK`, and each `rule` command names the matching hostname and service (the first one the new rule, the others
unchanged). The option `--config` goes before `ingress`.

11b. Install it (sudo), then check every hostname at once:

```sh
sudo cp -p /etc/cloudflared/config.yml /etc/cloudflared/config.yml.bak-YYYY-MM-DD
sudo install -m 644 "$HOME/cloudflared-config.staged.yml" /etc/cloudflared/config.yml
sudo systemctl restart cloudflared
systemctl is-active cloudflared
for h in pokesearch.andrestheperez.com courier.andrestheperez.com andrestheperez.com www.andrestheperez.com pokeprices.andrestheperez.com; do curl -sS -A 'Mozilla/5.0' -o /dev/null -w "$h %{http_code}\n" "https://$h/"; done
```

Expected: `active`, and all five hosts answer 200 (retry for up to a minute after the restart). Any other answer for an
existing host: restore the backup at once (step 13).

## Step 12. Verify in public [workstation]

**Who runs it:** Andres (the browser parts); the orchestrator may run the `curl` lines.

```sh
curl -sS -A 'Mozilla/5.0' https://pokeprices.andrestheperez.com/v1/ready
curl -sSI -A 'Mozilla/5.0' https://pokeprices.andrestheperez.com/
curl -sS -A 'Mozilla/5.0' -D - -o /dev/null https://pokeprices.andrestheperez.com/sanctum/csrf-cookie
curl -sSI -A 'Mozilla/5.0' https://pokeprices.andrestheperez.com/ | grep -iE '^(x-content-type-options|strict-transport-security|x-frame-options|content-security-policy|referrer-policy|permissions-policy):'
```

Expected: ready reports every component ok; the root answers 200; every `Set-Cookie` of the csrf-cookie response carries
`Secure`; the last command prints exactly six header lines (`X-Content-Type-Options`, `Strict-Transport-Security`,
`X-Frame-Options`, `Content-Security-Policy`, `Referrer-Policy`, `Permissions-Policy`). In a browser: the single-page app loads, the `Try the read-only demo` button signs in the demo account, and a
price shows its source and its "as of" date.

## Step 13. Rollback [server, sudo]

**Who runs it:** the image roll-back: Andres, or the orchestrator. The tunnel roll-back needs sudo: Andres, or the
orchestrator when Andres orders that run.

Images. Put the previous tag back in the `.env` (the tag is not a secret) and restart the stack on it:

```sh
sed -i "s|^IMAGE_TAG=.*|IMAGE_TAG=<old>|" .env
docker compose up -d --wait
docker inspect --format '{{.Config.Image}} {{.Image}}' $(docker compose ps -q api web)
```

Expected: the images are the `<old>` ones and the image IDs equal the IDs recorded before the update. Migrations are not
reverted: go back only to a tag whose code works with the migrated schema; otherwise restore the database from a backup
instead (the nightly dumps of step 15, restored as step 16 says: drill it first, the real restore only on Andres's go).

Tunnel. Restore the backup config, restart, and run the same matrix as step 11b:

```sh
sudo cp -p /etc/cloudflared/config.yml.bak-YYYY-MM-DD /etc/cloudflared/config.yml
sudo systemctl restart cloudflared
for h in pokesearch.andrestheperez.com courier.andrestheperez.com andrestheperez.com www.andrestheperez.com; do curl -sS -A 'Mozilla/5.0' -o /dev/null -w "$h %{http_code}\n" "https://$h/"; done
```

Expected: the four existing hosts answer 200; the PokéPrices host no longer answers from the app.

## Step 14. Update procedure [server]

**Who runs it:** Andres, or the orchestrator after his go. The build and ship legs are steps 2 and 3, on the workstation.

1. Build and ship the new tag (steps 2 and 3). Keep the old tag on the server: do not remove it. Step 3 also ships the
   current stack file. When it differs from the one running, the `up -d` of item 4 recreates every container once,
   postgres and both redis included (their data stays in the named volumes), because a changed service definition
   (a label, say) recreates the service. That is expected the first time after such a change, not a fault.
2. Record what runs now (the IDs the roll-back compares against) and, once step 15 is done, take a fresh backup before
   anything migrates:

   ```sh
   docker inspect --format '{{.Config.Image}} {{.Image}}' $(docker compose ps -q api web)
   systemctl --user start pokeprices-backup.service
   ```

3. Pending-migration check, on the workstation, between the two commits: read every migration the new commit adds. If
   it adds `2026_10_28_000002_…` (the `price_movers` step), run the read-only count of step 7 first and STOP if it is not `0`.

   ```sh
   git diff --name-status <old-sha> <sha> -- api/database/migrations
   ```

4. Switch the tag and restart on it:

   ```sh
   sed -i "s|^IMAGE_TAG=.*|IMAGE_TAG=<tag>|" .env
   docker compose config -q
   docker compose up -d --wait
   docker compose exec -T -u www-data api php artisan migrate --force
   docker inspect --format '{{.Config.Image}} {{.Image}}' $(docker compose ps -q api web)
   ```

   When item 6 ("after a map fix") applies, run `docker compose stop scheduler` straight away after the `up -d --wait`
   above, before the `migrate`: the `up` has just started the scheduler on the new image, and an hourly scan in that gap
   would ingest against the old map. Item 6 repeats the stop.

5. Run the public matrix of step 11b and the checks of step 12.

Expected: the image IDs differ from the recorded ones and match the new build, `migrate` ends without an error, every host
answers 200. Anything else: roll back (step 13).

6. After a map fix. On the workstation, when this is not empty, the new commit changes how products map to cards:

   ```sh
   git diff <old-sha> <sha> -- api/app/Domain/Mapping
   ```

   Then the product map is stale until it is rebuilt and the prices are replayed through it. On the server, in the new
   image: stop the scheduler first (item 4 started it), so nothing ingests in between (the replay holds the ingest lock too), and run step 8's
   map sequence again, with `<day>` and `<ref>` as there: `map:build`, the four `map:audit` checks and `map:golden`, read
   with the same STOP rules (the rows 35 and 36 mismatches are gone once the fixed code has rebuilt the map).

   ```sh
   docker compose stop scheduler
   docker compose exec -T -u www-data api php artisan pokeprices:map:build --day <day> --dataset /dataset/<ref>
   ```

   Then the four audits and the golden command of step 8, and the replay detached, as there:

   ```sh
   nohup setsid sh -c 'docker compose exec -T -u www-data api php artisan pokeprices:prices:replay --fresh --code-sha <full-sha>; echo REPLAY_EXIT $?' > replay.log 2>&1 < /dev/null &
   tail -n 5 replay.log
   ```

   Poll it with `tail -n 5 replay.log` and step 8's `ingest_runs` query from a second session. It takes about 35 minutes
   for 31 collected days on the server, because it runs three passes. The replay truncates the price tables at the start of pass 1 and of
   pass 2 and refills them, so the public site shows missing or partial prices until it ends: tell Andres first.
   A replay that stops with `replay locked: pricing ingest lock held` has written nothing: another writer of the
   price tables holds the ingest lock, a running ingest (see [OPERATIONS.md](OPERATIONS.md#the-lock-is-held-or-stuck)).
   That is not a STOP: wait for that ingest to end and run the replay again, and keep the scheduler stopped meanwhile. A
   replay that stops with `replay refused: price_events holds dispatched rows` has written nothing either, and that is
   a STOP, because alerts have already been dispatched from the current events: the scheduler stays stopped (do not
   start it to get past the refusal) and the operator tells Andres. Start the scheduler only after a replay that ran to
   the end. When `replay.log` ends with `REPLAY_EXIT 0` and `"identical":true`,
   start the scheduler:

   ```sh
   docker compose up -d --wait scheduler
   docker compose exec -T -u www-data api php artisan pokeprices:prices:freshness
   ```

   `DemoAccountSeeder --force` does not have to run again: the replay truncates only the price tables, the demo account,
   its watchlist and portfolio rows live in other tables, and the map build only adds to `card_variants`. Run it again
   (it is idempotent) only if the first run printed a "skipped" line, since a rebuilt map may now hold that variant.

## Step 15. Backup timer [server]

**Who runs it:** Andres, or the orchestrator after his go. The copy leg runs from the workstation (like step 3); the rest
runs on the server. Run it after step 6, when `postgres` is up.

A user timer dumps the database every night at 05:17 UTC with `pg_dump -Fc` inside the `postgres` container (socket
authentication there: the script handles no password and reads no environment file), checks that the dump is not empty
and that `pg_restore --list` can read it, and only then names it `pokeprices-<UTC stamp>.dump`. The 14 newest dumps are
kept. Files are mode 600. A failed dump leaves nothing behind and the unit fails. The three files are
`deploy/backup/pokeprices-backup.sh`, `deploy/backup/pokeprices-backup.service` and `deploy/backup/pokeprices-backup.timer`;
the `scp` also carries the restore drill script of step 16, which this step does not run.

Lingering: the user timer only fires while the user's services outlive the ssh session. That is the `Linger=yes` check
of step 1, so nothing is repeated here; if it was not `yes` then, this step is a STOP too.

Copy leg, on the workstation, from the repository root:

```sh
scp deploy/backup/pokeprices-backup.sh deploy/backup/pokeprices-backup.service deploy/backup/pokeprices-backup.timer deploy/backup/pokeprices-restore-drill.sh <deploy-user>@<deploy-host>:<deploy-dir>/
```

On the server. `DEPLOY_DIR` must be the absolute `<deploy-dir>` (the unit's paths are copied from it) and `<backup-dir>`
an absolute path outside it. The service file is a template whose two `@…@` tokens are filled in with `sed`, using `|`
as the delimiter because the values are paths; the timer needs no edit:

```sh
cd "$DEPLOY_DIR"
BACKUP_DIR=<backup-dir>
UNIT_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"
chmod 755 pokeprices-backup.sh
mkdir -p "$BACKUP_DIR" "$UNIT_DIR"
sed "s|@DEPLOY_DIR@|$DEPLOY_DIR|g; s|@BACKUP_DIR@|$BACKUP_DIR|g" pokeprices-backup.service > "$UNIT_DIR/pokeprices-backup.service"
cp pokeprices-backup.timer "$UNIT_DIR/pokeprices-backup.timer"
grep -c '@' "$UNIT_DIR/pokeprices-backup.service"
systemctl --user daemon-reload
systemctl --user enable --now pokeprices-backup.timer
systemctl --user start pokeprices-backup.service
journalctl --user -u pokeprices-backup.service -n 5 --no-pager
ls -l "$BACKUP_DIR"
systemctl --user list-timers
```

Expected: the `grep -c` prints `0` (its exit status is 1 when nothing matches, which is the good case: no token is left);
the manual `start` returns without an error after the dump ran (the unit is a one-shot); the journal shows one line, the
dump's file name and its size in bytes; `ls -l` shows exactly one `-rw-------` file `pokeprices-<stamp>.dump`; and
`list-timers` shows `pokeprices-backup.timer` with a next run at 05:17 UTC. A failed `start` means read the journal and STOP:
the usual causes are a Docker socket that is not at the user runtime directory (`DOCKER_HOST` in the unit points there) or
a `postgres` service that is not up.

## Step 16. Restore drill [server]

**Who runs it:** 16a: Andres, or the orchestrator after his go; it only creates and drops a scratch database. 16b, the real
restore: Andres only, on his explicit go. Run it after step 15 has made at least one dump.

A dump that `pg_restore --list` can read (the check of step 15) is not yet a dump that restores. The drill restores it into
a scratch database named `pokeprices_restore_drill` and compares what came back with the live database. The script cannot
touch the live database: it creates, fills, checks and drops only that one name (it takes no database name, and refuses one
in its environment), every query it runs is a read-only session, and it only reads the dump file. It refuses to start while
a database of that name already exists unless it is given `--replace`, and it drops the scratch database on every exit,
an interrupted one included. While it runs, the `postgres` volume holds a second copy of the database.

16a. The drill. The script is already in `<deploy-dir>` (step 15's `scp` carried it). On the server, on the newest dump:

```sh
cd "$DEPLOY_DIR"
BACKUP_DIR=<backup-dir>
chmod 755 pokeprices-restore-drill.sh
DEPLOY_DIR="$DEPLOY_DIR" ./pokeprices-restore-drill.sh "$(ls -1 "$BACKUP_DIR"/pokeprices-*.dump | sort | tail -n 1)"
echo "EXIT $?"
```

Expected: `EXIT 0`, and this shape of output (the numbers are the database's own):

```
restoring pokeprices-<stamp>.dump into the scratch database pokeprices_restore_drill
table drill_rows live_rows
card_variants <n> <n>
...
migrations <n> <n>
...
dump as of (newest ingest day): <day>
restore drill ok: <n> tables, the same set as the live database (live counts have moved on since the dump)
scratch database pokeprices_restore_drill dropped
```

One line per table with the exact row count in the restored copy and in the live database. The counts need not match: the
live database kept moving after the dump (sessions, ingest runs, the days collected since), so a live count above the
restored one is normal; a table missing on either side is not, and fails the drill. The `migrations` counts match when no
migration ran since the dump. The "as of" line is the newest `ingest_runs.day` inside the dump: the day the restored
database would be back at. A non-zero exit means the dump did not restore; read the last lines, they say whether the
scratch database was dropped or left behind (then remove it with the `dropdb` line the message prints, or run the drill
again with `--replace` before the dump path). Nothing in the live database changed either way. A failed drill is a STOP: the
backups cannot be relied on until the cause is found.

Cadence: once after this redeploy, then after every update that adds a migration (the `git diff --name-status` of step 14,
item 3, shows it), so a dump of the new schema has been proven restorable. For that run take a dump after the migration
first (`systemctl --user start pokeprices-backup.service`) and drill that one: the newest dump from before the update lacks
the new tables, so the drill would fail the table comparison for a reason that is not a bad backup.

When to run it on a live site: the count comparison reads the live tables, and the hourly :40 scan
(`pokeprices-ingest-pending`, step 9) writes to them, so a drill that overlaps an ingest can report a count difference that
is not a bad backup. Start it away from minute :40 (an ingest of a day can run past it, so check the worker is idle first
with step 8's `ingest_runs` query), and when the only thing that looks wrong is a count in the price tables, run the drill
once more before treating it as a STOP.

16b. The real restore, on Andres's go only. It replaces the live database with the chosen dump: everything written after
that dump is lost from the database (accounts, watchlists, holdings and settings created since, the sessions, the alert
delivery records), and the site is down while it runs. The database is dropped and rebuilt from the dump, so a dump
older than a migration restores the older schema and the `migrate` below runs the later migrations again. Take a fresh
dump first, so the state that is being replaced can be restored again; its pruning removes the oldest dump beyond the 14
kept, so copy the chosen dump aside first when it is the oldest. The `dropdb`, `createdb` and `pg_restore` lines below
are the destructive ones: none of them runs without Andres's go.

```sh
cd "$DEPLOY_DIR"
BACKUP_DIR=<backup-dir>
systemctl --user start pokeprices-backup.service
ls -l "$BACKUP_DIR"
docker compose stop scheduler worker api web
# the next three lines destroy the live database: Andres's go first; stop here if any of them fails
docker compose exec -T postgres dropdb -U pokeprices --force pokeprices
docker compose exec -T postgres createdb -U pokeprices pokeprices
docker compose exec -T postgres pg_restore -U pokeprices --no-owner --exit-on-error --single-transaction -d pokeprices < "$BACKUP_DIR"/pokeprices-<stamp>.dump
docker compose up -d --wait
docker compose exec -T -u www-data api php artisan migrate --force
curl -sS http://127.0.0.1:${APP_PORT}/v1/ready
docker compose exec -T -u www-data api php artisan pokeprices:prices:freshness
```

The writers are stopped first so nothing writes while the database is replaced; `postgres` and the two redis stay up.
Stop here if the restore line fails: the drop is not undone by a failed restore, so then the database is empty. Restore the
fresh dump taken first the same way (the `createdb` and `pg_restore` lines on that file; `dropdb` first if a half-filled
database was left), and tell Andres; do not run the lines after it. The queue lives in redis and is neither dumped nor
restored, so jobs queued before the restore may still be there and the worker takes them once it is up: check the queue
before the `up` below. The project documents no read-only command for the redis queue itself (OPERATIONS.md covers only
`failed_jobs`), so none is given here: if you cannot look at it, ask Andres before the `up`. Expected: `up` ends healthy,
`migrate` has nothing to run (or only the migrations the dump predates), `/v1/ready` reports every component ok, and
`prices:freshness` reports the newest day the restored database holds, which at first is the day of the dump's own "as of"
line.

Days collected after the dump: the restored `ingest_runs` knows nothing about them, so the hourly :40 scan sees a
collector day with a `SUCCESS` marker and no `ok` run and ingests it again, in place from `<snapshots-dir>`, oldest first;
no replay is needed; the freshness climbs back as those scans finish. One thing the scan does not rebuild: the alert delivery records go back to the dump too, so alerts sent for
a day after the dump can be created and sent again. Tell Andres before the restore.

## What runs where

Once the stack is up, nothing needs a person except the updates of step 14.

| What | Where | When | Does |
|---|---|---|---|
| `pokeprices-probe` | `scheduler` container (the job runs in `worker`) | every minute | queue liveness probe |
| `pokeprices-ingest-pending` | `scheduler` container (the job runs in `worker`) | hourly at :40 | ingests each collected day that holds `SUCCESS` and needs a run, in place from `/snapshots` |
| `pokeprices-freshness` | `scheduler` container (the job runs in `worker`) | hourly | logs the freshness of the newest day against the 26 h SLO |
| `pokeprices-alerts-dispatch` | `scheduler` container (the job runs in `worker`) | every five minutes | creates the deliveries of the day's price moves |
| `pokeprices-deliveries` | `scheduler` container (the job runs in `worker`) | every minute | sends the deliveries that are due |
| `pokeprices-prune-failed` | `scheduler` container | daily at 00:00 UTC | prunes failed queue jobs older than 30 days |
| `pokeprices-prune-batches` | `scheduler` container | daily at 00:00 UTC | prunes job batches older than 30 days |
| `pokeprices-prune-chunks` | `scheduler` container | daily at 00:00 UTC | prunes the chunks of superseded ingest runs, 30 days after they finished |
| `pokeprices-prune-resets` | `scheduler` container | daily at 03:17 UTC | clears the expired password-reset tokens (`auth:clear-resets`) |
| `pokeprices-price-problems` | `scheduler` container | hourly | posts each price-job problem to Discord once when it starts and once when it clears (`pokeprices:prices:report-problems`); posts nothing until `PRICE_JOB_DISCORD_WEBHOOK_URL` is set |
| the queue worker | `worker` container | always | runs the queued jobs above |
| the collector timer | the server's host, a user timer | a few times a day (UTC) | writes the day folders to `<snapshots-dir>`; the containers only read them |
| the backup timer | the server's host, a user timer (step 15) | daily at 05:17 UTC | dumps the database to `<backup-dir>` |
| Prerequisite: PokéSearch v1 (HG-48, step 0) | the server, on `<shared-net>` as `pokesearch` | always | the catalog the API reads; `/v1/ready` fails without it |
