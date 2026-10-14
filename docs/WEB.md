# PokéPrices web pages

What a visitor sees on the PokéPrices dev site (`http://pokeprices.localhost:8095`), how the pages refresh
and how to see a change after editing the web code. Every price on these pages is a **daily close**: one
value per card variant per day, as of that day's TCGCSV marker. Nothing here is a continuous quote. How the
prices get into the database is in [`OPERATIONS.md`](OPERATIONS.md).

## Pages

- **`/` (home).** The entry page: a one-line description and the "Biggest moves vs previous close" strip.
  The strip is static (no scrolling, no animation), each row is a ticker (see the next section), and it
  links to `/movers`.
- **`/search`.** Card search over the PokéSearch catalogue. Results are catalogue entries; the page shows no
  price. Opening a result goes to its card page.
- **`/card/{id}`.** One card, for example `/card/base1-4`.
  - The catalogue details come from PokéSearch through the API. When PokéSearch is unavailable the page
    says `Catalog data may be out of date: PokéSearch is unavailable`.
  - **Prices:** one ticker per priced variant, with a small sparkline beside it.
  - **Price history:** a chart of daily closes per variant (see Charts).
  - A card with no priced variant makes no history request and shows no history.
- **`/movers`.** The movers board, titled "Biggest moves". It shows one list at a time, chosen with the
  **Rises** and **Falls** buttons (`/movers` is Rises; `/movers?direction=down` is Falls). Each row names
  the card (or `Card <id>` while PokéSearch is unavailable) and prints the ticker change line. One
  `as of <day> from TCGCSV (daily close)` line stamps the list, and a scope line states the ranking rule:
  `Ranked by change vs previous close among variants whose previous close was at least $<amount>.`
  Before any day is ingested the board says `No movers yet`; a day with no ranked rows in the shown
  direction says `No rises ranked for <day>` or `No falls ranked for <day>`.
- **`/sign-in`.** Email and password. When two-factor is on, a second step asks for an authentication code (or a
  recovery code). It also offers `Forgot your password?`, `Create an account` and `Try the read-only demo`. A
  `?next=` address is followed after sign-in only when it is a page of this site. The sixth wrong sign-in in a minute is refused.
- **`/sign-up`.** Accounts are invite-only: an invite code, an email address and a password of at least 12
  characters.
- **`/password/forgot`.** Asks for an email address and says a reset link is on its way, whether or not the address
  has an account.
- **`/password/reset`.** Where the reset link lands (`?token=` and `&email=` are removed from the address bar on
  arrival); the person chooses a new password.
- **`/account`.** Who is signed in, whether the email is verified (with a button to send the verification email
  again), two-factor on and off with a QR code and one-time recovery codes, `Download your data (JSON)`,
  `Delete my account`, a link to `Alerts and webhooks` and `Sign out`. Signed out, it offers sign-in and sign-up. As
  the demo, it says the demo is read-only.
- **`/account/verify-email`.** Where the verification link in the email lands. It needs a signed-in person.
- **`/watchlist`.** The variants a person watches, each row a ticker with its own `as of <day> from TCGCSV (daily
  close)` line, a `Remove` button, and the card name from PokéSearch (`Card <id>` while it is unavailable). For a
  signed-in, verified account each row also shows `Alert: at <n>%` or `Alert: off`, a select `Alert threshold`
  (`Off`, `5%`, `10%`, `20%`, `50%`, plus the stored value when it is another number) and a `Save alert` button; `Off`
  removes the threshold. Below the list a line explains that an alert is sent when a card's daily close moves by at
  least its threshold and at least $1.00 against the previous close, with a link to `/alerts`. An unverified account
  sees `Verify your email address to set alerts.` instead of the control, and the demo sees no control.
- **`/portfolio`.** The variants a person holds with a quantity, each with its value and its own `as of` line, and a
  total computed by the API that names the days it rests on. A holding without a price is not valued and is named
  as not counted.
