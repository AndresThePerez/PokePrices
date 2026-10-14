# PokéPrices — adversarial review of `p5-work` (cloud, 2026-10)

Scope: the whole app at `p5-work` @ `90e4016`. Review only; no code changed. Deliberate product rules and the seven known items in the brief are excluded.

## Executive summary

1. **Web, High (demonstrated):** an in-flight `PUT /v1/me/notifications` from account A that resolves after A signs out and B signs in, all in the same tab, writes A's settings into B's cache (`NotificationSettings.tsx:69-70`).
2. **Web, High (demonstrated):** per-user caches are cleared only by six hand-wired UI handlers. An identity change seen through the `['me']` poll leaves A's watchlist, webhooks, settings and even A's one-time signing secret on screen under B.
3. **Web, Medium (demonstrated):** `forgetLists` is a hand-kept allowlist of un-namespaced keys, and it already misses `['verify-email', link]`. There is no global 401 path.
4. **API runtime, High:** every queued `IngestChunk` builds a new `DayIngest` and reloads the whole `source_product_map` (about 33k rows) for its 200 rows. The map is also not pinned to the run, so a `map:build` mid-run splits one day across two builds.
5. **API runtime, High/Medium:** no job declares `$timeout`. `DeliverDue`'s 60 s sweep budget equals the worker's 60 s kill, and `FinalizeDay` blocks on `pg_advisory_xact_lock` with no timeout on the only worker.
6. **API runtime, Medium:** the 5-minute dispatch filters `price_events` by `(source, day)`, but only the PK and the `(variant_key, …)` unique index exist. `watchlist_items` / `portfolio_holdings` lack `variant_key` indexes. `deliveries` / `webhook_endpoints` have only partial `user_id` indexes.
7. **API architecture, Medium:** the layering is enforced only by convention. Domain imports `App\Http` in six files; `AlertController` and `MoversController` hold raw SQL and domain rules; `CardController` duplicates (and diverges from) `VerifyCatalog`'s catalog write.
8. **API architecture, Medium:** limits and rules are copied, not shared: the quantity cap `9999` in four places with a dead config key; alert eligibility written three times (PHP plus two SQL copies); the price provenance block in five shapes.
9. **Cross-cutting, Medium:**
   - The skeleton `DatabaseSeeder` still creates a verified `test@example.com` / `password` user.
   - `composer.json` targets PHP `^8.3` while the image is 8.5.
   - The forbidden-words test list (which guards a deliberate product rule) has eight-plus drifting copies.
10. **Fix-first order:**
    - Web session-transition core: `['account', …]` key prefix, one `endSession()`, clear on `me` identity change, add `id` to `/v1/me`.
    - Job timeouts and lock discipline.
    - The three index migrations.
    - Chunk-scoped map loading.
    - An arch rule `App\Domain` must not use `App\Http`.

**Evidence levels.**
- **Web (Run):** run in this container on Node 22 (web suite baseline is green: 859 passed and 1 expected-fail; `tsc -b` and eslint clean). The five web leak probes in Appendix A failed against `p5-work` exactly as described.
- **API (Sketch):** these demonstrations are Pest or EXPLAIN sketches, not runs. The container has PHP 8.3 and no Postgres, and the app needs 8.5 + Postgres.
- **Ruled out:** `web/src/api/schema.d.ts` was regenerated with `openapi-typescript` and is byte-identical to the committed file, so spec drift is ruled out.

---

## (a) API architecture

House convention, checked first: there is no `app/Http/Requests`. Every controller validates inline with `$request->validate()`, and Domain failures come back as messages turned into `ValidationException`. Inline validation is therefore not reported. `tests/Unit/ArchRulesTest.php` checks `strict_types`, `final` and "no network in Mapping/Pricing". Nothing checks Domain→Http dependencies, DB use in controllers, or Problem mapping, and every finding below slips through that gap.

### A1 — Medium — The portfolio quantity cap is hard-coded four times; the config key and its accessor are dead

**Location:**
- `api/app/Http/Controllers/PortfolioController.php:75`: `'quantity' => ['required', 'integer:strict', 'min:1', 'max:9999']`
- `api/app/Domain/Accounts/AccountLimits.php:25-28`: `quantityMax()` reads `config/accounts.php:49` (`'quantity_max' => 9999`) and has **0 callers** (`grep -rn quantityMax api web/src` finds only the definition).
- `api/app/Domain/Accounts/Portfolio.php:51` documents "validated by the caller (1 to accounts.limits.quantity_max …)", which is false.
- The same `9999` also appears in:
  - `database/migrations/2026_10_15_000002_…:41` (`CHECK (quantity BETWEEN 1 AND 9999)`)
  - `openapi/api.json:663` (`"maximum": 9999`)
  - `web/src/account/ListParts.tsx:161` (`max={9999}`)

**Smell:** this is the only account limit that bypasses `AccountLimits`; the watchlist and portfolio *size* limits go through it. Changing the config does nothing. A future "fix" that wires the config up and raises it would hit the DB CHECK and return a 500 instead of a 422.

**Better shape:** `Portfolio::set()` validates the range against `AccountLimits::quantityMax()` and returns the 422 message, as it already does for unknown variants and a full portfolio. The controller drops `max:9999`. Add one parity test asserting config == spec `maximum` == the CHECK bound, as `MoversHistoryEdgeTest.php:274` already does for the movers limit.

**Demo (sketch):**
```php
config(['accounts.limits.quantity_max' => 10]);
$this->actingAs($u)->putJson('/v1/portfolio/base1-4:holo:unlimited:-', ['quantity' => 50])->assertUnprocessable();
// today: 200
```

### A2 — Medium — `AlertController` has no Domain class: raw SQL, the threshold floor and the messages live in the controller

**Location:**
- `api/app/Http/Controllers/AlertController.php:51`: `DB::update('UPDATE watchlist_items SET alert_pct = ? …')`
- `:69`: the clear
- `:76-81`: `floor()` = `max(1, (int) ceil(is_numeric($pct) ? (float) $pct : 5.0))`
- `:20`: `NOT_WATCHED`
- The sibling features go through Domain: `Watchlist`, `Portfolio`, `NotificationSettings`, `WebhookEndpoints`.

**Smell:** the movement percentage is interpreted three ways:
- here: ceil of a float, with a silent 5.0 fallback;
- `Domain/Pricing/DetectMovement.php:90`: the raw string bound as `?::numeric`, no fallback;
- `Domain/Alerts/NotificationText.php:117`: trailing zeros trimmed for display.

The floor only exists at HTTP write time. If the rule is raised from `5.00` to `7.00`, stored `alert_pct = 5` rows still read back as 5% but can never fire below 7% (no event exists), and nothing in Domain knows about it.

