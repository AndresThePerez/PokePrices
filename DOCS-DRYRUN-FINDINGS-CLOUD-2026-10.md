# Docs dry run: PokéPrices (cloud sandbox, 2026-10-14)

Branch under test: `p5-work` at `90e4016`. Method: a newcomer follows the README, then every doc it links
(and every doc those link), verbatim and in order. A gap counts as a finding, not something to work around.
No code or doc was changed.

Friction classes: **WORKS**, **UNCLEAR** (had to guess), **WRONG** (doc and repo disagree),
**BLOCKED** (cannot proceed in this sandbox; not a doc defect).

Sandbox facts: `docker` 29.8.2 and `docker compose` v5.6.0 are installed, but the daemon was not running.
Starting `dockerd` worked. Every image pull from Docker Hub then returned `429 Too Many Requests`, on the
first try and on one retry. No container ever ran, so every step that needs the stack was checked
statically: the files it names exist, the flags are real, the service names match `compose.dev.yml`, and
the versions match the lockfiles. Host tools: GNU Make 4.3, Python 3.13.16, jq 1.7, git.

---

## 1. Step-by-step log

| # | Instruction followed (source) | What happened | Class |
|---|---|---|---|
| 0 | Clone `AndresThePerez/PokePrices`, check out `p5-work` | The clone was already present with working credentials. `git checkout p5-work` worked. | WORKS |
| 1 | README: read "Layout" | `api/`, `web/`, `compose.dev.yml`, `gates/` and `scripts/` all exist. Laravel 13 matches (`composer.lock`: framework v13.35.0), and so do React 19.3.0, Vite 8.3.4, TypeScript 6.0.3 and Tailwind 4.3.3 (`web/package.json`). | WORKS |
| 2 | README: "nothing is installed on the host" | Not true for the gate path. `scripts/scorecard` and `scripts/verdict` are host Python (`#!/usr/bin/env -S python3 -I`). Gate scripts call host `jq` (about 70 calls), `git`, `sha256sum`, `bash` and `make`, and docs use `curl`. EC-mex needs a host `mex` binary or an nvm install under `$HOME`. No prerequisites list exists anywhere. | WRONG |
| 3 | README: `make init` | `make` → `docker compose ... up -d --wait --build postgres redis-queue redis-cache api node tools`. The Docker daemon was absent at first; after starting it, image pulls failed with 429 (two tries). | BLOCKED (sandbox) |
| 3a | Static check of `make init` | The `Makefile` targets `up` and `init` exist. `scripts/dev/ensure-databases.sh` and `scripts/dev/init-api-env.sh` exist. `api/.env.example`, `api/composer.lock` and `web/package-lock.json` exist. The services named in `CORE` all exist in `compose.dev.yml`. `docker/api/Dockerfile` has target `dev`, and `docker/tools/Dockerfile` exists. | WORKS (static) |
| 3b | README: "The dev site is `http://pokeprices.localhost:8095`" (right after `make init`) | `make init` starts only `CORE`, which does not include `web`, `worker` or `scheduler`. Port 8095 belongs to `web`, so after `make init` the dev site is not up. The only documented commands that start `web` are `make gates` (a full `up`) and WEB.md's rebuild command. The README never says which to run. | WRONG |
| 4 | README: `make gates` | Needs the stack, so blocked. I ran the host-only part through the documented scorecard: `scripts/scorecard --manifest gates/manifest-P0.json --only VER-selftest,HY-hygiene,AG-values`. Results: VER-selftest **PASS**. HY-hygiene **FAIL**: `local_patterns_missing: context primary needs the untracked local pattern file`. AG-values FAIL, because it needs containers (sandbox). | WRONG (HY-hygiene) / BLOCKED (rest) |
| 4a | Static check of `make gates` in a newcomer's default context | `run_context()` returns `primary` unless `GITHUB_ACTIONS=true`. In `primary`, three hard gates need untracked machine-local material that no reachable doc mentions. HY-hygiene needs `.git/info/hygiene.local`; its contents are "listed in plan task P0-i1-T29", which is not in the repo. EC-mex needs a local `.mex` scaffold and a `mex` binary. EC-ci-parity runs a nested fresh clone of the whole stack on port 18095. So `make gates` cannot pass on a fresh clone. | WRONG |
| 4b | README: "CI runs exactly this" | The command is the same, but CI runs in context `ci` (`GITHUB_ACTIONS`), which skips EC-mex and EC-ci-parity and does not require `hygiene.local`. Local and CI results differ. The only CI run on record is on `main` at P0-i2 (success); none on `p5-work`. | UNCLEAR |
| 4c | Dirty-tree rule (found only in `gates/manifest-P0.md`) | `scripts/scorecard` refuses any tree with untracked or modified files. A newcomer who edits a file and runs `make gates` to check it is refused. The README does not say to commit first. | UNCLEAR |
| 5 | README: `make verdict FINDINGS=<path/to/findings.json>` | Without `FINDINGS` it prints its own usage error (correct). With a path but no prior scorecard, it reports `scorecard:unreadable`. The `findings.json` schema, who writes it and what "loop step" means appear in no reachable doc; they are in `scripts/verdict`'s docstring only. | UNCLEAR |
| 6 | README: `make down` | Target exists (`docker compose -f compose.dev.yml down`). | WORKS (static) |
| 7 | `make` / `make help` | Prints `make up \| make init \| make gates \| make verdict FINDINGS=<path> \| make down` with no descriptions. `make up` is not in the README. | WORKS (thin) |
| 8 | README → `docs/OPERATIONS.md`, compose prefix line | The service `api` exists, `-w /workspace/api` matches the bind mount, and `postgres` and user/db `pokeprices` match. | WORKS (static) |
| 9 | OPERATIONS: "Check that the day arrived" | Command names match their `#[Signature]`s: `schedule:list`, `pokeprices:prices:freshness` and its `--json`. Schedule names match `routes/console.php`: `pokeprices-ingest-pending` at `hourlyAt(40)` and `pokeprices-freshness` hourly. The route `GET /v1/prices/freshness` exists. | WORKS (static) |
| 9a | OPERATIONS: the whole runbook assumes `.data/snapshots/<day>/` | `.data/` is gitignored and absent. The only explanation is "a separate read-only pull step", with no command, source or owner given. `.data/dataset/<dataset-checkout>` (for `map:build`) is also never explained. A newcomer has no way to get any price data, so every priced page and command shows the empty state. | BLOCKED (by docs, not sandbox) |
| 10 | OPERATIONS: ingest by hand | `--day`, `--sync`, `--queued`, `--resume`, `--wait=`, `--queue-connection`, `--force` and `--digest` all exist. The undocumented `--snapshots=` and `--connection=` also exist. | WORKS (static) |
| 11 | OPERATIONS: resume, psql queries | The tables and columns are plausible. The lock id `1085361408` equals `crc32('pricing:ingest')`, as `IngestLock` derives it. | WORKS (static) |
| 12 | OPERATIONS: replay | `--fresh`, `--order=asc\|desc`, `--days=` and `--code-sha=` exist. `"$(git rev-parse HEAD)"` expands on the host to lowercase hex, which the validator accepts. `tests/p2/T07.sh` exists. | WORKS (static) |
| 13 | OPERATIONS: map rebuild then replay | `map:build --day --dataset`, `map:audit --check names\|constraints\|coverage\|ambiguity --json --out`, `map:golden` and `map:oracle` all exist. `map:oracle` "needs the oracle recorded for the build's day", but the command that records it, `pokeprices:oracle:fetch --day`, is never named. | UNCLEAR |
| 14 | OPERATIONS: "Open decision … ADR 1 draft, question 11" | `docs/adr/0001-day-close.md` is `Status: Accepted (Andres, 2026-10-14)`, and its row 11 records the decision (current map). The doc still calls it an open draft. | WRONG |
| 15 | OPERATIONS: movement volume `--markdown /workspace/docs/measurements/movement-volume.md` | The option exists. The absolute path is correct inside the container. | WORKS (static) |
| 16 | OPERATIONS: the `IngestFailureTest` note | `api/tests/Feature/Pricing/IngestFailureTest.php` defines `t09WithLockHeld`. | WORKS |
| 17 | README → `docs/WEB.md`: rebuild and check `web` | The `web` service exists, `docker/web/Dockerfile` has target `runtime`, and nginx listens on 8095 with `server_name pokeprices.localhost`. | WORKS (static) |
| 17a | WEB/README: `pokeprices.localhost` | Relies on `*.localhost` resolving to loopback. Modern browsers and curl ≥7.85 do this; older resolvers may not. Not mentioned. | UNCLEAR (minor) |
| 17b | WEB: card search and names | They need PokéSearch on `host.docker.internal:8081` (`POKESEARCH_URL` in `compose.dev.yml`). OPERATIONS calls it "a separate project" and no doc says how to get it. Without it the pages degrade (`Card <id>`, "PokéSearch is unavailable"). | UNCLEAR |
| 18 | README → `docs/ACCOUNTS.md` | "This describes the code as committed on `p4-work`", but the branch is `p5-work`. The ADR is written as `adr/0003-accounts-and-sessions.md`, not as a link. | WRONG (stale) |
| 18a | ACCOUNTS: invite code | Registration needs `INVITE_CODE` in `api/.env`, described as "set by Andres … human gate HG-03; no agent reads or writes that file". A newcomer is not told whether they may set their own. Unset means every sign-up is refused. | UNCLEAR |
| 18b | ACCOUNTS: demo seeder command | `api/database/seeders/DemoAccountSeeder.php` exists. The command is well formed. | WORKS (static) |
| 18c | ACCOUNTS: config keys | `api/config/accounts.php` has `invite_code`, the demo keys and the limiter numbers as listed. | WORKS |
| 18d | ACCOUNTS: "open item in `docs/adr/drafts/0004-alert-delivery.md`" | That ADR says the opposite: row 9 says query-string passwords are ignored and "closes ADR 3 question 14". The `code`/`token` remainder is mentioned there as a note, not as an open item. | UNCLEAR |
| 19 | README → `docs/ALERTS.md`: artisan lines | `pokeprices:alerts:dispatch [--reset-watermark=]`, `alerts:deliver` and `alerts:discord-test` exist. The schedule entries `pokeprices-alerts-dispatch` (5 min) and `pokeprices-deliveries` (1 min) match. Every value in the config table matches `api/config/alerts.php`, including the `api:8096` local default. | WORKS (static) |
| 19a | ALERTS: local sink | `scripts/webhook-sink/sink.php` exists and supports `--summary`. The doc says to put the secret into `.gates/tmp/P5-sink/secret`, but nothing creates `.gates/tmp/P5-sink/`, and the sink never runs `mkdir`. The serve line blocks the terminal ("leave it running"), so the second line needs another terminal. That is implied, not stated. | UNCLEAR (minor) |
| 19b | ALERTS vs ACCOUNTS: where mail goes | ACCOUNTS says verification and reset mail is in `logs api`; ALERTS says alert mail is in `logs worker`. This is consistent (sync vs queued), but a reader of one doc only looks in the wrong log for the other kind of mail. | WORKS (fragile) |
| 20 | Search for CONTRIBUTING, docs index, tests how-to | No CONTRIBUTING and no docs index. The only command for running the API tests is in `api/README.md`, which the README never links. No doc says how to run the web tests (`npm test` → vitest) or the shell suites under `tests/`. | WRONG (missing) |

