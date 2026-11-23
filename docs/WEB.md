# PokéPrices web pages

What a visitor sees on the PokéPrices dev site (`http://pokeprices.localhost:8095`), how the pages refresh
and how to see a change after editing the web code. Every price on these pages is a **daily close**: one
value per card variant per day, as of that day's TCGCSV marker. Nothing here is a continuous quote. How the
prices get into the database is in [`OPERATIONS.md`](OPERATIONS.md).

## Pages

- **`/` (home).** The entry page, in this order: the heading, one sentence under it (`Each price is a
  TCGplayer market price collected once a day through TCGCSV, shown with its source and the day it is as
  of.`), the search box, the demo entry, then:
  - **Example searches**: `lugia neo genesis`, `umbreon vmax`, `umbreon ex` and `mewtwo`, each a link to
    `/search?q=<words>` in the default order. Each was checked to land its card on the first page of
    results (Lugia `neo1-9`, Umbreon VMAX `swsh7-215`, Umbreon ex `sv8pt5-161`, Mewtwo `base1-10`).
  - **Well-known cards**: six fixed tiles (Charizard `base1-4`, Blastoise `base1-2`, Pikachu `base1-58`,
    Lugia `neo1-9`, Charizard ex `sv3pt5-199`, Umbreon ex `sv8pt5-161`), each the card's picture, its name
    and its set, linking to its card page. The tiles are written into the page: they send no request, and
    the pictures load lazily from the catalogue's image host in a reserved box, with the plain card back
    when one fails (see Card pictures). A tile shows no price; the card page does, with its source and date.
  - The "Biggest moves vs previous close" strip. The strip is static (no scrolling, no animation), each
    row is a short row (see the next section), and it links to `/movers`.

  The home page asks the API for the two movers lists and the freshness answer, nothing more.
