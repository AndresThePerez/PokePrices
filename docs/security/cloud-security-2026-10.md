# Adversarial security review: p5-work, October 2026

**Scope.** Branch `p5-work` at `90e4016`. This is a review only and changes no code. Line numbers are at that tip.

**Method.** I read the code and confirmed framework behaviour against upstream sources: framework v13.35.0 and Guzzle 8.2.0 at the
locked tags, and Fortify and Sanctum at their current main branch (the lock pins Fortify v1.41.0 and Sanctum v4.3.3). I ran `UrlGuard` on its own to confirm finding C2. I did not run the Pest suite:
it needs Postgres, and `composer install` could not finish in the review container.

**Not re-reported.** The product rules the brief marks deliberate. The listed known items: `dns_get_record` has no
timeout; the response body is buffered and its read capped at 64 KiB; the webhook DELETE vs test-send FK race; Fortify
reads tokens from the query string; Discord length is counted in code points; the candidate query is unbounded;
`deliveries(endpoint_id)` has no index. The gaps the ASVS sheet already lists as `gap`: Secure flag, HSTS, absolute
session lifetime, breached-password check, bot checks.

Severity scale: High / Medium / Low / Info. Findings are ranked within each surface.

---

## (a) AuthN / AuthZ

### A1. Medium: the demo's data export leaks every demo visitor's IP address and browser

- **Where:** `api/app/Domain/Accounts/AccountExport.php:164-170` (`sessions()`),
  `api/app/Http/Controllers/MeController.php:50`, and the `me.export` route in `api/routes/api.php`, which
  `DemoReadOnly` allows because it is a GET.
- **Problem:** Every demo visitor is signed in as the same `users.id`. `sessions()` selects every `sessions` row
  `WHERE user_id = ?`.
- **Exploit:** An anonymous visitor clicks "Try the demo" (`POST /v1/demo/session`), then calls `GET /v1/me/export`.
  `data.sessions[]` lists `ip_address`, `user_agent` and `last_active_at` for every other demo visitor whose session is
  still live, or expired but not yet garbage-collected (lottery 2/100). An attacker can poll it to build a log of who
  is visiting. That is personal data handed to the public internet without an account.
- **Fix:** When `$user->is_demo`, `sessions()` returns only the caller's own row
  (`WHERE id = $request->session()->getId()`), or `[]`. Pass the session id into `AccountExport::of()`. The same
  concern applies to any future per-user table the demo shares (deliveries are skipped for the demo today).
- **Failing test:** Insert two `sessions` rows for the demo with IPs `192.0.2.10` and `192.0.2.20`. Sign in as the
  demo and `GET /v1/me/export`. Expect the body not to contain `192.0.2.20`. It does today.
- **ASVS sheet:** the row at line 68 ("download … holds no secret") holds for real users but not for the demo.

### A2. Medium: timing leaks which emails exist on `/login` and `/forgot-password`

- **Where:** `api/config/auth.php` (no `timebox_duration`, so the framework default of 200 000 µs applies),
  `api/.env.example:10` (`BCRYPT_ROUNDS=12`), Fortify's `AttemptToAuthenticate` (calls `SessionGuard::attempt`), and
  framework `PasswordBroker::sendResetLink` / `DatabaseTokenRepository::create`.
- **Login:** For an unknown email the guard runs no hash. For a known email with a wrong password it runs one
  cost-12 bcrypt, about 200–300 ms on typical hardware. The 200 ms timebox floor does not cover that.
- **Forgot-password:** For a known email the broker bcrypt-hashes the new token (cost 12). It then sends the
  `ResetPassword` notification synchronously, because the notification is not queued, so SMTP latency is added. That
  overruns the timebox by hundreds of ms. An unknown email returns at the floor. The response bodies are
  byte-identical (`ResetLinkAnswer`), but the timing differs.
- **Exploit:** For each candidate email, send one `/forgot-password` request a minute (the broker's own 60 s throttle
  returns fast after the first). Compare latency: known accounts stand out by more than 100 ms. On `/login`,
  alternate source IPs to stay under the `email|ip` bucket.
- **Fix:** Set `auth.timebox_duration` above the slowest legitimate path (for example 1 000 000). Queue the reset and
  verification notifications (`ShouldQueue`, or `Notification::route` on the queue). On login, do a dummy
  `Hash::check` when no user is found (Fortify `authenticateUsing`).
