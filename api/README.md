# PokéPrices API

The Laravel 13 API behind PokéPrices. One nginx (`../docker/nginx/pokeprices.conf`), the only
published port, sends Laravel `/v1/*`, `/up` and the reserved auth paths listed in the root README;
the single-page app in `../web` gets every other path.

## What it does in P0

- `GET /v1/ready` checks Postgres and both Redis instances (queue and cache). It answers 200 when
  every check passes, and 503 with an `application/problem+json` body listing the checks when one
  fails.
- `GET /up` is Laravel's liveness route.
- An error raised under `/v1` (unknown path, failed validation or authentication, `abort()`,
  uncaught exception) answers `application/problem+json`, and a 500 never shows the exception
  message.
- Every response Laravel sends carries an `X-Request-Id`: a valid incoming id is echoed, anything
  else is replaced by a fresh ULID.
- `php artisan pokeprices:queue-probe` dispatches a probe job and waits for the worker; the
  scheduler dispatches the same probe every minute.

There are no prices yet. When they arrive, every price shows its source and the "as of" date of
the daily close it came from.

## Working on it

Nothing PHP runs on the host. From the repository root:

```sh
make init                                                    # stack up, dependencies, api/.env once
docker compose -f compose.dev.yml exec -T -w /workspace/api api vendor/bin/pest -c phpunit.xml
make gates                                                   # every gate in gates/manifest-P0.json
```

The OpenAPI contract is `../openapi/api.json`. Gate C1 fails when it differs from a fresh
`php artisan scramble:export`, and the API tests validate their `/v1` responses against it. The
layout and the reserved paths are in the root [README](../README.md).
