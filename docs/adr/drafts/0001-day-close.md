# ADR 1: Day close for PokéPrices prices

Status: Proposed (draft for Andres; he moves it to docs/adr/ to accept it)

Date: 2026-10-13

Drafted during the PokéPrices P2 loop (human gate HG-20). Nothing here is in force
until Andres moves it out of `drafts/`. If he changes a definition, the orchestrator
plans a follow-up task; the code already implements the defaults below, and each
default is listed again, with its question, in the last section.

## Context

PokéPrices shows one price per card variant. Its only source is TCGplayer's
`marketPrice`, delivered as TCGCSV snapshots that a collector copies once a day,
each stamped with a marker (`marker.txt`, for example `2026-10-12T20:05:44+0000`).
The four days collected so far all carry markers around 20:05 UTC.

A price in this system is therefore a **daily close**: one value per variant per day,
as of that day's marker. It is not a continuous quote and never claims to be one.
Every price carries `source` (`tcgplayer`), `feed` (`tcgcsv`), `basis` (`market`),
`currency` (`USD`), `cadence` (`daily`), the close's `day` and its `as_of`.

Several later pieces depend on exactly what "the day", "the close" and "the previous
close" mean: the candles (`price_daily`), the latest value (`price_latest`), the
movers snapshot (`price_movers`), the movement outbox (`price_events`), replay and
the freshness metric. If the definitions drift after data accumulates, history has
to be rebuilt, so they are written down once, here, before the volume of data makes
a change expensive.

## Decision: the day and the close

- **Observation time.** A collected day's observation time `observed_at` is its
  TCGCSV marker, parsed to UTC.
- **The day.** Its `day` is the marker's UTC calendar date. That date must equal the
  folder name, or the day is refused with `marker_day_mismatch`. There is no
  re-labelling and no guessing.
- **Manifest cross-check.** A day is also refused with `manifest_mismatch` when the
  `marker`, `day` or `groups` fields of `manifest.json` disagree with `marker.txt`, the
  folder name or the group count of `groups.json`. This catches a half-copied folder.
  A day also needs its `SUCCESS` file, or it is not read at all.
- **The close.** The variant's close for a day is TCGplayer's `marketPrice` at that
  marker. Only `marketPrice` counts.
- **Null is no observation.** A row with a null `marketPrice` is stored raw in
  `price_points` but is **no observation**: there is no fallback to `midPrice`,
  `lowPrice` or `highPrice`, and nothing stands in for it.
- **Republished days.** A day TCGCSV did not republish has no folder, so it has no
  observation. It may be filled (next decision) but it is never invented.
- **Latest marker per UTC date wins.** Per (variant, day), exactly one observation
  counts: the one with the latest marker. When a run for day D has a marker different
  from the `observed_at` of the existing `price_points` rows of that day, the same
  transaction deletes those older rows before upserting. `price_points` therefore always
  equals what the folder on disk holds, and replay equals the first ingest. As a
  consequence `n_obs` is 1 for every real row; the column is kept.
- **Identity.** Prices are keyed by `variant_key` and `source` = `tcgplayer` (the
  marketplace, the same key the product map uses). Rows that do not map to a variant
  are counted per run and never stored.
- **Unmapped and unmatched rows** are counted, not guessed.

## Decision: carry-forward

`price_daily` has one row per (variant, source, calendar day), from the variant's first
real close through the newest ingested day of the source.

- **Real row.** `n_obs` is at least 1, `is_filled` is false, and open, high, low and
  close come from that day's real observations of the latest epoch observed that day.
- **Filled row.** On a day without a real observation, a filled row repeats the last
  real close (open = high = low = close). It has `n_obs` = 0, `is_filled` = true,
  `filled_from` = the real day it repeats, and `as_of` = that real observation's marker.
  Filled rows are flagged in the API and drawn as filled; they are never silent.
- **Cap.** A fill is allowed only while `day - filled_from` is at most 14 days. After
  that the series has a gap: no row at all.
- **Filled rows never count.** They never act as a close or a previous close, never feed
  `price_latest`'s close, never enter the movers snapshot, and never fire a movement
  event.
- **Recompute window.** Let P be the newest ingested day before a run and N the newest
  after it. Ingesting day D recomputes the days from the lesser of D and P + 1 up to
  the lesser of D + 14 and N, so the order in which days arrive never changes
  `price_daily`, `price_latest` or `price_movers`. With no P, the window starts at D;
  when D is newer than P, the uncollected days between them are filled like any other
  day.
- **No wall-clock column.** `price_daily` stores no run or wall-clock value, so identical
  inputs give byte-identical rows.

## Decision: previous close and change

- **Previous close.** For a real close on day D, the previous close is the most recent
  **real** close on an earlier day, in the **same** `series_epoch`, at most 14 days
  before D. Otherwise it is null. The window used on cards, boards and events is named
  `previous_close`; it is never labelled as a calendar day or hour span.