**Better shape:**
- `App\Domain\Alerts\AlertThreshold` with:
  - `floor(): int`
  - `set(int $userId, string $key, int $pct): ?string` (under `AccountLock`, as `Watchlist` does)
  - `clear(int $userId, string $key): void`
- One `Domain\Pricing\MovementRule::pct()/abs()` accessor read by `DetectMovement`, `NotificationText` and `AlertThreshold`.
- The controller maps the returned message to a 422 and nothing else.

### A3 — Medium — Domain depends on Http, and no arch rule stops it

**Location:**
- `api/app/Domain/Accounts/VariantRows.php:7`: `use App\Http\Resources\PriceBlock;`. Domain SQL is built from a Resource's `PriceBlock::COLUMNS`.
- `api/app/Domain/Catalog/{PokeSearchClient,SeedRef,CircuitBreaker,CatalogRepository,VerifyCatalog}.php` import `App\Http\Problems\*`.
- `VerifyCatalog` is a queue job, so a worker throws an HTTP 502 `UpstreamContract` `HttpException`.

**Smell:** transport types inside the domain. A queued job's failure is shaped as an HTTP response and logged as one.

**Better shape:**
- Move the column list to `App\Domain\Pricing\PriceColumns`; `PriceBlock` consumes it.
- Give Catalog its own exceptions (`CatalogUnavailable`, `CatalogContractViolation`, `CardMissing`), mapped to Problem types in `ProblemDetails`.
- Add the rule below.

**Demo (sketch; fails with 6 files today, by `grep -rln 'use App\\Http' app/Domain`):**
```php
arch('domain never depends on http')->expect('App\Domain')->not->toUse('App\Http');
```

### A4 — Medium — `GET /v1/cards/{id}` writes catalog status from the controller, copying `VerifyCatalog` and diverging from it

**Location:**
- `api/app/Http/Controllers/CardController.php:61-70` (`flagMissing`): `CardVariant::query()->where('card_id', $id)->update(['catalog_status' => 'catalog_missing', 'catalog_seed_ref' => $this->catalog->seedRef()->ref, …])`
- `api/app/Domain/Catalog/VerifyCatalog.php:82` writes the same transition as `'catalog_seed_ref' => $seedRef === '' ? null : $seedRef`.

**Smell:**
- A public, throttled GET performs a write from the HTTP layer.
- An empty seed ref (accepted by `SeedRef::fromMeta`, `SeedRef.php:41`) is stored as `''` by one path and `NULL` by the other.
- The variants→card status rule sits in `CardResource::catalogStatus()` (`CardResource.php:196-205`), and the variant query is in the controller (`:43-51`).

**Better shape:** `Domain/Catalog/CatalogStatus` with `markMissing(string $cardId, SeedRef $ref): bool`, used by both the controller path and `VerifyCatalog`, plus `of(Collection $variants): string`. The controller calls one `CardReader::find($id)`.

**Demo (sketch):** with an empty seed ref, hit `GET /v1/cards/x` for a card PokéSearch 404s, then run `VerifyCatalog` on another missing card. `SELECT DISTINCT catalog_seed_ref FROM card_variants WHERE catalog_status='catalog_missing'` returns both `''` and `NULL`.

### A5 — Medium — The alert-eligibility rule is written three times

**Location:**
- `api/app/Domain/Accounts/AlertEligibility.php:19`: `hasVerifiedEmail() && ! is_demo`, used by `RequireAlertEligibility`.
- `api/app/Domain/Alerts/Jobs/DispatchEvents.php:59`: `WHERE u.email_verified_at IS NOT NULL AND NOT u.is_demo`
- `:216`: the same predicate inlined again in `missingAlerts`.

**Smell:** the copies agree today, but no test ties them together. Add a condition to eligibility (say a locked account or a 2FA requirement) and the HTTP gate changes while dispatch keeps sending.

**Better shape:** `AlertEligibility::sqlPredicate(string $userAlias): string`, used at both SQL sites, plus one test running PHP and SQL over the 2×2 verified/demo matrix and asserting they agree.

### A6 — Medium — `MoversController` is a fat controller: two queries, a copied Domain helper, literal limits, and its own "as of" derivation

**Location:** `api/app/Http/Controllers/MoversController.php`:
- `:39-44`: raw `DB::selectOne` deriving `day`/`as_of`
- `:49-58`: a join query
- `:62-68`: a near line-for-line copy of `Domain/Accounts/CardNames::attach()`. CardNames' own comment at `:33` says "as MoversController does".
- `:32`/`:35`: `max:50` and default `20` as literals, while the comment at `:29` says the cap *is* `config('pricing.movers.limit')`.
- `Domain\Pricing\Movers` exists but has only `rebuild()`.

**Smell:** the newest day's "as of" (a deliberate product rule) is derived three ways:
- `/movers`: `max(price_daily.as_of)` of real closes
- `/cards/{id}/prices`: newest ok `ingest_runs.marker` (`Domain/Pricing/PriceHistory.php:44-50`)
- `/prices/freshness`: `IngestRun` ordered in PHP (`Domain/Pricing/Freshness.php:41-47`)

**Better shape:** `Movers::newest(string $direction, int $limit): array{day, as_of, rows}`, with the limit read from config inside it. Reuse `CardNames::attach`. Add one `Domain\Pricing\NewestDay` used by all three endpoints.

### A7 — Medium/Low — The price provenance block is copied in five places, in three shapes

**Location:**
- `api/app/Http/Resources/PriceBlock.php:31-35`: source from the row, plus feed, basis, currency and cadence
- `CardPricesResource.php:69-73`: source from config, with cadence
- `Domain/Alerts/PriceMovedPayload.php:57-59`: with cadence
- `MoversController.php:73-76`: **no cadence**
- `PortfolioController.php:51-54`: **no cadence**
- Watchlist meta has no provenance at all.

**Smell:** "every displayed price carries its source" is a product rule, and it is implemented by copy-paste, so a new endpoint copies whichever shape it finds first.

**Better shape:** `Domain\Pricing\Provenance::meta(): array{source, feed, basis, currency, cadence}`, spread everywhere, plus one contract test asserting the identical key set on each priced endpoint's `meta`.

### A8 — Low — The webhook test-send does lookup, rule and response by hand; the webhook limit is read twice

**Location:**
- `api/app/Http/Controllers/WebhookController.php:113`: `WebhookEndpoint::query()->whereKey((int) $id)->where('user_id', …)->first()`
- `:117`: the `disabled_at !== null` rule
- `:122`: `response()->json(['data' => ['message_id' => …]], 202)`, the only success body not built by a Resource
- The limit is read at `:45` and at `Domain/Alerts/WebhookEndpoints.php:113` (`(int) config('alerts.webhooks.limit', 5)`), and the docblock at `:52` hard-codes "at most 5".

