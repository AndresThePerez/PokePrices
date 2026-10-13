# PokéPrices accounts

How accounts work on the PokéPrices dev site (`http://pokeprices.localhost:8095`): sessions, the endpoints behind
sign-in, the invite code, the read-only demo, the watchlist and portfolio, the data a person can download or delete,
the rate limits, and where development mail goes. This describes the code as committed on `p4-work`; the decisions
behind it are in the accepted `adr/0003-accounts-and-sessions.md`, and the security evidence is in
[`security/asvs-l1.md`](security/asvs-l1.md). Accounts use an email address and a password only: there is no social
login, no API token and no public signup.

Every price a signed-in page shows is a daily close with its own `as of <day> from TCGCSV (daily close)` line, as
described in [`WEB.md`](WEB.md).

## Sessions

- **One hostname.** The single-page app and the API share `http://pokeprices.localhost:8095`, so there is no
  cross-origin login, no CORS configuration and no `SESSION_DOMAIN`. nginx sends `/v1/*`, `/sanctum/*` and
  Laravel's root paths for sign-in (`/login`, `/logout`, `/register`, `/forgot-password`, `/reset-password`,
  `/email/*`, `/user/*`, `/two-factor-challenge`) to Laravel; the app owns every other path. The account pages
  therefore sit at `/sign-in`, `/sign-up`, `/password/forgot`, `/password/reset`, `/account` and
  `/account/verify-email`, none of which nginx sends to Laravel.
- **Sanctum in cookie mode.** A signed-in browser holds one session cookie, `pokeprices-session` (`HttpOnly`,
  `SameSite=Lax`, no `Domain`), stored in the Postgres `sessions` table on the dev stack (`SESSION_DRIVER`), with
  Laravel's default lifetime of 120 minutes (`SESSION_LIFETIME`). The cookie has no `Secure` flag yet, because the
  dev site is plain `http`; that waits for the Deploy prompt. There are no API tokens: an `Authorization: Bearer`
  header is never read.
- **CSRF cookie.** Before its first write the app calls `GET /sanctum/csrf-cookie`, which sets an `XSRF-TOKEN`
  cookie. The app sends that cookie's decoded value as `X-XSRF-TOKEN` on every non-GET request. A missing or wrong
  token answers `419` with the problem type `csrf-token-mismatch`; the app refreshes the cookie once and retries
  once.
- **Stateful only on account routes.** Sanctum's `EnsureFrontendRequestsAreStateful` is route middleware on the
  `/v1` account group (`/v1/me`, `/v1/me/export`, `/v1/me/deletion`, `/v1/watchlist…`, `/v1/portfolio…`) and on
  `POST /v1/demo/session`; the application does not call `statefulApi()`. Laravel's own sign-in routes run in its
  `web` group, which has sessions. The `SANCTUM_STATEFUL_DOMAINS` value in `compose.dev.yml` is
  `pokeprices.localhost:8095`.
- **Public reads stay cookie-free.** `/v1/cards…`, `/v1/movers`, `/v1/prices/freshness` and `/v1/ready` never
  start a session and never set a cookie, and they keep their `Cache-Control` and `ETag` behaviour, even when a
  first-party `Referer` and a valid session cookie arrive with the request. That is why a visitor who never signs in
  never receives a cookie, and why the header carries static links and never asks the server who you are.
- **The `Referer` dependency.** Sanctum treats a request as first-party (stateful) when its `Origin` header, or
  failing that its `Referer`, names a host in `SANCTUM_STATEFUL_DOMAINS`. Browsers send `Origin` on same-origin
  writes; for account GETs the app sets `referrerPolicy: 'same-origin'` on the request so the `Referer` is present
  whatever the page's referrer policy is. The data-export link carries `referrerPolicy="same-origin"` and no
  `rel="noreferrer"` for the same reason. A request with neither header (curl, a script, a browser extension that
  strips referrers) is not first-party: `GET /v1/me` answers `401` and `POST /v1/demo/session` answers `419`, even
  with a valid cookie.
