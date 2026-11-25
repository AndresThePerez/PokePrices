# PokéPrices price operations

How the daily-close prices get into PokéPrices, how to check them, and what to do when a day does not
arrive, does not ingest, or has to be ingested again. Every price is a daily close from TCGCSV: one
value per variant per day, labelled with the day of the close. Nothing here is continuous or intraday.

Run every command from the repository root on the workstation. Each command below is written out in full
with the compose prefix, so a line can be pasted as it stands:

```sh
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan <command>
```

The development site is `http://pokeprices.localhost:8095`. The PokéSearch dev stack on
`http://localhost:8081` is a separate project; nothing in this runbook starts, stops or changes it.

Words used below:

- **day**: the UTC date of a collected snapshot folder `<day>/` under the snapshots root
  (`/workspace/.data/snapshots` inside the containers). A day is collected once `SUCCESS` is present in it.
- **marker**: the instant in the day's `marker.txt`. Its UTC date must equal the folder name.
- **run**: one attempt to ingest a day, one row in `ingest_runs` with a `mode` (`sync`, `queued`, `replay`)
  and a `status` (`running`, `ok`, `failed`, `refused`, `skipped_unchanged`, `superseded`).
- **the lock**: the Postgres advisory lock `pricing:ingest`. Every writer of the price tables holds it, so
  two writers never overlap.

## Daily routine

A new day arrives in four steps, and only the third is this repository's code.

1. **Collect.** The collector on the server writes the day's folder (`marker.txt`, `manifest.json`,
   `groups.json`, the per-group price files and, last, `SUCCESS`). The marker lands around 20:05 UTC.
2. **Pull.** On a dev machine a finished folder has to be copied to `.data/snapshots/<day>/` by hand, a
   copy that only reads on the server. Nothing does it on a schedule: no timer, script or job on the
   workstation pulls a day, nothing in PokéPrices fetches from the server or the network, and dev
   therefore holds no day newer than the last copy.

   In production there is no pull step: the collector's day folders on the server are mounted read-only
   into the api, worker and scheduler containers, so the :40 scan ingests each new day in place. The
   server commands are in [DEPLOY.md](DEPLOY.md).
3. **The :40 scan.** The scheduler runs `pokeprices-ingest-pending` hourly at minute 40. It lists the day
   folders that hold `SUCCESS`, oldest first, and starts an ingest for each day that needs one. Most scans
   find nothing to do and exit. The rules of one scan:
   - it skips the whole pass while another session holds the lock (a replay or a start);
   - a day whose marker already has an `ok` run is done (a `superseded` run does not count);
   - a day whose marker has a `running` run younger than 2 hours is left alone; an older one is stalled,
     and only its chunks that are not done are re-dispatched;
   - a day whose newest run is `refused`, with the same marker and the same error, within the last
     24 hours is skipped, so a permanently bad folder costs one refused row a day;
   - a day whose marker's newest runs are two consecutive `failed` runs is held for 6 hours after the
     newest one ended, and for 24 hours after three or more (see "A failed day");
   - every other day, including a day whose marker failed once, gets a new run. The start re-validates the
     whole folder before anything is written.

   The scheduler container only queues the scan; the scan itself, like every job below, runs on the worker.
4. **Ingest and finalize.** The run reads the day through chunked queued jobs on the worker (3 tries per
   chunk, the second 10 seconds after the first and the third 60 seconds after the second). When every
   chunk is done, one finalize transaction rolls the
   day up into `price_daily` (carrying a close forward at most 14 days, flagged `is_filled`), rebuilds
   `price_latest` and `price_movers`, refreshes `movement_volume`, writes any movement rows to
   `price_events` (only for an ordinary ingest of the newest day, never for a replay) and marks the run
   `ok`. Finalize is atomic: a failure leaves `price_daily` and `price_latest` as they were.

The scheduler also queues the freshness line once an hour (`pokeprices-freshness`); the worker writes it
(message `price_freshness`), so a stopped worker writes no freshness line at all. On a dev machine the
worker stops after its maximum run time (one hour) and nothing restarts it, by design: bring it back with
`docker compose -f compose.dev.yml up -d --wait worker`.

### Check that the day arrived

```sh
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan schedule:list
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:prices:freshness
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:prices:freshness --json
curl -s http://pokeprices.localhost:8095/v1/prices/freshness
```

`schedule:list` shows `pokeprices-ingest-pending` at `40 * * * *` and `pokeprices-freshness` hourly.

`pokeprices:prices:freshness` prints one line and exits 0 only within the SLO:

```
tcgplayer via tcgcsv: as of 2026-10-12T20:05:00Z, 3.2 h old, SLO 26 h: ok
```

| Output | Exit | Meaning |
|---|---|---|
| `... SLO 26 h: ok` | 0 | the newest `ok` run's marker is under 26 hours old |
| `... SLO 26 h: BREACHED` | 1 | 26 hours or more; no newer collected day was ingested in time |
| `... no prices ingested` | 1 | no `ok` run exists for the source |

The age is judged unrounded: 25.96 hours is within the SLO and prints as `26.0`; 26.00 hours is not.
`--json` and `GET /v1/prices/freshness` carry the same data: `as_of`, `day`, `age_hours`, `slo_hours`,
`within_slo`, `measured_at`, `last_run` (`id`, `status`, `mode`, `day`, `finished_at`), `missing_days` and
`missing_days_count`. A breach is the metric working, not a fault in itself: go to the freshness case in
the failure runbook.

`missing_days` lists the calendar days between the first and the newest day with an `ok` run that have no
`ok` run of their own, as ISO dates, oldest first. At most the newest 30 are listed;
`missing_days_count` counts them all. Both are empty and 0 when no day is missing. A missing day does not
change `within_slo`, and the one-line output does not show it: read `--json` or the endpoint, or go to
"A missing day" in the failure runbook. A missing day warns (the hourly line, the Discord report) only while
it is at most 14 calendar days before today's UTC date (`pricing.freshness.missing_day_warning_days`): on
20 October the day of 6 October still warns and the day of 5 October no longer does, though it stays in
`missing_days`.

To see the latest runs:

```sh
docker compose -f compose.dev.yml exec -T postgres psql -U pokeprices -d pokeprices -c "select id, day, mode, status, counts->>'mapped' as mapped, left(error, 80) as error from ingest_runs order by id desc limit 10"
```