**Better shape:**
- `WebhookEndpoints::queueTest(int $userId, int $id): Delivery`, throwing domain `WebhookNotFound` / `WebhookDisabled`.
- `WebhookEndpoints::limit()`.
- A `WebhookTestQueuedResource`.

### A9 — Low — `ProblemDetails` maps Problem classes through a closed `match`

**Location:** `api/app/Http/Problems/ProblemDetails.php:50-66` lists each class by hand. A new `HttpException` subclass under `App\Http\Problems` that is not added there renders as a generic `http-409`/`http-404` slug, with no failure anywhere. `ReadinessController.php:30` builds the `not-ready` problem inline instead of using a class.

**Better shape:** a `Problem` interface (`slug()`, `title()`, `extra()`) implemented by every class, and `ProblemDetails` dispatching on the interface. Add a unit test that instantiates every class in the namespace and asserts its slug does not start with `http-`.

### A10 — Low — Cache-Control is set in three places with different values, and one stateful route is uncovered

**Location:**
- `api/app/Http/Middleware/NoStore.php:22`: `no-store, private`
- `PriceFreshnessController.php:27` and `ReadinessController.php:31,34`: inline `no-store`
- `POST /v1/demo/session` (`routes/api.php:96-98`) starts a session but has no `NoStore`.

**Better shape:** apply the `NoStore` middleware on those three routes and delete the inline headers.

---

## (b) API data / runtime

Runtime facts used below:
- One worker: `queue:work redis --queue=default --tries=3 --max-time=3600` (`compose.dev.yml:103`).
- No `--timeout`, so Laravel's default of 60 s applies.
- `retry_after` 90 (`config/queue.php:71`).

### B1 — High — Every queued chunk reloads the whole product map, and the map is not pinned to the run

**Location:**
- `api/app/Domain/Pricing/Jobs/IngestChunk.php:52`: `(new DayIngest($db, $this->snapshots))->chunk(...)` builds a new instance per job, so the per-run caches `$maps` and `$readers` start empty each time.
- `api/app/Domain/Pricing/DayIngest.php:401-416` (`mapFor`) then runs `source_product_map->where('source', …)->get([...])`, the whole source map, once per chunk.
- The group file is also re-read and decompressed per chunk (`readerFor`, `:383`).
- The class comment ("loaded once per run", `:33`) is true only in `--sync` mode.

**Smell:** with about 45k rows a day (`docs/OPERATIONS.md:112`) and `chunk_rows` 200, there are at least 225 chunks. Each fetches the whole map of about 33k rows, so roughly **7.5M map rows** are read to write 45k price points.

The map is also not pinned. `MapBuilder` rewrites `source_product_map` in its own transaction (`MapBuilder.php:76`, delete at `:525`) without `IngestLock`. A build that commits between chunk N and N+1 normalizes one day with two builds, while `counts.map_build_id` (`DayIngest.php:146`) records only the first.

**Better shape:**
- Per chunk, load only the chunk's products: `WHERE source = ? AND source_product_id = ANY(?)`, served by the existing `source_product_map_source_product_subtype_unique`.
- Pin the build: either `MapBuilder` takes `IngestLock`, or `chunk()` refuses when `max(map_builds.id)` differs from the run's recorded `map_build_id`.

**Demo (Pest sketch):**
```php
DB::enableQueryLog();
foreach ($chunkIds as $c) (new IngestChunk($runId, $c, …))->handle();
expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "source_product_map"'))
    ->every(fn ($q) => str_contains($q['query'], 'source_product_id')))->toBeTrue(); // today: full-map selects
```

### B2 — High/Medium — No job declares `$timeout`; DeliverDue's budget equals the worker's kill threshold

**Location:**
- `grep -rn 'timeout\|Timeout' app/Domain/*/Jobs app/Jobs` finds no `$timeout` / `#[Timeout]`.
- `api/app/Domain/Alerts/Jobs/DeliverDue.php:64`: `$budget = (int) config('alerts.delivery.sweep_budget_seconds', 60)`, with `config/alerts.php:33` = `60`, which equals the worker's 60 s default.

**Smell:**
- The budget check (`:69`) runs *before* each send. One webhook send can take 5 s; an email send is bounded only by the SMTP socket timeout (default 60 s). The retention DELETE (B7) runs first and spends from the same 60 s.
- So the worker SIGKILLs the sweep before the "release the rest" path (`:70`) runs. The claimed rows stay leased for 300 s with `claims+1`, and an in-flight message may have been sent without its outcome UPDATE, so it is sent twice.
- `FinalizeDay` has `#[Tries(3)]` and backoff but no timeout, and runs the full `LatestPrices` rebuild (B10). Once that exceeds 60 s it is killed three times, marked failed, resumed by `IngestPending` two hours later, and killed again, in a loop.

**Better shape:**
- `DeliverDue`: `public int $timeout = 90`, with `sweep_budget_seconds` 40.
- `FinalizeDay` / `IngestChunk`: an explicit `$timeout` (for example 600) on a dedicated `ingest` queue whose worker has `--timeout` and `retry_after` above it.
- An explicit SMTP `timeout` in `config/mail.php`.

### B3 — Medium — FinalizeDay blocks on the ingest lock with no timeout, unlike every other lock holder

**Location:**
- `api/app/Domain/Pricing/Jobs/FinalizeDay.php:41`: `SELECT pg_advisory_xact_lock(?)`, which blocks indefinitely; no `lock_timeout` is set anywhere.
- By contrast, `StartDayIngest` tries the lock and calls `release(60)` (`StartDayIngest.php:44`), and `IngestPending` tries and skips.

**Smell:**
- `Replay` and a CLI `--sync` ingest hold the session lock for minutes. A `FinalizeDay` dequeued in that window ties up the **only** worker, starving `DeliverDue`/`DispatchEvents` until the 60 s kill. That repeats three times, then the run is marked failed.
- Separately, `release(60)` consumes attempts under `--tries=3`, and `StartDayIngest` has no `#[Tries]`/`retryUntil()`. A replay longer than about 3 minutes sends `StartDayIngest` to `failed_jobs` with MaxAttemptsExceeded.

**Better shape:** `pg_try_advisory_xact_lock` + `$this->release(60)` in FinalizeDay, and `retryUntil(now()->addHours(3))` on both jobs.

**Demo (sketch):** in one session run `SELECT pg_advisory_lock(<IngestLock::KEY>)`, then run `FinalizeDay::handle()`. It does not return until the session lock is released.

### B4 — Medium — The dispatch hot path has no usable index: `price_events (source, day)` and `variant_key` on both lists are missing