---

## 2. Ranked findings (worst strand first)

1. **`make gates` cannot pass on a fresh clone, and nothing reachable says why** (steps 4, 4a). The default
   context is `primary`, which hard-requires the untracked `.git/info/hygiene.local` (contents only in an
   off-repo plan task), a local `.mex` scaffold plus a `mex` binary (EC-mex), and a nested full-stack clone
   (EC-ci-parity). These rules appear only in `gates/manifest-P0.md` and the gate scripts, neither of which
   the README links. `PP_RUN_CONTEXT=nested|ci` is never mentioned. Confirmed live: HY-hygiene failed with
   `local_patterns_missing`.
2. **No route to any price data** (step 9a). Every price feature needs `.data/snapshots/<day>/` (and
   `.data/dataset/…` for the map), and the docs only say a "separate pull step" brings it. A newcomer
   cannot see a single price or run a single runbook command against real data.
3. **The dev site is not up after `make init`** (step 3b). The README puts the URL right after `make init`,
   but `web`, `worker` and `scheduler` are not in `CORE`. The obvious first check (open the site) fails, and
   the doc names no command to fix it. Only `make gates`, which is itself broken per finding 1, or WEB.md's
   rebuild line starts `web`.
4. **How to run tests is undiscoverable from the README** (step 20). The Pest command lives only in the
   unlinked `api/README.md`. Vitest and the `tests/p*`/`tests/gates` shell suites are not documented at all.
   `make gates` is the only path offered, and it is broken locally (finding 1).