- **Failing test (structural, so not flaky):**
  - `expect(config('auth.timebox_duration'))->toBeGreaterThanOrEqual(750_000)`.
  - With `Queue::fake()`, `POST /forgot-password` for a known user asserts the reset notification was pushed to the
    queue, not sent inline.
- **ASVS sheet:** rows at lines 27 and 29 prove only that the bodies are equal; this timing channel makes their claims
  weaker.

### A3. Medium: the two-factor challenge has a fixed 5-a-minute budget with no lockout

- **Where:** `api/app/Domain/Accounts/AccountRateLimits.php:100` (key `login:<pending user id>`) and
  `api/config/accounts.php:31` (5 a minute).
- **Problem:** Nothing escalates, nothing caps the day, and the user is never told. Fortify accepts a ±1-step window,
  so three codes are valid at any moment.
- **Exploit:** An attacker who has the password (phished or reused) gets about 7 200 guesses a day, roughly 2 % a day.
  Odds pass 50 % in about five weeks of quiet guessing, and the victim sees nothing. Recovery codes go through the
  same endpoint.
- **Fix:**
  - Add a second, longer limiter per pending user: for example 20 an hour and 50 a day, after which the pending
    sign-in is dropped until a password reset.
  - Email the account after N failed codes.
  - Count recovery-code failures in the same buckets.
- **Failing test:** Simulate 60 minutes of failures at 5 a minute (`travel()` in steps). The 21st failure inside the
  hour answers 429. Today it answers 422.

### A4. Low: undocumented "remember me" mints a cookie that lasts about 400 days

- **Where:** Fortify's `AttemptToAuthenticate` and `TwoFactorAuthenticatedSessionController` honour `remember` from the request
  body (`$request->boolean('remember')` and `$request->remember()`). Nothing in `FortifyServiceProvider::ROUTE_MIDDLEWARE` strips it.
- **Problem:** `POST /login {"remember": true}` sets `remember_web_*` with the framework's default lifetime of
  576 000 minutes. That sidesteps the 120-minute idle limit the docs describe; the absolute lifetime is a known `gap`,
  but this credential is not mentioned anywhere.
- **Exploit:** The SPA never sends `remember`, so the only clients that get the cookie are hand-rolled ones, such as an
  attacker using a stolen password. That attacker keeps access until the victim resets their password. There is no
  "sign out everywhere" and no password change.
- **Fix:** In `IgnoreQueryCredentials`, or a sibling middleware on `login.store` and `two-factor.login`, remove
  `remember` from the request body as well. Alternatively make it explicit and set a short `remember` cookie lifetime
  (`Auth::guard('web')->setRememberDuration(...)`).
- **Failing test:** `postJson('/login', [... 'remember' => true])` with the right password. Assert the response sets no
  cookie whose name starts with `remember_web_`.

### A5. Low: one shared invite code that never expires and can be reused

- **Where:** `api/app/Domain/Accounts/InviteCode.php:30` and `api/config/accounts.php:25`.
- **Problem:** Registration is gated by one environment string. Once it leaks or is guessed (5 tries a minute per IP,
  more with rotating IPs), registration is open for good. It cannot be revoked per person, and there is no record of
  which code created which account. Holding the code also lets someone enumerate emails through
  `email has already been taken` (`api/app/Actions/Fortify/CreateNewUser.php:31,39`). Every new account can then use
  the delivery platform (see C1).
- **Fix:**
  - Store single-use invite tokens hashed in an `invites` table, with an expiry and optionally a bound email.
  - Burn the token on use, and record `invited_by` on the user.
- **Failing test:** Register two different emails with the same code. The second answers 422 `errors.invite_code`.

**Checked and found sound:**

- **Route stack:** the account group's middleware order (`api/routes/api.php:47-54`).
- **Demo write refusal:** `DemoReadOnly` covers both the `/v1` group and the web group, including every Fortify write.
- **Demo sign-in:** `POST /v1/demo/session` regenerates the session id.
- **Password reset:** deletes every session and rotates the remember token (Fortify `CompletePasswordReset`). Its
  answer is the same whether or not the account exists, apart from A2's timing.
- **Account deletion:** cascades to webhooks, deliveries and notification settings.
- **Verification links:** checked on the SPA side by `isVerificationLink` (`web/src/api/client.ts:264`).
- **Mailed links:** built from `APP_URL`, never from the Host header.

---

## (b) Input and injection

There are no findings at Medium or above. Every request boundary I traced is validated before use.

### B1. Low: catalog text goes into Discord markdown and email subjects without escaping