**Location:**
- `api/app/Domain/Alerts/DeliverableDay.php:107`: `FROM price_events e WHERE e.source = ? AND e.day = ?::date AND e.id <= ?::bigint`
- It is run four times per 5-minute `DispatchEvents`, even when nothing is missing: counts `:153`, missing `:184`, missingAlerts `:211`, missingDigests `:241`.
- `database/migrations/2026_10_14_000004_…:47-48` creates only the PK and `UNIQUE (variant_key, source, day, series_epoch)`. `id <= ceiling` matches nearly the whole table, and the outbox is never pruned.
- The same queries join `watchlist_items` / `portfolio_holdings` on `variant_key`, whose only indexes are `UNIQUE (user_id, variant_key)` (`2026_10_15_000002:26,40`), which a `variant_key` probe cannot lead with.

**Better shape:**
```sql
CREATE INDEX price_events_source_day_id_index ON price_events (source, day, id);
CREATE INDEX watchlist_items_variant_key_index ON watchlist_items (variant_key) WHERE alert_pct IS NOT NULL; -- plus a plain one for the digest join
CREATE INDEX portfolio_holdings_variant_key_index ON portfolio_holdings (variant_key);
```

**Demo:** `EXPLAIN` of the counts CTE on a seeded DB shows `Seq Scan on price_events`, and its cost grows linearly with every day ever ingested.

### B5 — Medium — `deliveries` and `webhook_endpoints` have only *partial* `user_id` indexes

This is distinct from the known `deliveries(endpoint_id)` item.

**Location:**
- `database/migrations/2026_10_16_000001_create_alert_delivery_tables.php:81-82`: `(user_id, event_id) WHERE kind='alert.triggered'` and `(user_id, day) WHERE kind='digest'`
- `:48`: `webhook_endpoints (user_id) WHERE kind='discord'`
- None of them serves a plain `user_id` predicate.

**Queries that pay:**
- `Domain/Accounts/AccountExport.php:122`: `FROM deliveries WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?`, a seq scan plus sort.
- `Domain/Accounts/AccountDeletion.php:24`: `DELETE FROM users` cascades to `deliveries` and `webhook_endpoints` by `user_id`. These are seq scans inside the deletion transaction, holding row locks on `users`.
- `Domain/Alerts/WebhookEndpoints.php:86` (`rows()`) and `:111` (`limitError`) scan on every list and create.

**Better shape:**
```sql
CREATE INDEX deliveries_user_created_index ON deliveries (user_id, created_at DESC, id DESC);
CREATE INDEX webhook_endpoints_user_id_index ON webhook_endpoints (user_id);
```

### B6 — Medium — Two runs of the same marker can run at once; `withoutOverlapping()` does not protect queued jobs

**Location:**
- `api/app/Domain/Pricing/DayIngest.php:115-122` only counts an `ok` run of the marker as done. A `running` one is ignored, and `ingest_runs` has no unique constraint on `(source, marker) WHERE status = 'running'`.
- `routes/console.php:15,21`: `Schedule::job(...)->withoutOverlapping()` guards only the enqueue step. `IngestPending` and `DispatchEvents` are not `ShouldBeUnique`.

**Interleaving:**
1. `IngestPending` dispatches StartDayIngest #1 for day D, and an operator runs `pokeprices:prices:ingest --queued D` (or a released #1 re-runs).
2. Both serialize on the lock. #1 inserts run 1 (`running`) and dispatches its batch.
3. #2's `start()` sees no `ok` run, inserts run 2, plans chunks again and dispatches a second batch.
4. Both batches upsert the same keys with `ingest_run_id` flapping. Both `FinalizeDay`s succeed, leaving two `ok` runs for D.

**Better shape:** in `start()`, treat a non-stalled `running` run of the marker as in flight unless `--force`, and add the partial unique index. Make `IngestPending` / `DispatchEvents` `ShouldBeUnique`.

### B7 — Medium/Low — The retention DELETE scans `deliveries` on every one-minute sweep

**Location:** `api/app/Domain/Alerts/Jobs/DeliverDue.php:57-60`: `DELETE FROM deliveries WHERE status IN ('delivered','failed') AND created_at < ?`. No index covers `created_at`; the only status index is `deliveries_due_index (next_attempt_at) WHERE status='pending'`.

**Smell:** a full scan every minute that usually deletes nothing, charged against the 60 s budget in B2.

**Better shape:** move retention to a daily scheduled command that deletes in batches (`… AND id IN (SELECT id … LIMIT 5000)`), or add `CREATE INDEX deliveries_retention_index ON deliveries (created_at) WHERE status IN ('delivered','failed')`.

### B8 — Low/Medium — In `DispatchEvents`, one parent deleted mid-run aborts the whole fan-out

**Location:** `api/app/Domain/Alerts/Jobs/DispatchEvents.php:309` and `:382` send multi-row INSERTs (up to 500 rows) carrying `user_id`/`endpoint_id` FKs. The candidates were read earlier (`:183/:210/:240`) with no transaction around the run.

**Interleaving:**
1. `missing()` returns (event, endpoint 42).
2. The user deletes endpoint 42 (`WebhookEndpoints.php:159`) or their account, and that commits.
3. The INSERT fails with 23503.
4. The job aborts after a partial fan-out, and the alerts and digests for **every** user (created after `:117-121`) are skipped this run.

It self-heals on the next run, but one user's action delays everyone's alerts by a cycle, and it is logged as a job failure.

**Better shape:** `INSERT … SELECT v.* FROM (VALUES …) v JOIN webhook_endpoints p ON p.id = v.endpoint_id JOIN users u ON u.id = v.user_id ON CONFLICT DO NOTHING`.

### B9 — Low — `DeliverySender`'s outcome UPDATE is outside its catch-all, and it uses a different connection

**Location:** `api/app/Domain/Alerts/DeliverySender.php`: the try/catch spans only `:77-101`. The outcome `DB::transaction(... DB::update ...)` at `:112-130` is outside it, and goes through the `DB` facade's default connection, while `DeliverDue::sweep()` claims on its injected `$db`.

**Smell:** a DB error in the outcome write escapes `send()`, which is documented as "never throws", and ends the loop at `DeliverDue.php:79`. Up to 9 claimed rows stay leased for 300 s with `claims+1`, counting toward the `2×max_attempts` retirement (`:53-56`).

**Better shape:** wrap each row in `sweep()` in a try/catch that calls `release()` for that row and continues, and pass `$db` into the sender.

### B10 — Low — Per-finalize and per-request full scans: `LatestPrices::rebuild` and the movers "as of"