- **Change.** `change` = close minus previous close, with 2 decimals.
  `change_pct` = `round((close - previous_close) / previous_close x 100, 2)` using
  Postgres `numeric` rounding, half away from zero. It is null when the previous close is
  null or 0.00.
- **Money.**
  - Prices and changes are `numeric(12,2)`. `change_pct` is `numeric(12,2)` in every
    table, so a very large ratio cannot overflow a finalize.
  - JSON carries decimal strings with exactly two decimals (`"70.81"`, `"-1.45"`), never
    floats. The browser renders the API's digits and does no float arithmetic on prices.
  - A raw value is converted once, with `sprintf('%.2f')`, and accepted only if it
    round-trips to the same number. A value that fails the round trip, is negative, is
    10000000000 or more, or is neither a number nor null refuses the **whole day** with
    a `bad_value:` error. No row is skipped silently.
- Measured on the four collected days: no value has more than two decimals, none is
  negative or reaches 10000000000, and the largest is 214398.51.

## Decision: epochs

- **Epoch.** Normalization joins `(source_product_id, source_subtype)` to the product
  map (`source = 'tcgplayer'`) and takes `variant_key` and `series_epoch` from the
  current map row. A mapping change starts a new `series_epoch` for the variant.
- **No cross-epoch comparison.** A close is never compared across `series_epoch`: a new
  epoch has no previous close, no mover entry and no movement event at the boundary, and
  a chart breaks there. The first close of an epoch is a fresh start, not a move.
- **Replay semantics (R180).**
  - Replay normalizes with the **current map build and epoch**. It relabels history with
    the current map's epochs.
  - A variant remapped from one product to another therefore has its old and new product
    series merge into one epoch after rebuild-then-replay, because every old row is read
    again and given the epoch the current map assigns.
  - "Identical twice" is defined at a fixed (day set, map build, code sha). The replay's
    digest covers `price_points`, `price_daily` and `price_latest`, and records the map
    build id and the map's epoch counts, so a map change between two digests is visible.
    The movers snapshot has its own equality check and is outside the digest.
  - Replay never writes `price_events`, and never updates, deletes or truncates them.
  - A replay validates and pins its day list before it wipes anything, and holds the
    single writer lock for its whole duration.
- Consulting `source_product_map_history` instead of the current map, which would keep
  pre-remap epochs, is the alternative; it is listed under Open for Andres.

## Decision: schedule and freshness

- **Schedule.** A scheduled command, `ingest-pending`, runs hourly at minute 40. It is a
  no-op unless a day folder with a new `SUCCESS` file exists that has no finished ingest.
  When one exists it ingests the day through chunked queued jobs and finalizes it
  exactly once.
- **Why hourly, not one daily run.** The System Design (section 5) sketches a single run at
  20:30 UTC. The markers land around 20:05 UTC, but the collector's own timer fires at
  21:00, 23:00 and 03:00 UTC, and a collected day is pulled to this workstation by a
  separate step at a time the collector does not control. A single 20:30 run would
  usually fire before the day exists, and then wait a full day, which would break the
  freshness target. An hourly check costs one cheap directory listing and recovers
  within the hour.
- **Freshness.** `as_of` for a source is the newest marker among its successful ingest
  runs. `age_hours` is the time between that marker and the moment of measurement,
  displayed with 1 decimal, rounded half up. It is a metric about the data, not about
  the page.
- **SLO: 26 hours.** `within_slo` is computed on the **unrounded** age: it is true while
  the unrounded age is under 26 hours. Examples: 25.96 hours is within the SLO and is
  displayed as 26.0; 26.00 hours is not within the SLO. The display never decides the
  SLO. The freshness command, its log line and the API print the same displayed value.
- **Honesty wording.** Every rendered price reads `as of <day> from TCGCSV (daily
  close)`. Dates come from the data, never from the clock. Nothing on a page or in the
  API calls the feed continuous, instant or intraday, and no intraday range exists.

## Decision: movement thresholds (draft)

Rule `draft-v1` fires for a real close on day D when all of these hold:

- D's previous close exists, as defined above;
- the absolute `change_pct` is at least 5.00;
- the absolute `change` is at least 1.00 (dollars);
- D is the newest finalized day of the source when the day is finalized.

Both thresholds are filled in from the measured volume and stay at 5.00 percent and
1.00 dollar. Over the three day-to-day transitions of the collected days (2026-10-10,
2026-10-11 and 2026-10-12, about 33,000 compared variants each), `draft-v1` crosses
163.00 variants per day on average (141 to 197). The grid measured beside it gives
321.00 per day at 3.00 percent and 0.50 dollar, 88.00 at 10.00 percent and 2.00
dollars, and 47.67 at 20.00 percent and 5.00 dollars: the tight rule roughly doubles
the draft volume, the loose and coarse rules cut it to about a half and a quarter.
Another row of the grid is a one-line change in `config/pricing.php` if Andres prefers
it.

- **Backfill is silent.** A day older than the newest finalized day never fires; it is
  counted as `backfill`. The rule never fires across epochs and never on filled rows.
