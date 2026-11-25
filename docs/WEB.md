# PokéPrices web pages

What a visitor sees on the PokéPrices dev site (`http://pokeprices.localhost:8095`), how the pages refresh
and how to see a change after editing the web code. Every price on these pages is a **daily close**: one
value per card variant per day, as of that day's TCGCSV marker. Nothing here is a continuous quote. How the
prices get into the database is in [`OPERATIONS.md`](OPERATIONS.md).

## Pages

- **`/` (home).** The entry page, in this order: the heading, one sentence under it (`Each price is a
  TCGplayer market price collected once a day through TCGCSV, shown with its source and the day it is as
  of.`), the search box, the demo entry (a `Try the read-only demo` button, outlined like the page's other
  buttons), then:
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
  - **See it working**, last on the page: the read-only demo's portfolio total with its change on the day, three of
    its watched cards (each with its price and change, its source and `as of` day) and one alert sample, each part
    labelled as sample data from the demo collection and linking to `/portfolio`, `/watchlist` and `/alerts`. Signed
    in or out it is the same sample. It sits in a box that holds its height from the first frame, and it is absent
    when any of its three reads fails.

  The home page asks the API for the two movers lists and the freshness answer, and for the demo's three public reads
  (`GET /v1/demo/watchlist`, `/portfolio` and `/alerts`, no cookie and no session), and nothing more unless the browser
  already holds the `XSRF-TOKEN` cookie, when it also asks `GET /v1/me` once.
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
  - **Pages.** `Previous page` and `Next page` keep the address's `page` (page 1 stays implicit). After
    either, keyboard focus moves to the page's heading, `Search cards` (focusable for that, but not in the
    tab order), since the link that was pressed is gone while the next page loads. An address naming a page
    past the last one shows the last page: the API answers it (in `Best match` with one more catalogue
    call, since PokéSearch answers a page past its last with no rows; a price order pages its own list),
    and the page then replaces the address's `page` with the last page, leaving `q`, `sort` and every
    other parameter exactly as they were. The replace adds no history step and sends no request. A search
    with no matches keeps the page its address names. If the result changes between the API's two calls,
    so that the last page's answer names a page outside its own pages, the API answers 503 and the page
    shows its `Search failed` block; it never shows a page number past the last.
  - **Loading.** While a search loads, the page reserves one viewport of height below the search box, as
    the card page and the movers board do. A full page of results is taller than that, so the footer starts
    below the fold and the rows push nothing on screen when they arrive; a result shorter than the viewport
    pulls the footer up once. A page change reserves the same room again.
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
  - **Listing links.** In Sources, each mapped TCGplayer product's line is followed by a link,
    `Product <id> on TCGplayer`, to `https://www.tcgplayer.com/product/<id>`: the plain product address
    built from the id, with no query, tracking or affiliate part. It opens in a new tab (`rel="noopener
    noreferrer"`), and screen readers hear `(opens in a new tab)`. A variant with no mapped product, or an
    id that is not a positive whole number, shows no link.
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
- **`/sign-up`.** It opens with `Accounts are invite-only for the moment. Try the read-only demo.` and the
  `Try the read-only demo` button beside that sentence, then the form: an invite code, an email address and a
  password of at least 12 characters. Opening the page asks for nothing; the demo button asks only when pressed.
- **`/password/forgot`.** Asks for an email address and says a reset link is on its way, whether or not the address
  has an account.
- **`/password/reset`.** Where the reset link lands (`?token=` and `&email=` are removed from the address bar on
  arrival); the person chooses a new password.
- **`/account`.** Who is signed in, whether the email is verified (with a button to send the verification email
  again), two-factor on and off with a QR code and one-time recovery codes, `Download your data (JSON)`,
  `Delete my account`, a link to `Alerts and webhooks` and `Sign out`. Signed out, it offers sign-in and sign-up. As
  the demo, it says the demo is read-only, shows two-factor as off with no button, and says the demo cannot be
  deleted.
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
  sees `Verify your email address to set alerts.` instead of the control. The demo sees no control and no
  `Remove`; the row of its seeded alert says `Sample alert: at 10%`. `Remove` leaves `Removed.` with an `Undo`
  button in the row's place (see Undo below).