- **`/alerts`.** `Alerts and webhooks`, linked from `/account` (the header is unchanged). It needs a signed-in
  account: signed out it offers `Sign in` and `Create an account`; as the demo it says the read-only demo cannot set
  alerts or add webhooks and shows no form; an unverified account is asked to verify its email address and sees its
  lists without a form. A verified account sees three sections:
  - `Where alerts go`: a choice of `Email (<address>)` or `Discord` (disabled until a Discord webhook is added), the
    checkbox `Send me a daily digest` and `Save`;
  - `Per-card alerts`: a pointer to the watchlist, where each threshold is set;
  - `Webhooks`: each webhook as `<url_hint>`, its type (`Signed webhook` or `Discord`), the date it was added and the
    status of its last message, with `Send a test message` and `Delete`; and the form `Add a webhook`. A new signed
    webhook's secret is shown once, in a box, until `I have stored the secret` is pressed; it is never shown again.

  The messages themselves, who gets them and the delivery rules are in [`ALERTS.md`](ALERTS.md).

The watchlist and portfolio are filled from the card page, where each priced variant has `Add to watchlist` and
`Add to portfolio` buttons. The account pages, the sessions behind them and the rules for the demo are described in
[`ACCOUNTS.md`](ACCOUNTS.md).

Every page also carries the header with links and the display settings (see Display settings).

## What every price shows

A **ticker** is any compact readout of one variant's price: the card page's price lines, a movers row, a
home-strip row. Every ticker shows, from the API only:

1. **The last daily close**, as `Market price $<close>`.
2. **The change vs the previous close**, in one of these forms:
   - `▲ +$<change> (+<pct>%) vs previous close (<previous day>)`
   - `▼ -$<change> (-<pct>%) vs previous close (<previous day>)`
   - `No change vs previous close (<previous day>)`
   - `No previous close yet`

   The percentage is left out when the API did not send one; it is never computed in the browser. The
   window is always called "previous close": the previous day can be more than one day back, and the line
   prints it. It is never called 1D, 24h or yesterday, and there is no 1D range anywhere.
3. **The as-of stamp and the source:** `as of <day> from TCGCSV (daily close)`, once per variant on a card
   page, or once per list on the movers board and home strip, where every row shares one day.
4. **Colour only in addition.** Up and down are always carried by the arrow and the sign. Colour repeats
   them (see Display settings), so the meaning survives for readers who cannot tell the colours apart.

Money is the API's decimal string with thousands separators (`1234.50` shows as `$1,234.50`), never
re-rounded. The browser reads no clock: every date is a `YYYY-MM-DD` day string from the API. A ticker never
animates, flashes or shows an activity dot, and nothing marks what is new since a previous visit. A day with
no new close looks exactly like the day before.

## Charts

- **Ranges.** `1W`, `1M`, `3M`, `YTD` and `1Y`, in that order, default `1M` on every visit. All five are
  always enabled. The window comes from the API (`meta.from` to `meta.to`, anchored on the newest ingested
  day); the browser never computes it. The selected range is not kept in the address.
- **A line of closes, not candles.** With one observation a day, open, high, low and close are the same
  number, so candles would be flat ticks, and the API serves closes only. The line has point markers so
  sparse history stays visible; a one-point segment shows as a marker.
- **Segments.** The line breaks where a day is missing and where the variant's `series_epoch` changes (the
  TCGplayer product mapping changed, so earlier closes are a different series). It is never drawn across
  either.
- **Filled days.** When no new close was collected, the API repeats the last real close and flags it
  (`is_filled`, with the real day it repeats). The chart draws those points in a muted colour, the
  sparkline draws them dashed, and the data table says `Carried forward from <day>`. Filled days never count
  as a close, and the text summary says how many days repeat an earlier close.