- **`/search`.** Card search over the PokéSearch catalogue. Results are catalogue entries, each with the card's
  picture (see Card pictures) and its headline price: the latest close of **the first priced variant in card
  order** (the card page's variants, by variant key; not the variant the card page opens on), named on the row.
  Each row is a short row like the lists' (see the next section): the price and the short change, with the
  list's one `as of <day> from TCGCSV (daily close)` line above it, or `Closes from <first> to <last> from
  TCGCSV (daily close)` with each row's own `Close of <day>` when the rows' days differ; the row's accessible
  name holds the full `Market price $<amount>`, change and `as of` lines. A card with no priced variant reads
  `No price in the collected snapshots` (search shows no listing, even for a card whose variants have one).
  Opening a result goes to its card page, opened on the variant the row priced.
  - **Order.** `Best match` (PokéSearch's relevance, the default), `Price: high to low` and `Price: low to
    high`, kept in the address (`sort=price_desc` or `sort=price_asc`) so a reload and Back keep it; choosing
    an order goes back to page 1. PokéSearch pages its results and cannot sort by price, so a price order
    covers **the top 100 matches** (its largest page) only, and the page says so: `Ordered by price among the
    top 100 of <N> matches; cards with no price come last.`, or `among all <N> matches` when there are no
    more than 100. Cards without a price come last and equal prices keep PokéSearch's order.
  - **Word cap.** A search has at most **32 words** (PokéSearch's limit; any run of spaces, tabs or other
    whitespace separates two words). A longer one shows `A search can have at most 32 words.` under the
    search box, and the API answers it with a 422 on `q`.
- **`/card/{id}`.** One card, for example `/card/base1-4`.
  - The catalogue details come from PokéSearch through the API. When PokéSearch is unavailable the page
    says `Catalog data may be out of date: PokéSearch is unavailable`.
  - **Prices:** one ticker per priced variant, with a small sparkline beside it.
  - **Lowest listing.** When the snapshot a close came from lists a lowest price, the headline and that
    variant's row show `Lowest listing $<amount>` beside the market price. When the API flags the close as
    a thin market (a market price under half that lowest listing), a note follows, under the close's own
    `as of` line: `Thin market: the market price is from few or old sales; the lowest listing in the same
    snapshot is $<amount>.` Exactly half is not flagged. A variant with no close whose newest snapshot lists
    a lowest price reads `No recent sales; lowest listing $<amount>` with
    `Listing as of <day> from TCGCSV (daily snapshot)` under it, in place of
    `No price in the collected snapshots`; a variant with neither keeps that line. The watchlist and the
    portfolio rows do not carry the listing.
  - **A card with listings and no close.** When no variant of the card has a close but at least one has a
    listing, the page reads as it does beside a priced variant: each variant with a listing reads
    `No recent sales; lowest listing $<amount>` with its own `Listing as of <day> from TCGCSV (daily
    snapshot)` line, a variant with neither reads `No price in the collected snapshots`, and the headline
    shows the variant the address names (else the first one with a listing) with that same line and no
    price. The chart says `No daily close is held for this card yet.` and draws nothing.
  - **Price history:** a chart of daily closes per variant (see Charts).
  - A card with neither a close nor a listing on any variant shows `No prices yet`, makes no history
    request and shows no history.
  - **Which variant opens.** The headline price and the chart show one variant. `?variant=<variant key>` names
    it, the key percent-encoded as one query value, for example
    `/card/ex13-3?variant=ex13-3%3Areverse%3A-%3A-`. The rows of the movers strip, the movers board, the
    watchlist and the portfolio link this way, so a row opens on the variant it shows. A key of a variant the
    card has opens that variant whether or not it has a close: one without a close shows its
    `No recent sales; lowest listing $<amount>` line (or `No price in the collected snapshots`) in the
    headline, is pressed in the chart's `Variant` group after the priced ones, and the chart shows its
    no-close line, never another variant's series. Without the parameter, or when it is empty or names no
    variant of the card (an unknown, malformed or another card's key), the page opens its default variant,
    the first priced one with history (on a card with no close, the first one with a listing), without an
    error. So does a variant that loses its close on a poll while the page shows it.
  - **The address follows the choice.** Choosing another variant in the chart's `Variant` group replaces the
    address with that variant's `?variant=` (it adds no history step), so Back leaves the card page, Forward
    and a reload reopen the variant last chosen, and a copied address opens what was on screen.
- **`/movers`.** The movers board, titled "Biggest moves". It shows one list at a time, chosen with the
  **Rises** and **Falls** buttons (`/movers` is Rises; `/movers?direction=down` is Falls). Each row is a
  short row: the rank, the card's picture, the card name (or `Card <id>` while PokéSearch is unavailable),
  the set line, and the price with the short change. One
  `as of <day> from TCGCSV (daily close)` line stamps the list, and a scope line states the ranking rule:
  `Ranked by change vs previous close among variants whose previous close was at least $<amount>, that
  moved at least 5% and at least $1.00, had four different closes among their last five, and whose two
  closes each sat between half the lowest listing and 1.5 times the mid listing of their own day.` The
  amount is the answer's `min_previous_close`; the other figures are the ranking's fixed thresholds (see
  [`OPERATIONS.md`](OPERATIONS.md), which also explains why a thin-market close is never ranked). The home
  strip carries the same line. Before any day is ingested the board says `No movers yet`; a day with no ranked rows in the shown
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
- **`/watchlist`.** The variants a person watches, each a short row with the card's picture, the card name from
  PokéSearch (`Card <id>` while it is unavailable) and a `Remove` button. One line above the list stamps it:
  `as of <day> from TCGCSV (daily close)`, or `Closes from <first day> to <last day> from TCGCSV (daily close)`
  when the rows' closes are of different days, and then each row also shows `Close of <day>`. Right under the
  stamp, `Change vs previous close.` names what each row's change is measured against, for every session. For a
  signed-in, verified account each row also shows `Alert: at <n>%` or `Alert: off`, a select `Alert threshold`
  (`Off`, `5%`, `10%`, `20%`, `50%`, plus the stored value when it is another number) and a `Save alert` button; `Off`
  removes the threshold. Below the list a line explains that an alert is sent when a card's daily close moves by at
  least its threshold and at least $1.00 against the previous close, with a link to `/alerts`. An unverified account
  sees `Verify your email address to set alerts.` instead of the control, and the demo sees no control.
- **`/portfolio`.** The variants a person holds with a quantity, each a short row with the card's picture and its
  value, and a total computed by the API that names the days it rests on; that line is the list's one stamp (each
  row also shows `Close of <day>` when the days differ). A holding without a price is not valued and is named as
  not counted. The total's panel ends with `Change is each card's close vs its previous close, not a gain on your
  holding.`, which names what each row's change is measured against: it is the card's daily-close change, not a
  return on the holding.
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
- **`/about`.** `About`: what PokéPrices is (daily closes from TCGplayer, collected once a day, not a running
  quote), a linked thank-you to TCGCSV (`https://tcgcsv.com`) as the source of the price data, who built it
  with a link to the source code on GitHub, the affiliation disclaimer (not affiliated with or endorsed by
  Nintendo, The Pokémon Company, Game Freak, Creatures or TCGplayer; card images and names belong to their
  owners), a privacy paragraph (the cookies, what an account stores, the third-party image host and
  Cloudflare) and the contact link to `https://andrestheperez.com`. It shows no price and sends no
  request. nginx serves it like every other app path (`/about` is not reserved). The privacy paragraph
  opens: "Browsing prices sets no cookies. Opening a sign-in or account page (sign-in, account, watchlist,
  portfolio, alerts), signing in or trying the demo sets two: a session cookie, which keeps you signed in
  once you are, and a security token that protects the site’s forms."