**Location:**
- `api/app/Domain/Pricing/LatestPrices.php`: `DELETE FROM price_latest WHERE source = ?`, then `DISTINCT ON` over **all** of `price_daily` history on every finalize. That is O(history) per day, leaves about 33k dead tuples per run, and is what pushes FinalizeDay toward B2's kill.
- `api/app/Http/Controllers/MoversController.php:41-43`: `(SELECT max(d.as_of) FROM price_daily d WHERE d.source=? AND d.day=m.day AND NOT d.is_filled)` on every public `/movers` request, about 33k heap fetches through `(source, day)`.

**Better shape:**
- Rebuild only variants with rows in the rollup window, as an upsert-where-distinct diff (as `DailyRollup` already does).
- Persist `as_of` into the movers table in `Movers::rebuild`.

---

## (c) Web architecture

### Query-key lifecycle (every key, audited)

| Key (defined at) | Whose data | Sign-out `AccountPage.tsx:73-75` | Sign-in / sign-up / 2FA / demo (`SignInPage.tsx:42,129`, `SignUpPage.tsx:43`, `DemoButton.tsx:28`) | Delete `YourData.tsx:71-73` | 401 from API | Identity change seen only via `['me']` poll (other tab, expiry) |
|---|---|---|---|---|---|---|
| `['me']` (`session.ts:8`) | current user; **no id** | `setQueryData(null)` + invalidate | invalidate `refetchType:'all'` | `setQueryData(null)` + invalidate | `fetchMe` maps a 401 to `null` | replaced |
| `['watchlist']`, `['portfolio']` (`lists.ts:8-9`) | user | `forgetLists` (removeQueries also cancels in-flight fetches) | `forgetLists` | `forgetLists` | **kept**; pages render `data` before the 401 branch | **kept: A's rows under B** |
| `['webhooks']`, `['notifications']` (`alerts.ts:20-21`) | user | `forgetLists` | `forgetLists` | `forgetLists` | **kept** | **kept** |
| `['verify-email', link]` (`VerifyEmailPage.tsx:37`, `staleTime: Infinity`) | user | **not cleared** | **not cleared** | **not cleared** | shown as sign-in | **kept** |
| `['card', id]`, `['card-prices', id, range]`, `['movers', dir, n]` | public (`CardResource` has no per-user fields) | kept (correct) | kept | kept | n/a | fine |
| Search results | no key; fetched in `useEffect` (`SearchPage.tsx:164-184`) | n/a | n/a | n/a | n/a | n/a |
| Component state: signing secret (`Webhooks.tsx:241`), 2FA QR/setup key/recovery codes (`TwoFactor.tsx:72-73`), settings draft | user | unmounted by `navigate('/')` | page change | unmounted | n/a | **survives while mounted** |
| localStorage (`preferences.ts`) | theme/palette only | fine | fine | fine | fine | fine |

Checked and fine: `removeQueries` destroys the query and cancels its fetch, so an in-flight list fetch cannot repopulate the cache after `forgetLists`. A late `invalidateQueries` from a write after sign-out creates no query. Money stays a decimal string end to end (`Number()` only for chart geometry).

### C1 — High — A late write answer lands in the next account's cache (demonstrated)

**Location:** `web/src/account/NotificationSettings.tsx:69-70`:
```ts
if (queryClient.getQueryState(NOTIFICATIONS_KEY) !== undefined) {
  queryClient.setQueryData<NotificationsBody>(NOTIFICATIONS_KEY, result.data);
}
```
The guard checks that the key *exists*, not *whose* data it holds.

**Smell:** in a single tab:
1. A clicks Save; the PUT is slow.
2. A signs out, B signs in and opens /alerts.
3. A's PUT resolves, and A's `channel`/`digest`/`discord_available` become B's cached settings.

If B then clicks Save, B persists A's choices.

**Better shape:**
- Never `setQueryData` from a write; `invalidateQueries` instead.
- Or capture a session generation (incremented by `endSession()`, see C4) before the `await` and drop the answer if it changed.
- Best: `useMutation({ mutationKey: ['account', 'notifications'] })`, with the mutation cache cleared on every session transition.

**Demo (run):** Appendix A, test 4. Output: `B digest before A answer false` / `B digest after A answer true`, then `AssertionError: expected true to be false`.

### C2 — High — An identity change seen through `['me']` never clears per-user caches (demonstrated)

**Location:** `web/src/account/session.ts:39-49` and `web/src/query.ts:15-17`. Clearing exists only in the six UI handlers; nothing reacts when `['me']` changes A→B or A→null.

**Smell:** a shared browser with another tab that signs A out and B in (or a session that expires, followed by sign-in elsewhere). This tab's next `['me']` poll or focus refetch says B, while `['watchlist']`, `['portfolio']`, `['webhooks']` and `['notifications']` still hold A's data.
- Every page renders `data` first. A's data stays up while B's refetch is in flight, and indefinitely if it fails (`retry: false`, last good data kept).
- On a page with no observer, A's copy shows immediately from cache (stale-while-revalidate within `gcTime`).

The `['me']` body has no stable id (`schema.d.ts:600-605`, `MeResource`), so today the client can only key on `email`.

**Better shape:**
- Namespace every per-user key under `['account', …]`.
- In `createQueryClient`, subscribe to the query cache. On a successful `['me']` update whose identity differs from the last seen, run `cancelQueries` + `removeQueries({ queryKey: ['account'] })` and `mutationCache.clear()`.
- Add `id` (or an opaque `subject`) to `/v1/me`.

**Demo (run):** Appendix A, tests 1-2:
- `expected [<a href="/card/ex5-17">Chimecho</a>] to deeply equal []`
- `ALERTS AFTER SWITCH { emailShown: true, aWebhookShown: true }`, which is B's email radio rendered beside A's private webhook URL.

### C3 — Medium — Per-user component state is not keyed by identity; A's one-time signing secret shows under B (demonstrated)

**Location:**
- `web/src/account/AccountPage.tsx:96-102`: `<SignedIn me>` / `<TwoFactorSection me={me}/>` with no `key`.
- `web/src/account/Webhooks.tsx:233-258`: the signing secret held in `useState`.
- `TwoFactor.tsx:72-73`: QR, setup key and recovery codes.
- The NotificationSettings `draft`.

**Better shape:** `key={me.id}` (until then, `me.email`) on the signed-in subtree of `AccountPage` and `AlertsPage`, so an identity change remounts and drops every secret held in state.

**Demo (run):** Appendix A, test 3 prints `B EMAIL SHOWN true A SECRET SHOWN true`.

### C4 — Medium — `forgetLists` is a hand-kept allowlist, it already misses a key, and the transition is copied six times (demonstrated)