- **History begins.** When a variant's first collected close is later than the start of the selected range,
  a note above the chart says `History begins <day>. PokéPrices has no daily close for this variant before
  that day.` The ranges are not greyed out: the note states the short history instead.
- **Variants.** When two or more variants have history, a `Variant` group of buttons chooses which one to
  draw. A card with no held close says `No daily close is held for this card yet.`
- **Summary and table.** A text summary, hidden visually but read by screen readers, gives the number of
  closes, the first and last day, the last close, and the high and low (statistics use the real points of
  the newest epoch only). A `Show data table` button opens the same data as a table with the columns `Day`,
  `Close` and `Note`. The table is closed by default.
- **Attribution.** Under every chart, the link `Chart by TradingView Lightweight Charts` points to
  `https://www.tradingview.com/`, as the library's licence requires.
- **If drawing fails** the page stays up and says `The chart could not be drawn. Show the data table to read
  every close.`

## Refresh

- **Polling, five minutes, only while the tab is visible.** Each page's data is re-requested every 300
  seconds. A hidden tab does not poll. Coming back to a tab refetches only data older than five minutes.
  There is no websocket, no server-sent event stream and no push: the data changes once a day.
- **A failed poll keeps what is on screen.** The last good answer stays; an error block appears only when
  there is no good answer to show. A query whose first load failed keeps polling at the same pace, so a page
  that failed once heals itself when the API answers again.
- **HTTP caching.** Price answers carry `Cache-Control: public, max-age=300, stale-while-revalidate=600`
  and a body-hash `ETag`, so the browser revalidates with `If-None-Match` and an unchanged answer costs a
  `304`. The single-page app sets no cache headers of its own. An answer flagged `meta.degraded` (a stale
  catalogue copy) is sent `no-store`, without an `ETag`, and is never answered `304`.
- **How long a new day takes to appear.** Once the API holds a new day, a page that is open shows it after at
  most the 5-minute poll plus the API's `max-age=300`: a poll can land just before the change and be served
  from the browser cache for up to five more minutes, so allow up to 10 minutes. While the cached copy is
  served stale and revalidated in the background (`stale-while-revalidate=600`), one extra poll can pass.
- **When the day itself arrives.** The collector's marker lands around 20:05 UTC and the API ingests it at
  the next hourly `:40` scan (see `OPERATIONS.md`). Before that, every page keeps showing the previous day,
  stamped with its own `as of` day.

## Display settings

The header has two native selects on every page:

- **Theme:** `System`, `Light`, `Dark`. `System` follows the operating system's preference and follows it
  as it changes. Stored under the `localStorage` key `pokeprices:theme` (default `system`).
- **Up/down colours:** `Green and red`, `Blue and orange`. The blue and orange pair is the
  colour-blind-safe choice. Stored under the `localStorage` key `pokeprices:palette` (default `green-red`).

Both are applied before the first paint, so a page never flashes the wrong theme. An unknown stored value,
or a browser that blocks `localStorage`, falls back to the defaults. Display settings stay in this browser's `localStorage`; accounts do not store them.

The same sizing rules hold on every page: tap targets of at least 44 px, and no horizontal scroll at 390 px
width.

## Seeing a change on the dev site

The `web` service serves a baked image, so edited web code shows on the dev site only after the image is
rebuilt. This recreates `web` only; the API is untouched.

```sh
docker compose -f compose.dev.yml up -d --wait --build web
curl -sf -o /dev/null http://pokeprices.localhost:8095/
curl -s http://pokeprices.localhost:8095/ | grep -o 'assets/index-[^"]*\.js'
```

The first command rebuilds and waits until `web` is healthy. The second proves the site answers. The third
prints the entry asset name; a new name after a rebuild is the proof the browser is served the new build (a
hard reload fetches it). If the dependency layer cache was pruned, the build downloads packages from the npm
registry once.

If the API container was recreated, restart the web service so nginx finds it again:

```sh
docker compose -f compose.dev.yml restart web
```
