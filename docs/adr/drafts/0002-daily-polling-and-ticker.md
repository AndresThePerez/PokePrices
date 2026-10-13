# ADR 2: Daily polling and what a ticker means in PokéPrices

Status: Proposed (draft for Andres; he moves it to docs/adr/ to accept it)

Date: 2026-10-13

Drafted during the PokéPrices P3 loop (proposed human gate HG-21). Nothing here is in force
until Andres moves it out of `drafts/`. If he changes a decision, the orchestrator plans a
follow-up task; the code already implements the defaults below, and each question with its
default is listed again in the last section. The day-close definitions this ADR builds on are
in the day-close draft, `0001-day-close.md`, which is also still a draft.

## Context

Every PokéPrices price is a daily close: one value per variant per day, as of that day's
TCGCSV marker (see the day-close draft). The collected data changes once a day. The web pages
show prices in several compact places: the price lines on a card, the rows of the movers board
and the strip of biggest moves on the home page. Feasibility §7 and the System Design (§9, §12)
call for TanStack Query polling instead of push, charts of daily closes, and a "ticker" that
carries the last close, the change, the as-of stamp and the source on every number.

"Ticker" tends to mean a continuous quote. Here it must not, so the word is defined once, before
the readouts multiply. The same goes for the refresh model, the ranges a chart offers and the
chart type, each of which a later phase could quietly change if it were not written down.

## Decision: polling, not push

- **Polling.** The browser asks again every 300 seconds (`refetchInterval: 300000`), only while
  the tab is visible (`refetchIntervalInBackground: false`). Data stays fresh for 300 seconds
  (`staleTime: 300000`), a return to the tab refetches only stale data
  (`refetchOnWindowFocus: true`), and a failed request is not retried within a cycle
  (`retry: false`). One query client is created per app mount.
- **HTTP caching does the rest.** The API answers history and movers with
  `Cache-Control: public, max-age=300, stale-while-revalidate=600` and a body-hash `ETag`, so a
  poll that finds nothing new is a `304`. The single-page app sets no cache headers of its own.
  A degraded answer (a stale catalogue copy) is `no-store` with no `ETag`.
- **No SSE, Reverb or websockets.** The data changes once a day; a persistent connection would
  hold a socket open to announce one event in 24 hours. **Revisit trigger:** an hourly source,
  such as the P7 JustTCG hot set.
- **Worst-case freshness.** A page that is open shows a new day after at most the 5-minute poll
  plus the API's `max-age=300`, so up to 10 minutes after the API holds it.
- **Failed queries keep polling** (see Consequences).

## Decision: what a ticker means

A *ticker* is any compact readout of one variant's price: a card page's price line, a movers row, a
home-strip row. Every ticker shows, from the API only:

1. the **last daily close**, as `Market price $<close>`;
2. the **change vs the previous close**: `▲ +$<change> (+<pct>%) vs previous close (<previous day>)`,
   `▼ -$<change> (-<pct>%) vs previous close (<previous day>)`, `No change vs previous close
   (<previous day>)` or `No previous close yet`, with the percentage left out when the API sent
   none. The window is always called "previous close" and the previous day is printed;
3. the **as-of stamp and source**: `as of <day> from TCGCSV (daily close)`, per variant, or once
   per list when every row shares one day;
4. colour **only in addition** to the arrow and the sign.

A ticker refreshes only by the polling above. It never animates, flashes, scrolls by itself
or shows an activity dot, and nothing marks what is new since a visitor's last visit. A day
without a new close looks exactly like the day before. The browser never reads the clock for
anything it shows; dates are the API's day strings. Money is the API's decimal string, and a
percentage is printed only when the API sent one.

## Decision: no 1D range

The chart offers `1W`, `1M`, `3M`, `YTD` and `1Y`, always all enabled, default `1M`. There is no
1D range, label or query: with one close a day, a one-day window holds a single point and
would imply intraday movement that does not exist. The window comes from the API, anchored on
the newest ingested day.

## Decision: a History begins banner instead of greyed ranges

When a variant's first collected close is later than the start of the selected range, a note above
the chart says `History begins <day>. PokéPrices has no daily close for this variant before that
day.` Research 14 suggested greying out ranges until enough days exist; the banner states the
actual short history instead, and no range is ever unavailable.

## Decision: a line chart of closes, not candles

The chart is a line of daily closes with point markers. With one observation a day, open, high, low
and close are the same number, so candles would be flat ticks, and the API serves closes only.
The line breaks at missing days and where the `series_epoch` changes, so it never draws across a
product-mapping change. Carried-forward days are drawn in a muted colour and flagged in the data
table. A text summary and a data table accompany every chart, and the library's required attribution
link sits under it.

## Alternatives considered

- **Server-sent events or websockets for price updates.** Rejected for now: one change a day does
  not justify holding connections open, and polling with `ETag` revalidation costs a `304` per
  five minutes per open page. Reconsider with an hourly source.
- **A 1D range.** Rejected: it implies intraday data the product does not have.
- **Greyed-out ranges until history is long enough.** Rejected: a disabled button explains nothing;
  the banner names the first day instead.
- **Candlesticks.** Rejected: flat ticks with one observation a day.
- **A "live" or "updated just now" indicator, or a relative age.** Rejected: it would need the
  browser's clock and would claim a continuity the data does not have.
- **Refetch on a faster cycle.** Rejected: the HTTP cache would serve the same answer for up to five
  minutes anyway.

## Consequences

- A price on screen is always one daily close with its own as-of day, and no page claims to be
  current beyond that day.
- A new day takes up to 10 minutes (5-minute poll plus `max-age=300`) to reach an open page, and one more
  poll can pass while a cached copy is served stale and revalidated. That is far below the daily
  cadence of the data.
- **A query whose first load failed keeps polling.** A failed request, including a `404`, does not stop
  the five-minute cycle, so a page that failed once heals itself when the API answers again (a card that
  appears in the catalogue later, a restart that ended). A failed refresh never replaces a good
  answer that is already on screen; an error shows only when there is nothing better.
- A hidden tab makes no requests, which keeps the load on the API proportional to the tabs being looked
  at.
- One definition of a ticker applies to every compact readout, so a later readout (an alert, a
  watchlist row) inherits the same wording and the same limits.
- Switching to push later changes the refresh layer only; the ticker wording and the stamps stay.

## Open for Andres

Each question has a default the code already uses; none blocks the loop.

| # | Question | Default |
|---|---|---|
| 1 | Playwright smoke suite as product (feasibility §7 names Playwright and Lighthouse as P3 exit gates; the gate machinery is parked) | Not in P3; the orchestrator's browser checks and Vitest stand in, and the smoke suite returns with the gate bundle |
| 2 | Runtime `config.json` from nginx (System Design §9) | Deferred to the Deploy prompt; the app calls same-origin `/v1`, so the file would hold one constant, and building it edits the nginx config and the web image build |
| 3 | Sparklines on the movers board | Not built: they need each mover's recent closes (a batch history read); sparklines sit beside the priced variants on the card page, from the history query already fetched |
| 4 | A stale indicator (a badge on prices older than three days, or a site line when the freshness SLO is breached) | None; each variant shows its own as-of day, and a browser-computed age would break the no-clock rule |