The watchlist and portfolio are filled from the card page, where each priced variant has `Add to watchlist` and
`Add to portfolio` buttons. Each row's card name links back to the card page on that row's variant
(`/card/<id>?variant=<variant key>`, see the card page above). The account pages, the sessions behind them and the rules for the demo are described in
[`ACCOUNTS.md`](ACCOUNTS.md).

Every page also carries the header with links and the display settings (see Display settings).

**Footer.** On every page, under the display settings, the footer links `About` (`/about`), `Source` (the
project's repository, `https://github.com/AndresThePerez/PokePrices`), `Built by Andres Perez` and `Contact`
(both `https://andrestheperez.com`). There is no email address anywhere on the site. On `/account`, which
carries its own Display panel, the footer holds the links only, without the settings.

**Link previews.** `index.html` carries an `og:image` (`/og-image.png`, a 1200 × 630 PNG drawn from
`og-image.svg`, with its width, height and alt text), a `twitter:card` of `summary_large_image` and a
canonical link, each on the site's public address. Every address shares the same preview: there are no
per-card previews. The app points the canonical link at the page on screen (its path, without the query),
so a card page is not declared a copy of the home page.

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
   page, or once per list on the movers board, the home strip, the watchlist and the portfolio (a list whose
   closes are of several days says `Closes from <first day> to <last day> from TCGCSV (daily close)`).
4. **Colour only in addition.** Up and down are always carried by the arrow and the sign. Colour repeats
   them (see Display settings), so the meaning survives for readers who cannot tell the colours apart.

A list stamped once uses a **short row**, so a phone shows more of it: the price as `$<close>` and the change
as the arrow with the signed percentage (`▲ +<pct>%`, `▼ -<pct>%`; the signed amount when the API sent no
percentage; `No change`; `No previous close yet`). Each such list says once, in visible text, that the change is
against the previous close: the movers board and the home strip in their scope line (`Ranked by change vs
previous close …`), the watchlist under its stamp and the portfolio at the end of its total's panel. The full ticker lines above, `Market price $<close>`, the
change line with its previous day and the row's own `as of` line, stay in each row's accessible name, so a
screen reader reads them in full; the card page always prints them in full.

## Card pictures

Search results and the rows of the movers board, the home strip, the watchlist and the portfolio show the
card's small picture, 48 px wide, loaded lazily from the catalogue's own image address (the API passes the
address PokéSearch gives; PokéPrices stores and resizes no image). When the catalogue gives no picture, or the
picture fails to load, the row shows a plain card back with the card's first letter in its place.

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
- **Stale-prices banner.** Every page that shows a price (home, `/search`, a card page, `/movers`,
  `/watchlist` and `/portfolio`) asks `GET /v1/prices/freshness` once, polled like the page's own data. When the answer is out
  of its freshness SLO (`within_slo: false`), one line appears under the header, outside the page's main
  region: `Prices are out of date: the newest collected prices are as of <day>, <age_hours> hours old.`
  (the day and the age with one decimal are the API's; the browser reads no clock). Nothing appears while
  the answer is within the SLO, while it loads, when the request fails or is not the documented shape, or
  before any day was ingested. The pages that show no price (sign-in, sign-up, the password
  pages, `/account`, `/account/verify-email`, `/alerts`, `/about` and the not-found page) neither ask for it nor show the banner. The dev
  stack, whose closes end on 2026-10-13, shows it on every page that shows a price.

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