**Location:**
- `web/src/account/lists.ts:60-64` names four un-namespaced keys.
- The sequence `forgetLists(qc); await qc.invalidateQueries({queryKey: ME_KEY, refetchType: 'all'}); navigate(…)` is copied at `SignInPage.tsx:42-44` and `129-131`, `SignUpPage.tsx:43-44`, `DemoButton.tsx:28-30`, `AccountPage.tsx:73-75` and `YourData.tsx:71-73`.
- `['verify-email', link]` (`VerifyEmailPage.tsx:36-47`, `staleTime: Infinity`) is not in the list.
- The state-changing GET is also modelled as a cached query, with a side effect (`invalidateQueries(ME_KEY)`) inside its `queryFn`.

**Better shape:**
- `startSession(qc)` / `endSession(qc)` in `session.ts` that cancel and remove `['account']` and clear mutations.
- One `accountKey(...)` factory for every per-user key.
- VerifyEmail becomes a one-shot `useMutation` with the `['me']` invalidation in `onSuccess`.

**Demo (run):** Appendix A, test 5 prints `LINK GETs 1 verified shown to B true`. B is told "Your email address is verified." from A's cached answer, and no request is sent.

### C5 — Medium — There is no global 401 path

**Location:**
- `web/src/query.ts` has no `QueryCache({ onError })`.
- The pages check `data !== undefined` before `error?.status === 401`: `WatchlistPage.tsx:248-251`, `PortfolioPage.tsx` (same shape), `AlertsPage.tsx:82-85`, `CardActions.tsx:114-124`.
- A 401 from a write (`CardActions` refusal) shows a sign-in prompt but never invalidates `['me']`.

**Smell:** when a session expires, list pages keep showing the old account's data with a header that still says "Signed in as …".