- **Events (R180a).** `price_events` rows are written only by a sync or queued ingest of
  the newest finalized day, in the finalize transaction, and never by replay. Replay
  never writes, updates or deletes events, and `replay --fresh` leaves existing events
  untouched and refuses once any event has been dispatched. The outbox therefore does not
  depend on replay order and never receives stale days. Nothing is dispatched in P2.
- **Movers board.** For day D, variants with a real close on D and a previous close of
  at least 2.00 are ranked by direction (up when the change is positive, down when
  negative), ordered by absolute `change_pct`, then absolute `change`, then `variant_key`,
  top 50 per direction. The 1.00 dollar floor belongs to the movement rule, not to the
  board.
- **Thresholds are configuration, not deploy knobs.** They sit in `config/pricing.php`
  and have no environment override.
- **Planner pre-measure** (all price rows, mapped or not, `marketPrice` only, the draft
  rule applied to day-over-day changes; a sanity bound, not the deliverable):

  | transition | moved |
  |---|---|
  | 2026-10-09 to 2026-10-10 | 260 |
  | 2026-10-10 to 2026-10-11 | 225 |
  | 2026-10-11 to 2026-10-12 | 216 |

  The largest three-day change on the collected days is +956 percent. No suspect-jump
  guard is built; extreme moves are counted in the measurement.

Measured volume: draft-v1 mean 163.00 events per day (range 141 to 197 over 2026-10-10 to 2026-10-12, measured on the dev database from `price_daily` after a fresh replay of every collected day; the first sync ingest of the newest day, 2026-10-12, wrote 151 events, equal to that day's measured count); per-day tables for all four rules are in docs/measurements/movement-volume.md.

## Alternatives considered

- **Label the day with 00:00 UTC instead of the marker's date.** A close dated by a
  midnight label would move every observation, taken around 20:05 UTC, onto an instant
  that was never observed, and it would break the equality between folder name and
  marker date that catches half-copied folders. The marker's UTC date was chosen.
- **Fall back to `midPrice` when `marketPrice` is null.** Rejected: the bases differ, so
  a mixed series would silently compare unlike things, and a null `marketPrice` is itself
  information. `midPrice` and `lowPrice` stay stored raw and are not used.
- **Carry forward for 7 days (Research 14).** Superseded by the System Design's 14 days:
  collection gaps of a week or more happen, and a visible, flagged fill is better than a
  hole, as long as the cap keeps it honest. The cap is 14.
- **ULID event ids.** Not needed while nothing is dispatched. Events use an identity id
  plus a unique key on (`variant_key`, source, day, `series_epoch`), which gives the same
  once-only guarantee. P5 can add a public id when it adds dispatch.

## Consequences

- A price on screen is always one daily close with its own `as_of` day. A variant with
  no close inside the 14-day cap shows nothing instead of a stale number.
- Ingest order never changes `price_daily`, `price_latest` or `price_movers`, and a
  replay of every collected day gives identical rows at a fixed (day set, map build,
  code sha).
- A map rebuild followed by a replay relabels all history with the current epochs. A
  remapped variant's earlier series merges into its new epoch. That is deliberate and is
  documented in the operations runbook.
- The hourly schedule means a newly pulled day is ingested within the hour, so the 26
  hour SLO has headroom against a daily publication cadence.
- Both movement thresholds can change after the measurement. That changes
  `config/pricing.php` and the events that later days produce; it does not rewrite
  history, because backfilled days never fire.
- Changing the definitions in this ADR after data accumulates means a replay and, for
  the table shapes, a plan amendment.

## Open for Andres

Each question has a default the code already uses; none blocks the loop.

| # | Question | Default |
|---|---|---|
| 1 | Day-close label | The marker's UTC date, equal to the folder name; a mismatch is refused. Alternatives: a 00:00 UTC label, or the collection date |
| 2 | Close basis | `marketPrice` only; null is no observation (filled or a gap); no `midPrice` or `lowPrice` fallback |
| 3 | Movers window | Since the previous real close only (`previous_close`); a longer window is P3's call |
| 4 | Movers eligibility | Previous close of at least 2.00, top 50 per direction, no dollar floor on the change |
| 5 | Ingest schedule | Hourly at minute 40, a no-op without a new `SUCCESS` day, instead of one 20:30 UTC run |
| 6 | Events without watchlists | Written for every variant crossing `draft-v1`, never dispatched; P5 decides their scoping |
| 7 | Suspect-jump guard | Not built; extreme moves are counted in the measurement |
| 8 | Price `source` key | `tcgplayer` (the marketplace, matching the map); TCGCSV is recorded as `feed` |
| 9 | Carry-forward cap | 14 days, replacing Research 14's 7 |
| 10 | Stale badge on the card | None; each variant shows its own as-of day |
| 11 | Replay and the map | Normalize against the current map (history relabelled); the alternative is `source_product_map_history`, which keeps pre-remap epochs |
| 12 | The two thresholds | 5.00 percent and 1.00 dollar, kept after measurement (163.00 events per day on average); the grid's tight, loose and coarse rows are the alternatives |