- **`/portfolio`.** The variants a person holds with a quantity, each a short row with the card's picture and its
  value, and a total computed by the API that names the days it rests on; that line is the list's one stamp (each
  row also shows `Close of <day>` when the days differ). A holding without a price is not valued and is named as
  not counted. The total's panel ends with `Change is each card's close vs its previous close, not a gain on your
  holding.`, which names what each row's change is measured against: it is the card's daily-close change, not a
  return on the holding. The panel reads, in order: `An estimate at daily-close market prices, not a sale value.`,
  `Total value $<total>`, the days line, the not-counted line when a holding has no price, the day's change of the
  total (`▲ +$16.93 (+1.64%) vs previous close.`; `No change vs previous close.`; with no percent,
  `▲ +$4.00 vs previous close; no percent, as the previous closes add up to $0.00.`; with no previous close at all,
  `No previous close yet, so no change to show.`), the counts (`3 cards, 1 without a price.`, or `12 cards.`),
  `The change leaves out <n> card(s) with no previous close.` when some priced card has none, then the line above.
  Every figure is the API's (see [`ACCOUNTS.md`](ACCOUNTS.md) for the rule) and rests on the one days line. Each
  row has `Quantity owned` with `Save`, which says `Saved.` once the quantity is saved (the alerts page's line,
  announced the same way), and `Remove`, which leaves `Removed.` with `Undo` (see Undo below).
- **Undo.** On `/watchlist` and `/portfolio`, `Remove` replaces the row, once the API has removed it, by
  `Removed.` and `Undo`, and keyboard focus moves to `Undo`. `Undo` puts the row back through the same routes:
  `PUT /v1/watchlist/{key}` (and `PUT /v1/alerts/{key}` with its threshold when the row had an alert), or
  `PUT /v1/portfolio/{key}` with the quantity it had; then the list is read again and focus moves to the restored
  row. The line stays until the visitor leaves the page or removes another row (one line per list), and goes by
  itself once the list read again holds the row (put back from another tab, say); when it goes while holding focus,
  focus moves to the row that takes its place, or to the page heading when there is none, never to the page body. A
  removal that fails keeps the row with its problem block and gives focus back to its `Remove`; an `Undo` whose
  row write fails says `Removed. Undo did not go through.` with the problem block and offers `Undo` again. When
  the row write succeeds and only the alert write fails, the row is back on the server without its alert, so
  the line goes, the list is read again, focus moves to the restored row, and that row shows its `Alert: off`
  with its threshold select and one status line (announced like `Saved.`, not a problem block): `Back on your
  watchlist. Its alert could not be put back; set it again.` The alert is set again on that row; the line goes
  when the visitor saves that row's alert, removes the row or leaves the page.
- **`/alerts`.** `Alerts and webhooks`, linked from `/account` (the header is unchanged). It needs a signed-in
  account: signed out it offers `Sign in` and `Create an account`; an unverified account is asked to verify its
  email address and sees its lists without a form. As the demo it says the read-only demo cannot set alerts or add
  webhooks, then `The alert, the email and the signed webhook delivery below are samples: nothing is ever sent from
  the demo.`, and shows, with no form and no button, the samples of `GET /v1/demo/samples` (see
  [`ALERTS.md`](ALERTS.md#the-demos-samples)):
  - `Sample alert`: the card and variant, and `An alert at 10% (and at least $1.00) on the demo's watchlist: …`;
  - `Sample alert email`: `Delivered by email on <day>.`, the subject and the text as the account would have
    received them;
  - `Sample signed webhook delivery`: `Delivered to https://example.test/… on <day>.`, the `webhook-id`,
    `webhook-timestamp` and `webhook-signature` headers, the exact body (its bytes are what is signed, so the
    event's own RFC 3339 times stay in it) and the sample secret under `Sample signing secret, published for the
    demo only; it belongs to no account.`;
  - its webhooks list, which holds the `Sample signed webhook`.

  With no sample message it says `No sample messages: no card on the demo watchlist moved by its alert threshold in
  the collected closes.` The demo's /alerts asks `GET /v1/me`, `GET /v1/webhooks` and `GET /v1/demo/samples`, and
  shows no market price of its own (the sample texts carry their own source and day), so it has no stale-prices
  banner. A verified account sees three sections:
  - `Where alerts go`: a choice of `Email (<address>)` or `Discord` (disabled until a Discord webhook is added), the
    checkbox `Send me a daily digest` and `Save`;
  - `Per-card alerts`: a pointer to the watchlist, where each threshold is set;
  - `Webhooks`: each webhook as `<url_hint>`, its type (`Signed webhook` or `Discord`), the date it was added and the
    status of its last message, with `Send a test message` and `Delete`; and the form `Add a webhook`. A new signed
    webhook's secret is shown once, in a box, until `I have stored the secret` is pressed; it is never shown again.

  The messages themselves, who gets them and the delivery rules are in [`ALERTS.md`](ALERTS.md).
- **`/about`.** `About`: what PokéPrices is (daily closes from TCGplayer, collected once a day, not a running
  quote), a linked thank-you to TCGCSV (`https://tcgcsv.com`) as the source of the price data, a section
  saying it is a personal portfolio project and not a business (no ads, no affiliate links, no paid
  features, no data sold; the code is published to be read and all rights are reserved), who built it
  with a link to the source code on GitHub, the affiliation disclaimer (not affiliated with or endorsed by
  Nintendo, The Pokémon Company, Game Freak, Creatures or TCGplayer; card images and names belong to their
  owners), a privacy paragraph (the cookies, what an account stores, the two third-party image hosts and
  Cloudflare) and the contact link to `https://andrestheperez.com`. It shows no price and sends no
  request. nginx serves it like every other app path (`/about` is not reserved). The privacy paragraph
  opens: "Opening and reading the site sets no cookies. Sending a sign-in, sign-up or password form, or
  pressing the demo button, sets two: a session cookie, which keeps you signed in once you are, and a
  security token that protects the site’s forms. The security token is not removed when you sign out, so a
  later visit from a browser that still holds it asks, on opening a page, whether you are signed in."

The watchlist and portfolio are filled from the card page, where each variant has `Add to watchlist` and
`Add to portfolio` buttons. Once the tab knows who is signed in, the card page reads both lists (one
`GET /v1/watchlist` and one `GET /v1/portfolio` for the whole page, never one per variant) and each variant on
them says so for as long as it is true, after a reload too: `On your watchlist.` with `See your watchlist` and
`Remove`, and `In your portfolio: <n>.` with `See your portfolio`, `Edit` (the quantity form) and `Remove`, both
at once when both are true; the `Add` button of a list the variant is on gives way to its line and comes back,
focused, when the variant is removed. A tab known to be signed in to the read-only demo shows the two lines and
no `Add`, `Remove`, `Edit` or quantity form. A public page learns the session after a reload only when the
browser already holds the app's security token cookie (`XSRF-TOKEN`, set together with the `pokeprices-session`
cookie by any account form the app sends, a sign-in, sign-up or password form, with or without the right
credentials, or by the demo): home, `/search`, a card page and `/movers` then ask `GET /v1/me` once, when
nothing is known yet; without that cookie they ask nothing about the session, so opening and reading the site
sets no cookies. The cookie stays after a sign-out, so a visitor who signed out, or sent any account form, still
holds it: a later visit (a reload or a new tab) sends `GET /v1/me` once on opening and may log one `401`. The sign-in and account pages follow the same rule: with no cookie they are signed out at once and ask
nothing, with it they ask on opening. With that cookie, until `GET /v1/me` (and, signed in, both lists) has answered, a card page shows no
account control on any variant and holds the space of one `Add` row, empty. When an `Add` row arrives there,
nothing below moves; when the list lines or, for the read-only demo, no control replace it, the content below
can shift once. A 401, a failed session read or a failed list read then shows what it would have shown (the
`Add` buttons, calmly, with no page-wide error). A card page opened directly by the demo therefore never shows
an `Add` button: the held space, then its lines and no write control. Only a tab with no such cookie and nothing
cached (which has not asked who is signed in) shows the `Add` buttons at once, and a press by the demo answers
`The demo account is read-only.` Any write the demo can still attempt anywhere (the sign-up or
password forms, for instance) shows that same sentence, with no request id. A signed-in session's portfolio
estimate also shows in the header, under the bar, on home, `/search`, a card page, `/movers` and `/watchlist`
(the pages where the stale-prices banner can show, less `/portfolio`, which states the total itself): one link
to `/portfolio`, `Portfolio estimate $<total>` with the short change (`▲ +1.64%`), whose accessible name goes on
with the full sentence (an estimate at daily-close market prices, not a sale value; the change vs previous
close; the cards; TCGplayer market prices and the days line). It comes from `GET /v1/me` and adopts the
portfolio list's figures whenever that list is read again, so it sends no request of its own; nothing shows
signed out, while the session loads, when its request fails, or for a portfolio with no priced card. Each row's card name links back to the card page on that row's variant
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

The card page shows the card's picture in a column 240 px wide (104 px below 640 px), inside a mat: the image
itself is 222 px wide there (94 px below 640 px). The catalogue serves two sizes (the small one 240 or 245 px
wide, the large one 600 or 734 px), and the page offers both with `srcset` (small first, described as 240 and
600 px wide) and `sizes="(min-width: 640px) 222px, 94px"`, so the browser fetches the small one unless the slot
times the screen's pixel density needs more (a 2x screen from 640 px up, a 3x phone). A card with only one of
the two, or the same address twice, has that one as its only source. The image carries a fixed `width` and
`height` (245 × 342) in a 63:88 box, so the picture takes its room before it loads, and it is the page's one
high-priority image. When the catalogue gives no picture, or it fails to load, the same card back as the rows'
takes its place, at the column's size.

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
  `https://www.tradingview.com/`, as the library's licence requires. It is an ordinary link outside the
  chart's own box; the library's logo link, which it would draw inside that box (a link inside an image), is
  turned off.
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
