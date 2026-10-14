# ADR 4: Alert delivery: the deliverable day, signed webhooks, retries and the address guard

Status: Proposed

Date: 2026-10-14

Drafted during the PokéPrices P5 loop (proposed human gate HG-23). It counts only after Andres moves it to `docs/adr/`
and marks it Accepted. If he changes a decision, the orchestrator plans a follow-up task; the code already implements the
defaults below, and each question with its default is listed again in the last section. How the pieces work day to day is
in `docs/ALERTS.md`; the accounts they rely on are in ADR 3 and `docs/ACCOUNTS.md`.

## Context

Until P5, PokéPrices only recorded movement events: the ingest writes a `price_events` row for each variant whose daily
close moved by at least 5 % and $1.00 against the previous close (about 160 a day, ADR 1), and nothing sent them. P5 turns
them into messages for people who keep a card: signed webhooks, one Discord webhook, per-card alerts and one daily digest
by email or Discord. System Design section 8 is the spec (Standard Webhooks signing, retries with jitter, deduplication by
message id, an address guard, at-least-once delivery); this ADR records the choices that go beyond it or that a later
phase could quietly undo. The first of them was carried from P2 as R192: a crash repair re-ingests superseded days in
ascending order, each momentarily the newest finalized day, so the movement rule can write events for days that
originally fired nothing, and P5 had to decide whether those are ever delivered before any dispatcher existed.

The home server is shared and small, and one worker serves both the ingest and the deliveries. There is no mail provider
yet and no public address, which limits what is real at this stage.

## Decision: only the deliverable day is ever delivered (R192)

One class, `DeliverableDay`, decides which events may be delivered, and every fan-out reads events through it. Three days
are read from the data, all ignoring days after the app clock's UTC date: the **newest** finalized day (newest `ok` run),
the **floor** (newest `ok` or `superseded` run, which survives Replay's wipe because Replay only relabels runs) and the
**watermark** (newest day any dispatch has run for, in `dispatch_days`). A day is deliverable when it is not older than the
floor and not older than the watermark. The first dispatch of a day also records a **ceiling**, the highest
`price_events.id` of that day, and only events up to it are delivered.

Effect: on a first start only the newest day is sent. During a crash repair nothing is sent until the pre-crash newest day
is `ok` again, and after that only that day's events up to its ceiling. A day passed over by a catch-up, a day removed, a
future-dated run and events added to an already-dispatched day are skipped, and each skip but the last is logged at
warning. `pokeprices:alerts:dispatch --reset-watermark=<YYYY-MM-DD>` clears a watermark left by a mistake.

`price_events.dispatched_at` is **never stamped**. Stamping it would trip Replay's interlock (`replay --fresh` refuses once
any row is dispatched, R180a) and end the documented "Map rebuild then replay" procedure after the first message. Dispatch
state lives in `dispatch_days` and `deliveries`; P5 writes no price, run or event row.

## Decision: Standard Webhooks signing, and a secret shown once

A signed webhook receives `webhook-id`, `webhook-timestamp` and `webhook-signature` (`v1,` and the base64 HMAC-SHA256 of
`<id>.<timestamp>.<body>`), with the body stored and resent byte-identical. The secret is `whsec_` and the base64 of 32
random bytes, made on the server, stored encrypted with `APP_KEY`, and returned once in the `201` of `POST /v1/webhooks`.
It is never shown again by any endpoint, the export, a log or an error; to change it, a person deletes the webhook and adds
it again. Webhook URLs are stored encrypted too and only a `url_hint` (host, no path) is shown. A Discord webhook URL
carries a token, so it gets the same treatment.

## Decision: retries with equal jitter, at-least-once delivery, dedupe by `webhook-id`

A message has 6 attempts. After failed attempt *n* it waits `min(3600, 30 × 4^(n-1))` seconds with equal jitter. `408`,
`429`, `5xx`, connection errors, timeouts, unresolvable hosts and internal errors are retried; other `4xx` and redirects
are not. A `410` turns a webhook off, and so do Discord's `404` and `401`. Delivery is **at least once**: a lost
acknowledgement makes PokéPrices resend the same message with the same `webhook-id`, and the receiver drops duplicates by
that id. Exactly-once delivery is not promised.

