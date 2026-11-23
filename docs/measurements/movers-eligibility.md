# Movers eligibility: thresholds and evidence

What the movers board ranks, why each threshold has the value it has, and what the rule does to the
development data. Every figure below was read from the development database with read-only queries over
`price_daily` and `price_points`; the "after" figures are the `SELECT` of `Movers::rebuild()` itself with
its thresholds written in as literals, so they are what a rebuild stores. Counts are variants per day.

- Days measured: 2026-10-09 to 2026-10-13 (5 collected days, none missing)
- Real closes: 164,907 over the 5 days (32,969 to 32,991 a day), all in `series_epoch` 1; 12,516 to 12,541 a
  day are 2.00 USD or more
- Observations: one per variant and day; every real close has its observation in `price_points`, and every
  one of them carries a low and a mid listing above zero
- Map build: 4. Source: tcgplayer, feed tcgcsv, daily closes

## The rule

For day D a variant is ranked when all of this holds. The first two lines are the rule the board had
before; the other three are new.

1. It has a real close on D and a previous close: its most recent real close on an earlier day in the same
   `series_epoch`, at most 14 days back (`pricing.fill_cap_days`).
2. The previous close is at least 2.00 (`pricing.movers.min_previous_close`).
3. **A material move.** `abs(change_pct)`, as stored with two decimals, is at least 5.00, and `abs(change)`
   is at least 1.00, both.
4. **Recent price activity.** Of the variant's five most recent real closes in the epoch (the close of D
   included, none older than 14 days before D), at least four are distinct values.
5. **In line with the listings.** Both closes of the move, the one on D and the previous one, lie between
   half the low listing and twice the mid listing of their own observation, bounds included. A close whose
   observation is missing, or lists no low or no mid, or lists either at 0.00, is not ranked.

The order is unchanged: `abs(change_pct)` descending, then `abs(change)` descending, then `variant_key`
in byte order, the first 50 per direction.

| threshold | value | where |
|---|---|---|
| previous-close floor | 2.00 USD | `config/pricing.php`, `movers.min_previous_close` (unchanged) |
| smallest move, percent | 5.00 | `PricingConfig::MOVERS_MIN_CHANGE_PCT` |
| smallest move, amount | 1.00 USD | `PricingConfig::MOVERS_MIN_CHANGE` |
| closes looked at for activity | 5 | `PricingConfig::MOVERS_ACTIVITY_CLOSES` |
| distinct values needed among them | 4 | `PricingConfig::MOVERS_ACTIVITY_MIN_DISTINCT` |
| lower bound of a close | 0.5 x its low listing | `PricingConfig::MOVERS_LISTING_LOW_RATIO` |
| upper bound of a close | 2 x its mid listing | `PricingConfig::MOVERS_LISTING_MID_RATIO` |

Edges, each pinned by a test in `api/tests/Feature/Pricing/MoversEligibilityTest.php`: exactly 5.00 % and
exactly 1.00 count, 4.99 % and 0.99 do not (a percent of 4.995 is stored as 5.00 and counts); exactly four
distinct values count, three do not; a close exactly at half its low or twice its mid counts, one cent
outside does not; a previous close of 2.00 counts, 1.99 does not.

## Evidence for the activity test

A market close from this source is an average of recent sales, so it changes on a day the card sold and
stays put on a day it did not. How often a series changed is therefore a measure of how often the card
trades. The question is how many changes separate a traded card from one that sat still and then took a
single sale.

**How a series behaved before predicts what its next step is.** Variants with a real close on all five
days and a close of 2.00 or more on 2026-10-12, grouped by on how many of the three steps before
2026-10-13 their close changed:

| changes in the three steps before | variants | changed again on 10-13 | of those moves, at least 5 % and 1.00 |
|---|---|---|---|
| 0 | 6,664 | 9.9 % | 14.5 % (96) |
| 1 | 2,830 | 23.2 % | 5.5 % (36) |
| 2 | 1,426 | 53.6 % | 1.2 % (9) |
| 3 | 1,588 | 85.6 % | 1.2 % (16) |

A series that had been still, or had changed once, lurches when it moves: its steps are material about
four to twelve times as often as those of a series that changed on two or three of the three days. From two
changes up the share is the same 1.2 %. That is where the line is drawn: the step of D plus at least two
changes in the three steps before it, which is four distinct values among five closes.

**What a material move did the next day**, by how many distinct values its series showed up to the day of
the move (among at most five closes):