After a change to job classes or the schedule (anything under `api/app/Domain/Pricing/Jobs` or
`api/routes/console.php`), restart the loop-owned worker and scheduler containers, because both keep the
old code in memory until they restart:

```sh
docker compose -f compose.dev.yml restart worker scheduler
```

### The movers board

`price_movers` holds, for each collected day, the variants whose close moved most against their previous
close, ranked once at finalize; `GET /v1/movers` reads the newest day that has rows. A variant is ranked
on day D when all of this holds:

- **A previous close.** It has a real close on D (never a carried-forward one) and a previous close: its
  most recent real close on an earlier day in the same `series_epoch`, at most 14 days back.
- **The floor.** The previous close is at least 2.00 USD (`pricing.movers.min_previous_close`).
- **A material move.** The change is at least 5.00 % (as stored, two decimals) and at least 1.00 USD,
  both. Exactly 5.00 % and exactly 1.00 count.
- **Recent price activity.** Of the variant's five most recent real closes in the epoch, the close of D
  included and none older than 14 days before D, at least four are distinct values. A day without a real
  close (missing or carried forward) is no observation: it adds neither a close nor a change, and the count
  reaches one real close further back instead. A price the series already showed is no new value, so a
  spike that comes back the next day is ranked on neither day.
- **In line with the listings.** Both closes of the move are held against the low and mid listing stored
  with their own observation in `price_points`: each must be no lower than half the low listing and no
  higher than one and a half times the mid listing, bounds included. A close whose observation is
  missing, or lists no low or no mid, or lists either at 0.00, is not ranked; neither is a close of 0.00.
- **Not a thin market.** The close of D is not under half the low listing of its own observation
  (`PricingConfig::THIN_MARKET_RATIO`), the line the card page flags as a thin market (see Lowest listing
  and thin markets). With both ratios at one half the listing bound above already keeps such a close out,
  so on dev this removes no row; it is stated on its own so the board never ranks a close the card page
  flags, whatever the listing bounds are set to.

The ranked variants are ordered by the size of the change in percent, then by the change, then by
`variant_key`; rises and falls are ranked apart and 50 of each are kept at most. The thresholds are the
`MOVERS_*` constants of `PricingConfig` and the floor in `config/pricing.php`;
`docs/measurements/movers-eligibility.md` holds the evidence for each value and what the rule cannot do.

What follows from the rule:

- **The board is short.** A day ranks the variants that meet the rule and no others: a few dozen out of
  about 33,000 closes, not 50 rises and 50 falls. One direction can be empty while the other is not.
- **A new series ranks nothing for three days.** With fewer than four real closes in its epoch no variant
  can show four values. The first three days after collection starts, after a `replay` of fewer than four
  days, or after a remap of a variant therefore have no movers for it.
- **A day with no ranked variant has no rows.** `GET /v1/movers` then answers the newest day that has
  some, with that day in `meta.day` and its marker in `meta.as_of`, so the board says which close it shows.
- **Order of arrival does not matter.** Everything a day's movers are made of lies between 14 days before
  it and the day itself, and ingesting a day rebuilds the movers of that day and the 14 days after it.

#### Rebuild the movers of stored days

`price_movers` is derived data. Its rows are rewritten when their day is in the recompute window of an
ingest, and by a replay; a change to the thresholds does not touch the days already stored. To rank stored
days again under the rule as it is, without ingesting anything, rebuild them from `price_daily` and
`price_points` (here one day, 2026-10-13; give another first and last day for a range):

```sh
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan tinker --execute='$db = Illuminate\Support\Facades\DB::connection(); if (! App\Domain\Pricing\IngestLock::tryAcquire($db)) { echo "pricing ingest lock held, nothing changed\n"; return; } try { $db->transaction(fn () => App\Domain\Pricing\Movers::rebuild($db, "2026-10-13", "2026-10-13")); echo $db->getDatabaseName(), " ", json_encode($db->select("SELECT direction, count(*) AS n FROM price_movers WHERE day BETWEEN ? AND ? GROUP BY direction ORDER BY direction", ["2026-10-13", "2026-10-13"])), "\n"; } finally { App\Domain\Pricing\IngestLock::release($db); }'
```

It takes the lock, and in one transaction deletes the `price_movers` rows of those days for the source and
inserts the rows the rule gives; it reads the two price tables and writes nothing else. It prints the
database name and the rows per direction it left, or `pricing ingest lock held, nothing changed` when an
ingest or a replay is running. Running it twice gives the same rows.

### Lowest listing and thin markets

The market price of a variant is an average of recent sales, so a card that rarely sells can show a close
far from what it is listed at. `price_latest` holds no listing; `GET /v1/cards/{id}` reads it from
`price_points` with one query for all the card's variants (no index and no migration were added):