Sends happen inside one short, bounded sweep job (`DeliverDue`, every minute, one at a time): at most 10 rows, 3 per
endpoint, within a 60-second budget, 2 seconds to connect and 5 in total, a 300-second claim lease and at most 64 KiB of
response read. A row claimed 12 times is failed `internal` without a send. Delivered and failed rows are kept 30 days, and
the account export lists the newest 500 deliveries. No exception message, URL, secret or address is ever logged.

## Decision: the address guard and the dev allow-list

A webhook URL must be `https`, without user-info or fragment, at most 2048 bytes, with a host that is a plain DNS name or a
canonical IP literal and that resolves only to addresses outside a fixed list of private, loopback, link-local, reserved,
shared (CGNAT), multicast and transition ranges (IPv4 and IPv6; `docs/ALERTS.md` lists them). The guard runs at
registration and again before every send; the request is pinned to the approved address, uses no proxy and never follows a
redirect. The only exceptions are exact `host:port` pairs in `WEBHOOK_PRIVATE_ALLOW`, which may also use `http`; on the dev
stack (`APP_ENV` `local`) the default is the local test receiver `api:8096` and nothing else.

## Decision: Discord is a notification channel, not a signed webhook

A person can register one Discord webhook (five signed webhooks besides). Signed webhooks receive every watched
`price.moved`; the Discord webhook receives only the person's alerts and daily digest, and only when they choose Discord.
The message is plain text in Discord's envelope with mentions switched off, cut to Discord's 2000 characters. The one send
to a real Discord endpoint is a command run once after Andres sets `DISCORD_TEST_WEBHOOK_URL` (HG-04); every other check
runs against a local receiver that verifies the signature.

## Decision: email through the log mailer until a provider exists