| day of the move | distinct values | material moves | unchanged the next day | gave back half or more | held or went on |
|---|---|---|---|---|---|
| 2026-10-10 | 2 | 196 | 169 (86.2 %) | 10 | 17 |
| 2026-10-11 | 2 | 113 | 98 (86.7 %) | 5 | 10 |
| 2026-10-11 | 3 | 27 | 9 (33.3 %) | 1 | 17 |
| 2026-10-12 | 2 | 112 | 104 (92.9 %) | 1 | 7 |
| 2026-10-12 | 3 | 19 | 14 (73.7 %) | 2 | 3 |
| 2026-10-12 | 4 | 19 | 2 (10.5 %) | 1 | 16 |

On 2026-10-12, with four days of history, a material move in a series showing three values was followed
by an unchanged close 14 times out of 19: no further sale. With four values that happened 2 times out of
19, and 16 of the 19 held or went on. Three distinct values, the figure the review's trial used, still
lets mostly untraded cards through; four does not.

**Why distinct values and not a count of changes.** A spike that comes back the next day changes twice
but shows two values. Of the 10 material moves on the development days that returned exactly to the close
of two days before, 8 sat in a series with two distinct values. Counting distinct values leaves both days
of such a spike off the board; counting changes would rank the way back.

**Alternatives measured**, with the move and listing tests held fixed (ranked variants, up / down):

| day | 3 of last 3 | 3 of last 4 | 3 of last 5 | 4 of last 4 | 4 of last 5 (chosen) | 5 of last 5 |
|---|---|---|---|---|---|---|
| 2026-10-10 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| 2026-10-11 | 12 / 12 | 12 / 12 | 12 / 12 | 0 / 0 | 0 / 0 | 0 / 0 |
| 2026-10-12 | 13 / 13 | 21 / 15 | 21 / 15 | 8 / 11 | 8 / 11 | 0 / 0 |
| 2026-10-13 | 15 / 13 | 23 / 16 | 28 / 18 | 4 / 13 | 7 / 13 | 3 / 13 |

With three of five, eight of the ten biggest rises of 2026-10-13 would be series showing exactly three
values, led by `hsp-HGSS10` cracked ice (99.68, 99.65, 99.65, 99.65, then 199.23: +99.93 %). Five of five
keeps three rises. Four of five allows one unchanged day in four.

**The window.** It is counted in closes, not calendar days: the five most recent real closes, none older
than the 14 days the previous close may reach back. A filled or missing day is no observation, so it adds
neither a close nor a change, and the count reaches one real close further back instead. A collector
outage therefore does not empty the board for the days after it, and the activity either side of a gap
still counts. The 14-day reach is also what keeps the board deterministic: ingesting a day recomputes the
movers of that day and the 14 days after it, which covers every day whose activity count or previous
close the new day can change.

- On the five development days the window cannot be filled before 2026-10-13. 2026-10-12 has four closes
  and ranks only series whose close changed on every day; 2026-10-10 and 2026-10-11 have two and three
  closes and rank nothing.
- On forty days of history it means the same five closes as on 2026-10-13, normally the last five days.
  Nothing older than the fifth close back counts, so a series cannot qualify on activity it showed weeks
  before. A longer window could not be tuned here: dev holds no sixth day to measure it on.

## Evidence for the material move

The floor of 5 % and 1.00 was set by the owner's ruling; it is the same pair the movement rule uses, kept
as the movers' own constants. On 2026-10-13 it takes the candidates from 3,444 (1,690 up, 1,754 down) to
158 (116 up, 42 down). On the stored board of that day, 27 of the 50 biggest falls moved less than 1.00
and 3 of the 50 biggest rises did.

## Evidence for the listing test

Where a close sits against the low and mid listings of its own observation, for closes of 2.00 or more
on all five days, by how many distinct closes the series showed over the five days:

| distinct closes in five days | closes | under half the low | over twice the mid | median close / low | median close / mid |
|---|---|---|---|---|---|
| 1 (never changed) | 30,110 | 7.78 % | 2.89 % | 1.48 | 0.97 |
| 2 | 14,818 | 2.94 % | 1.98 % | 1.66 | 1.00 |
| 3 | 6,556 | 0.66 % | 0.90 % | 1.63 | 0.99 |
| 4 | 5,152 | 0.12 % | 0.82 % | 1.50 | 0.94 |
| 5 (changed every day) | 6,004 | 0.00 % | 0.52 % | 1.37 | 0.89 |

