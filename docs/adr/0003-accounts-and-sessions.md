# ADR 3: Accounts, sessions and the read-only demo

Status: Accepted (Andres, 2026-10-14)

Date: 2026-10-13

Drafted during the PokéPrices P4 loop (proposed human gate HG-22). Accepted by Andres on 2026-10-14. If he changes a decision, the orchestrator plans a
follow-up task; the code already implements the defaults below, and each question with its
default is listed again in the last section. How the pieces work day to day is in
`docs/ACCOUNTS.md`; the security evidence is in `docs/security/asvs-l1.md`.

## Context

Until P4, PokéPrices had no people in it: every page was public, and nothing was stored per
visitor. P4 adds accounts so a person can keep a watchlist and a portfolio of card variants, with
an honest price on every row. The feasibility report asks for Sanctum plus Fortify, email and
password only, an invite-only start with a demo account, and one hostname. The System Design (§7)
is the spec; this ADR records the choices that go beyond it or that a later phase could quietly
undo.

The home server is shared, small and not yet public. There is no mail provider, no https and no
proxy configuration for client addresses, which limits what can be hardened at this stage.

## Decision: invite-only registration with one shared code

Registration requires an invite code, read from the `INVITE_CODE` environment variable (human
gate HG-03: Andres sets it in `api/.env`; it is never committed). The code is checked before the
email or the password is examined, with a constant-time comparison, and a bad or missing code
answers one error, `invite_code`. With no code configured, nobody can register. Public signup is
a later phase (P7), and Turnstile on signup belongs to the Deploy prompt.

## Decision: same-origin Sanctum cookie sessions, no tokens

The app and the API share one hostname, so a Sanctum cookie session works without CORS or
`SESSION_DOMAIN`. Sanctum's stateful middleware is attached only to the account routes (and the
demo entry), not to the `/v1` group as a whole, so public reads never start a session or set a
cookie and keep their caching. Writes carry Laravel's CSRF token through the `XSRF-TOKEN`
cookie. There are no personal access tokens: an `Authorization: Bearer` header is never read.
The session store is Postgres in development.

## Decision: Fortify, headless, with its root paths outside the `/v1` contract