Email alerts and digests use the framework's default mailer, which is `log` here, so a message lands in the worker log, with
its recipient (the mailer's own output; the application writes no address to its own logs). The address is read when the
message is sent. A real provider is a later decision and changes nothing else in this ADR.

## Alternatives considered

- **Newest finalized day only, or a dispatch watermark only, for R192.** Rejected: the first is wrong during a repair (each
  old day is momentarily the newest) and the second would send every historical day on the first run. The floor and the
  ceiling close both gaps.
- **A created-at or run watermark.** Rejected: `price_events` has no `created_at`, and a repair run is an ordinary run.
- **A `dispatched` flag written by the ingest.** Rejected: it edits DetectMovement and `price_events`, which are frozen,
  and it trips Replay's interlock.
- **Exactly-once delivery.** Rejected: it needs the receiver's cooperation; at-least-once with `webhook-id` dedupe is the
  standard contract.
- **One job per delivery, or a parallel pool of requests.** Rejected: one worker serves the ingest too; a serial, bounded
  sweep keeps one code path for webhook, Discord and email and cannot starve the ingest.
- **Letting a person read the secret again.** Rejected: showing it once keeps it out of every later response, the export
  and every log; deleting a webhook and adding it again is cheap.
- **Sending Discord messages for every `price.moved`.** Rejected: a channel is for people, and a busy day has about 160
  events.
- **A hosted provider, a queue product or a new package.** Rejected: nothing in this phase needs one (no new dependency).

## Consequences

- **At-least-once means receivers must dedupe.** A receiver that does not will see a message twice after a lost
  acknowledgement.
- **A skipped day stays skipped.** If the scheduler is down for more than a day, the days in between are passed over, not
  sent late, and only the warning log says so. Events added to an already-dispatched day are never sent.
- **A name is frozen into a delivery.** The card name comes from PokéSearch when the delivery is created; while the
  catalogue is unavailable within 30 minutes of the day's finalize, creation waits, and after that it goes ahead with
  `Card <card_id>`.
- **DNS resolution has no timeout of its own.** A slow resolver can make one send overrun the 60-second sweep budget by the
  resolver's timeout.
- **The response body is buffered.** The HTTP client buffers it to a temporary stream until the 5-second limit; the 64 KiB
  cap bounds what is read, not what arrives within those seconds.
- **A webhook delete and a test send for the same webhook can race** on the foreign key from `deliveries` to
  `webhook_endpoints`; the test request can fail. The window is narrow.
- **A Discord webhook turned off after messages were created for it** ends those messages `no_channel`; they are not
  re-routed to email.
- **Discord message length is counted in code points**; Discord may count UTF-16 units, so text with many characters
  outside the basic plane could exceed the limit on Discord's side.
- **The dispatch reads every eligible person-and-event row of the deliverable day without a limit.** That is small at about
  160 events a day and would need batching at orders of magnitude more.
- **Rotating `APP_KEY` ends every stored webhook**: stored URLs and secrets can no longer be read, sends end `failed` with
  `internal` instead of looping, and the webhooks must be added again.
- **Query-string credentials.** `IgnoreQueryCredentials` removes `password`, `password_confirmation` and
  `current_password` from the query string, which closes the item left open in ADR 3 (question 14). Fortify's two-factor
  `code` and `recovery_code` and the password-reset `token` are still read from the query string if a client sends them
  there; they are outside this change and stay an open item.
- **The email address appears in the dev worker log** through the `log` mailer, until a provider exists.
- **One worker, one queue.** A burst of deliveries and an ingest share the worker; the sweep caps keep a sweep short, and
  an ingest queued meanwhile runs right after it.

## Open for Andres

Each question has a default the code already uses; none blocks the loop. They are quoted from the P5 planner report
(`.superpowers/sdd/P5-i1/stage-A/planner-report.md`).

| # | Question | Default |
|---|---|---|
| 1 | Crash-repair delivery (R192): deliver only the deliverable day (newest finalized day, not older than the floor or the watermark, events up to the ceiling). A day not dispatched before the next one finalizes is skipped and logged at warning; events added to an already-sent day are never sent | As stated |
| 2 | Never stamp `price_events.dispatched_at` (it would lock `replay --fresh` for good) | Never stamp |
| 3 | The local test receiver is `php -S` in the `api` container on the compose-internal port 8096, allow-listed only when `APP_ENV` is `local` | As stated |
| 4 | Discord is a notification channel; signed webhooks get every watched `price.moved` | As stated |
| 5 | Alert thresholds are whole numbers from 5 to 100 percent, on watchlist items only | As stated |
| 6 | No empty digests: no event, no digest | No empty digests |
| 7 | The signing secret is shown once and stored encrypted with `APP_KEY`; rotating `APP_KEY` means adding webhooks again (their sends end `failed` with `internal` instead of looping) | As stated |
| 8 | 6 attempts, 30 s × 4^(n-1) capped at one hour with equal jitter; other `4xx` not retried; `410` (and Discord's `404` and `401`) turn a webhook off; sweeps of 10 rows (3 per endpoint), 2 s connect, 5 s total, a 60 s budget | As stated |
| 9 | The password in the query string is ignored (`IgnoreQueryCredentials`); closes ADR 3 question 14 | Done |
| 10 | The account export is version 2: alert thresholds, notification settings, webhooks without URL or secret, and deliveries without bodies | As stated |
| 11 | A new `/alerts` page, linked from `/account`; the header is unchanged | As stated |
| 12 | No new dependency, composer or npm | None |
| 13 | The orchestrator creates and deletes one throwaway `example.com` account on the dev database for the sink and browser checks, as in P4 (R211), extended to P5 | Yes |
| 14 | Days dated after the app clock's UTC date are ignored by the deliverable-day rule, so one bad future day cannot poison the watermark; a past-dated mistake is cleared with `--reset-watermark` | As stated |
| 15 | Delivered and failed rows are kept 30 days; the export lists the newest 500 deliveries | As stated |