(The medians are of 2026-10-13.) The close of a card that trades every day is never under half its
cheapest listing and is over twice its mid listing one time in two hundred; for it, the 99th percentile of
close / mid is 1.83. A card that never changed is outside that band one time in ten. So "far outside" is
below 0.5 x low or above 2 x mid: the band holds 99.5 % of the closes of traded cards and is wide enough
that a rising card whose sales average lags its listings stays inside.

**Both closes are tested.** A move has two ends, and either can be the odd one. `ex13-3` Reverse is the
case: its 752.00 on 2026-10-13 is in line with its listings (549.99 low, 1,498.99 mid), and it is the 5.00
of the four days before that is not, at 0.009 x the low listing. Testing the close of the day alone would
call that +14,940 % a move in line with the market. The previous close is also what keeps the way back
from an odd sale off the board the day after. Among the material moves of 2026-10-13, 25 rises and 3 falls
have the day's close out of line, and 5 more rises and 1 fall have only the previous close out of line.

**Missing listings.** A close whose observation is missing, or has no low or no mid, or has either at
0.00, cannot be shown to be in line and is not ranked: the board prefers a missed mover to one it cannot
check. On dev this excludes nothing: all 164,907 real closes have both listings above zero. A close of
0.00 is never in line (it is under half of any low above zero).

**Alternatives measured**, with the move and activity tests held fixed, 2026-10-12 and 2026-10-13
together (ranked variants, up / down):

| no listing test | day's close only, 0.5 / 2 | both closes, 0.5 / 2 (chosen) | both, 0.5 / 1.5 | both, 0.5 / 3 | both, 0.75 / 2 | both, 0.25 / 2 | both strictly low to mid |
|---|---|---|---|---|---|---|---|
| 16 / 24 | 15 / 24 | 15 / 24 | 14 / 24 | 16 / 24 | 13 / 24 | 15 / 24 | 7 / 16 |

Once the activity test has run, the listing test removes one row on dev: `base4-3` holo on 2026-10-13
(29.03 to 30.69, at 2.05 x its mid listing of 15.00). The development days hold no odd sale inside an
actively traded series, which is the case this test is for, so its two ratios rest on where closes sit
against listings (the table above), not on outcomes. At 1.5 x mid it would also remove `xy0-26`, the
biggest rise left on 2026-10-13, at 1.52 x.

## Before and after

Eligible variants per day and direction (before: every changed close with a previous close of 2.00 or
more; the stored board keeps the first 50 of each direction):

| day | direction | before | material | and active | and day's close in line | after (both closes in line) |
|---|---|---|---|---|---|---|
| 2026-10-10 | up | 1,721 | 178 | 0 | 0 | 0 |
| 2026-10-10 | down | 1,691 | 18 | 0 | 0 | 0 |
| 2026-10-11 | up | 1,817 | 112 | 0 | 0 | 0 |
| 2026-10-11 | down | 1,677 | 28 | 0 | 0 | 0 |
| 2026-10-12 | up | 1,783 | 120 | 8 | 8 | 8 |
| 2026-10-12 | down | 1,756 | 30 | 11 | 11 | 11 |
| 2026-10-13 | up | 1,690 | 116 | 8 | 7 | 7 |
| 2026-10-13 | down | 1,754 | 42 | 13 | 13 | 13 |

2026-10-13 goes from 3,444 eligible variants to 20. Each test alone on that day would leave 158 (material),
2,020 (active) and 3,355 (in line); without the activity test the other two would leave 124.

### The ten biggest rises of 2026-10-13

Before (the stored board), with the closes of the five days and the tests each row fails:

