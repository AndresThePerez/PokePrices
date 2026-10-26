# PokéPrices alerts and webhooks

How PokéPrices tells a person about the cards they keep: signed webhooks, one Discord webhook, per-card alerts and one
daily digest. Every message is about a **daily close**: a price moves once per collected day, and each message names its
source and the day of the close it is about. Nothing here is a continuous quote. How the prices and the movement events
get into the database is in [`OPERATIONS.md`](OPERATIONS.md); who may use alerts is in [`ACCOUNTS.md`](ACCOUNTS.md); the
`/alerts` page is in [`WEB.md`](WEB.md). The decisions behind the delivery rules are drafted in
`docs/adr/drafts/0004-alert-delivery.md`.

Every artisan line below carries the compose prefix, so it can be pasted as written. The names of environment variables
are given, never their values.

## What is sent

The movement rule is `draft-v1`: a variant's daily close moved by at least 5 % **and** at least $1.00 against the
previous close of the same series (`config('pricing.movement')`, `pct` `5.00` and `abs` `1.00`). The ingest writes one
`price_events` row for every variant that crosses it (about 160 a day: ADR 0001 measured a mean of 163 and a range of
141 to 197, see `docs/measurements/movement-volume.md`). Alerts only read those rows. They never write a price, a run or
an event, and never stamp `price_events.dispatched_at`.

Four kinds of message exist. Each is one row in `deliveries`.

| Kind | Channel | What it is |
|---|---|---|
| `price.moved` | signed webhook | One event of the deliverable day (see "Crash repair and the deliverable day"), as JSON. |
| `alert.triggered` | email or Discord | One event on a card whose per-card threshold the move reached. Plain text. |
| `digest` | email or Discord | One message per person per collected day listing their moves. Plain text. |
| `webhook.test` | the endpoint tested | A test message that carries no price. |

**The `price.moved` body.** JSON written once, stored as the exact bytes, and resent byte-identical on every retry. The
keys come in this order:

```
{"type": "price.moved", "timestamp": "<the event's as_of, RFC 3339 UTC to the second>",
 "data": {"event_id": "evt_<id>", "variant_key": "…", "card_id": "…",
          "card": {"name": "…", "set_name": "…", "number": "…"} | null,
          "direction": "up" | "down", "close": "…", "previous_close": "…", "change": "…", "change_pct": "…",
          "day": "YYYY-MM-DD", "as_of": "<RFC 3339 UTC>", "previous_day": "YYYY-MM-DD",
          "previous_as_of": "<RFC 3339 UTC>", "series_epoch": <integer>, "rule": "draft-v1",
          "source": "tcgplayer", "feed": "tcgcsv", "basis": "market", "currency": "USD", "cadence": "daily"}}
```

- Money and `change_pct` are the database's decimal text (`"968.91"`), never a float and never re-rounded.
- `timestamp` is the event's own `as_of`, never the time of sending. `message_id` (the `webhook-id` header) is
  `msg_` followed by a ULID.
- `card` comes from the PokéSearch catalogue when the delivery is created. When the catalogue is unavailable it is
  `null` and the name is not retried: a name is frozen into the delivery. A message is never compared across
  `series_epoch`: events are written within one epoch.
- `webhook.test` has `{"type": "webhook.test", "timestamp": …, "data": {"message": "PokéPrices test message. It carries
  no price."}}`. Its `timestamp` is the time the test was queued, the only message whose time is a clock time.

**The text messages.** One renderer builds the text for email and for Discord (Discord gets the text without the
subject line).

- Alert subject: `PokéPrices alert: <card> moved <+|-><pct>% (<day>)`. The body has the card and variant, `Market price
  $<close>`, the change line (`▲ +$<change> (+<pct>%) vs previous close (<previous day>)`, or `▼ -$…` for a fall),
  `as of <day> from TCGCSV (daily close)`, `Your alert threshold: <n>% (and at least $1.00).` and a link to the card
  page. The 5 % and $1.00 are read from `config('pricing.movement')`.