- **`price.lowest_listing`** is the low listing of the observation the close came from (the `price_points`
  row at the close's `as_of`), a decimal string with two decimals, or `null` when that observation lists
  no low or a low of 0.00.
- **`price.thin_market`** is `true` when that listing is present and the close is under half of it
  (`PricingConfig::THIN_MARKET_RATIO`, 0.5). Exactly half is not thin.
- **`listing`** is on a variant that has observations but no close (its market price was never given):
  the low listing of its newest observation, with that observation's `day` and `as_of`, when the low is
  above zero. `price` stays `null`, and `listing` is left out on every other variant.

Only the card answer carries these fields; the watchlist and portfolio price blocks are unchanged. On dev
(closes 2026-10-09 to 2026-10-13), 2,950 of 165,072 price points have a market under half their low
listing, 518 of the 32,992 newest closes are flagged, and 31 variants have listings but no close on
2026-10-13 (1st Edition Base Charizard, `base1-4:holo:1st:-`, among them).

## Ingest a day by hand

Use this when a collected day should be ingested now, or ingested again. The day folder must already be in
the snapshots root with `SUCCESS` present. The snapshot is only read.

```sh
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:prices:ingest --day 2026-10-12 --sync
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:prices:ingest --day 2026-10-12 --queued --wait=900
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:prices:ingest --day 2026-10-12 --sync --force --digest
```

- `--day` is required, `YYYY-MM-DD`. Exactly one of `--sync`, `--queued` or `--resume` is required.
- `--sync` runs start, every chunk and finalize in this process, in one transaction, and prints one JSON
  line: `run_id`, `day`, `marker`, `rows`, `mapped`, `unmapped`, `null_market`, `upserted`, `unchanged`,
  `daily_rows`. About 45,000 rows per day, of which about 33,000 are mapped. It needs no worker.
- `--queued` starts the run here (validation, the run row, the chunk plan), then the chunks and finalize
  run on the worker through the `redis` queue connection (`--queue-connection` changes it). It needs the
  worker container running. Without `--wait` it prints the run id and its status at once. With
  `--wait=<seconds>` it polls the run for at most that long and prints the counts with the table digests.
  One real day took about 25 seconds this way.
- `--force` ingests a marker that already has an `ok` run again. The upsert is idempotent: a second run
  gives `unchanged` equal to `mapped`. Without `--force`, such a day is not ingested again and the command
  prints `"status":"skipped_unchanged"` and exits 0.
- `--digest` (with `--sync`) adds the determinism digest of the price tables to the line.

| Command and outcome | Exit |
|---|---|
| `--sync` ingested, or `skipped_unchanged` | 0 |
| `--queued` or `--resume` without `--wait`: run started | 0 (1 if its status is already `failed`) |
| `--queued` or `--resume` with `--wait`: run ended `ok` | 0 |
| `--wait` ended `failed`, `superseded` or still `running` at the bound | 1 |
| `refused` (the day failed validation; the reason is printed) | 1 |
| `pricing ingest lock held` | 1 |
| bad option (`--day` not a date, none or several modes, `--wait` not a number) | 1 |
| `--resume` with nothing to resume | 1 |

A second writer is refused, not queued behind: `pricing ingest lock held` means a replay, a start or a
finalize is running. Wait for it, or look at the lock case in the failure runbook.

A day that already has a `running` run is not started again. While any `running` run of that day exists,
`--sync`, `--queued` and `--force` all write nothing, print `a running run of <day> exists (run N)` and exit 1,
whatever the marker. A run that is `running` for good (a worker that died) therefore blocks every start of
that day until `--resume` or the :40 scan (after 2 hours) resumes it, or a replay supersedes it; `--resume`
and the scan reach only a queued run of the folder's current marker, so after the marker changed only a replay
clears it; see "A stalled or crashed run".

## Resume a failed run

A queued run fails when one chunk runs out of its 3 tries (or finalize does). The other chunks stay `done`
and finalize does not run, so `price_daily` and `price_latest` are untouched, although `price_points` may
already hold the rows of the chunks that finished.

Read the state first:

```sh
docker compose -f compose.dev.yml exec -T postgres psql -U pokeprices -d pokeprices -c "select id, day, mode, status, started_at, finished_at, error from ingest_runs where day = '2026-10-12' order by id"
docker compose -f compose.dev.yml exec -T postgres psql -U pokeprices -d pokeprices -c "select group_id, \"offset\", status, attempts, left(error, 100) as error from ingest_run_chunks where ingest_run_id = 42 and status <> 'done' order by id"
docker compose -f compose.dev.yml exec -T postgres psql -U pokeprices -d pokeprices -c "select id, queue, failed_at, left(exception, 160) as exception from failed_jobs order by id desc limit 10"
```

- `ingest_runs.error` names why the run failed. `status = 'failed'` with chunks `failed` is a chunk
  failure; with every chunk `done` it is a finalize failure.
- `ingest_run_chunks.status` is `pending`, `done` or `failed`; `attempts` counts the tries and `error`
  holds the last one.
- `failed_jobs` holds one row per job that exhausted its tries (`IngestChunk`, `FinalizeDay`). It is the
  record of the failure; the rows are kept 30 days (720 hours), then pruned once a day with `job_batches`
  (`pokeprices-prune-failed` and `pokeprices-prune-batches` in the schedule). The `ingest_run_chunks` rows of a
  `superseded` run are kept 30 days (720 hours) after the run finished, then pruned once a day
  (`pokeprices-prune-chunks`); the `ingest_runs` row stays, and a superseded run that never finished keeps its chunks.

Fix the cause (a file that was rewritten, a full disk, a stopped database), then resume:

```sh
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:prices:ingest --day 2026-10-12 --resume --wait=900
```

`--resume` re-opens the newest `running` or `failed` queued run of the day's current marker, turns its
`failed` chunks back to `pending`, and re-dispatches only the chunks that are not done. Done chunks are
not read again and finalize sums each chunk's counts once, so nothing is counted twice. The result equals a
clean run's: the digests are the same. It prints `resumed_chunks` when run without `--wait`. If the marker
in the folder has changed since the run started, there is nothing to resume (exit 1); ingest the day afresh
with `--queued`. Only a queued run can be resumed: a failed `--sync` ingest is simply run again, and a
newer `ok` or `superseded` run of the marker also leaves nothing to resume.

Two limits on `--resume`:

- It does not apply the running-run guard that `--sync`, `--queued` and `--force` starts obey. It reopens the
  newest `running` queued run of the marker as well, so never `--resume` a day while a fresh `--queued` run of
  that day is running: it would re-dispatch that running run's chunks that are not done.
- It cannot rescue the chunks of a run from an older map build. After a map rebuild, each chunk of such a run
  that still has to run fails (see "Map rebuild then replay"), so `--resume` of it fails again; only a run
  whose chunks are all done (finalize failed) resumes. Otherwise ingest the day afresh with `--queued`, or
  replay.

## Replay

Replay wipes the derived price tables and ingests every collected day again, three times, to prove the
result is the same each time. Run it after a map rebuild (see the next section) or after a fix to the
rollup or the normalization. It is not a daily job.

```sh
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:prices:replay --fresh --code-sha "$(git rev-parse HEAD)"
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:prices:replay --fresh --order=desc --days=2026-10-10,2026-10-11
```

- `--fresh` is required. Without it the command names the tables it would truncate and exits 1.
- `--days=all` is the default; a comma-separated list of collected days replays only those, but the wipe
  still removes every day's rows (see below).
- `--order=asc|desc` is the ingest order of all three passes. `--code-sha` is recorded in the output.
- The output is one JSON line: `days`, `day_count`, `order`, `map_build_id`, `code_sha`, `passes` (each with
  `pass`, `digests` for `price_points`, `price_daily` and `price_latest`, `movers_check` and `seconds`) and
  `identical`. The command exits 0 only when `identical` is true. It exits 1 for a missing `--fresh`, a
  bad option, a held lock, a refused preflight, a failed day and a mismatch between the passes.

How it works, and the guarantees:

- **Preflight before any wipe.** It lists the days once (every `<day>/` with `SUCCESS`, or the days named),
  validates each with the ingest's own start checks (marker, manifest, every group file present and
  decodable, every money value) and pins that day list and each day's marker for all three passes. An empty
  list, a refused day, or dispatched events stop it with nothing written.