**Better shape:** in the `QueryCache` `onError`, a 401 on any `['account', …]` key calls `invalidateQueries(ME_KEY)`, which (with C2's subscriber) clears everything per-user.

### C6 — Medium — The client/API seam is two pipelines, with casts instead of decoding

**Location:**
- `web/src/api/client.ts:72-109` (public `request`): `response.json()`, an empty 2xx is a failure, no `redirect: 'error'`.
- `:316-410` (account `send`/`settle`/`accountRequest`): `text()` + `JSON.parse`, an empty 2xx is ok.
- The problem+json mapping is copied at `:91-99` and `:373-381`; `data: body as T` at `:90` and `:371`.
- `web/src/account/session.ts:17-37` re-validates `getMe`'s own typed result, casts `me as Me` (`:22`) and rebuilds the "unexpected answer" failure that `client.ts:418` already has.
- `CardPage.tsx:79-110` (`isPoint`/`pointsOf`) and `PriceHistory.tsx:230` re-validate in components, while every other caller trusts the cast.

**Better shape:** one `core(path, init): Promise<ApiResult<unknown>>`, one problem mapper, and a per-endpoint decoder in `client.ts`, so components never validate wire shapes and `session.ts` just reads `result.data.data`.

### C7 — Medium/Low — No `useMutation` anywhere; 13 hand-rolled pending/failure state machines

**Location:** `const [pending, setPending] = useState(false)` + `if (!pending) void x()` in:
- `AccountPage.tsx:20,60`
- `CardActions.tsx:152`
- `PortfolioPage.tsx:53`
- `WatchlistPage.tsx:154`
- `YourData.tsx:53`
- `SignInPage.tsx:26,109`
- `SignUpPage.tsx:21`
- `DemoButton.tsx:15`
- `TwoFactor.tsx:77`
- `ResetPasswordPage.tsx:22`
- `ForgotPasswordPage.tsx:12`

`useOnce` (`alerts.ts:50`) duplicates the pattern for P5 only.

**Smell:** writes are invisible to the QueryClient, so they cannot be cancelled or cleared on session change. That is the root enabler of C1.

**Better shape:** `useMutation` with `['account', …]` mutation keys. `isPending` replaces the local flag, and `endSession()` clears the mutation cache.

### C8 — Low — SearchPage fetches in an effect and bypasses the QueryClient

**Location:** `web/src/pages/SearchPage.tsx:164-184`: `useEffect` + `setSettled`, with `key` as a redundant dependency.

**Smell:**
- No cache on back/forward, so every return to results refetches.
- No D9 polling.
- A separate Problem UI.
- The only data path in the app outside TanStack Query.

**Better shape:** `useQuery({ queryKey: ['search', q, page], queryFn, enabled: q !== '' })`.

### C9 — Low — Card-page quantity prefill comes from a stale cache into an un-keyed form

**Location:** `web/src/account/CardActions.tsx:109-121`. The prefill comes from cached `['portfolio']` (5-minute `staleTime`) and is passed as `initial` into `QuantityForm`, which copies it into state once. `PortfolioPage.tsx:81` solves the same thing with `key={String(holding.quantity)}`.

**Better shape:** `key={String(heldQuantity(portfolio.data, variantKey))}` here as well, or lift the prefill into the form through one shared `useHolding(variantKey)` hook used by both views.

### C10 — Low — UI primitives are duplicated

**Location:**
- Five "problem block + Request ID" components: `Form.tsx:99`, `SearchPage.tsx:58`, `CardPage.tsx:290`, `PriceHistory.tsx:71`, `MoverList.tsx` (about line 100).
- Four label + `aria-invalid` + error implementations: `Form.tsx:29` `Field`, `Webhooks.tsx:166` `Labelled`, `ListParts.tsx` `QuantityForm`, and the inline alert control in `WatchlistPage.tsx:87-125`.
- Four signed-out prompts: `SignedOutList`, AlertsPage `SignedOut`, VerifyEmail `SignInFirst`, CardActions `SignInPrompt`.
- Seven copies of `<main className="mx-auto max-w-3xl p-6">`.
- The control class string copied in `WatchlistPage.tsx:51-54`, `Webhooks.tsx:52-55`, `Form.tsx:9-13` and `ListParts.tsx:120-123`, although `account/fields.ts` exists for exactly this.

**Better shape:**
- One `ProblemBlock`, one `Field`, one `SignInPrompt({ next })`, one `PageMain`.
- `CONTROL` / `BANNER_ERROR` / `BANNER_WARN` constants in `fields.ts`.

---

## (d) Cross-cutting

Checked and clean, so not reported:
- `schema.d.ts` matches the spec, and the C6 gate enforces it.
- Every compose env var is read through `config/`.
- The movers limit agrees across validation, spec and config.
- 26 test files freeze time.

### D1 — Medium — The skeleton seeder creates a verified account with a known password

**Location:**
- `api/database/seeders/DatabaseSeeder.php:20`: `User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com'])`
- `database/factories/UserFactory.php` sets password `'password'` (8 characters, below the app's 12-character rule) and `email_verified_at => now()`.

**Smell:**
- A bare `php artisan db:seed` or `migrate --seed` on a real instance creates a verified, alert-eligible account with a guessable password.
- `docs/ACCOUNTS.md:114` says "DatabaseSeeder is not edited", which documents the leftover rather than a decision.
- `UserFactory` is used by no test (`grep -rn 'User::factory' api/tests` → 0), and `fakerphp/faker` exists only for it.

**Better shape:** `DatabaseSeeder::run()` calls `DemoAccountSeeder` only (or nothing). Delete `UserFactory`, `HasFactory` on `User` and the faker dependency.

### D2 — Medium — The PHP version disagrees across composer, the lock, the tooling and Docker

**Location:**
- `api/composer.json:9`: `"php": "^8.3"`
- `composer.lock:12716-12717`: `"platform": {"php": "^8.3"}`
- But `composer.json:97` `config.platform.php` = `8.5.11`, and the image is `php:8.5-fpm-alpine` (`docker/api/Dockerfile:5`).
- Neither `rector.php` nor `phpstan.neon` sets a PHP version, so both infer the `^8.3` floor.

**Smell:** static analysis and automated refactoring target the wrong language version, so 8.4/8.5 deprecations are not reported and 8.4+ features are refused.

Other skeleton leftovers in `composer.json`:
- The `setup` script runs `npm install` / `npm run build` in `api/`, which has no `package.json`.
- The `dev` script runs `php artisan dev`.

**Better shape:** `"php": "~8.5.0"`, `->withPhpSets(php85: true)` in rector, `phpVersion: 80500` in phpstan, and delete the dead scripts.

### D3 — Medium — The forbidden-words test list, which guards a deliberate product rule, has eight-plus copies that have drifted

**Location:**
- Canonical lists:
  - `web/src/test/fixtures.ts:747-756`: 8 patterns.
  - `api/tests/Support/ForbiddenWords.php:15-26`: "the PHP copy", with no parity test.
- Full re-declarations: `web/src/Header.test.tsx:24-33`, `web/src/pages/MoverList.test.tsx:25-34`.
- Subsets:
  - `api/tests/Feature/Pricing/MoversTest.php:490` and `CardPricesTest.php:350`: `/real-time|realtime|\blive\b|24h|as of today|\b1d\b/`. No `today`, `now` or `24 h`.
  - `Cards/CardShowPriceTest.php:266`: also no `\b1d\b`.
  - `MovementVolumeTest.php:320`: `live|real-time|realtime` only.
  - `web/src/router.edge.test.tsx:115`: `real-?time|\blive\b` only.
  - `web/src/App.test.tsx:26`: `'realtime'` only.

**Smell:** the rule "nothing may say live/real-time/today/now/24h" is enforced with different strictness per surface. On `/movers` and card prices, a string containing "now" or "today" passes the tests.

**Better shape:** one JSON list in `gates/fixtures/forbidden-words.json`, read by both `ForbiddenWords.php` and `fixtures.ts`. Every test imports it, and the inline regexes are deleted.

### D4 — Medium — Movement-rule figures and alert bounds are copied across config, controller, migration, spec prose and web

**Location:**
- `api/config/pricing.php:13-15`: `'pct' => '5.00', 'abs' => '1.00'`
- `web/src/account/alerts.ts:16,18`: `MOVEMENT_PCT = 5`, `MOVEMENT_ABS = '1.00'`
- `WatchlistPage.tsx:44`: `THRESHOLDS = [5, 10, 20, 50]`
- The ceiling `100` is a literal at `AlertController.php:39,47`, and in the CHECK `BETWEEN 1 AND 100` (`2026_10_16_000002_add_alert_settings.php:24`). DB floor 1, API floor 5.
- `openapi/api.json:1249`: `"pct": {"type": "integer"}` with **no** `minimum`/`maximum`; the bounds exist only in prose (`:1227`, "from the movement rule's 5 … to 100").

**Smell:** the figures agree today, but the web copy (`NotificationSettings.tsx:20`, `Webhooks.tsx:31`) states the rule from its own constants. ADR 0001 is explicitly a *draft* rule (`'rule' => 'draft-v1'`), so it is expected to change, and the web would then describe the wrong rule.

**Better shape:** emit `minimum`/`maximum` on `pct` in the spec (generated from `MovementRule`, see A2), and return `meta.rule: {pct, abs}` from `GET /v1/me/notifications` for the web to print.

### D5 — Medium — The "as of" stamp, the provenance footnote and SPA paths are duplicated across api and web

**Location:**
- `` `as of ${day} from TCGCSV (daily close)` `` is a literal in:
  - `web/src/account/PortfolioPage.tsx:115`
  - `web/src/account/ListParts.tsx:115`
  - `web/src/pages/CardPage.tsx:126`
  - `web/src/pages/PriceHistory.tsx:89`
  - `api/app/Domain/Alerts/NotificationText.php:111`
  - `api/app/Console/Commands/AlertsDiscordTest.php:95`
- The footnote exists as `NotificationText::FOOTNOTE` (`:20`) but is re-typed at `AlertsDiscordTest.php:99` and in `web/src/format.ts:24`.
- SPA routes are built server-side:
  - `/card/` and `/watchlist` (`NotificationText.php:45,68`)
  - `/account/verify-email` and `/password/reset` (`AccountLinks.php:37,44`)
  - `'home' => '/account'` (`config/fortify.php:32`)

  They must match `web/src/router.ts:28,60-78`, and no test ties them. There are two APP_URL joiners: `AccountLinks::base()` (`:47-52`) and `NotificationText::url()` (`:128-131`).

**Better shape:**
- `asOfStamp(day)` in `format.ts`.
- One `App\Domain\SpaLinks` owning every SPA path and the single APP_URL joiner.
- `AlertsDiscordTest` uses `NotificationText::FOOTNOTE`.
- One cross-check test that reads `router.ts` route patterns and asserts every `SpaLinks` path matches.

### D6 — Medium/Low — Dead code: two models, the base controller, a never-written column and the safeguard that reads it, a fillable ghost

**Location:**
- `api/app/Domain/Pricing/Models/PriceEvent.php` and `Models/MovementVolumeRow.php`: **0 references** across app, tests, routes, config, scripts and docs.
- `api/app/Http/Controllers/Controller.php`: extended by nothing.
- `price_events.dispatched_at` is never written (`DeliverableDay.php:23-24` says so), so the R180a safeguard in `Replay.php:141-144` can never trigger. P5 tracks dispatch in `dispatch_days` instead.
- `users.name` is written by no code but is still in `#[Fillable(['name', …])]` (`app/Models/User.php:29`).

**Better shape:**
- Delete the two models and `Controller.php`.
- Drop `dispatched_at` and the dead branch. If R180a should still mean something, re-point it at `EXISTS (SELECT 1 FROM dispatch_days)`.
- Remove `name` from Fillable.

### D7 — Medium/Low — Rate limits live in three config locations with two shapes and three registrars; the queue name is stated four times

**Location:**
- Config:
  - `api/config/rate_limits.php`: `['per_minute' => n, 'by' => 'ip']`
  - `config/accounts.php` `rate_limits`: bare ints
  - `config/alerts.php:56` `'rate_limits' => ['webhook-test' => 5]`
- Registrars, each the same `register()` → `tiers(config(...))` → `afterResolving` → `apply()`:
  - `Domain/Catalog/RateLimits` (75 lines)
  - `Domain/Accounts/AccountRateLimits` (120 lines)
  - `Domain/Alerts/AlertRateLimits` (69 lines)
- Queue `default` is stated in four places: `config/pricing.php` `'queue'`, `app/Jobs/ProbeJob.php:18`, `compose.dev.yml:103 --queue=default`, and implicitly by the alert jobs. Changing `pricing.queue` silently strands ingest chunks.

**Better shape:** one `config/rate_limits.php` with a single tier shape keyed by group, one registrar, and one `queues` config consumed by every job and by the worker command.

### D8 — Low — Meta-tests that test other tests' source, and edge/review file sprawl

**Location:**
- `web/src/api/client.edge-carveout-applied.test.ts:3-13` imports `client.test.ts?raw` and asserts it contains a literal `replaceAll(...)`.
- `client.edge-carveout.test.ts:16-20` tests its own local lambdas, not production code.
- `api/tests/Feature/Alerts/DiscordTestCommandTest.php:106-108` asserts `file_get_contents(config_path('alerts.php'))` contains an `env(...)` literal.
- Sprawl:
  - web: 21 `*edge*` and 1 `*.review.test.tsx` out of 64 test files, with nine `client.*.test.ts` for one module.
  - api: 43 `*EdgeTest.php` out of 142.
  - Mixed naming: `YourDataEdge.test.tsx` vs `*.edge.test.tsx`.

**Better shape:** delete the meta-tests (source scans belong in `gates/hygiene.patterns`). Fold edge and review cases into each module's test file, grouped by behaviour.

### D9 — Low — Wall-clock sleeps, and stray HTTP is allowed by default

**Location:**
- `api/tests/Feature/ReadinessPokeSearchEdgeTest.php:82`: `usleep(5_500_000)` waiting for a real Redis TTL.
- `api/tests/Unit/Catalog/PokeSearchClientTest.php:176`: `usleep(1_000_000)` because the client reads the real clock.
- web: `setTimeout(resolve, 30)` at `TwoFactor.edge.test.tsx:275`, `account.edge.test.tsx:261` and `CardActions.test.tsx:99`.
- No global `Http::preventStrayRequests()` in `tests/Pest.php` / `TestCase.php`; only some files opt in.

**Better shape:**
- Inject a clock into `PokeSearchClient`.
- `Redis::expire`/`del` instead of sleeping.
- `vi.useFakeTimers()` / `waitFor`.
- `Http::preventStrayRequests()` in `TestCase::setUp()`.

### D10 — Low — Dependency and contract hygiene

**Location:**
- `laravel/tinker` is in `require` (prod) but used only by `tests/p0/T04-edge.sh:74`.
- `laravel/pail` and `laravel/pao` are referenced nowhere (Makefile, scripts, gates, docs).
- Pinning is mixed: `fortify 1.41.0`, `sanctum 4.3.3`, `paratest 7.26.0` and `opis 2.6.0` are exact; the rest are `^`.
- `@types/node 24.19.2` (`web/package.json:30`) is ahead of the runtime `node:22-slim` (`docker/web/Dockerfile:2`, `.nvmrc`), so Node-24-only APIs type-check and then fail at runtime.
- Problem slugs are matched by suffix literals in web (`lists.ts:43` `'/demo-read-only'`, `Form.tsx:85`, `CardPage.tsx:22`). The spec's `Problem.type` is a free `uri` with no enum.
- `UrlGuard::MAX_BYTES = 2048` (`app/Domain/Alerts/UrlGuard.php:31`) is not emitted as `maxLength` on the webhook `url` in the spec.
- `MoverDirection` (`web/src/router.ts:5`) hand-duplicates the generated `MoversDirection` (`client.ts:25`).

**Better shape:**
- Move tinker to `require-dev`; drop pail/pao; pin `@types/node@22`.
- Emit a problem-type enum (or `x-problem-slugs`) and `maxLength: 2048` in the spec, so `schema.d.ts` carries them and the web imports the slugs.
- Delete `MoverDirection`.

---

## Appendix A — Web leak probes (run against `p5-work`; all five fail)

These were run as a throwaway `web/src/zz-review-leak.test.tsx` using the repo's own `stubAccountFetch` / `jsonResponse` / `signedInMe` fixtures, then deleted (not committed). Each test asserts the *correct* behaviour, so a failure demonstrates the defect.

```
× watchlist: account A rows stay on screen once ["me"] says account B
    AssertionError: expected [ <a href="/card/ex5-17">Chimecho</a> ] to deeply equal []
× alerts: B sees A webhooks and settings next to B email
    ALERTS AFTER SWITCH { emailShown: true, aWebhookShown: true }
× alerts: A signing secret (component state) survives the switch to B
    B EMAIL SHOWN true A SECRET SHOWN true
× A PUT /v1/me/notifications resolving after sign-out + sign-in as B overwrites B settings
    B digest before A answer false / B digest after A answer true
× B is told the address is verified from A cached answer, without a request
    LINK GETs 1 verified shown to B true  (expected 2 requests)
Tests  5 failed (5)
```

The core of test 4 (C1), the in-tab late-write case:

```tsx
vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
  init?.method === 'PUT' && String(input) === '/v1/me/notifications'
    ? new Promise<Response>((resolve) => { releasePut = resolve; })   // A's save hangs
    : stubbed(input, init));
// A on /alerts: tick digest, Save → (pending)
// /account → Sign out → /sign-in as B → /alerts
expect(digest()).toBe(false);                                         // B's own setting
releasePut(jsonResponse(notificationsBody({ digest: true })));        // A's answer lands
await settle();
expect(digest()).toBe(false);                                         // FAILS: true
```

The core of tests 1-3 (C2/C3): render as A, then switch the `GET /v1/me` stub to B and refetch `['me']` (as the five-minute poll or a window focus would). Assert that no A-owned text remains (`Chimecho`, `https://a-private.example.com/…`, A's signing secret). All three are still present.