- Digest subject: `PokéPrices daily digest: closes of <day>`. The body opens with the rule and the `as of` line, then
  one line per move (`<card> · <variant>: $<previous close> → $<close>, <change line>`), largest percentage move first,
  at most 25 lines (`config('alerts.digest.max_lines')`), then `…and <k> more: <APP_URL>/watchlist` when cut, then
  `TCGplayer market prices via TCGCSV, collected once a day.`
- A card the catalogue cannot name is `Card <card_id>`. Money is grouped with commas (`$1,234.50`) from the stored text.
  The texts show dates only, never a time of day.

## Who receives it

An **eligible** person has a verified email address and is not the demo account. The **watched set** of a person is
their watchlist together with their portfolio.

- **`price.moved`:** each deliverable event goes once to each active signed webhook of each eligible person whose
  watched set holds the event's variant. A Discord webhook never receives `price.moved`. A disabled webhook receives
  nothing new.
- **`alert.triggered`:** a watchlist row can carry a threshold, a whole number from 5 (the movement rule's percentage,
  rounded up) to 100. When a deliverable event of that variant has `abs(change_pct)` at or above the threshold, the
  person gets one alert. Portfolio holdings carry no threshold, so a card held but not watched gets no alert (it can
  still appear in the digest). There is no cooldown: prices change once a day.
- **`digest`:** a person who chose `Send me a daily digest` gets one message per collected day when at least one
  deliverable event is in their watched set. No event, no digest. A variant on both lists counts once.
- **Channel.** `PUT /v1/me/notifications` with `{"channel": "email" | "discord", "digest": true | false}` chooses where
  alerts and the digest go; with no settings row the person has `email` and no digest. `discord` needs an active
  Discord webhook (422 `Add a Discord webhook first.` otherwise). The channel is decided when the delivery is created:
  `discord` with an active Discord webhook goes there, anything else goes by email. Deleting the Discord webhook sets the
  channel back to `email`. A Discord webhook that is turned off after a message was created ends that message
  `no_channel`; it is not re-routed to email.
- **Email.** The message goes to the address the person has when it is sent, not when it was created. No mail provider is
  configured, so the `log` mailer writes the message to the worker log (`docker compose -f compose.dev.yml logs worker`),
  recipient included. That output is the mailer's own, not an application log line; the application itself never writes
  an email address to a log. Eligibility is checked when a delivery is created, not again when it is sent.
- **The demo account cannot set alerts or add webhooks and never receives anything: it has no email address or Discord of its own.**
  Its writes are refused with `403` `demo-read-only`. An unverified account is refused with `403` `email-not-verified`
  on `POST /v1/webhooks`, `POST /v1/webhooks/{id}/test`, `PUT /v1/alerts/{variantKey}` and `PUT /v1/me/notifications`
  (reading its lists and deleting still work).
- **Late watchers.** Fan-out is idempotent: a person who starts watching a variant after the first dispatch of the day
  gets that day's messages on the next dispatch run, for events up to the day's ceiling only.
- **Limits per person:** five signed webhooks and one Discord webhook (a disabled one still counts).

## Signed webhooks

A signed webhook follows the Standard Webhooks signing scheme.

- **Register.** `POST /v1/webhooks` with `{"kind": "standard", "url": "https://…", "description": "…"}` answers `201`
  with the endpoint and its secret. `GET /v1/webhooks` lists endpoints (`id`, `kind`, `url_hint`, `description`,
  `created_at`, `disabled`, and the status of the last delivery). `DELETE /v1/webhooks/{id}` answers `204`, also for an
  unknown id or another person's (existence is never revealed); all of the endpoint's deliveries, delivered and failed
  ones too, go with it (the foreign key cascades), so they also leave the export.
  `POST /v1/webhooks/{id}/test` queues a test message (`202` with its `message_id`; five a minute per person; `404`
  `webhook-not-found`; `409` `webhook-disabled`). The same actions are on the `/alerts` page.