5. **Host prerequisites are wrong and unlisted** (step 2). "Nothing is installed on the host" is false:
   Python 3, jq, git, GNU make, bash, coreutils `sha256sum`, curl and a Docker Compose new enough for
   `up --wait` are all needed, plus `mex` for EC-mex.
6. **"CI runs exactly this" hides a context switch** (step 4b). The same `make gates` runs a different gate
   set in CI (`ci`) than locally (`primary`). A newcomer cannot reproduce CI locally from the README.
7. **Dirty-tree refusal** (step 4c). The scorecard refuses an uncommitted change, so "edit, then
   `make gates`" fails until you commit. This is documented only in `gates/manifest-P0.md`.
8. **PokéSearch dependency unexplained** (step 17b). Search and card names need a separate project on
   `:8081` that no doc says how to obtain or run.
9. **Invite code ownership is unclear** (step 18a). Sign-up is impossible until `INVITE_CODE` is set, and
   the doc frames setting it as one person's human gate. A newcomer cannot tell whether to set their own
   value in their local `api/.env`.
10. **Stale statements.** ACCOUNTS says "as committed on `p4-work`" (step 18). OPERATIONS calls ADR 0001
    question 11 an open draft, though it is Accepted and decided (step 14). `api/README.md` says "What it
    does in P0 … There are no prices yet." ACCOUNTS calls something an open item in ADR 0004 that the ADR
    marks closed (step 18d).