- **Where:** `api/app/Domain/Alerts/NotificationText.php:38,103` and `api/app/Domain/Alerts/DiscordMessage.php:84`.
  The card name comes from PokéSearch.
- **Problem:** `allowed_mentions: {parse: []}` (`DiscordMessage.php:36`) blocks pings. Markdown still renders,
  including masked links `[text](https://…)`, headings, code blocks and spoilers.
- **Exploit:** A poisoned or compromised catalog name, or a future user-supplied label, puts a phishing link inside an
  official-looking "PokéPrices alert" in the user's Discord. Symfony Mime encodes a CR or LF in the email subject, so
  there is no header injection there; the text body carries the same untrusted text.
- **Fix:**
  - For Discord, escape ``\ * _ ~ ` | > # [ ] ( ) -`` in `cardLabel()`.
  - For both channels, strip C0/C1 controls and bidi or zero-width characters, and cap the name at about 120 characters.
- **Failing test:** With the card name `[Claim](https://example.invalid)`, `DiscordMessage::envelope(...)` content
  contains `\[Claim\]\(` and no unescaped `](`.

### B2. Info: the webhook description accepts control and bidi characters

- **Where:** `api/app/Http/Controllers/WebhookController.php:63` (`string|min:1|max:100`).
- **Problem:** Email has `not_regex:/[\x00-\x1F\x7F]/`; description does not. U+202E (bidi override), zero-width
  characters and NUL-adjacent controls are stored and echoed in `GET /v1/webhooks` and the export. The owner is the only
  reader, so impact is cosmetic. Apply the same `not_regex` for consistency.

**Checked and found sound:**

- **SQL:** every statement binds its values. The only interpolations are constants (`MapAudit.php:149`,
  `PricePartitions.php:28`, and `DeliverDue`'s `IN (?, …)` placeholder lists).
- **Variant keys:** `{variantKey}` is anchored with `\A…\z` and checked before every query, DELETE included.
- **Card ids:** checked against the `CardId` pattern, then `rawurlencode`d before the upstream call.
- **Mass assignment:** none from request input. `Delivery` and `WebhookEndpoint` are `Guarded(['id'])`, but are only
  filled through explicit `forceFill` arrays.
- **Request id:** `X-Request-Id` is pattern-checked in both nginx and Laravel.
- **Logs and payloads:** logs are JSON-encoded lines, and payloads are built with `json_encode`. Neither leaves a
  path for log or JSON injection.

---

## (c) The delivery pipeline as an attack platform

### C1. High (Medium if every invitee is trusted): one account can take the platform's entire delivery capacity, and slow down ingest

- **Where:** `api/config/alerts.php:31-33` (`batch` 10, `per_endpoint` 3, budget 60 s), `api/config/alerts.php:50`
  (`webhook-test` 5 a minute per user), `api/app/Domain/Alerts/Jobs/DeliverDue.php:62,83`, and
  `api/app/Domain/Alerts/TestMessage.php` (each test is due immediately).
- **Problem:**
  - The platform sends at most 10 rows a minute. The per-sweep cap is per endpoint, not per user or host, and one
    account holds up to 6 endpoints (5 signed, 1 Discord), so it can claim 18 rows, more than the whole batch.
  - Rows are claimed oldest `next_attempt_at` first, with no fairness between users.
  - The `$slow` back-off triggers only on `timeout` or `connection`. A receiver that answers 503 after 4.9 s (inside
    the 5 s timeout) never triggers it, and 503 is retryable up to 6 attempts.
- **Exploit:**
  1. Register 5 signed endpoints on one attacker host that holds every request about 4.9 s and then answers 503.
  2. Call `POST /v1/webhooks/{id}/test` 5 times a minute, all day. New rows plus their retries far exceed 10 a minute.
     Every sweep spends about 49 of its 60 s on the attacker's sockets.
  3. Real `price.moved`, alerts and digests are created later than the attacker's backlog, so they wait hours. The
     daily close becomes useless.
  4. The `DeliverDue` docblock says the same worker serves ingest, so ingest jobs queue behind the sweeps.
  5. Side effect: PokéPrices sends signed POSTs to any public URL the attacker names. That is low-volume, but it is
     traffic with PokéPrices as the source.
- **Fix:**
  - Claim round-robin by `user_id`, with at most 2 rows per user per sweep.
  - Allow at most one pending `webhook.test` per endpoint (409 otherwise), and cap tests per user per day (for
    example 20).
  - Count an attempt longer than about 2 s as slow, whatever its outcome.
  - Give deliveries their own queue and worker, separate from ingest.
  - Optionally cap concurrent rows per destination host.
- **Failing test:** User A has 5 endpoints and 30 due `webhook.test` rows. User B has 1 `alert.triggered` row created a
  minute later. A single `DeliverDue::claim($db, $now, 10)` must include B's row. Today it returns ten of A's rows.

### C2. Low (depends on deployment): the SSRF blocklist misses several IPv6 and translation ranges

- **Where:** `api/app/Domain/Alerts/UrlGuard.php:26-29` and the embedded-IPv4 check at `:111`.
- **Problem:** I ran `UrlGuard::check()` on its own. These URLs are **allowed**:

  | URL | Range not blocked |
  |---|---|
  | `https://[64:ff9b:1::a9fe:a9fe]/` | RFC 8215 local-use NAT64 prefix (`64:ff9b:1::/48`) |
  | `https://[::ffff:0:169.254.169.254]/` | SIIT IPv4-translated (`::ffff:0:0:0/96`). The embedded check only knows `::ffff:0:0/96` and `::/96` |
  | `https://[fec0::1]/` | Deprecated site-local |
  | `https://[2001:0:…]/` | Teredo, which embeds an IPv4 address |
  | `https://[100::1]/` | Discard-only |
  | `https://192.88.99.1/` | 6to4 relay anycast |

  A DNS name whose AAAA record points into these ranges passes the same way. The `64:ff9b::/96` well-known prefix and
  the mapped and compatible forms are blocked correctly.
- **Exploit:** On an IPv6-only or NAT64/SIIT deployment (common on cloud IPv6-only subnets), a webhook to
  `[64:ff9b:1::a9fe:a9fe]` reaches 169.254.169.254, the cloud metadata service. The test-send status in
  `last_delivery` is enough to confirm it.
- **Fix:**
  - Invert the IPv6 rule: allow only global unicast `2000::/3`, then subtract the special ranges.
  - Decode embedded IPv4 from both NAT64 prefixes, from SIIT and from Teredo (the client address is XOR-ed), and
    check it as IPv4.
  - Add `192.88.99.0/24`.
- **Failing test:** A dataset in `UrlGuardTest` of the six URLs above, each expecting `blocked_address`.

### C3. Low: the allow-list skips every check, and its default depends on `APP_ENV`

- **Where:** `api/app/Domain/Alerts/UrlGuard.php:67-69` and `api/config/alerts.php` (`private_allow`).
- **Problem:** An allow-listed `host:port` skips the scheme check, the address check and pinning; it is resolved again
  by curl at send time. The default allow-lists `api:8096` whenever `APP_ENV === 'local'`. `.env.example` and
  `compose.dev.yml` both ship `APP_ENV=local`, so a production deploy that copies `.env.example` lets any user target
  `http://api:8096/`, an internal container.
- **Fix:**
  - Default to an empty allow-list and set it only in `compose.dev.yml`.
  - Require allow-list entries to be IP literals so that the address is pinned.
  - Fail the boot when `APP_ENV=production` and the allow-list is not empty.
- **Failing test:** With `APP_ENV=production` and no `WEBHOOK_PRIVATE_ALLOW`, `config('alerts.webhooks.private_allow')`
  is `[]`. To make the config rule hold whatever `APP_ENV` says, assert that the guard refuses `http://api:8096/`.

### C4. Low: signing secrets cannot be rotated, and share their key with cookies

- **Where:** `api/app/Domain/Alerts/WebhookEndpoints.php` (`create`, `delete`) and
  `api/app/Domain/Alerts/Models/WebhookEndpoint.php` (`encrypted` casts).
- **Problem:**
  - Rotating a leaked secret means delete and re-add. That cascades and drops pending deliveries, and changes the id.
    There is no overlap window in which both secrets verify.
  - URLs, secrets, the two-factor secret and cookies all use one `APP_KEY`, with no `APP_PREVIOUS_KEYS` plan. A leaked
    `APP_KEY` (for example from an environment dump) decrypts every webhook URL, every Discord token and every
    signing secret at once.
  - The ciphertexts are not bound to their row, so someone with database write access can move them between users.
- **Fix:**
  - Add `POST /v1/webhooks/{id}/rotate-secret`, which returns a new secret once and signs with both secrets (two `v1,`
    entries) for 24 h.
  - Use a separate encrypter key for webhook material.
  - Document the `APP_PREVIOUS_KEYS` rotation.

**Note on a known item:** Guzzle's `decode_content` defaults to on, so curl decompresses (`CURLOPT_ENCODING=''`,
`CurlFactory.php:2609-2621`). A hostile receiver can therefore send a gzip bomb during the 5 s window. The decompressed
output, not the bytes on the wire, is what fills the buffered body. That makes the known buffered-body item worse; the
cheapest mitigation is `'decode_content' => false` in `DeliverySender::post()`.

**Checked and found sound:**

- **Address pinning:** the guard runs at every send and pins the approved address with `CURLOPT_RESOLVE`.
- **Proxy bypass:** Guzzle 8.2 pins `CURLOPT_PROXY=''` and `CURLOPT_NOPROXY`, so libcurl cannot fall back to
  `HTTPS_PROXY` from the environment and skip the pin.
- **Retries:** redirects are off and treated as non-retryable, `Retry-After` is capped at 1 h, and `410` disables the
  receiver's own endpoint only.
- **Sender errors:** they record `last_error` only, and no URL or secret is logged.
- **Discord URLs:** match a strict `\A…$D` pattern.

---

## (d) Secrets, headers and transport

### D1. Medium: IP-keyed limits under the planned proxy, and a request limit shared by all signed-in users

- **Where:** `api/app/Domain/Accounts/AccountRateLimits.php:74,99,103`, `api/app/Domain/Catalog/RateLimits.php`,
  `api/routes/api.php:48`, and `api/config/accounts.php:13`, which documents the bucket collapse. `bootstrap/app.php`
  never calls `trustProxies`.
- **Problem:** The config comment admits the IP buckets collapse into one global bucket behind a proxy. Three
  consequences go beyond the sheet's gap row:
  1. **Shared request limit.** `ThrottleBeforeSession:account-guest` (60 a minute, keyed by IP) is the first
     middleware on the whole signed-in `/v1` group, not only on anonymous requests. Behind one proxy IP, all
     signed-in users together get 60 requests a minute. One script hitting `/v1/me` 60 times a minute takes the
     account API down for everyone. `public-read` (120) and `search` (60) collapse the same way.
  2. **Targeted lockout.** The login key is `email|ip`. With a collapsed IP, 5 wrong passwords a minute for a victim's
     email lock the victim out indefinitely.
  3. **The likely fix is unsafe.** `trustProxies(at: '*')` makes `X-Forwarded-For` attacker-chosen, so every request
     gets a fresh bucket and password guessing is unlimited.
- **Also with real client IPs:** IPv6 is keyed per /128, so one /64 is 2^64 buckets. The login limiter has no
  per-account bucket independent of IP.
- **Fix:**
  - `trustProxies(at: [<exact proxy CIDRs>], headers: Request::HEADER_X_FORWARDED_FOR)`.
  - Key IPv6 addresses by /64.
  - Add a per-email login bucket (for example 20 an hour), independent of IP, on the failure path only.
  - Apply `account-guest` only to requests that carry no authenticated session, and leave signed-in traffic to the
    per-user `account` tier.
- **Failing tests:**
  - Two requests from `2001:db8::1` and `2001:db8::2` share one `account-guest` bucket.
  - 21 failed logins for one email from 21 different IPs within an hour: the 21st answers 429.
  - 61 signed-in `/v1/me` requests from 61 different users behind one `REMOTE_ADDR` all answer 200.

### D2. Medium-Low: no CSP or framing protection on the SPA, and the error pages lose `nosniff`

- **Where:** `docker/nginx/pokeprices.conf:14`.
- **Problem:**
  - Only `X-Content-Type-Options` is set.
  - The `@api_unavailable` and `@payload_too_large` locations declare their own `add_header`, so by nginx's
    inheritance rule they drop the server-level `nosniff`.
  - There is no `Content-Security-Policy`, no `frame-ancestors` or `X-Frame-Options`, no `Referrer-Policy` and no
    `Permissions-Policy`.
- **Exploit:** An attacker's page frames `/account` invisibly and gets a signed-in user to click one-click actions:
  delete a webhook, turn an alert off, switch the channel to email, remove watchlist cards. With no CSP, any future
  XSS has no backstop.
- **Fix:** Use one `include`d snippet, repeated in every location that has its own `add_header`:
  `Content-Security-Policy "default-src 'self'; script-src 'self' 'sha256-<pre-paint inline script>'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'; form-action 'self'" always`,
  `X-Frame-Options DENY`, `Referrer-Policy no-referrer` (this keeps the reset page's token out of `Referer`), and a
  restrictive `Permissions-Policy`.
- **Failing test:** A `gates/` check that fetches `/`, `/account` and a forced 503 through nginx, and asserts all
  headers are present on all three.
- **ASVS sheet:** headers are listed as "not assessed in P4".

### D3. Info: secrets in API responses, exports, logs and error paths

I found no leak:

- **API responses:** the webhook secret appears once, in the 201 (`WebhookCreatedResource`), which is no-store.
  `GET /v1/webhooks` and the export select `url_hint` only. `url` and `secret` are `#[Hidden]`.
- **Logs:** `DeliverySender` logs a class name only. The `#[\SensitiveParameter]` markings cover the guard, signer and
  sender.
- **Error paths:** `ProblemDetails` never renders exception text, even with `APP_DEBUG=true`.
- **Committed secrets:** the only one in the repository is the Standard Webhooks spec test vector in
  `docs/ALERTS.md:133`.

**CSRF and cookies:**

- **Account API:** Sanctum verifies CSRF on stateful requests. A request that is not first-party never gets a session
  (`fromFrontend` matches `domain/*` exactly, so `pokeprices.localhost:8095.evil.tld` does not match), and answers 401.
- **Fortify routes:** use the `web` group's CSRF check.
- **Demo sign-in:** `POST /v1/demo/session` requires a session, so CSRF applies.
- **Cookie flags:** HttpOnly and SameSite=Lax are set. The missing Secure flag is the sheet's known `gap`.

---

## ASVS L1 evidence-sheet audit (`docs/security/asvs-l1.md`)

- **Counts:** 49 rows: 42 `pass`, 7 `gap`, 0 `na`.
- **Citations:** 88 of 89 cited tests still exist at this tip as `it(...)` declarations. None is skipped or marked
  todo.
- **Config and code:** the cited config values and code paths still exist.

### Rotted

| Sheet line | Claim | Problem |
|---|---|---|
| **58** | V8: only a verified, non-demo person may create alerts; "no route uses it yet" | **The cited test `is not attached to any route in P4` no longer exists.** P5 replaced it with `it('is attached to exactly the alert-creating routes'` (`api/tests/Feature/Accounts/AlertEligibilityTest.php:37`), which asserts `alerts.put`, `notifications.put`, `webhooks.store` and `webhooks.test`. The claim is now false: `api/routes/api.php` attaches `RequireAlertEligibility` to those 4 routes. The docblock at `api/app/Domain/Accounts/AlertEligibility.php:11` is stale too. Rewrite the row and cite the new test. |

### Weak (the evidence proves less than the row claims)

| Sheet line | Claim | What is wrong |
|---|---|---|
| 27 | The sign-in failure message is generic | `SessionTest.php:84` covers only a wrong password for a known email. Nothing asserts that an unknown email gets the same answer. A2's timing channel is also open. |
| 29 | A reset request does not reveal whether the account exists | The tests prove the bodies are byte-identical, not that the timing is (A2). |
| 30 | 12–72 characters and 72 bytes, on register **and reset** | On reset only the too-short case is tested (`PasswordResetTest.php:130`). |
| 57 | Deleting the account needs the current password | The second citation (`AccountDataTest.php:362`) is the demo's 403. The missing-password test at `:323` is not cited. |
| 59 | Wrong types get a validation error, never a 500 | `AccountEdgeTest.php:60` asserts only `>=400 && <500`, which also passes on 409, 419 or 429. |
| 60 | Variant key checked for shape **and existence**; list sizes bounded | `WatchlistKeyTest.php:63` covers shape only. The tests that cover existence and size (`WatchlistTest.php:122,133`, `PortfolioTest.php:156`) are not cited. |
| 61 | A per-person limit cannot be beaten by concurrent requests | **Wrong path:** `AccountEdgeTest.php:225` is the duplicate-email registration race. The tests that cover this are `PortfolioTest.php:270` and the `WatchlistPortfolioEdgeTest` "serialises two writers" test. |
| 68 | The export holds everything, and nothing secret | Completeness (`AccountDataTest.php:127`) is not cited. For the demo, the export also holds other people's data (A1). |
| 72 | No traces, messages or class names in errors | The debug-on test (`ProblemJsonEdgeTest.php:48`) checks only the message. Class and trace are checked only at the test environment's default `app.debug`. |

### Missing coverage

The sheet has no rows for P5's new endpoints: webhooks, alerts and notifications (anonymous 401, per-user isolation,
no-store, eligibility). It also has no row for the SSRF guard. These should be added before the sheet is used as P5
evidence.