Fortify supplies registration, sign-in, password reset, email verification and optional TOTP
two-factor sign-in with views turned off. Its routes stay at Laravel's root paths (`/login`,
`/register`, `/user/…` and the rest), which nginx already sends to Laravel, so the app's own
pages use other paths (`/sign-in`, `/sign-up`, `/account`, `/password/…`). The `openapi/api.json`
contract stays `/v1` only; the session endpoints are documented in `docs/ACCOUNTS.md`, and a test
asserts none of them leaks into the contract. A signed-in person who posts to a guest route gets
a `409` problem, never a redirect (Laravel's own guest middleware always redirects, and a
browser would follow it into the app's HTML). A forgotten-password request answers the same `200`
whether or not the address has an account.

## Decision: a read-only demo, enforced on the server, entered with one click

A seeded demo account lets a visitor look at a watchlist and a portfolio without an invite. It is
shared, so it is read-only on the server: a middleware refuses every write (`403`, the problem
type `demo-read-only`) except signing out, including Fortify's own write routes, and a password
reset can never change its password. The page hides write controls too, but no test relies on
that. The entry is `POST /v1/demo/session`, limited per IP; the demo's other requests have their
own tier. There is no committed demo password: unset, the seeder stores a random one and the
demo is entered only with the button.

## Decision: watchlist and portfolio per variant, with no foreign key to `card_variants`

Both lists hold variant keys, not cards, because a price belongs to a variant. The tables have a
foreign key to `users` (with cascade) and none to `card_variants`: that table is append-only and
the mapping tests drop and re-create it, so a key would break them; the write path checks that the
variant exists instead. A portfolio holds a quantity only (no cost basis). Limits are 100 variants
per list and 9999 per quantity, enforced under a per-user lock so two requests cannot pass the
limit together.

## Decision: idempotent-in-effect writes instead of an `Idempotency-Key` header

System Design §6 asks for idempotency on writes. Every P4 write is idempotent in effect: a PUT
sets, a DELETE removes (and answers `204` when nothing was there), the demo entry signs the demo
in again, and an account cannot be deleted twice. A header would add storage and rules for no
extra safety, so it waits for the first write that is not naturally idempotent (alerts, P5).

## Decision: values are computed by the API

A holding's value and the portfolio total are Postgres `numeric` results sent as decimal strings.
The browser never multiplies or adds money. A holding without a price has no value, is left out of
the total and is counted as excluded; the total names the first and last day it rests on. Each
row carries its own `as of` line from the data.

## Decision: account deletion is a POST

Scramble documents the validated fields of a DELETE as query parameters, which would put the
password in a URL (and so in logs and browser history). Deletion is therefore
`POST /v1/me/deletion` with the password in the JSON body, rate limited per user. A test asserts
that no operation in the contract takes a `password` parameter.

## Alternatives considered

- **Sanctum personal access tokens or an API key.** Rejected: nothing programmatic needs them yet.
  Bearer headers stay unread.
- **A second hostname for the API, with CORS.** Rejected by the feasibility report's hostname
  decision; one origin removes a class of cookie and CSRF problems.
- **Stateful middleware on all of `/v1`.** Rejected: it would give public price reads a session and
  a cookie and break their shared caching.
- **Public signup, with Turnstile, from the start.** Rejected for P4: invite-only is the safer
  first exposure (feasibility Answer 4); Turnstile needs the Deploy prompt.
- **Sign-in with a social provider.** Rejected at this stage (Socialite is P7); email and password only.
- **A foreign key from the lists to `card_variants`.** Rejected: see above.
- **A demo whose writes are blocked only in the page.** Rejected: the server is the boundary.
- **A breached-password check or argon2id.** Rejected at this stage: the check calls the network, and
  bcrypt (the framework default) with a 12 to 72 character rule is enough for an invite-only
  start.
- **An `Idempotency-Key` header.** Deferred, see above.

## Consequences

- **The `Referer` and `Origin` dependency.** Sanctum treats a request as first-party from its
  `Origin` or `Referer` header. The app sets `referrerPolicy: 'same-origin'` on account requests,
  and a request without either header gets no session (`401`, or `419` for the demo entry). A
  browser setup that strips both would not be able to sign in.
- **Cookie and proxy hardening wait for the Deploy prompt.** The session cookie has no `Secure`
  flag until the site is served over https, Turnstile is not installed, and `trustProxies` is not
  scoped. Until then every IP-keyed rate limit (registration, the anonymous account limiter, the
  demo entry, the demo's own tier and the two anonymous read tiers) is in practice one global
  bucket, because nginx's address is the only client address the API sees. Pest proves the
  per-IP keying; the dev topology cannot. The anonymous account limiter also counts signed-in
  requests, so all account traffic on that topology shares 60 a minute.
- **A reset request never reveals whether an account exists**, and neither does registration
  without a valid invite code. The cost is that a person who mistypes their address gets no hint.
- **A password reset ends every other session** of that person, and deletion removes the account,
  its lists, its sessions and its reset tokens in one transaction. The export holds everything
  kept about a person, including session records (last activity, address, browser), and no secret.
- **Mail goes to the log** until a provider is configured. A link in the log is a credential and
  must not be pasted anywhere.
- **The demo is shared**, so one visitor can use up the demo's request budget for others on a
  shared address; the per-IP keying of that budget helps only once real client addresses are
  visible.
- **Email addresses are case-folded** before validation, the reset broker and the limiter key, and
  the database has a unique index on `lower(email)`, so case never splits an account.
- Alerts, webhooks and digests (P5) will require a verified email; P4 builds only the check
  (`AlertEligibility`), attached to no route.

## Open for Andres

Ruled 2026-10-14 (R225): all defaults below stand as accepted.

Each question has a default the code already uses; none blocks the loop. Q1 to Q12 are quoted
from the P4 planner report (`.superpowers/sdd/P4-i1/stage-A/planner-report.md`); items 13 to 16
were found during the build.

| # | Question | Default |
|---|---|---|
| 1 | One-click demo (`POST /v1/demo/session`), `DEMO_PASSWORD` optional (unset = random). Its per-IP limit is global until Deploy | Yes |
| 2 | Watchlist and portfolio per variant, not per card | Per variant |
| 3 | The portfolio stores a quantity only (no cost basis, gain or loss) | Quantity only |
| 4 | Static Watchlist, Portfolio and Account header links (no session fetch on public pages) | Static |
| 5 | No breached-password check (the check is a network call); 12 characters to 72 bytes, bcrypt | No check until Deploy |
| 6 | §6 "idempotency keys": every state-changing `/v1` call is idempotent in effect; an `Idempotency-Key` header is deferred to P5 | Defer |
| 7 | Display preferences stay in `localStorage` | Yes |
| 8 | Limits of 100 watchlist variants, 100 portfolio variants, quantity 1 to 9999 | As stated |
| 9 | `users.name` is nullable and never collected | Yes |
| 10 | The ASVS L1 evidence document is kept; requirement ids only if verifiable | Kept; no ids until the official list is on hand |
| 11 | Playwright stays parked | Parked |
| 12 | The orchestrator creates and deletes one throwaway `example.com` account on the dev database for browser checks | Yes |
| 13 | Two-factor setup has no cancel: leaving `/account` mid-setup abandons an unconfirmed secret (the page shows nothing of it any more), and turning two-factor on again makes Fortify regenerate the secret. A `Cancel` control needs a new string and a call that discards an unconfirmed secret | No cancel; re-enable regenerates |
| 14 | `POST /v1/me/deletion` reads merged input, so a password sent as a query parameter is accepted. The hardening is to read the JSON body only, and it is an open item; the app never sends it that way | Open; body-only reading when scheduled |
| 15 | Implementation note R219(c): the framework's local-disk file serving is turned off (`serve` false in `config/filesystems.php`), because its `PUT storage/{path}` route would be a registered write route the demo test could not cover; nginx routes no `/storage` path and PokéPrices serves no files | Off |
| 16 | Implementation note R221: the two card-page controls (`Add to watchlist`, `Add to portfolio`) are `input type="button"` elements, so the card page's pinned row text and list structure stay unchanged; they are real buttons with accessible names and 44 px targets | As built |