11. **`make verdict` is opaque** (step 5). There is no schema or producer for `findings.json`, and "loop
    step" is undefined.
12. **`map:oracle` prerequisite unnamed** (step 13). The runbook needs a recorded oracle but never names
    `pokeprices:oracle:fetch`.
13. **Port override half-supported.** `PP_HTTP_PORT` changes the published port and `APP_URL`, but
    `SANCTUM_STATEFUL_DOMAINS` is hard-coded to `pokeprices.localhost:8095`, so sign-in on any other port
    is not first-party. The variable is also undocumented.
14. **Minor copy-paste friction.** The sink directory `.gates/tmp/P5-sink/` must be created by hand
    (step 19a). Mail logs are split across `api` and `worker` (step 19b). `*.localhost` resolution is
    assumed (step 17a). `make help` lists target names with no descriptions (step 7).

### Docs unreachable by links from the README

Reachable by link: README → OPERATIONS, WEB, ACCOUNTS, ALERTS → `docs/security/asvs-l1.md` (from ACCOUNTS).
Everything else is either named only as plain code text or not referenced at all:

| Doc | How (if at all) a reader could find it |
|---|---|
| `api/README.md` | Not referenced. It holds the only test command. |
| `gates/manifest-P0.md` | Not referenced (the README names only `manifest-P0.json`). It holds the run contract, the dirty-tree rule and the contexts. |
| `docs/adr/0001-day-close.md` | Only as "ADR 1" / "ADR 0001" in prose. |
| `docs/adr/0002-daily-polling-and-ticker.md` | Not referenced anywhere reachable. |
| `docs/adr/0003-accounts-and-sessions.md` | Code text `adr/0003-…` in ACCOUNTS (no link). |
| `docs/adr/drafts/0004-alert-delivery.md` | Code text in ACCOUNTS and ALERTS (no link). |
| `docs/adr/drafts/pokesearch/0012-versioned-card-api.md` | Not referenced. |
| `docs/measurements/movement-volume.md` | Code text in ALERTS and OPERATIONS (no link). |
| `openapi/api.json` | Named in ACCOUNTS and `api/README.md` only. |
| `tests/pending-gates.txt` | Not referenced. |

---

## 3. The stranger's answers

- **What does this project do?** It is a web app (a Laravel 13 API plus a React SPA) that shows the
  once-a-day closing prices of Pokémon cards, sourced from TCGplayer via TCGCSV, on top of a separate card
  catalogue (PokéSearch). Every price carries its source and "as of" day. On top of that come accounts
  (invite-only, plus a read-only demo), a watchlist and portfolio, and alerts: per-card thresholds, a daily
  digest, signed webhooks and Discord.
- **How do I run its tests?** Not from the README. From the unlinked `api/README.md`:
  `make init` then
  `docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pest -c phpunit.xml`.
  For the web, by inference from `web/package.json`:
  `docker compose -f compose.dev.yml exec -T -w /workspace/web node npm test`. The documented "everything"
  path, `make gates`, will be red locally until the three primary-context prerequisites in finding 1 are
  provided.
- **Where do I make my first change?** API: `api/app/` (domains under `api/app/Domain/{Pricing,Alerts,…}`,
  commands under `api/app/Console/Commands`), with tests in `api/tests/`. Web: `web/src/`, and to see it,
  WEB.md's `docker compose -f compose.dev.yml up -d --wait --build web`. A change under `/v1` must also
  keep `openapi/api.json` in step with `php artisan scramble:export` (gate C1). That fact is only in
  `api/README.md`. Commit before running `make gates` (dirty-tree rule).
- **Which docs did I never find a path to from the README?** See the table above. The most damaging
  omissions are `api/README.md` (the test command) and `gates/manifest-P0.md` (the rules that decide
  whether `make gates` can pass).
