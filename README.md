# PokéPrices

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
make gates                                 # run every gate in gates/manifest-P0.json; CI runs exactly this
make verdict FINDINGS=<path/to/findings.json>   # phase verdict (loop step, not CI)
make down                                  # stop the stack (volumes are kept)
```

Every PHP and Node tool runs inside the compose services
(`docker compose -f compose.dev.yml exec -T ...`); nothing is installed on the host. The dev site
is `http://pokeprices.localhost:8095`. How prices are ingested, checked, replayed and repaired is in
[`docs/OPERATIONS.md`](docs/OPERATIONS.md). What each web page shows, how the pages refresh and how to
see a change on the dev site is in [`docs/WEB.md`](docs/WEB.md).

## Reserved paths

Laravel owns `/v1/*`, `/up`, `/sanctum/*`, `/login`, `/logout`, `/register`, `/forgot-password`,
`/reset-password`, `/email/*`, `/user/*` and `/two-factor-challenge`. The SPA owns every other
path, and its auth pages live under `/account/*`.