| rank | variant | closes 10-09 to 10-13 | change | not ranked because |
|---|---|---|---|---|
| 1 | `ex13-3:reverse:-:-` | 5.00 5.00 5.00 5.00 752.00 | +14,940.00 % | no activity; previous close at 0.009 x low |
| 2 | `ex10-17:holo:-:-` | 99.00 99.00 99.99 99.99 539.91 | +439.96 % | no activity; close at 6.65 x mid |
| 3 | `ex15-10:reverse:-:-` | 215.66 215.66 215.66 215.66 999.97 | +363.68 % | no activity; close at 2.38 x mid |
| 4 | `ex7-15:reverse:-:-` | 300.00 300.00 300.00 300.00 1150.00 | +283.33 % | no activity; close at 3.31 x mid |
| 5 | `hsp-HGSS11:holo:-:cracked-ice` | 82.78 82.78 82.78 82.78 271.97 | +228.55 % | no activity; close at 5.44 x mid |
| 6 | `dp6-11:reverse:-:-` | 107.83 107.83 107.83 107.83 322.67 | +199.24 % | no activity; close at 6.29 x mid |
| 7 | `pop5-2:normal:-:-` | 128.65 127.58 127.58 127.58 378.02 | +196.30 % | no activity; close at 7.13 x mid |
| 8 | `pop5-3:normal:-:-` | 149.59 149.59 149.59 149.59 388.33 | +159.60 % | no activity; previous close at 0.48 x low |
| 9 | `ex11-111:holo:-:-` | 1010.00 1010.00 1010.00 1010.00 2339.99 | +131.68 % | no activity; both closes under half the low |
| 10 | `base6-40:reverse:-:-` | 96.48 96.48 96.48 96.48 200.00 | +107.30 % | no activity |

After (seven rises are ranked; "before" is the rank the old rule gave, stored or not):

| rank | variant | closes 10-09 to 10-13 | previous close | close | change | before |
|---|---|---|---|---|---|---|
| 1 | `xy0-26:normal:-:-` | 43.25 43.29 43.36 43.36 64.88 | 43.36 | 64.88 | +49.63 % | 23 |
| 2 | `pop1-3:holo:-:-` | 166.73 174.10 174.10 182.77 213.95 | 182.77 | 213.95 | +17.06 % | 55 |
| 3 | `swshp-SWSH144:holo:-:-` | 18.90 19.06 18.61 18.37 20.86 | 18.37 | 20.86 | +13.55 % | 74 |
| 4 | `sv3-228:holo:-:-` | 32.13 31.29 31.74 31.05 34.92 | 31.05 | 34.92 | +12.46 % | 82 |
| 5 | `pl4-26:reverse:-:-` | 7.48 7.48 7.59 11.57 12.77 | 11.57 | 12.77 | +10.37 % | 101 |
| 6 | `me55-149:holo:-:-` | 53.45 52.68 52.49 43.32 47.50 | 43.32 | 47.50 | +9.65 % | 109 |
| 7 | `basep-51:normal:-:stamp-pokemon-center-ny` | 106.22 109.14 110.29 110.29 119.87 | 110.29 | 119.87 | +8.69 % | 123 |

### The ten biggest falls of 2026-10-13

Before (the stored board):

| rank | variant | closes 10-09 to 10-13 | change | not ranked because |
|---|---|---|---|---|
| 1 | `ex3-65:reverse:-:-` | 83.00 178.66 178.66 178.66 83.00 | -53.54 % | no activity (a spike that came back); both closes under half the low |
| 2 | `pl3-51:reverse:-:-` | 3.02 3.02 3.02 3.02 1.86 | -38.41 % | no activity |
| 3 | `ex7-46:reverse:-:-` | 246.98 246.98 398.95 398.95 247.98 | -37.84 % | no activity; both closes under half the low |
| 4 | `mcd15-1:holo:-:-` | 12.40 12.40 12.40 12.40 7.73 | -37.66 % | no activity; previous close at 2.07 x mid |
| 5 | `dp5-5:reverse:-:-` | 119.99 119.99 119.99 119.99 77.78 | -35.18 % | no activity |
| 6 | `base6-47:reverse:-:-` | 200.69 200.69 200.69 200.69 145.00 | -27.75 % | no activity |
| 7 | `ex10-106:holo:-:-` | 275.26 275.26 275.26 275.26 200.52 | -27.15 % | no activity |
| 8 | `ex6-8:normal:-:-` | 28.78 39.39 39.39 39.39 28.78 | -26.94 % | no activity (a spike that came back) |
| 9 | `me2-103:holo:-:-` | 1.85 2.04 1.93 2.61 1.95 | -25.29 % | a move of 0.66 |
| 10 | `me2pt5-142:holo:-:-` | 3.78 3.85 4.69 5.77 4.38 | -24.09 % | ranked, first |

After (thirteen falls are ranked; the first ten):