- **Account answers are never cached.** Every account response carries `Cache-Control: no-store, private` and no
  `ETag`, including the two-factor secret reads, because the `web` group has the same header.
- **No redirects.** A signed-out request to an account route answers a `401` problem. A signed-in person who posts
  to a sign-in route (`/login`, `/register`, `/forgot-password`, `/reset-password`, `/two-factor-challenge`) gets
  `409` with the problem type `already-signed-in`, never a redirect to the app's HTML. The app's account requests
  use `redirect: 'error'`, so any redirect is a failure.
- **Reset ends other sessions.** A successful password reset deletes every `sessions` row of that person in the same
  transaction.
- **Errors are problem documents** (`application/problem+json`), with one exception: Fortify's `423` "Password
  confirmation required." answer, which is a plain `{"message": …}` body that the two-factor screen reads.

## Session endpoints

These are Laravel's root paths from Fortify (headless: no views, no redirects) and Sanctum. Every one is sent
`Accept: application/json` by the app. They are outside `openapi/api.json` by design: the contract documents `/v1`
only, so no sign-in path appears in it, and the contract tests assert that. The `/v1` account endpoints are listed in
[Watchlist and portfolio](#watchlist-and-portfolio) and [Your data](#your-data). `GET /up` is Laravel's health route,
not a session endpoint.

| Method | Path | JSON body | Success | What the app does with it |
|---|---|---|---|---|
| GET | `/sanctum/csrf-cookie` | none | `204`, sets `XSRF-TOKEN` | Called once before the first write, and again after a `419`. |
| POST | `/register` | `invite_code`, `email`, `password`, `password_confirmation` | `201`, empty body | The `/sign-up` page. The new person is signed in and a verification mail is sent; the page opens `/account`. `422` carries `errors.invite_code` for a bad code. |
| POST | `/login` | `email`, `password` | `200` `{"two_factor": false}` or `{"two_factor": true}` | The `/sign-in` page. `true` means a two-factor code is still needed; the page shows the authentication-code step. `422` is the generic wrong-sign-in message; the sixth try in a minute is `429` with `Retry-After`. |
| POST | `/two-factor-challenge` | `code`, or `recovery_code` | `204` | The authentication-code step of `/sign-in`. Each recovery code works once. |
| POST | `/logout` | none | `204` | The `Sign out` button on `/account`. |
| POST | `/forgot-password` | `email` | `200` `{"message": …}` | The `/password/forgot` page shows the message verbatim. The answer is the same whether or not the address has an account. |
| POST | `/reset-password` | `token`, `email`, `password`, `password_confirmation` | `200` `{"message": …}` | The `/password/reset` page. A bad token or wrong address is `422` with one generic message. |
| GET | `/email/verify/{id}/{hash}` | none (a signed link) | `204` | The `/account/verify-email` page calls it with the link from the mail; it needs the person to be signed in. |
| POST | `/email/verification-notification` | none | `202` (`204` when the address is already verified) | The `Send the verification email again` button on `/account`. |
| POST | `/user/confirm-password` | `password` | `201`, empty body | Called by the two-factor screen when a step answers `423`; the confirmation lasts three hours (`AUTH_PASSWORD_TIMEOUT`). |
| GET | `/user/confirmed-password-status` | none | `200` `{"confirmed": <bool>}` | Not used by the app. |
| POST | `/user/two-factor-authentication` | none | `200`, empty body | `Turn on two-factor authentication`; needs a confirmed password, else `423`. |
| POST | `/user/confirmed-two-factor-authentication` | `code` | `200`, empty body | The `Confirm` step of two-factor setup; a wrong code is `422` under `code`. |
| GET | `/user/two-factor-qr-code` | none | `200` `{"svg": …, "url": …}` | Draws the QR code as an image in component state; `url` is not used. |
| GET | `/user/two-factor-secret-key` | none | `200` `{"secretKey": …}` (`404` when two-factor is not started) | Shows the setup key beside the QR code. |
| GET | `/user/two-factor-recovery-codes` | none | `200`, a JSON array of codes | Shown once, right after confirming or regenerating, in component state only. |
| POST | `/user/two-factor-recovery-codes` | none | `200`, empty body | `Show new recovery codes`: regenerates the codes, then reads them. |
| DELETE | `/user/two-factor-authentication` | none | `200`, empty body | `Turn off two-factor authentication`. |

Fortify's profile-update, password-update and passkey routes are not enabled, and there are no view routes.

## Invite code

- Registration is invite-only. The code is the `INVITE_CODE` environment variable, set by Andres in `api/.env`
  (human gate HG-03); it is never committed, and no agent reads or writes that file. Tests set a runtime value with
  `config(['accounts.invite_code' => …])`.
- The code is checked first, before the email or the password is examined. A missing, empty, non-text or wrong code
  answers `422` with exactly one error, `errors.invite_code`: "The invite code is not valid." So a request without a
  valid code learns nothing about which addresses already have accounts.
- **When `INVITE_CODE` is unset, no code can match, so every registration is refused.** The sign-up page and every
  test still work; only a person's own registration on the dev site waits for HG-03.
- Choose at least 16 random characters. Registration is throttled at 5 a minute per IP, and until `trustProxies`
  lands with the Deploy prompt that IP is shared by everyone behind nginx (see [Rate limits](#rate-limits)), so the
  code, not the throttle, is the defence.
- Public signup is a later phase (P7); Turnstile on signup belongs to the Deploy prompt.

## Demo account

- **What it is.** One shared, seeded account that can look at a watchlist and a portfolio but cannot change
  anything. A visitor enters it with the `Try the read-only demo` button, which calls `POST /v1/demo/session`
  (`204`; the session id is regenerated and the visitor is signed in as the demo). It answers `404` with the problem
  type `demo-unavailable` when the demo is disabled or has not been seeded.
- **Seeding.** `docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan db:seed --class=DemoAccountSeeder --no-interaction`.
  The seeder is idempotent: it creates or repairs one verified demo user without two-factor, and sets a watchlist of
  `base1-4:holo:unlimited:-`, `base1-4:holo:1st:-` and `ex5-17:reverse:-:-` and a portfolio of one
  `base1-4:holo:unlimited:-` and three `ex5-17:reverse:-:-`. A variant missing from the database is skipped with one
  console line each, so it runs on an empty database. It refuses to touch a non-demo account that holds the demo
  address. `DatabaseSeeder` is not edited.
- **Configuration** (names only; values are set in `api/.env` or the defaults in `api/config/accounts.php`):
  - `DEMO_ENABLED`: default on; off makes the entry answer `404`.
  - `DEMO_EMAIL`: the demo's address; the default is an `example.com` address.
  - `DEMO_PASSWORD`: no committed default. Unset, the seeder stores a random 40-character password and the demo is
    entered only with the button. Set it if you also want the normal sign-in form to work for the demo.
- **What read-only covers.** The server refuses with `403` and the problem type `demo-read-only` every request whose
  method is not GET, HEAD or OPTIONS, except signing out, on the whole account group and on every Fortify write
  (the two-factor routes, `/email/verification-notification`, `/register` and `/login` while signed in). The
  enumeration test walks every registered write route. A password reset can never change the demo's password (`422`).
  The demo may download its data (a read) but cannot delete itself. The pages also hide write controls for the demo
  and say it is the read-only demo, but no test relies on the pages for enforcement. The account page still shows the
  two-factor button to the demo; the server answers it with `403`.
- **Limits.** The entry is 10 a minute per IP; the demo's account API is 30 a minute keyed by IP, so one visitor
  cannot spend it for everyone. Both are global on the dev topology (see [Rate limits](#rate-limits)).

## Watchlist and portfolio

Both lists are per variant (a variant key such as `base1-4:holo:unlimited:-`), owned by one account, and reachable
only by that account; no endpoint takes a user id. They need a signed-in session (`401` otherwise), and the demo
may read but not write.

| Method | Path | Success | Notes |
|---|---|---|---|
| GET | `/v1/watchlist` | `200` | `data` rows ordered by `card_id`, then `variant_key`; `meta` has `count`, `limit` and `degraded`. |
| PUT | `/v1/watchlist/{variantKey}` | `200` | No body. Adds the variant; `200` also when it was already there. |
| DELETE | `/v1/watchlist/{variantKey}` | `204` | Also `204` when the item is absent. |
| GET | `/v1/portfolio` | `200` | `data` rows plus `meta` (below). |
| PUT | `/v1/portfolio/{variantKey}` | `200` | Body `{"quantity": <integer 1..9999>}`. The quantity is set, not added. |
| DELETE | `/v1/portfolio/{variantKey}` | `204` | Also `204` when the holding is absent. |

- **Limits.** 100 variants per watchlist, 100 per portfolio, quantity 1 to 9999. The 101st variant is `422`
  under `errors.variant_key`; a key that is already held still answers `200`. Each write takes a per-user lock
  (`pg_advisory_xact_lock`) around count-then-insert, so two writers of one person cannot pass the limit together and
  different people never block each other.
- **Variant keys.** The key shape mirrors the `card_variants` constraints. A key that is malformed, or that no
  variant has, is `422` under `errors.variant_key` on PUT and `204` on DELETE. There is no foreign key from either
  table to `card_variants` (that table is rebuilt by the mapping tests and is append-only), so the write path checks
  existence instead. A key sent percent-encoded (`ex10-%3F:holo:-:-`) is decoded.
- **Honest rows.** Each row has `variant_key`, `card_id`, `finish`, `edition`, `treatment`, a `card` object (`name`,
  `set_name`, `number`, or `null`) and a `price` block, or `null`. The price block is exactly the card page's: source,
  feed, basis, currency, cadence, the close, its `day` and `as_of`, the previous close and change, and `series_epoch`,
  read from `price_latest`. Card names come from the PokéSearch catalogue through the same cached batched read the
  movers board uses; when PokéSearch is unavailable `meta.degraded` is true and `card` may be `null`. Every priced row
  carries its own day; rows can differ.
- **Valuation (portfolio).** Each holding has `quantity` and `value`. The value, and the total in `meta.total_value`,
  are computed by the API in Postgres `numeric` and sent as strings with two decimals (`"79.02"`); the browser never
  multiplies or adds money. A holding without a price has `value` `null`, is never valued at zero, is left out of
  the total and is counted in `meta.unpriced`. `meta` also holds `count`, `limit`, `priced`, `first_day`,
  `last_day` (the earliest and latest day among priced holdings, so the total names the days it rests on), `source`,
  `feed`, `basis`, `currency` and `degraded`. Worked example: one `base1-4:holo:unlimited:-` at `"968.91"` and three
  `ex5-17:reverse:-:-` at `"26.34"` give values `"968.91"` and `"79.02"` and `total_value` `"1047.93"`.
- **Not kept.** No cost basis, gain or loss, history of holdings, alert thresholds or per-card (rather than
  per-variant) watching.
- **Idempotent writes.** Every state-changing `/v1` call is idempotent in effect (PUT sets, DELETE removes), so there
  is no `Idempotency-Key` header.

## Your data

- **Download.** `GET /v1/me/export` (the `Download your data (JSON)` link on `/account`) answers `200` with
  `Content-Disposition: attachment; filename="pokeprices-export.json"`. The body is
  `{"data": {"format": "pokeprices-account-export", "version": 1, "account": …, "watchlist": […], "portfolio": […],
  "sessions": […]}}`:
  - `account`: `email`, `email_verified_at`, `two_factor` (a boolean) and `created_at`.
  - `watchlist`: `variant_key` and `added_at`, in byte order of the key.
  - `portfolio`: `variant_key`, `quantity`, `added_at` and `updated_at`, in byte order of the key.
  - `sessions`: the records kept about the person's sign-ins, `last_active_at`, `ip_address` and `user_agent`, newest
    first.
  - It never holds the password hash, a remember token, the two-factor secret, a recovery code, a session id or a
    session payload. The demo may download its data. The answer is `no-store`.
- **Delete.** `Delete my account` on `/account` calls `POST /v1/me/deletion` with the body
  `{"password": "<current password>"}`; it is a POST so the password never travels in a URL. It answers `204` and
  removes, in one transaction, the user row, its watchlist and portfolio rows, every `sessions` row with the person's
  id and the person's `password_reset_tokens` row; the current session is then invalidated. A wrong password is `422`
  under `errors.password`; the demo is refused with `403`; the sixth wrong try in a minute is `429`. The same address
  can register again later with the invite code.
- **Never send the password in the query string.** The endpoint reads merged input at present, so
  `POST /v1/me/deletion?password=…` would also be accepted; a URL ends up in logs and history. The app never does
  that, and nobody should. Reading the body only is a named open item in the draft ADR.
- **Password reset ends other sessions.** After a successful reset, no other browser stays signed in.
- Display settings (theme and colours) stay in the browser's `localStorage`; accounts do not store them, so they are
  not in the export.

## Rate limits

A `429` is the problem type `too-many-requests` with a `Retry-After` header. The two anonymous tiers are unchanged
from before accounts; the account tiers are defined in `api/config/accounts.php`.

| Limiter | Per minute | Keyed by | Where |
|---|---|---|---|
| `public-read` | 120 | IP | the public price and card reads |
| `search` | 60 | IP | card search |
| `login` | 5 | the lower-cased email and the IP | `POST /login` |
| `two-factor` | 5 | the pending sign-in | `POST /two-factor-challenge` |
| `register` | 5 | IP | `POST /register` |
| `account-guest` | 60 | IP | first on the whole account group, and on `/sanctum/csrf-cookie`, `/forgot-password` and `/reset-password`; it runs before a session starts, so anonymous requests cannot create unmetered `sessions` rows |
| `password-confirm` | 5 | the user | `/user/confirm-password`, `/user/confirmed-two-factor-authentication` and `POST /v1/me/deletion` |
| `account` | 120 | the user | the signed-in account group |
| `account-demo` | 30 | the IP | used by `account` when the user is the demo |
| `demo-session` | 10 | IP | `POST /v1/demo/session` |

Fortify's two verification routes also carry its own `6` a minute.

- **Topology caveat.** nginx hands the API its own address as the client address, and `trustProxies` waits for the
  Deploy prompt. Until then every IP-keyed bucket (`register`, `account-guest`, `account-demo`, `demo-session`, and
  the two anonymous tiers) is in practice one global bucket for all visitors. The tests prove the per-IP keying; they
  cannot prove it on this topology.
- **`account-guest` also counts signed-in traffic.** It runs before the session starts, so a signed-in request spends
  it too. On a one-IP topology all account traffic from everyone shares the 60 a minute, and the per-user 120 is
  reachable only from several addresses. A busy account page can therefore meet a `429` earlier than the per-user
  number suggests.
- A reset request is never throttled in a way that reveals whether an account exists: it answers `200` with the same
  message whatever the broker did.

## Mail in development

- No mail provider is configured, so `MAIL_MAILER` falls back to the `log` mailer: a verification or reset mail is
  written to the API log. Read it with `docker compose -f compose.dev.yml logs api`. The Pest suite uses the `array`
  mailer.
- The reset link has the path shape `/password/reset?token=…&email=…` and the verification link has the shape
  `/account/verify-email?link=…`; both start with `APP_URL`. Reset links expire after 60 minutes and verification links
  after 60 minutes (the framework defaults).
- A link is a credential until it is used or expires. **Never paste a reset or verification link into a report or
  chat**; say only that a link of the right shape was logged.
