# PokéPrices

PokéPrices is a daily-close Pokémon card price tracker: search a card, see what it closed at, how
that compares with the previous close and how its price has moved. Prices are collected once a day;
each one is shown with its source and the day it is as of, and none is an intraday quote.

- **The site:** <https://pokeprices.andrestheperez.com>
- **The demo:** open the [home page](https://pokeprices.andrestheperez.com/) and press
  `Try the read-only demo`. That signs the browser in to a shared account with a watchlist, a
  portfolio and a sample alert, in which nothing can be changed. No address opens the demo by itself.

![The PokéPrices home page: the card search box, the "Try the read-only demo" button, four example searches, six well-known cards as picture tiles and the start of the "Biggest moves vs previous close" strip](docs/media/home.png)

![The card page of Lugia from Neo Genesis: the card's picture, its market price with the change vs the previous close, the lowest listing, the line "as of 2026-10-13 from TCGCSV (daily close)" and the price history chart](docs/media/card-page.png)

## What is interesting

- **Every price says where it is from and when.** A price line ends `as of <day> from TCGCSV (daily
  close)`, and a change is always against the previous close, whose day is printed beside it. The rules
  are in [`docs/WEB.md`](docs/WEB.md#what-every-price-shows).
- **Marketplace products are mapped to catalogue cards, and the map is audited.** The price feed lists
  marketplace products; a product map decides which card variant each price belongs to. Five audit
  checks (`pokeprices:map:audit`: `names`, `constraints`, `coverage`, `ambiguity`, `priced`) and a
  golden set judge a new map before history is replayed onto it: see
  [Map rebuild then replay](docs/OPERATIONS.md#map-rebuild-then-replay).
- **The movers board ranks only real market moves.** A variant is ranked only when it moved at least 5%
  and at least $1.00 from a previous close of at least $2.00, when four of its last five closes differ,
  and when both closes lie between half the low listing and one and a half times the mid listing. The
  thresholds and the measurements behind them are in
  [`docs/measurements/movers-eligibility.md`](docs/measurements/movers-eligibility.md).
- **Alerts go out as signed webhooks, and the read-only demo shows one.** Webhooks follow the Standard
  Webhooks signing scheme (HMAC-SHA256). The demo's Alerts page shows a sample delivery with its
  headers, its exact body and a published sample secret, so the signature can be checked by hand;
  nothing is ever sent from the demo. See [`docs/ALERTS.md`](docs/ALERTS.md#the-demos-samples).
- **The API contract, the web app's types and the code cannot drift apart unnoticed.**
  [`openapi/api.json`](openapi/api.json) is exported from the API code and
  [`web/src/api/schema.d.ts`](web/src/api/schema.d.ts) is generated from it; `make test` fails when
  either differs from a fresh copy. `make gates` runs every gate of
  [`gates/manifest-P0.md`](gates/manifest-P0.md) into a scorecard.

Built by [Andres Perez](https://andrestheperez.com).

## In this repository

PokéPrices shows daily-close Pokémon card prices on top of the PokéSearch card catalogue. Every
price shows its source and the "as of" date of the close it came from. There are no intraday
quotes: a price changes once a day, after that day's close is collected.

## Layout

- `api/`: the Laravel 13 API, served under `/v1`.
- `web/`: the single-page app (React 19 + Vite + TypeScript + Tailwind v4).
- `compose.dev.yml`: the whole development runtime.
- `gates/`: the phase manifest and its freeze records.
- `scripts/`: the scorecard, the verdict and the single-gate scripts.

## Development

```sh
make init                                  # bring up the core services, install dependencies, create api/.env once
make test                                  # unit suites: Pest, Vitest, tsc, eslint; then openapi/api.json and web/src/api/schema.d.ts must equal a fresh export and a fresh generation; CI runs exactly this on every non-draft PR
make gates                                 # run every gate in gates/manifest-P0.json (loop step, not CI)
make verdict FINDINGS=<path/to/findings.json>   # phase verdict (loop step, not CI)
make down                                  # stop the stack (volumes are kept)
```

Every PHP and Node tool runs inside the compose services
(`docker compose -f compose.dev.yml exec -T ...`); nothing is installed on the host. The dev site
is `http://pokeprices.localhost:8095`. How prices are ingested, checked, replayed and repaired is in
[`docs/OPERATIONS.md`](docs/OPERATIONS.md). What each web page shows, how the pages refresh and how to
see a change on the dev site is in [`docs/WEB.md`](docs/WEB.md). How accounts, sessions, the demo, the watchlist and
portfolio, data export and deletion work is in [`docs/ACCOUNTS.md`](docs/ACCOUNTS.md). How alerts, signed webhooks and
the daily digest work is in [`docs/ALERTS.md`](docs/ALERTS.md).

## Reserved paths

Laravel owns `/v1/*`, `/up`, `/sanctum/*`, `/login`, `/logout`, `/register`, `/forgot-password`,
`/reset-password`, `/email/*`, `/user/*` and `/two-factor-challenge`. The SPA owns every other
path, including `/about` and its account pages: `/sign-in`, `/sign-up`, `/password/forgot`,
`/password/reset`, `/account`, `/account/verify-email`, `/watchlist`, `/portfolio` and `/alerts`.
