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
  Laravel's default lifetime of 120 minutes (`SESSION_LIFETIME`). The `Secure` flag comes from
  `SESSION_SECURE_COOKIE=true`, which production sets behind the tunnel (`deploy/stack.server.yml`), where
  `TRUSTED_PROXIES=REMOTE_ADDR` also makes Laravel trust the forwarded `https`, so the request and its URLs are
  `https` too. The dev site is plain `http` and leaves both unset, so its cookies carry no `Secure` flag. There are no
  API tokens: an `Authorization: Bearer` header is never read.
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
- **A password change ends the other sessions.** A successful `PUT /user/password` deletes, in the same transaction,
  every other `sessions` row of that person; the browser that made the change stays signed in (see
  [Changing the password](#changing-the-password)).
- **Errors are problem documents** (`application/problem+json`). A route that needs a recently confirmed password
  answers `423` with the problem type `password-confirmation-required` while the session has no confirmation, or one
  older than `AUTH_PASSWORD_TIMEOUT`; the two-factor screen reads the status and asks for the password.

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
| POST | `/logout` | none | `204` | The `Sign out` button on `/account` and in the demo strip. The app then opens the home page, which shows the status line "You are signed out." once: it is gone after a reload, and after leaving the home page and coming back. |
| POST | `/forgot-password` | `email` | `200` `{"message": …}` | The `/password/forgot` page shows the message verbatim. The answer is the same whether or not the address has an account. |
| POST | `/reset-password` | `token`, `email`, `password`, `password_confirmation` | `200` `{"message": …}` | The `/password/reset` page. A bad token or wrong address is `422` with one generic message. |
| GET | `/email/verify/{id}/{hash}` | none (a signed link) | `204` | The `/account/verify-email` page calls it with the link from the mail; it needs the person to be signed in. |
| POST | `/email/verification-notification` | none | `202` (`204` when the address is already verified) | The `Send the verification email again` button on `/account`. |
| POST | `/user/confirm-password` | `password` | `201`, empty body | Called by the two-factor screen when a step answers `423`; the confirmation lasts three hours (`AUTH_PASSWORD_TIMEOUT`). |
| GET | `/user/confirmed-password-status` | none | `200` `{"confirmed": <bool>}` | Not used by the app. |
| PUT | `/user/password` | `current_password`, `password`, `password_confirmation` | `200`, empty body | Changes the signed-in person's own password; see [Changing the password](#changing-the-password). The `Change password` panel on `/account`. |
| POST | `/user/two-factor-authentication` | none | `200`, empty body | `Turn on two-factor authentication`; needs a confirmed password, else `423`. |
| POST | `/user/confirmed-two-factor-authentication` | `code` | `200`, empty body | The `Confirm` step of two-factor setup; a wrong code is `422` under `code`. |
| GET | `/user/two-factor-qr-code` | none | `200` `{"svg": …, "url": …}` | Draws the QR code as an image in component state; `url` is not used. |
| GET | `/user/two-factor-secret-key` | none | `200` `{"secretKey": …}` (`404` when two-factor is not started) | Shows the setup key beside the QR code. |
| GET | `/user/two-factor-recovery-codes` | none | `200`, a JSON array of codes | Shown once, right after confirming or regenerating, in component state only. |
| POST | `/user/two-factor-recovery-codes` | none | `200`, empty body | `Show new recovery codes`: regenerates the codes, then reads them. |
| DELETE | `/user/two-factor-authentication` | none | `200`, empty body | `Turn off two-factor authentication`. |

Fortify's profile-update and passkey routes are not enabled, and there are no view routes.

### Changing the password

`PUT /user/password` changes the password of the signed-in person, and only theirs: the account is the one the
session names, and no field, header or query parameter can name another.

- **Body.** `current_password`, `password` and `password_confirmation`, as JSON. A password in the query string is
  ignored, as everywhere else.
- **Rules.** `current_password` must be the account's current password. The new `password` follows the sign-up and
  reset rule: 12 to 72 characters (and at most 72 bytes), with a matching `password_confirmation`.
- **Answers.** `200` with an empty body on success. `401` when nobody is signed in. `422` with the problem type
  `validation-failed` when a rule fails: `errors.current_password` for a missing or wrong current password,
  `errors.password` for a new password that is too short, too long or not confirmed, each a list of messages a page
  can show under that field. Nothing changes on a `422`. The read-only demo is refused with `403` and the problem
  type `demo-read-only`, and its password stays as it was.
- **Limit.** The `password-confirm` limiter: 5 a minute per person, counting every attempt, shared with the other
  password checks (see [Rate limits](#rate-limits)). The sixth in a minute is `429` with `Retry-After`.
- **Other sessions.** After a change, every other session of the account is signed out: its `sessions` row is
  deleted in the same transaction as the new password, and a remember-me cookie issued before the change no longer
  signs anyone in. The browser that made the change keeps its session and stays signed in. A reset link mailed
  before the change stops working. A refused change signs nobody out.
- **The form.** `/account` has a `Change password` panel with three fields: `Current password`, `New password` and
  `Confirm new password`. It sends them with `PUT /user/password`. A `422` message shows under its own field, a `429`
  reads "Could not change the password: Too Many Requests. Wait a minute and try again.", and a success shows the
  status line "Password changed. Your other sessions are signed out." The three fields are emptied after every
  answer. The read-only demo has no such panel.

### Checks in the browser

Every account form checks itself before it is sent: sign in and its two-factor step, sign up, forgot password, reset
password, two-factor setup, the confirm-password prompt, account deletion and change password.

- **What is checked.** A required field that is empty; a new password shorter than 12 or longer than 72 characters
  (sign up, reset password, change password); a confirmation that differs from the password; an email address with
  nothing before its `@`, without an `@`, or with a space in it (sign in, sign up, forgot password). A current
  password is only checked for being there. The address on the reset page comes from the link and is not checked.
- **What the person sees.** Every failing field gets its message at once, under the field and tied to it with
  `aria-describedby` and `aria-invalid`; focus moves to the first failing field; no request is sent, and what was
  typed stays.
- **The server stays the judge.** A form that passes is sent as before, and a `422` shows each field's message in the
  same place. The email check is loose on purpose: `name@` passes it and the API refuses it.
- **Account deletion.** `Delete my account` stays disabled until a password is typed, so the empty-password message
  answers only a submit that arrives another way.

## Invite code

- Registration is invite-only. The code is the `INVITE_CODE` environment variable, set by Andres in `api/.env`
  (human gate HG-03); it is never committed, and no agent reads or writes that file. Tests set a runtime value with
  `config(['accounts.invite_code' => …])`.
- The code is checked first, before the email or the password is examined. A missing, empty, non-text or wrong code
  answers `422` with exactly one error, `errors.invite_code`: "The invite code is not valid." So a request without a
  valid code learns nothing about which addresses already have accounts.
- **When `INVITE_CODE` is unset, no code can match, so every registration is refused.** The sign-up page and every
  test still work; only a person's own registration on the dev site waits for HG-03.
- Choose at least 16 random characters. Registration is throttled at 5 a minute per IP. On the dev stack that IP is
  nginx's, one address shared by everyone; in production each client keys its own bucket (see
  [Rate limits](#rate-limits)). Either way the code, not the throttle, is the defence.
- Public signup is a later phase (P7); Turnstile on signup belongs to the Deploy prompt.

## Demo account

- **What it is.** One shared, seeded account that can look at a watchlist, a portfolio, an alert and sample messages
  but cannot change anything. A visitor enters it with the `Try the read-only demo` button, which calls
  `POST /v1/demo/session` (`204`; the session id is regenerated and the visitor is signed in as the demo). It answers
  `404` with the problem type `demo-unavailable` when the demo is disabled or has not been seeded.
- **Seeding.** `docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan db:seed --class=DemoAccountSeeder --no-interaction`.
  The seeder is idempotent (a second run leaves the same rows) and sends, queues and fetches nothing. It creates or
  repairs one verified demo user without two-factor and sets:
  - a watchlist of 14 well-known cards, one variant each, no card twice (`DemoAccount::WATCHLIST`): Charizard
    `base1-4:holo:1st:-` (kept as the example of a variant with no close), Blastoise `base1-2`, Venusaur `base1-15`,
    Mewtwo `base1-10`, Zapdos `base1-16`, Pikachu `base1-58`, Snorlax `base2-11`, Dragonite `base3-4`, Lugia `neo1-9`,
    Chimecho `ex5-17` (reverse holo), Umbreon VMAX `swsh7-95`, Magikarp `sv2-203`, Mew ex `sv4pt5-232` and Umbreon ex
    `sv8pt5-161`;
  - a portfolio drawn from the same variants (`DemoAccount::PORTFOLIO`): one Blastoise, two Pikachu, one Lugia, three
    Chimecho and one Magikarp;
  - the alert samples described in [`ALERTS.md`](ALERTS.md#the-demos-samples): one alert, one sample signed webhook and
    two delivered sample messages.

  A variant missing from the database is skipped with one console line each, so it runs on an empty database. The
  lists live in `api/app/Domain/Accounts/DemoAccount.php`; the `watchlist` and `portfolio` keys of
  `api/config/accounts.php` are no longer read. It refuses to touch a non-demo account that holds the demo address.
  `DatabaseSeeder` seeds nothing (there is no default user); the demo account comes only from `DemoAccountSeeder`.
  After a deploy that changes these lists, run the same seeder once on the server.
- **Configuration** (names only; values are set in `api/.env` or the defaults in `api/config/accounts.php`):
  - `DEMO_ENABLED`: default on; off makes the entry answer `404`.
  - `DEMO_EMAIL`: the demo's address; the default is an `example.com` address.
  - `DEMO_PASSWORD`: no committed default. Unset, the seeder stores a random 40-character password and the demo is
    entered only with the button. Set it if you also want the normal sign-in form to work for the demo.
- **What read-only covers.** The server refuses with `403` and the problem type `demo-read-only` every request whose
  method is not GET, HEAD or OPTIONS, except signing out, on the whole account group and on every Fortify write
  (the two-factor routes, `/email/verification-notification`, `/register` and `/login` while signed in). The
  enumeration test walks every registered write route. A password reset can never change the demo's password (`422`),
  and neither can `PUT /user/password` (`403`).
  The demo may download its data (a read) but cannot delete itself. It may read its alert, its sample messages
  (`GET /v1/demo/samples`), its webhooks list and where its alerts go; creating, changing or deleting an alert, a
  webhook or the notification settings, and the webhook test send, are refused like every other write. The pages
  also hide the write controls a read-only session cannot use (the two-factor buttons, the card page's Add, Remove
  and Edit controls and its quantity form, the list and alert controls, Undo) and say it is the read-only demo, but
  no test relies on the pages for enforcement; a write that can still be attempted shows the calm sentence
  `The demo account is read-only.` A card page opened directly (a reload or a pasted link) knows the demo session
  when the browser holds the `XSRF-TOKEN` cookie, which entering the demo sets: it asks `GET /v1/me`, shows no
  account control on any variant (holding the space of one Add row) until the session and the lists have answered, then shows
  the list lines without a write control, so no Add button shows at any moment. Only a tab with no such cookie
  and nothing cached shows the Add buttons.
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
- **The estimate and the day's change (portfolio).** The total is an estimate at daily-close market prices, not a
  sale value. `meta` carries, beside it, the change against the previous close, computed in Postgres `numeric` in the
  same statement as the rows (`Portfolio::figures()`), never in floats:
  - `total_value` is the sum of quantity × latest close over the holdings that have a close (unchanged);
  - `change` is the sum of quantity × (close − previous close) over the holdings that have **both** a close and a
    previous close, as a string with two decimals; `null` when no holding has both. The previous close is the one
    each row's price block shows (`price_latest`: the most recent real close on an earlier day in the same series
    epoch, at most 14 days before), so a missing day, a filled close or a series-epoch change is handled exactly as
    on the row;
  - `change_pct` is `change` against the sum of quantity × previous close over the same holdings, × 100, rounded once
    to two decimals (half away from zero); `null` when that sum is `0.00` or no holding has both. The rows' own
    percents are never added or averaged;
  - `no_previous_close` counts the holdings with a close and no previous close: they are in the total and not in the
    change, so the page can say the change covers fewer cards than the total;
  - `count`, `priced` and `unpriced` are the cards (holdings), and every figure rests on the same `first_day`,
    `last_day`, `source`, `feed`, `basis` and `currency` as the total.

  Worked example: `base1-4:holo:unlimited:-` × 1 at `"968.91"` (previous `"950.00"`), `ex5-17:reverse:-:-` × 3 at
  `"26.34"` (previous `"27.00"`) and an unpriced first edition × 2 give `total_value` `"1047.93"`, `change`
  `1 × 18.91 + 3 × (−0.66)` = `"16.93"` against `1 × 950.00 + 3 × 27.00` = `1031.00`, so `change_pct` `"1.64"`
  (16.93 / 1031.00 × 100 = 1.642…), with `count` 3, `unpriced` 1 and `no_previous_close` 0.
- **The estimate outside the portfolio page.** `GET /v1/me` carries the same figures as `data.portfolio` (`count`,
  `priced`, `unpriced`, `no_previous_close`, `total_value`, `change`, `change_pct`, `first_day`, `last_day`, then
  `source`, `feed`, `basis`, `currency`), from `Portfolio::summary()`: one aggregate statement over the holdings and
  their latest closes, with no catalogue read, whatever the portfolio holds. It rides on the session read the pages
  already make, so the header needs no request of its own. Signed out, `GET /v1/me` answers `401` as before.
- **Not kept.** No cost basis, gain or loss, history of holdings, or per-card (rather than per-variant) watching.
  A watchlist row can carry an alert threshold (`alert_pct`, the last key of each watchlist item; portfolio holdings
  have none); how alerts work is in [`ALERTS.md`](ALERTS.md).
- **Idempotent writes.** Every state-changing `/v1` call is idempotent in effect (PUT sets, DELETE removes), so there
  is no `Idempotency-Key` header.
- **Undo and the card page's list state (web).** There is no undo route: the pages' `Undo` after a removal puts
  the row back through the same writes (`PUT /v1/watchlist/{variantKey}`, then `PUT /v1/alerts/{variantKey}` with
  its threshold when the row had an alert; `PUT /v1/portfolio/{variantKey}` with the quantity it had), each
  refused for the demo like any write. When the watchlist write succeeds and only the alert write fails, the row
  is back without its alert: the page reads the list again and the row says `Back on your watchlist. Its alert
  could not be put back; set it again.`, where its alert can be set. The card page of a known session reads `GET /v1/watchlist` and
  `GET /v1/portfolio` once each to say which variants are on which list, with `Remove` and `Edit` through the same
  routes. Where the estimate shows, Undo's focus rules and the exact lines are in [`WEB.md`](WEB.md).

## Your data

- **Download.** `GET /v1/me/export` (the `Download your data (JSON)` link on `/account`) answers `200` with
  `Content-Disposition: attachment; filename="pokeprices-export.json"`. The body is
  `{"data": {"format": "pokeprices-account-export", "version": 2, "account": …, "watchlist": […], "portfolio": […],
  "sessions": […], "notifications": {…}, "webhooks": […], "deliveries": […]}}`:
  - `account`: `email`, `email_verified_at`, `two_factor` (a boolean) and `created_at`.
  - `watchlist`: `variant_key`, `added_at` and `alert_pct` (the per-card alert threshold, or `null`), in byte order of
    the key.
  - `portfolio`: `variant_key`, `quantity`, `added_at` and `updated_at`, in byte order of the key.
  - `sessions`: the records kept about the person's sign-ins, `last_active_at`, `ip_address` and `user_agent`, newest
    first. Every demo visitor shares the one demo account, so the demo's export lists only the caller's own session,
    never another visitor's.
  - `notifications`: `channel` (`email` or `discord`) and `digest`; with no stored settings, `email` and `false`.
  - `webhooks`: for each webhook `kind`, `url_hint`, `description`, `created_at` and `disabled`, ordered by
    `created_at`. Never the URL or the signing secret.
  - `deliveries`: for each message `kind`, `channel`, `day`, `status`, `attempts`, `created_at` and `delivered_at`,
    newest first, at most the newest 500 (delivered and failed rows are kept 30 days, so this is everything kept in
    practice; see [`ALERTS.md`](ALERTS.md)). Never the message body.
  - It never holds the password hash, a remember token, the two-factor secret, a recovery code, a session id or a
    session payload. The demo may download its data. The answer is `no-store`.
- **Delete.** `Delete my account` on `/account` calls `POST /v1/me/deletion` with the body
  `{"password": "<current password>"}`; it is a POST so the password never travels in a URL. It answers `204` and
  removes, in one transaction, the user row, its watchlist and portfolio rows, every `sessions` row with the person's
  id and the person's `password_reset_tokens` row; the current session is then invalidated. A wrong password is `422`
  under `errors.password`; the demo is refused with `403`; the sixth wrong try in a minute is `429`. The same address
  can register again later with the invite code.
- **Never send the password in the query string.** The password is read from the request body only: a `password`,
  `password_confirmation` or `current_password` in the query string is ignored, so such a request fails validation.
  A URL ends up in logs and history, so nobody should send one anyway.
- **Account writes ignore the whole query string.** A write to `login`, `register`, `two-factor-challenge`,
  `reset-password`, `forgot-password`, `user/confirm-password`, `user/confirmed-two-factor-authentication`,
  `user/two-factor-recovery-codes` or `v1/me/deletion` reads nothing from the query string: an invite code, a two-factor
  `code` or `recovery_code`, a reset `token` or `remember` sent only there counts for nothing. A GET or HEAD
  keeps its query string (only the three password keys above are dropped from it), because the signed
  email-verification link needs its query to be checked.
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
| `account-guest` | 60 | IP | requests without a signed-in session: first on the whole account group, and on `/sanctum/csrf-cookie`, `/forgot-password` and `/reset-password`; it runs before a session starts, so anonymous requests cannot create unmetered `sessions` rows |
| `password-confirm` | 5 | the user | `/user/confirm-password`, `/user/confirmed-two-factor-authentication`, `PUT /user/password` and `POST /v1/me/deletion` |
| `account` | 120 | the user | the signed-in account group |
| `account-demo` | 30 | the IP | used by `account` when the user is the demo |
| `demo-session` | 10 | IP | `POST /v1/demo/session` |

Fortify's two verification routes also carry its own `6` a minute.

- **Topology caveat.** The client address comes from `TRUSTED_PROXIES` (`api/config/trustedproxy.php`); a trusted
  proxy is believed for `X-Forwarded-For`, `-Proto` and `-Port` only, never `X-Forwarded-Host`. Dev leaves it unset,
  so nothing is trusted and the API sees one shared client address for everyone behind nginx: every IP-keyed bucket
  (`register`, `account-guest`, `account-demo`, `demo-session`, and the two anonymous tiers) is one global bucket on
  the dev stack. Production sets `TRUSTED_PROXIES=REMOTE_ADDR`, which trusts only the immediate peer: php-fpm admits
  only nginx (its allow-list) and nginx is published on loopback only, so that peer is the tunnel, which appends the
  real client to `X-Forwarded-For`; Laravel takes the rightmost entry and each client keys its own bucket. `*` is
  never for production: it trusts every hop, so the leftmost, client-chosen `X-Forwarded-For` entry becomes the IP and
  a client could pick a fresh bucket per request. `api/tests/Feature/TrustedProxiesTest.php` proves both.
- **`account-guest` counts only requests without a signed-in session.** It still runs before any session starts.
  "Signed in" is decided there without starting one (`App\Domain\Accounts\SignedInSession`): the session cookie is
  decrypted and the `user_id` of that one `sessions` row is read by its primary key. No cookie, a cookie that does not
  decrypt, a value that is not a session id, an id with no row, a row idle for longer than `SESSION_LIFETIME` and a
  session nobody signed in to are all guests, and so is every request when sessions are not kept in the database. A
  signed-in user behind an address whose guest bucket is full therefore still gets the per-user 120 of `account`.
  - What it costs before a request is counted: a guest without a cookie, or with one that does not decrypt, costs no
    query; a cookie that decrypts costs one primary-key read of `sessions`, also when the answer is the `429`. A
    signed-in request on the account group costs that read, then the session start and the user lookup, before
    `account` counts it.
  - On `/sanctum/csrf-cookie`, `/forgot-password` and `/reset-password` no `account` tier follows, so a signed-in
    request is counted there, before the session starts, at the `account` number (120 a minute) in a bucket of its
    own, keyed by the user and the client address. It spends neither the guest bucket nor the `account` bucket.
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