- **The secret.** `whsec_` followed by the base64 of 32 random bytes, made on the server and stored encrypted with
  `APP_KEY`. It is returned **once**, in the `201` body, and never again: not by the list, not by the export, not in a log
  or an error. To change it, delete the webhook and add it again. The URL is stored encrypted too and only its
  `url_hint` (`https://<host>/…`, no path and no query) is ever shown back. If `APP_KEY` is ever rotated, stored URLs and
  secrets can no longer be read; sends to them end `failed` with `internal` and the webhooks must be added again.
- **Headers on every attempt.**

  | Header | Value |
  |---|---|
  | `webhook-id` | the delivery's `message_id`; the same on every attempt |
  | `webhook-timestamp` | Unix seconds of this attempt |
  | `webhook-signature` | `v1,` followed by the base64 of the HMAC-SHA256 of `<webhook-id>.<webhook-timestamp>.<body>` |
  | `Content-Type` | `application/json` |
  | `User-Agent` | `PokePrices-Webhooks/1` |

- **Verifying a delivery.** Use the raw request body, byte for byte.
  1. Take the secret, drop the `whsec_` prefix and base64-decode the rest: that is the key.
  2. Build `"<webhook-id>.<webhook-timestamp>.<body>"` and compute the HMAC-SHA256 with the key.
  3. Base64-encode it and compare, in constant time, with each space-separated `v1,<signature>` value of the header.
  4. Reject a timestamp more than five minutes from your clock (the local sink does).
  5. Drop a `webhook-id` you have already handled (see "Retries and duplicates").

  In PHP: `'v1,'.base64_encode(hash_hmac('sha256', "$id.$timestamp.$body", base64_decode(substr($secret, 6)), true))`.
  The reference vector of the Standard Webhooks specification (published, not a secret of ours): secret
  `whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw`, id `msg_p5jXN8AQM9LWM0D4loKWxJek`, timestamp `1614265330` and body
  `{"test": 2432232314}` give `v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=`.
- **What the receiver should answer.** Any `2xx` means delivered. `408`, `429` and `5xx` are retried. Any other `4xx` is a
  failure with no retry. A `3xx` is a failure (`redirect`) and the `Location` is never requested. A `410` also turns the
  webhook off (`disabled` true in the list, shown on `/alerts`): no new message is created for it, and its other
  pending messages end `failed` with `no_channel` without a request.
- **Known limit.** Deleting a webhook at the same moment a test message is queued for it can race on the foreign key from
  `deliveries` to `webhook_endpoints`: the insert of the test message can violate it, and that one test request then
  fails with a server error. The window is narrow, and the delete itself is not affected.

## Discord

Discord is a notification channel, not a signed webhook: it receives alerts and the digest when the person chooses
Discord, never `price.moved`.

- **Register.** `POST /v1/webhooks` with `{"kind": "discord", "url": "https://discord.com/api/webhooks/<id>/<token>"}`.
  The host must be exactly `discord.com`, `discordapp.com`, `ptb.discord.com` or `canary.discord.com`, the path must match
  `/api/webhooks/<digits>/<token>`, and there is no query, user-info or port. The URL carries a token, so it is stored
  encrypted and treated as a secret: the list shows only `https://<host>/…`, and no log, export or error holds it. A person
  can have one Discord webhook. The `secret` field of the `201` is `null`.
- **The message.** `{"content": "<text>", "username": "PokéPrices", "allowed_mentions": {"parse": []}}`, with no signature
  headers. Mentions are switched off.
- **Length.** Discord accepts 2000 characters. A longer text is cut on a line boundary and ends with `…and <k> more`; a
  digest keeps as many lines as fit and its footnote. PokéPrices counts Unicode code points, while Discord may count
  UTF-16 units, so a text full of characters outside the basic plane (emoji) could in theory still exceed the limit
  on Discord's side. Card names are not expected to hold any.
- **Turned off by Discord's answers.** A `404` or `401` from a Discord webhook (Discord's answer for a deleted webhook or a
  bad token), like a `410`, turns the webhook off. A `429` with `Retry-After` is honoured (capped at one hour).