- **The lock.** It takes `pricing:ingest` for its whole duration. A replay and an ingest never overlap; the
  other writer exits 1 with `pricing ingest lock held`, and the :40 scan skips its pass.
- **The wipe.** Before passes 1 and 2, in one transaction, it marks the source's `ok` and `running` runs
  `superseded` and truncates `price_points`, `price_daily`, `price_latest`, `price_movers` and
  `movement_volume`. With a `--days` subset the wipe still removes the other days' rows, and their runs are
  superseded too, so the :40 scan ingests them again. A queued chunk that was in flight finds its run
  `superseded` and exits without writing. Pass 3 ingests again without wiping.
- **Events are untouched (R180a).** Replay never writes, updates, deletes or truncates `price_events`, and
  a replayed day never runs the movement rule. `replay --fresh` refuses (exit 1, "price_events holds
  dispatched rows") as soon as any `price_events.dispatched_at` is set: dispatched events cannot be taken
  back. Until P5 dispatches events nothing sets `dispatched_at`, and the outbox is append-only: rows are
  added by an ordinary ingest and are never updated or deleted by anything in P2.
- **What "identical" means (R180c).** The three digests (rows and sha256 of `price_points`, `price_daily`
  and `price_latest`) and the three `movers_check` values are equal across the passes. It holds at a
  fixed day set, map build and code sha: replay normalizes with the current map build and epochs, so a
  replay after a rebuild is identical to itself but not to the replay before the rebuild. The output
  records `map_build_id` so a change is visible. `price_events` is never part of the digest.
- **Superseded runs and a crashed replay.** If the replay dies after a wipe, the pinned days' former `ok`
  runs stay `superseded` and the tables are partly empty. Nothing else is needed: a superseded run does not
  count as done, so the next :40 scan ingests those days again through the queue, oldest first, as
  ordinary queued ingests (an event already in the outbox is never rewritten). If you want it sooner,
  run the replay again or ingest each day by hand.
- **Durations.** Measured on the four collected days 2026-10-09 to 2026-10-12: a pass took 24 to 33
  seconds and the whole command (3 passes) 74 to 90 seconds. It grows with the number of collected days;
  give a replay of many days a generous timeout or run it detached.
- **During a long replay, freshness answers a breach-like state.** Between the wipe and the first `ok`
  replay run, `pokeprices:prices:freshness` prints `no prices ingested` (exit 1), and then an old
  `as_of` until the newest day lands. That is the honest answer for those minutes, not a fault. The tell
  is `last_run` in `--json` or in `/v1/prices/freshness`: `mode` `replay` with `status` `running`.
- **Test script note.** `tests/p2/T07.sh` runs the real replay over every collected day in the dev
  database, descending first and ascending last, and leaves the dev database in the ascending replay
  state by design. It takes minutes and needs the stack running.

## Map rebuild then replay

The product map decides which variant and which `series_epoch` a price row belongs to. A rebuild is the
one deliberate change to that mapping, and it is followed by a replay so that stored history agrees with
the new map. The sequence:

```sh
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:map:build --day 2026-10-12 --dataset /workspace/.data/dataset/<dataset-checkout>
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:map:audit --check constraints
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:map:audit --check coverage
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:map:audit --check ambiguity
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:map:audit --check names
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:map:audit --check priced --json
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:map:golden
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:map:oracle
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:prices:replay --fresh --code-sha "$(git rev-parse HEAD)"
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:prices:freshness
```

1. **Build.** `pokeprices:map:build` reads the collected day and the dataset checkout and writes a new
   `map_builds` row (its id is the `map_build_id` in every later digest). The snapshot is only read. It
   exits 1 with `map build refused` when the day or the dataset is unusable.
2. **Check before replaying.** The five audit checks (`names`, `constraints`, `coverage`, `ambiguity`,
   `priced`), the golden-set judgement (`pokeprices:map:golden`) and the oracle comparison
   (`pokeprices:map:oracle`, which needs the oracle recorded for the build's day) each print an artefact line
   (`--json` and `--out` write it out) and exit 0; they exit 1 only when they refuse to run. Read the values:
   a worse `coverage`, a new `ambiguity` or a `priced` value above 0 is a reason to stop and not replay.
   - **The priced-card share (`priced`, DC7).** Of the dataset's cards, the share that has at least one
     current `source_product_map` row, overall and per era (the set's `series`). A card whose only products
     were rejected (`unknown_tag`, `name_disagreement`, …) counts for `coverage`, because it is listed for
     review, but not here: it has no price. The share is held to a floor: 96.00 % overall, a floor per era
     (for example Sun & Moon 95.00 %, XY 94.00 %, Sword & Shield 97.50 %, HeartGold & SoulSilver 93.50 %;
     the table is `ERA_FLOORS_BP` in `api/app/Domain/Mapping/MapAudit.php`) and 90.00 % for an era without
     its own. The value is the number of floors missed (0 is a pass) out of one plus the number of eras. The
     2026-10-11 build measured 97.86 % overall; before the hyphenated `-EX`/`-GX`, Prism Star, Unown and
     rarity-tag rules it was 89.46 %, which this check would have failed.
   - **The unmapped-products list.** The same artefact lists the build's unmapped card products, at most
     25, the dearest first by the collected day's market price (a product without one last); sealed products
     (`non_card`) and groups with no catalogue set (`no_set_alias`) are left out. It reads the build's
     collected day from `services.tcgcsv.snapshots` (`--snapshots` to point elsewhere) and refuses with
     `map audit refused` when that day is missing. The share is computed from the map rows, not from the
     price tables, so it is already right after a rebuild and before its replay.
3. **Replay.** Only then run `pokeprices:prices:replay --fresh`. Do not ingest new days between a rebuild
   and its replay if you can avoid it. Stop the scheduler before the replay and start it again only after
   the replay has run to the end, as step 14, item 6 of `docs/DEPLOY.md` does: while the replay runs, the
   earlier runs are `superseded`, and the hourly price-job report (see "The Discord report") would
   otherwise post false messages.

What a rebuild changes:

- **Epochs.** A variant whose product or subtype changes has its old row moved to
  `source_product_map_history` (end reason `remapped`) and its current row gets `series_epoch + 1`. Every
  other row is left as it is, so a rebuild that changes nothing changes no epoch.
- **Stored prices keep their old labels until the replay.** `price_points`, `price_daily` and
  `price_latest` carry the epoch they had when ingested. A day ingested after the rebuild and before the
  replay is labelled with the new epoch, so the variant's chart breaks at that boundary and the new epoch
  has no previous close.
- **The replay relabels history (R180c).** Replay normalizes with the **current** map build and epoch: every
  stored day is read again from the raw files and takes the epoch the current map gives its variant.
  After rebuild-then-replay, a remapped variant's old and new product series that the current map still
  holds merge into one epoch. A product the current map no longer holds is unmapped in the replay and
  writes no row (it is counted in `unmapped`).
- **Digests.** `price_points`, `price_daily` and `price_latest` change wherever a label changed; the new
  digests are the new baseline at the new `map_build_id`. Compare passes within one replay, not across
  the rebuild.
- **A run in flight when the map is rebuilt.** A run is pinned to the map build it started on. If a newer
  build lands while it runs (a rebuild mid-day), each of its chunks that has not finished throws
  `the product map changed since ingest run N started (map build A, now B)`, writes nothing and fails
  through its 3 tries; the run then fails, and the next :40 scan opens a fresh run on the new map.
  `--resume` of a run from the older build fails the same way on every chunk it re-dispatches, so ingest
  the day afresh with `--queued`, or replay. A run that recorded no map build skips this check.
- **Movers and measured volume.** `price_movers` and `movement_volume` are rebuilt by the replay. Nothing
  is ever compared across `series_epoch`: the first close of an epoch has no previous close, no mover
  entry and no movement event. The movers also count activity inside the epoch only, so a remapped
  variant is back on the board at the earliest with the fourth distinct close of its new epoch (see "The
  movers board").
- **Events are not relabelled.** `price_events` rows keep the `series_epoch` they were written with,
  because replay never touches the outbox.
- **Open decision.** Normalizing against `source_product_map_history` instead of the current map would keep
  the pre-remap epochs. That is open for Andres (ADR 1 draft, question 11); until it is decided the
  current-map rule above is what replay does.

To re-measure the movement volume after a replay, and to refresh the committed measurement file:

```sh
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:prices:movement-volume --markdown /workspace/docs/measurements/movement-volume.md
```

It rewrites `movement_volume` from `price_daily` under the lock, prints a JSON summary and exits 0, or
exits 1 when the lock is held or `price_daily` is empty.

## Partitions and retention

`price_points` is partitioned by month on the observation instant (UTC), one partition per month named
`price_points_yYYYYmMM`. It has no default partition. Every run creates the partition of its marker's
month if it is missing, before it writes anything, so nobody prepares months by hand. A month that was not
created fails the write with SQLSTATE 23514; a run cannot hit that because it creates its own month first.

Retention is documented and not built. The intent is to keep raw `price_points` for 24 months and drop
whole monthly partitions older than that. No job does it, nothing is dropped today, and `price_daily` is
not partitioned. Until the drop exists, storage only grows; when it is built it will be a separate task
with its own tests.

Account rows are pruned too. A password-reset token is good for 60 minutes; the scheduler clears the expired
`password_reset_tokens` rows once a day at 03:17 UTC (`pokeprices-prune-resets` in the schedule, the
framework's `auth:clear-resets`), a minute at which neither the :40 scan nor the hourly freshness line runs.
A token that is still good is never touched.

## Failure runbook

Each case: the symptom, where it shows, what to run. A failed or refused ingest never leaves a half-done
day behind the daily tables: finalize is atomic, so `price_daily` and `price_latest` only change when a
run goes `ok`. A queued run that fails part-way does leave the rows of its finished chunks in
`price_points`; the rollup of another day reads another day's rows only when an `ok` run of that day and
marker exists, so those rows reach no daily close until their own run is resumed and goes `ok`.

Three warnings in the worker's log say that a day is in trouble:

| Message | When | Context |
|---|---|---|
| `price_freshness` (warning) | hourly, while the SLO is breached or a day of the last 14 is missing | the freshness fields, plus `missing_days` and `missing_days_count` while a day is missing (also at info) |
| `ingest_refused` | a scheduled start refused a day folder; once per day, marker and reason, however often the scan retries it | `day`, `marker` (null when none was read), `error` |
| `ingest_blocked` | a scheduled start found a run left `running` on its day; once per run | `day`, `run_id` |

`price_freshness` is the hourly line itself, so it repeats every hour while its cause lasts; the other two
are written once. `ingest_failed_repeatedly` (see "A failed day") is the fourth, older one.

### The Discord report

The same problems are posted to a Discord channel: one message when a problem starts and one when it is
gone, with no reminder in between.

- **The job.** `pokeprices-price-problems` in the schedule runs `pokeprices:prices:report-problems` every
  hour on the hour. The scheduler runs the command itself; it is not a queued job, so a stopped worker is
  still reported. It reads the same list as the freshness answer's missing days and the warnings above
  (late, a missing day, a refused day, a stuck run or a day on hold), so the three cannot disagree.
- **The variable.** `PRICE_JOB_DISCORD_WEBHOOK_URL`, a Discord webhook address
  (`https://discord.com/api/webhooks/<id>/<token>`), read as `alerts.discord.price_job_webhook_url`. It
  carries a token: it is set in the environment only, never committed, and the command never prints, logs
  or stores it. On the server it is a line of the `.env` beside the stack file, which passes it to the
  `api`, `worker` and `scheduler` containers (`docs/DEPLOY.md`, step 5). While it is unset or empty the
  command prints `PRICE_JOB_DISCORD_WEBHOOK_URL is not set; nothing was sent.`, exits 0 and writes nothing.
- **The table.** What was posted is kept in `reported_price_problems` (one row per report: `key`, `kind`,
  `day`, `sentence`, `reported_at`, `cleared_at`), so a restart repeats nothing. The migration
  `2026_11_24_000001_create_reported_price_problems_table` creates it. The update in `docs/DEPLOY.md`
  starts the scheduler before it runs `migrate`; a report that runs in that gap with the variable set
  fails on the missing table before it posts anything, and the next hour's run is normal. One open row
  per problem is enforced by a unique index.
  Nothing prunes the table; it grows by two timestamps per problem.

The messages, each with the day in ISO form (`<seen>` and `<over>` are UTC instants of the hourly run that
noticed, not of the cause):

| Message | Meaning |
|---|---|
| `PokéPrices price job problem: The newest prices are from <day>, which is past the limit of 26 hours. Seen since <seen>.` | late: the SLO is breached; see "Freshness breach" |
| `PokéPrices price job problem: No price day has been ingested yet. Seen since <seen>.` | late on an empty database |
| `PokéPrices price job problem: No price run finished for <day>. Seen since <seen>.` | a missing day; see "A missing day" |
| `PokéPrices price job problem: The folder of <day> was refused: <reason>. Seen since <seen>.` | a refused day; the reason is the one in `ingest_runs.error`; see "A refused day" |
| `PokéPrices price job problem: Run <id> of <day> has not finished and blocks its day. Seen since <seen>.` | a run left `running` for three hours or longer; see "A stalled or crashed run" |
| `PokéPrices price job problem: <day> failed twice in a row and is on hold. Seen since <seen>.` | a day on hold (or `<n> times`); see "A failed day" |
| `PokéPrices price job problem cleared: <the sentence as it was posted> That was seen since <seen> and is over as of <over>.` | the problem is no longer listed |

How to read them:

- One message per problem. A refused or stuck day that lies between two ingested days is also a missing
  day, so it gets two messages, and two "cleared" messages when its run goes `ok`.
- "Cleared" says that this entry is gone, not that the day is in order. A hold that ends is cleared while
  the day may still have no `ok` run; the late message names the newest day, so when a newer day arrives
  that is itself already past the limit, the old one is cleared and a new one is posted. A missing day
  is cleared once when it turns 15 days old and is never posted again, though it may still have no run.
- A problem that starts and ends between two hourly runs is never posted. The same problem starting again
  after it was cleared is posted again.
- A post that fails (a timeout, a 429, a 5xx, an address the guard refuses) writes a
  `price_problem_report_failed` warning with the problem's key, `started` or `cleared`, the HTTP status
  and the error, records nothing, and is tried again the next hour: one attempt per problem per run. The
  scheduler keeps the output of the commands it runs in a file and shows none of it, except for this
  command: when a run fails, the scheduler prints one line per failed post in its own output, which is the
  log of the `scheduler` service (the stack's `logs` command for that service, on a dev machine and on the
  server alike). It shows

  ```text
  price_problem_report_failed key=<key> event=<started|cleared> status=<status>
  ```

  with the problem's key, `started` or `cleared`, and the HTTP status (`429`, `500`), or a word when
  there was none: `timeout`, `connection`, `refused` (the address guard refused the address or it is not
  a Discord webhook address), `unresolvable` or `internal`. The line never holds the address. What to
  do: a status of 4xx (other than 429) means Discord refused the message, so check that the webhook still
  exists and that `PRICE_JOB_DISCORD_WEBHOOK_URL` is the whole address; `429` and `5xx` are Discord's and
  clear on their own; `timeout` and `connection` point at the network of the host; `refused` points at
  the variable (a wrong or truncated address). Nothing needs resetting: the next hour's run tries the
  same posts again, and the lines stop when a run posts them all.
- Delivery is at least once. A message Discord accepted whose answer was lost, or whose row could not be
  written, is posted again the next hour.

A test by hand. The command is safe to run at any time; it posts only what the schedule would post next:

```sh
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:prices:report-problems
docker compose -f compose.dev.yml exec -T postgres psql -U pokeprices -d pokeprices -c "select key, reported_at, cleared_at from reported_price_problems order by id desc limit 20"
```

It prints `{"posted":<n>,"cleared":<n>,"failed":<n>}` and exits 1 when a post failed. With the variable set
to the webhook of a test channel on a dev machine whose newest day is past the limit, the first run posts
the late message and the second run prints three zeros. `Another price-job report is running; nothing was
sent.` means the scheduled run holds the report's advisory lock; run it again a minute later.

Note: a stopped scheduler is noticed by nothing in this stack. The report, the freshness line and the scan
all stop with it, and no message is posted. Only a check from outside sees it, through the freshness that
stops moving, for example once an hour:

```sh
curl -fsS http://pokeprices.localhost:8095/v1/prices/freshness | grep -q '"within_slo":true'
```

A non-zero exit means the prices are past the limit or the site does not answer.

### A refused day

- **Symptom.** `pokeprices:prices:ingest` prints `ingest refused (run N): <reason>` and exits 1; the :40
  scan writes an `ingest_runs` row with `status = 'refused'` and the reason in `error`, and the worker logs
  one `ingest_refused` warning with the day, the marker and the reason (not again for the same day, marker
  and reason; a new marker or a new reason is logged again). Nothing was written, no chunk exists.
- **Reasons.** The reason names the file:
  - `missing_day:<day>`, `missing_file:SUCCESS`, `missing_file:<file>`: the folder, `SUCCESS`, the
    manifest, `groups.json` or a group file is not there.
  - `bad_marker:marker.txt`: the marker does not parse.
  - `marker_day_mismatch:marker.txt`: the marker's UTC date is not the folder's date.
  - `manifest_mismatch:manifest.json:marker`, `:day` or `:groups`: the manifest disagrees with the folder.
  - `bad_document:<file>`: a JSON document or a `products/<group>.json.zst` file is malformed or truncated.
  - `bad_value:<file>:<productId>:<field>`: a money value fails the round trip, is negative, is
    10000000000 or more, or is neither a number nor null. The whole day is refused; no row is skipped
    silently.
- **What to run.** Read the reason with the `ingest_runs` query in the daily routine. The raw files are
  never edited here; the fix is a good folder: a new collection on the server, or on a dev machine a fresh
  copy by hand. Then ingest the day by hand (`--sync` or `--queued`), or wait for the scan.
- **Suppression.** The :40 scan does not retry a refusal for the same marker and the same error within
  24 hours (one refused row a day). A folder that changed, with another error or another marker, is
  retried at the next scan. `pokeprices:prices:ingest` by hand always tries.

### A failed day

- **Symptom.** A chunk exhausted its 3 tries (10 and then 60 seconds apart), or finalize failed. The
  run is `failed`; the chunk is `failed`; `failed_jobs` has an `IngestChunk` or `FinalizeDay` row; `last_run`
  in the freshness data shows `failed`. A sync ingest exits 1 with `ingest failed (run N): <error>`.
- **What to run.** The queries in "Resume a failed run", then fix the cause, then `--resume`.
- **A day that keeps failing backs off.** The :40 scan suppresses not only refused days: a failing day is
  no longer retried at every :40 scan either. The scan counts the consecutive `failed` runs of the day's
  marker, newest first, up to the first run of that marker that is not `failed` (`skipped_unchanged` runs do
  not count either way). After one failure the next :40 scan retries. After two, the day is held for 6 hours
  from the newest failed run's `finished_at`; after three or more, for 24 hours. At three or more the scan
  logs one `ingest_failed_repeatedly` warning per newest failed run, with the day, the marker, the count, the
  error and the end of the hold. A new marker (a re-collected folder) starts the count again, and so does
  marking the newest failed run `superseded`. `--resume` and `--queued` by hand do not wait for the hold.
  By design, the scan resumes a stale (> 2 h) `running` run before it reads the hold, so such a run is
  resumed even when newer `failed` runs of its marker would hold the day. To stop the retries, fix the day
  (the cause in the error, or a good folder) and get a run to `ok` with `--resume` or a
  fresh `--queued`; the scan then sees the day as done.

### A stalled or crashed run

- **Symptom.** A run is `running` for more than 2 hours (a worker that died, a restart in the middle).
- **What to run.** Nothing: the :40 scan resumes a stalled run and re-dispatches only the chunks that are
  not done. By hand: `--resume`. If the worker container is down, bring it back first
  (`docker compose -f compose.dev.yml up -d --wait worker`) and restart it after job-class code changes.
- **What it blocks meanwhile.** While the run is `running`, a `--sync`, `--queued` or `--force` start of that
  day is refused with `a running run of <day> exists (run N)` (exit 1). Clearing it: `--resume`, the :40 scan
  once the run is older than 2 hours (both only for a queued run of the folder's current marker), or a replay
  (which marks every `running` run `superseded`; the only way when the marker has changed). Do not `--resume`
  while a fresh `--queued` run of the day is running, and after a map rebuild ingest the day afresh with
  `--queued` or replay instead of resuming a run from the older build (see "Map rebuild then replay").
- **What the log says.** A scheduled start that finds a run left `running` on its day logs one
  `ingest_blocked` warning with the day and the run id, once per run. The scan only sends a start for a day
  whose current marker has no running run, so this warning means a run of an earlier marker of the day is
  still `running` and nothing will resume it: the day stays blocked until a replay, while later days go on
  ingesting and freshness stays within the SLO. The same warning can appear once, harmlessly, when two
  starts of one day were queued behind a busy worker; then the run it names goes `ok` soon after.

### A missing day

- **Symptom.** `missing_days` in `/v1/prices/freshness` (or `--json`) is not empty, and the hourly
  `price_freshness` record is a warning that carries `missing_days` and `missing_days_count`, also while
  `within_slo` is true. A day between the first and the newest ingested day has no `ok` run: it was never
  collected, its folder has no `SUCCESS` or is not readable, it was refused, it is held after failures, or
  a run blocks it. Its `price_daily` rows are carried forward from an earlier close (`is_filled`).
- **What to run.** The `ingest_runs` query in the daily routine for that day. A `refused`, `failed` or
  `running` row leads to its case here. No row at all means the folder never arrived or holds no
  `SUCCESS`: look in the snapshots root. Once a good folder is there, the scan ingests it, the day's rows
  are recomputed with the days after it, and the day leaves `missing_days`. A day the source never
  published stays listed; nothing here invents a close for it. Once it is more than 14 calendar days old
  (UTC) it no longer makes the hourly record a warning and its Discord report is cleared once.

### A crashed replay

- **Symptom.** The replay command died or was stopped after a wipe: the price tables hold fewer days than
  `ingest_runs` shows, and the pinned days' former `ok` runs are `superseded`.
- **What to run.** Wait for the :40 scan, which ingests the superseded days again, or ingest them by hand;
  once they are `ok`, `pokeprices:prices:freshness` is back within the SLO. Run the replay again
  afterwards to prove the result is identical.
  - **Movement events from the repair.** The repair ingests are ordinary queued ingests, and after the wipe no
    finalized day exists yet, so each repaired day is momentarily the newest finalized day when it finalizes. The
    movement rule can therefore write `price_events` rows for days that originally fired nothing (days first
    ingested as a backfill or out of order). Nothing dispatches these rows in P2 (`dispatched_at` stays null).
    The outbox key prevents duplicates: an event already in the outbox is never rewritten. Alerts and webhooks never
    send them: until the newest day that was finalized before the crash is finalized again, nothing is sent, and after
    that only that day's events are sent, never an event added to a day that was already sent (see docs/ALERTS.md,
    "Crash repair and the deliverable day").

### An unmapped surge

- **Symptom.** A run is `ok` but `counts.mapped` is 0 or far below its usual 33,000 and `counts.unmapped`
  is high. The run writes no price row and leaves `price_latest` alone, so a map regression cannot blank
  the prices.
- **What to run.** `pokeprices:map:audit --check coverage` and `--check priced` (its list names the dearest
  products the map rejected); if the map is wrong, follow "Map rebuild then replay".

### Duplicate rows, a late day

- Two rows for one key in a file: the first occurrence wins and `duplicate_rows` counts it; nothing fails.
- A day ingested after a newer one (the pull delivered it late) gives the same `price_points`,
  `price_daily` and `price_latest` as the in-order run. It writes no movement event, because only the
  newest finalized day fires the rule.

### The lock is held or stuck

- **Symptom.** `pricing ingest lock held`: a sync ingest, a replay or a volume run exits 1; a queued start
  releases itself and tries again after 60 seconds; the :40 scan skips its pass and logs the same message.
- **What it means.** Another session is writing: a replay, a start or a finalize. The lock belongs to a
  database session, so it goes away when that session ends, including when its process crashes.
- **What to run.** Find the holder:

  ```sh
  docker compose -f compose.dev.yml exec -T postgres psql -U pokeprices -d pokeprices -c "select l.pid, a.state, a.query_start, left(a.query, 80) as query from pg_locks l join pg_stat_activity a using (pid) where l.locktype = 'advisory' and l.classid = 0 and l.objid = 1085361408"
  ```

  A row whose `query_start` is recent is a writer at work: wait. A session that has sat `idle` for
  a long time and belongs to no running command is a leak; end that one session with
  `select pg_terminate_backend(<pid>)` in `psql`, then check that no run is left `running` and let the
  scan or `--resume` finish the day. Do not end the session of a replay that is still working.
- **A finalize waits its turn.** A queued `FinalizeDay` that finds the lock held releases itself for 60
  seconds and tries again, for up to 6 hours after it was queued; only three real exceptions (a timeout is
  one) fail it and its run.
- **Test infrastructure note.** `t09WithLockHeld` in `api/tests/Feature/Pricing/IngestFailureTest.php` holds
  the lock on a second connection with a blocking call. If a real lock leak ever exists, that test hangs
  rather than fails. A Pest run of `tests/Feature/Pricing` that hangs points to a leaked lock, so look
  here before suspecting the test.

### Freshness breach

- **Symptom.** `pokeprices:prices:freshness` prints `BREACHED` or `no prices ingested` and exits 1;
  `/v1/prices/freshness` has `within_slo: false`; the hourly `price_freshness` log record is a warning.
  The worker writes that record, so while the worker is stopped there is no hourly line at all; the
  command and the endpoint still answer.
- **What to run, in order.**
  1. Is a replay running? `last_run` with `mode` `replay` and `status` `running` explains it (see Replay).
  2. Was a newer day collected? Look for a `<day>/SUCCESS` newer than `as_of` in the snapshots root. If
     there is none, the collector is the problem (on a dev machine, nobody copied a newer day in) and
     nothing here can fix it.
  3. Is the pipeline running? `docker compose -f compose.dev.yml ps` shows `worker` and `scheduler`
     healthy; if not, `docker compose -f compose.dev.yml up -d --wait worker scheduler`.
  4. What did the newest run say? `last_run` and the `ingest_runs` query: `refused` and `failed` runs do
     not move `as_of`; follow those cases above.
  5. If everything is in order, ingest the day by hand.

### A marker with a fraction of a second

`ingest_runs.marker` is stored truncated to the second, and the run compares the folder's marker at that
precision, so a sub-second marker is not a mismatch. The collector writes whole seconds, so this is a
latent note only; nothing depends on it today.

### A Redis outage

Redis has two roles. `redis-queue` holds the job queue, the rate limiters and the catalogue's locks;
`redis-cache` holds the catalogue's cached data. While one of them does not answer, a route answers degraded
or the problem `http-503` (`503`, title `Service Unavailable`) with `Retry-After: 5`, never a bare `500`.

- **Symptom.** `/v1/ready` answers `503` with `checks.redis_queue` or `checks.redis_cache` at `fail`.
- **What to run.** `docker compose -f compose.dev.yml ps` shows which of the two is not healthy;
  `docker compose -f compose.dev.yml up -d --wait redis-queue redis-cache` starts it again.

A rate limit that cannot be counted is refused, never waved through: with `redis-queue` down a rate-limited
route answers the `503`. The contract documents that `503` on every rate-limited operation, and only
`GET /v1/ready` has no rate limit. The rows below are the routes `api/tests/Feature/CacheOutageTest.php`
exercises (the readiness row: `ReadinessTest.php` and `ReadinessEdgeTest.php`); "not tested" means no test
reads that cell, not that the route fails.

| Route | `redis-queue` down | `redis-cache` down |
|---|---|---|
| `GET /v1/ready` | `503` `not-ready`, `checks.redis_queue: fail` | `503` `not-ready`, `checks.redis_cache: fail` |
| `GET /v1/prices/freshness`, `GET /v1/cards/{id}/prices` | `503` | answers as without the outage |
| `GET /v1/movers` | `503` | `200` degraded: every `card` is null, `meta.degraded` true, `no-store` |
| `GET /v1/cards` (search) | `503` | `503`, also for a page past the last, which would read the catalogue twice |
| `GET /v1/cards/{id}` | `503` | `503` |
| `GET /v1/watchlist` | `503` | `200` degraded: every `card` is null, `meta.degraded` true |
| `GET /v1/portfolio` | not tested | `200` degraded: every `card` is null, `meta.degraded` true |
| `GET /v1/me` | `503`, for a guest and for a signed-in account, `no-store` | answers as without the outage |
| `GET /v1/me/export` | not tested | answers as without the outage |
| `GET /v1/demo/samples` | `503` | answers as without the outage |
| `POST /v1/demo/session` | `503` | not tested |
| `/sanctum/csrf-cookie`, `/login`, `/register`, `/forgot-password` | `503` | not tested |

With `redis-cache` down while PokéSearch is up, a card and a search still answer the `503`: the catalogue is
read through its cache and never around it.

### The last good copy does not outlive the Redis cache

While PokéSearch is down, a card, a search and the card names answer from their last good copy (kept 7
days) with `meta.degraded` true. Those copies are kept on `redis-cache` only. In `compose.dev.yml` that
service keeps nothing on disk (`--appendonly no`, `--save ""`) and evicts under memory pressure
(`--maxmemory 96mb`, `allkeys-lru`), so a restart of `redis-cache`, or an eviction, loses them: with
PokéSearch still down, a card read before then answers the `503` `upstream-unavailable` with `Retry-After`
until PokéSearch answers again. `api/tests/Feature/Cards/CardLastGoodLimitTest.php` pins this.

- **What to do.** Nothing here brings a lost copy back; it returns with the next answer from PokéSearch.
  Do not restart `redis-cache` while PokéSearch is down unless the cache itself is the problem.
- **Why the copies are not kept elsewhere.** A search copy is about 14 KB and its key is chosen by the
  caller (the search text and the page), at up to 60 searches a minute from one address. On `redis-queue`
  (`--maxmemory 48mb`, `noeviction`, also the job queue) that fills the store and then refuses the queue's
  writes. In the database cache store nothing removes expired rows without a scheduled prune, and every
  catalogue fill would add a Postgres write to a public read. A card copy is about 2 KB and a card name
  about 0.3 KB; those are bounded by the size of the catalogue.

## What never happens

- **No raw file is deleted, moved or written.** The ingest, the replay and the map commands only read the
  snapshot folders. A "fix" for a bad day is a good folder from the pull step, never an edit in place. Runs
  are marked `superseded`, never deleted.
- **No Elasticsearch writes.** Prices are in Postgres only. The product map is read, never written, by the
  pricing code.
- **No claim that a price is current to the minute.** Every price carries its source, feed, basis,
  currency, cadence, day and `as_of` from the data and reads `as of <day> from TCGCSV (daily close)`. A
  carried-forward close is flagged and capped at 14 days. There is no intraday range and no one-day
  range; the movers window is "since the previous close".
- **No dispatch of events before P5.** `price_events` is an outbox that only collects rows
  (`dispatched_at` stays null); nothing sends them. Replay never touches it, and `replay --fresh` refuses
  once any row has been dispatched.
- **No fetch from the network by the pricing code.** The collector and the pull step are outside it.
