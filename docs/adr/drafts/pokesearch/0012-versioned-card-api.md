# ADR 12 — A versioned card API for a second independent client

Status: Proposed (draft for Andres; he moves it into PokéSearch docs/DECISIONS.md)

Drafted 2026-10-12 during the PokéPrices P1 loop. Nothing in the PokéSearch
repository was edited to write this: ADR 7 and ADR 8 keep their text, and the
only changes Andres makes to them are the pointer lines listed in the last part.
Records are append-only, so a reversal here is a pointer forward, not a rewrite.

Three decisions in ADR 8 rested on the same premise: PokéSearch had two
consumers, both co-versioned with the server, and the intended internal traffic
source was Courier. PokéPrices (a Laravel API and a React SPA) is now a third caller
that ships on its own schedule, and Courier is slated for retirement. The
premise is gone, so the three positions are revisited together, and ADR 7 gains
two error codes the new surface needs.

## Part 1: Versioning reversal of ADR 8

**What ADR 8 said.** URL versioning (`/api/v1/…`) was deliberately not built:
"Two consumers exist, both co-versioned with the server. A version segment would
be ceremony without a second independent client."

**What changed.** Laravel is the second independent client. PokéPrices deploys
on its own cadence, caches hydrated cards keyed by card id and the PokéSearch
`seed.ref`, and cannot be rebuilt in lockstep with a PokéSearch release. That is
exactly the condition ADR 8 named for adding a version segment.

**The decision.**

- A versioned surface under `/api/v1/` is added for the Laravel client. The
  existing unversioned `/api/*` routes keep working for the embedded UI.
- v1 is additive-only: new endpoints, new optional parameters and new response
  fields may appear; no field is removed, renamed or retyped, no parameter
  changes meaning, and no existing error code changes status.
- Any breaking change ships as `/api/v2`, and v1 stays served for a deprecation
  window announced before the break.
- The contract is the published schemas plus the C4 diagrams (the container and
  component views of how PokéPrices calls PokéSearch). A change that alters
  either in a non-additive way is a break and follows the rule above. Contract
  tests on the PokéPrices side pin the schemas, so a break fails there before it
  reaches a user.

**Cost.** Two route prefixes to keep coherent, and a standing obligation: every
v1 field becomes a promise. The additive-only rule is the price of letting the
client deploy independently.

## Part 2: Rate-limit position revisited

**What ADR 8 said.** App-level rate limiting was not built: Cloudflare fronts the
public URL, and Courier, the internal load generator, is the intended traffic
source. A limiter would return `429` to the very tool built to exercise the API.

**What changed.** The reason for having no limiter was Courier. The trigger for
revisiting the position is therefore written down as an event: **Courier
retired (Deploy prompt)**. Courier's retirement is scheduled work of the
PokéPrices Deploy prompt, not of the P1 loop, so it has not happened.

**The decision.**

- No limiter is added now. Until the trigger fires, ADR 8's reasoning stands.
- The `X-Api-Key` header exists on the v1 surface so that keyed clients can be
  identified and exempted from any limiter introduced later. PokéPrices is the
  first keyed client; a key identifies a caller, it does not grant extra data.
- When the trigger fires, the question is reopened with measured traffic in hand,
  and the outcome is a new ADR rather than an edit to this one.

**Cost.** The public surface stays unthrottled at the application layer in the
meantime, which remains acceptable only while Cloudflare absorbs abusive traffic.

## Part 3: Reseeding reversal

**What ADR 8 said.** Re-seeding to a newer corpus was out of scope until Courier
ships, because its fixtures assert exact corpus cardinalities, so a reseed is a
coordinated two-project change.

**What changed.** PokéPrices needs the cards the older corpus lacks (the newest
sets: me5, me55 and me55c, 311 cards). The dataset fork is fast-forwarded to
upstream (the push is Andres's, HG-05), and the corpus moves to
`39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11`: **20,635** cards, counted at T01 of
the P1 loop (not assumed from release notes), up from 20,324.

**The decision.** PokéSearch reseeds to that ref, and the places that pin the
count move with it (README, acceptance workflow and test, the compose seed ref,
and the relevance baseline, which must still meet ADR 9's bar before it is
accepted). The reseed is Andres's to run, locally first.

**Courier coordination.** There is no Courier change in this loop. Courier's
live collections (`internal/collections/data/03-suggest-health.json` and
`01-search-basics.json`) assert 20,324 against the server instance. That server
instance stays on the old seed until a Deploy prompt moves it, so the assertions
remain true where Courier runs them. The coupling ADR 8 described is handled by
sequencing, not by editing Courier: the local development stack reseeds first,
the server instance reseeds at deploy time,
and no environment ever has Courier pointed at a corpus whose cardinalities its
fixtures do not match.

**Earlier text is not rewritten.** ADR 7, ADR 8 and the other records keep their
20,324 figures. They were true when written, and they identify the old corpus
for anyone reading the history.

**Cost.** Two corpora exist at once for a while (development on 20,635, server on
20,324), so any check that quotes a card count must say which environment it ran
against.

## ADR 7 addendum

ADR 7 defined one error envelope and its codes. The v1 surface
needs two more:

| Code | HTTP status | Meaning |
|---|---|---|
| `not_found` | 404 | The requested card id does not exist in the seeded corpus. |
| `unauthorized` | 401 | An `X-Api-Key` header was sent and is not recognised (a request with no key is not rejected; the key is optional). |

The envelope is unchanged: `{"error": {"code", "field", "message"}, "request_id"}`,
with `field` omitted when no single field is at fault. `X-Request-Id` handling is
as ADR 7 describes. The existing codes and statuses keep their meaning, so this
is an additive extension and consistent with Part 1.

## Pointer lines

Records are append-only, so these are the only changes Andres makes to ADR 7 and
ADR 8 when he moves this draft into `docs/DECISIONS.md`. Each is one line,
appended directly under the paragraph named.

Under ADR 8, the paragraph "URL versioning (`/api/v1/…`)":

```
→ revisited by ADR 12
```

Under ADR 8, the paragraph "App-level rate limiting":

```
→ revisited by ADR 12
```

Under ADR 8, the paragraph "Re-seeding to a newer corpus":

```
→ revisited by ADR 12
```

Under ADR 7, after the paragraph that defines the error shape:

```
→ codes extended by ADR 12
```

In the index table at the top of `docs/DECISIONS.md`, row 8 changes its status
cell to:

```
Accepted — partly superseded by ADR 12
```

The new ADR 12 row is added to the same table by Andres when he appends this
record.