- **The Discord check (HG-04).** `DISCORD_TEST_WEBHOOK_URL` is set by Andres in `api/.env`; it is never committed and no
  agent reads it. Unset, the command prints `DISCORD_TEST_WEBHOOK_URL is not set; nothing was sent.` and exits 1. Set, it
  checks the URL as a Discord webhook, sends one test message synchronously (the text `PokéPrices test message.` and the
  three largest moves of the deliverable day, or `No card crossed the movement rule on <day>.`, or `No finalized day yet.`)
  and prints `{"status": <HTTP status>, "delivered": <bool>}`, exit 0 on a `2xx`. It never prints or logs the URL.

  ```sh
  docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:alerts:discord-test
  ```

## Retries and duplicates

Delivery is **at least once**. A receiver that answers slowly, or whose acknowledgement is lost, will see the same message
again with the same `webhook-id`. **A receiver must drop a `webhook-id` it has already handled.** PokéPrices never
promises exactly once. Three layers keep duplicates to that case:

- the unique indexes on `deliveries` (one `price.moved` per event and endpoint, one `alert.triggered` per person and event,
  one `digest` per person and day), so running the dispatch any number of times creates each message once;
- the sender acts only on a row that is still `pending` and whose lease is the one its own claim set, so a delivered
  message is never sent again by PokéPrices;
- the receiver's own dedupe on `webhook-id`.

**Attempts.** At most 6 attempts per message. After failed attempt *n* the next attempt waits
`min(3600, 30 × 4^(n-1))` seconds with equal jitter (half of it plus a random amount up to the other half): 30, 120, 480,
1920 and 3600 seconds before jitter, so the first delay is 15 to 30 seconds. A `Retry-After` on a `429` or `503` raises the
delay to that value when it is larger, capped at an hour. Retried: connection errors, timeouts, an unresolvable host, an internal error, and
HTTP `408`, `429` and `5xx`. A message ends `delivered` or `failed`. `last_error` is one of `connection`, `timeout`,
`http_status`, `redirect`, `blocked_address`, `unresolvable`, `no_channel` or `internal`.

**The sweep.** One worker serves ingest too, so a sweep is short and bounded. `DeliverDue` runs every minute and only one
sweep is queued or running at a time (`ShouldBeUnique`, 120 seconds). In order it:

1. retires pending rows claimed 12 times (twice the attempt limit) as `failed` with `internal`, and deletes `delivered` and
   `failed` rows older than 30 days;
2. claims at most 10 due rows, at most 3 per endpoint, oldest first, and sets a 300-second lease on each;
3. sends them one after another, in the job itself, while less than 60 seconds have passed. When the budget is spent, or an
   endpoint timed out or refused the connection, the rest of the claimed rows of the sweep (or of that endpoint) are
   released unsent, with no attempt counted.

Each send has a 2-second connect timeout and a 5-second total timeout; an email send waits at most 5 seconds on the SMTP
server (`config('mail.mailers.smtp.timeout')`). `attempts` counts sends that were actually made, and
`claims` counts claims. The response body is read at most up to 64 KiB (65,536 bytes) and then closed. It is never
decompressed: a `gzip` answer is read as the bytes that came over the network. The HTTP client
buffers the response to a temporary stream until the 5-second limit, so the 64 KiB cap bounds what is read, not what the
network may deliver within those 5 seconds. DNS resolution has no timeout of its own in PHP: a slow resolver can make one
send overrun the 60-second budget by the resolver's own timeout (the budget is "60 seconds plus one send"). The sweep job
itself times out after 80 seconds, below the queue's 90-second `retry_after`, and is then failed rather than handed out
again while it still runs.

**No exception reaches a log.** A send never reports or rethrows an exception: an error is recorded as the attempt's
`last_error` (`internal` for an unexpected one) and logged as a class name only. When recording the outcome itself fails,
the sweep goes on with the next row; that row keeps its 300-second lease and is sent again, with the same `webhook-id`,
after it, and a `delivery_outcome_unrecorded` warning carries only the delivery id and the class name. No log line carries a URL, `url_hint`,
secret, signature, body or email address, and a send never adds a row to `failed_jobs`.