| rank | variant | closes 10-09 to 10-13 | previous close | close | change | before |
|---|---|---|---|---|---|---|
| 1 | `me2pt5-142:holo:-:-` | 3.78 3.85 4.69 5.77 4.38 | 5.77 | 4.38 | -24.09 % | 10 |
| 2 | `gym2-41:normal:unlimited:-` | 20.13 20.34 20.80 21.03 17.57 | 21.03 | 17.57 | -16.45 % | 23 |
| 3 | `me55-133:holo:-:-` | 7.05 6.25 6.75 6.97 5.90 | 6.97 | 5.90 | -15.35 % | 25 |
| 4 | `me55-146:holo:-:-` | 11.73 12.90 12.19 11.66 10.52 | 11.66 | 10.52 | -9.78 % | 54 |
| 5 | `me4-118:holo:-:-` | 25.20 27.07 25.48 26.14 23.86 | 26.14 | 23.86 | -8.72 % | 64 |
| 6 | `me2pt5-238:holo:-:-` | 16.18 16.05 16.23 16.10 14.81 | 16.10 | 14.81 | -8.01 % | 74 |
| 7 | `me55c-114:holo:-:-` | 18.85 18.54 19.39 20.02 18.48 | 20.02 | 18.48 | -7.69 % | 79 |
| 8 | `me55-151:holo:-:-` | 55.04 59.03 57.36 50.88 47.48 | 50.88 | 47.48 | -6.68 % | 96 |
| 9 | `me4-121:holo:-:-` | 16.25 15.95 16.80 16.54 15.49 | 16.54 | 15.49 | -6.35 % | 100 |
| 10 | `me55-158:holo:-:-` | 64.08 62.04 62.67 59.90 56.10 | 59.90 | 56.10 | -6.34 % | 101 |

### Where the stored board of 2026-10-13 went

| | rises (50 stored) | falls (50 stored) |
|---|---|---|
| flat on every earlier day | 37 | 13 |
| reverse holos | 26 | 14 |
| fail the material move | 3 | 27 |
| fail the activity test | 48 | 22 |
| day's close out of line | 12 | 2 |
| previous close out of line | 7 | 3 |
| fail the activity test and nothing else | 30 | 17 |
| still ranked | 1 | 3 |

## Cost

`Movers::rebuild()` is still one statement per recompute window. `EXPLAIN (ANALYZE, BUFFERS)` of its
`SELECT` for 2026-10-13 on dev (32,969 real closes), three runs each with a warm cache:

| | estimated total cost | buffers | execution time |
|---|---|---|---|
| before | 164,244 | 133,919 | 159 to 162 ms |
| after | 164,648 | 134,371 | 179 ms |

The previous-close probe per real close is the cost, as before. The comparison and its cheap tests (floor,
move) run first in a materialized step, which leaves 158 rows on that day; only those pay for the
activity count (one index range read of at most five rows on `price_daily`'s primary key) and, for the 21
that pass it, the two observation lookups (primary-key probes on `price_points`). No index was added and
there is no migration. Written as one flat join, the planner started from a scan of the whole
`price_points` month (692 ms), which is why the step is materialized.

## What the rule cannot do

- **Five days are thin.** The activity line was drawn on one full window (2026-10-13) and one of four
  closes (2026-10-12): two days, 39 ranked rows. The split it rests on (the tables above) is clear, but
  it should be measured again once dev or production holds a few weeks, with the same queries.
- **The first three days of a series rank nothing**, however busy it is: a new card, a new
  `series_epoch`, or the start of collection. On 2026-10-11, 27 material moves sat in series that had
  changed on both days so far, and 17 of them held the next day; the rule does not see them.
- **A card that trades weekly is never ranked.** Its real moves are single steps days apart, which is
  exactly the shape of the single odd sale the rule is there to keep out. On dev that is most of the
  catalogue: 6,010 of the 12,523 variants of 2.00 or more did not change once in five days.
- **Activity is not volume.** The source gives no sale counts. Four distinct closes say the average moved
  on three of four steps, not how many copies sold; small jitter counts as much as a large step. `xy0-26`
  (43.25, 43.29, 43.36, 43.36, then 64.88) passes with three steps of a few cents before its +49.63 %.
- **Listings can be thin too.** A card with one or two listings has a low and a mid that say little, and
  the band then excludes or admits on that. `ex4-6` Reverse lists a single copy at 199.99 against a close
  of 20.90.
- **A real revaluation of an untraded card is left out.** If a card that had not sold for weeks sells
  three times in a week at a new level, it is ranked from its fourth distinct close, not its first.
- **The board can be short or empty.** With these thresholds dev ranks 7 rises and 13 falls on its newest
  day, not 50 and 50. A day with no ranked variant has no rows, and `GET /v1/movers` then serves the newest
  day that has some, under that day's date.