**What is kept.** Delivered and failed rows are kept for 30 days (`config('alerts.delivery.retention_days')`). The account
export (`GET /v1/me/export`, version 2) lists the newest 500 deliveries (`config('alerts.export_deliveries_limit')`): with
30-day retention that is everything kept in practice. It never holds a message body, a webhook URL or a secret.

**Load.** The dispatch reads the eligible person and event rows of the deliverable day without a limit. At about 160
events a day that is small; a day with orders of magnitude more events would need batching.

## Address guard

A webhook URL is checked when it is registered and again before every send, by `UrlGuard`. A URL is allowed when all hold:

- the scheme is `https` (`http` only for an allow-listed `host:port`);
- there is no user-info, no fragment and no backslash, it has at most 2048 bytes, and it has a host;
- the host is a plain DNS name or a canonical IP literal. Decimal, octal, hexadecimal and short IPv4 forms (`2130706433`,
  `0x7f000001`, `127.1`) and a trailing dot are refused unseen;
- the host is not an IP literal in a blocked block, and it resolves (A and AAAA) to at least one address, **every**
  resolved address being outside every blocked block. An IPv4 address carried inside an IPv6 one is checked as IPv4 too.

**Blocked blocks** (abbreviated: missing IPv4 octets are zero). IPv4: `0/8`, `10/8`, `100.64/10` (including Tailscale),
`127/8`, `169.254/16`, `172.16/12`, `192.0.0/24`, `192.168/16`, `198.18/15`, `224/4`, `240/4`. IPv6: `::/128`, `::1/128`,
`::ffff:0:0/96`, `64:ff9b::/96`, `2002::/16`, `fc00::/7` (including Tailscale's range), `fe80::/10`, `ff00::/8`. The
documentation ranges of RFC 5737 (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`) are not blocked.

- **Allow-list.** `WEBHOOK_PRIVATE_ALLOW` is a comma-separated list of exact `host:port` pairs. An allow-listed pair skips
  resolution and the block check and may use `http`. Nothing else may. When the variable is unset, the allow-list is
  `api:8096` on the dev stack (`APP_ENV` `local`, see "The local sink") and empty everywhere else.
- **The address checked is the address used.** The request is pinned to the address the guard approved (cURL's resolve
  option), is sent without a proxy from the environment, never re-resolves and never follows a redirect.
- **Answers.** Registration answers `422` under `errors.url`: `Use an https URL.`, `PokéPrices does not send to private,
  local or reserved network addresses.`, `This host name does not resolve.`, the Discord shape message or `The URL is too
  long.` A send records `blocked_address` (no retry) or `unresolvable` (retried).

## Crash repair and the deliverable day

A crashed replay is repaired by ingesting the superseded days again in ascending order, so each repaired day is
momentarily the newest finalized day and can get movement events for days that originally fired nothing
(see `OPERATIONS.md`, "A crashed replay"). Sending those would be sending a stale day. One class, `DeliverableDay`, decides
which events may ever be delivered, and every fan-out reads events through it and nothing else.

**The rule.** Three days are read from the data, all ignoring days after the app clock's UTC date (no close is dated after
the day it was collected):

- the **newest** finalized day: the newest day of an `ok` run;
- the **floor**: the newest day of an `ok` or `superseded` run, that is the newest day ever finalized and not failed. It
  survives a replay's wipe, which only relabels runs `superseded`;
- the **watermark**: the newest day any dispatch has run for (`dispatch_days`).

A day is deliverable when the newest day exists, is not older than the floor and is not older than the watermark. In
addition, the first dispatch of a day records its **ceiling**, the highest `price_events.id` of that day, in
`dispatch_days.event_id_ceiling`, and only events of that day with an id up to the ceiling are delivered. Ids only grow,
so an event written for a day after its first dispatch is never delivered.

**Why this rule.** Four simpler rules were rejected:

- *the newest finalized day only* does not help during a repair, where each old day is momentarily the newest;
- *a dispatch watermark only* never re-sends an old day but, on the first run, would send every historical day;
- *a created-at or run watermark* needs a time on events that `price_events` does not have, and a repair run is an
  ordinary run, indistinguishable from a first ingest;
- *a flag written by the ingest* edits DetectMovement and `price_events`, which are frozen.

The chosen rule also avoids stamping `price_events.dispatched_at`. Stamping it would trip Replay's interlock (`replay
--fresh` refuses once any row is dispatched) and end the documented "Map rebuild then replay" procedure on the dev
database after the first message.

**What is skipped, and why.** Each case is logged as an `alerts_dispatch_skipped` warning in the worker log
(`docker compose -f compose.dev.yml logs worker`) with a `reason`, the `days` concerned, and the newest day, floor and
watermark:

| What is skipped | Why | `reason` |
|---|---|---|
| Older days re-finalized by a crash repair, until the pre-crash newest day is `ok` again (after that, only that day's events, up to its ceiling) | the newest day is older than the floor | `repair in progress` |
| Days a catch-up passes over: `ok` days between the watermark and the newest day (the scheduler was down for more than a day) | only the newest day is dispatched; the day is skipped, not sent late | `days passed over` (lists them) |
| A newest day older than the last dispatched day (a day removed) | the newest day is older than the watermark | `newest older than the last dispatched day` |
| An `ok` or `superseded` run dated after the app clock's UTC date | it is ignored by all three terms, so a future-dated run cannot freeze the floor | `future-dated run ignored` |
| Events added to an already-dispatched day (a repair re-finalizing it with crossings it did not have before, or a re-ingest) | their ids are above the ceiling | none: they are never selected |
| Everything, for one run, while the catalogue is unavailable within 30 minutes of the day's finalize | names are frozen into the delivery; retried on the next tick, then created with `card` `null` | `catalog degraded` |

On a first start with no watermark, only the newest day is sent, and its ceiling is recorded then. A day with no event is
recorded with a null ceiling and sends nothing. A day first dispatched after a repair gets its ceiling from the events the
repair wrote for it, and only if it is then the newest finalized day.

**The operator's reset.** A watermark left by mistake (for example a day whose runs are gone, so the newest finalized
day is older than the watermark and nothing is deliverable) is cleared with:

```sh
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:alerts:dispatch --reset-watermark=2026-10-12
```

It deletes the source's `dispatch_days` rows dated after that day, prints `{"reset_after": "2026-10-12", "deleted": <n>}`
and dispatches nothing. It refuses (exit 1, nothing deleted) a malformed date, a date after the newest finalized day, or a
database with no finalized day. A day it re-opens is deliverable again only if it is the newest finalized day, and a
message that already exists is never created again, so a reset never duplicates. Do not use it to send a skipped day
late: the rule skips it on purpose.

**Volume.** The dispatch line reports `events` (deliverable events of the day) beside `measured` (that day's
`movement_volume` `up + down` for `draft-v1`). On a day first ingested as it was collected they are equal.

## The local sink

`scripts/webhook-sink/sink.php` is a standalone receiver that verifies the Standard Webhooks signature without loading any
application code, so the check is independent of the sender. It answers `401` when the check fails, and, for a verified
request, `503` for the first `SINK_FAIL_FIRST` attempts of each `webhook-id` and `204` after that (failure injection).
It writes one JSON line per request (`id`, `attempt`, `verified`, `answered`, `duplicate_after_success`) and never the
secret, a signature or a body.

It runs inside the `api` container on the compose network only (port 8096, nothing published). On the dev stack
`api:8096` is allow-listed by default, so `http://api:8096/hook` can be registered as a signed webhook. Put the secret shown
once at registration into the git-ignored `.gates/tmp/P5-sink/secret` file, never into a report or chat.

```sh
docker compose -f compose.dev.yml exec -T -e SINK_SECRET_FILE=/workspace/.gates/tmp/P5-sink/secret -e SINK_LOG=/workspace/.gates/tmp/P5-sink/log.jsonl -e SINK_FAIL_FIRST=2 api php -S 0.0.0.0:8096 /workspace/scripts/webhook-sink/sink.php
docker compose -f compose.dev.yml exec -T api php /workspace/scripts/webhook-sink/sink.php --summary /workspace/.gates/tmp/P5-sink/log.jsonl
```

The first line serves (leave it running); the second prints `{"requests", "verified", "unique_ids", "delivered_ids",
"invalid", "duplicates_after_success", "max_attempts"}`. With `SINK_FAIL_FIRST=2` each message is received three times and
`max_attempts` is 3. The M1 check on 2026-10-14 sent 6 messages (one test and five `price.moved`): all 6 delivered at the
third attempt, 18 requests verified, 0 invalid, 0 duplicates, and `events` equal to `measured` (160) for the deliverable
day.

## Commands and schedule

| Command | What it does |
|---|---|
| `pokeprices:alerts:dispatch` | Runs one dispatch in the foreground and prints one JSON line. Exit 0, also when nothing is deliverable. |
| `pokeprices:alerts:dispatch --reset-watermark=<YYYY-MM-DD>` | The operator's reset (see above). |
| `pokeprices:alerts:deliver` | Runs one delivery sweep in the foreground (same caps and budget) and prints `{"retired", "claimed", "delivered", "retry", "failed", "released"}`. |
| `pokeprices:alerts:discord-test` | The Discord check (see "Discord"). |

```sh
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:alerts:dispatch
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan pokeprices:alerts:deliver
docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan schedule:list
```

The dispatch line is `{"source", "deliverable_day", "newest_day", "floor", "watermark", "ceiling", "events", "measured",
"scoped_events", "created": {"price.moved", "alert.triggered", "digest"}, "skipped", "passed_over"}`. `skipped` is null, or
`no finalized day`, `repair in progress: …`, `newest finalized day <d> is older than the last dispatched day <w>` or
`catalog degraded`. `scoped_events` is the events with at least one eligible watcher or holder. The same object is the
`alerts_dispatch` log line.

Two entries are scheduled, both on the `redis` connection and the `default` queue, both `withoutOverlapping`:

- `pokeprices-alerts-dispatch`: every five minutes, creates the day's deliveries;
- `pokeprices-deliveries`: every minute, one delivery sweep.

After a change to a job class, restart the worker and the scheduler:
`docker compose -f compose.dev.yml restart worker scheduler`. Log lines: `alerts_dispatch`, `alerts_dispatch_skipped` and
`delivery_attempt` (`delivery_id`, `kind`, `channel`, `endpoint_id`, `attempt`, `outcome`, `status`, `error`,
`exception` only for an internal error, `duration_ms`).

## Configuration

Values are set in `api/config/alerts.php`. Environment variable names, never values:

| Name | Effect |
|---|---|
| `DISCORD_TEST_WEBHOOK_URL` | The one Discord webhook `pokeprices:alerts:discord-test` sends to. Unset sends nothing. |
| `WEBHOOK_PRIVATE_ALLOW` | Comma-separated exact `host:port` pairs the address guard lets through (and `http`). Default `api:8096` when `APP_ENV` is `local`, empty otherwise. |
| `APP_URL` | The base of the links in alert and digest texts. |
| `APP_KEY` | Encrypts webhook URLs and secrets. |
| `MAIL_MAILER` | The mailer for email alerts and digests; `log` unless set. |

| Key (`config('alerts.…')`) | Default |
|---|---|
| `webhooks.limit`, `webhooks.discord_limit` | 5 signed webhooks, 1 Discord webhook per person |
| `webhooks.connect_timeout_seconds`, `webhooks.timeout_seconds` | 2, 5 |
| `webhooks.max_response_bytes` | 65536 |
| `delivery.max_attempts` | 6 |
| `delivery.base_seconds`, `delivery.factor`, `delivery.cap_seconds` | 30, 4, 3600 |
| `delivery.lease_seconds` | 300 |
| `delivery.batch`, `delivery.per_endpoint` | 10, 3 |
| `delivery.sweep_budget_seconds` | 60 |
| `delivery.retention_days` | 30 |
| `catalog_grace_minutes` | 30 |
| `export_deliveries_limit` | 500 |
| `rate_limits.webhook-test` | 5 a minute per person |
| `digest.max_lines` | 25 |
