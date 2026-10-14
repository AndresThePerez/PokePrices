# Plan: fix the review findings on PR #1

Source: code review of https://github.com/AndresThePerez/PokePrices/pull/1 (base `p5-work` @ `90e4016`,
head @ `f2e8a7b`). Do this work on the PR's head branch, not on `main`.

Ten findings, ordered by priority. Every one is **unfixed**. Most were traced by reading code
(TanStack Query `@tanstack/query-core@5.104.2` semantics), not reproduced, so **write the failing
test first** for each one and confirm it fails before fixing. If a test can't reproduce a finding,
note that and skip the fix rather than changing code speculatively.

## Background: the session model (read this first)

- `web/src/account/session.ts`: `['me']` (`ME_KEY`) is the signed-in user. All per-user data lives
  under `['account', …]` (`accountKey`). `forgetAccount` removes unobserved account queries and
  resets observed ones (skipping `ONE_TIME` ones). `endSession` is called by this tab on sign-in /
  sign-out / demo / delete; it forgets and writes a localStorage nonce (`SESSION_SIGNAL_KEY`) so
  other tabs forget too.
- `web/src/query.ts` `createQueryClient`: tracks `identity` (the last account `['me']` named) and a
  `forgotten` flag (set by a transition; the next `['me']` **success** becomes the new baseline
  without forgetting again). If `['me']` names a different account than `identity`,
  `forgetAccount` runs. The cross-tab signal handler (lines ~86-96) forgets, then
  `cancelQueries(ME_KEY)` and re-invalidates.
- TanStack facts this plan relies on:
  - `setQueryData` dispatches `success` (manual).
  - A cancel with `revert` dispatches `setState`, **not** `success`, and puts the previous data back.
  - When the last observer unmounts and the fetch consumed its abort signal (`fetchMe` does),
    the fetch is cancelled with revert.
  - `invalidateQueries`/`refetch` with `cancelRefetch` only cancels an in-flight fetch when
    `state.data !== undefined`; on a first load it **joins** the existing request.
  - `removeQueries`/`resetQueries` cancel silently: old answers are never dispatched.

## Checks to run (all must pass before pushing)

```
cd web && npm run lint && npm run typecheck && npm test
cd api && php artisan test   # or the repo's Makefile target; see Makefile
```

---

## 1. `forgotten` stays true after signing out from AccountPage (HIGH)

**Files:** `web/src/account/AccountPage.tsx:72-75`, `web/src/query.ts:107-111`

**Bug:** sign-out does `setQueryData(ME_KEY, null)` → `endSession` (sets `forgotten = true`) →
`invalidateQueries(ME_KEY)` → `navigate('/')`. The navigation unmounts the only `['me']` observer, so
the refetch is revert-cancelled (a `setState`, ignored by the subscriber). `forgotten` is never
cleared and `identity` stays A. Later, the next `['me']` answer (say B) is taken as a baseline
**without** `forgetAccount`, so data cached for A in the meantime (e.g. `PORTFOLIO_KEY` from
CardActions, 5-min staleTime) renders under B. Matters most when localStorage is blocked (no
cross-tab signal).

**Fix (pick one, prefer the first):**
- In the `forgotten` branch of the `queryCache.subscribe` handler, if `identity !== null` and
  `next !== identity`, call `forgetAccount(client)` before taking `next` as the baseline. Check
  this doesn't drop the new account's own first answers. Those arrive after the transition's
  forget, and resetting them just causes one refetch. If that refetch cost is unacceptable, use
  the next option instead.
- Or, in `AccountPage`, set `forgotten`-clearing state directly: since the sign-out already knows
  the answer is `null`, call `endSession` **before** `setQueryData(ME_KEY, null)` so the manual
  `success` consumes `forgotten` with `identity = null`. Check that the ordering comment in
  `query.ts` still holds.

**Test:** in `web/src/query.session.test.ts`, simulate: identity A, the AccountPage sign-out
sequence with the `['me']` observer unmounted before the refetch resolves, then cache an account
query, then `['me']` answers B. Assert that the account query was forgotten.

## 2. Cross-tab signal reverts `['me']` to the old account (HIGH)

**File:** `web/src/query.ts:86-96`

**Bug:** the signal handler cancels `['me']` with revert, which puts A's answer back. `useSession`
keeps saying signed in as A while the reset account queries refetch with B's cookie, so B's data
shows under "Signed in as A". If the `['me']` refetch errors, `useSession` still returns A because
data wins over error, and `forgotten` stays true until the next poll.

**Fix:** in the signal handler, drop the old answer instead of reverting it:
`client.resetQueries({ queryKey: ME_KEY })` (observed: goes back to loading and refetches) or
`removeQueries` + `invalidateQueries`. Make sure the account pages render the loading state, not
the signed-out state, while `['me']` has no data. `useSession` has a loading state; verify it
covers this.

**Test:** with `['me']` = A and an observer mounted, fire the storage event. Assert `['me']` data
is undefined / the session reads loading until the new answer, and never A with B's account data.
Also test the error path: the refetch fails, so the session must not report signed in as A.

## 3. A `['me']` request already in flight is accepted as the post-sign-in baseline (MEDIUM)

**Files:** `web/src/account/SignInPage.tsx:41-42` and `:128-129`, `web/src/account/DemoButton.tsx:27-28`,
`web/src/account/SignUpPage.tsx:42-43`; flag logic in `web/src/query.ts:107-111`

**Bug:** after `endSession`, these await `invalidateQueries({ queryKey: ME_KEY, refetchType: 'all' })`.
If `['me']` has no data yet (its first load errored and a focus refetch is in flight), the invalidate
**joins** that old request. It answers null because it carried the old cookie, consumes `forgotten`,
and the user is shown signed out after a successful sign-in.

**Fix:** cancel first. The cleanest option is in one place: make `endSession` (or a small helper
it exports, such as `refreshSession(queryClient)`) do `await cancelQueries({ queryKey: ME_KEY })` then
`invalidateQueries(..., refetchType: 'all')`. Then switch all four call sites to it. This matches what
the signal handler already does.

**Test:** `['me']` first load fails, a refetch is pending (a manually resolved promise), then sign in.
Resolve the old request with a 401 and the new one with the user. Assert the page ends up signed in.

## 4. Saved notification settings are lost on remount when the refetch fails (MEDIUM)

**File:** `web/src/account/NotificationSettings.tsx:51-55, 74-86`

**Bug:** the PUT answer is now held only in `SettingsForm` state (`held`), not in the cache. If the
user leaves `/alerts` before the refetch lands (revert-cancelled), or the form remounts while the
refetch keeps failing, the form shows the settings from before the save after it said "Saved."

**Fix:** write the answer back to the cache, guarded so it can't cross a session transition:
capture an identity/generation token before the PUT. After it resolves, if the query still exists
and no transition happened, `setQueryData(NOTIFICATIONS_KEY, result.data)`. Then delete `held`,
`reads` and the three `dataUpdateCount` reads. A cheap generation counter could be exported from
`session.ts`, bumped by `endSession` and the signal handler. Update the file's header comment.

**Test:** save, unmount the form before the refetch resolves, make the refetch fail, remount. Assert
the saved values are shown. Keep the existing transition test: an answer landing after `endSession`
must not be written.

## 5. `key={initial}` wipes the quantity being typed (LOW)

**File:** `web/src/account/CardActions.tsx:117-128`

**Bug:** `QuantityForm` is keyed on the held quantity, so a same-account background refetch (poll or
focus) that returns a different quantity remounts the form and drops the text being typed.

**Fix:** key on the account instead, e.g. `useSession()`'s email, which matches the intent in the
comment ("account switches"). Alternatively, keep the key but only reset when the form is untouched.

**Test:** type into the form, make the query return a different quantity, and assert the typed value
is kept.

## 6. Webhook one-time secret outlives an account switch (LOW)

**Files:** `web/src/account/Webhooks.tsx:241` (`AddWebhook` `secret` state), `web/src/account/AlertsPage.tsx:92`

**Bug:** `secret` is only cleared by the `Fragment key={me.email}` remount, which waits for `['me']`.
After a cross-tab switch, B's webhook list can render next to A's secret, for up to 5 minutes if
storage is blocked. Fixing #2 shrinks this window.

**Fix:** after #2, re-check. If it still reproduces, render `AddWebhook` only once the webhook list
has loaded (so the reset unmounts it), or clear `secret` on a session-generation change (see #4).

## 7. "Email verified" survives an account switch (LOW)

**Files:** `web/src/account/VerifyEmailPage.tsx:37-52`, `web/src/account/session.ts:51-54`

**Bug:** `forgetAccount` skips observed `ONE_TIME` queries, so A's `verification.data === true`
stays on screen with `staleTime: Infinity` after another tab signs in as B.

**Fix:** key the page's content on the signed-in email (as AlertsPage does), or make the verify page
show the success state only while `['me']` names the account it verified. Keep the ONE_TIME
guarantee of one GET per page visit.

## 8. Back-end: forged-cookie test doesn't exercise the fix (TEST QUALITY)

**File:** `api/tests/Feature/Accounts/DemoExportSessionsEdgeTest.php:89-101`

Every forged cookie is unauthenticated and gets 401 at `auth:sanctum` before
`AccountExport::sessions()` runs, so this test passes even without the fix, and its comment overclaims.
**Fix:** reword the test name and comment to say what it does prove (a forged cookie is
unauthenticated and leaks nothing). The real wildcard coverage is the direct `$export->of($demo, '%')`
check at line 112. Optionally add an authenticated demo request whose real session id is checked
against `%`/`_` rows.

## 9. Back-end: demo export drops the visitor's own older session rows (LOW, decide)

**File:** `api/app/Domain/Accounts/AccountExport.php:172-180`

A demo visitor who signs in twice keeps an older row under the old id (`regenerate(false)` in
`DemoSessionController.php:42-43`). The export now returns only the current row. It fails closed
and leaks nothing. **Decide with the owner** before changing anything. The likely right fix is
`session()->regenerate(true)` (destroy the old row) on demo sign-in, not widening the query.

## 10. Back-end: "never writes the session id" test passes on base (TEST QUALITY, optional)

**File:** `api/tests/Feature/Accounts/DemoExportSessionsEdgeTest.php:134-145`

The base query never selected `id`, so this is a regression guard, not a test of the fix. It's
harmless. At most, add a one-line comment saying it's a guard.

---

## Optional cleanup (only if touching those files anyway)

- `NotificationSettings.tsx`: `getQueryState(NOTIFICATIONS_KEY)?.dataUpdateCount ?? 0` appears 3×
  (goes away with fix #4).
- The "Keyed by the account" comments in `AccountPage.tsx` and `AlertsPage.tsx` are near-duplicates.
- `AccountPage`'s `deleted` flag sits outside the account key, so "Your account has been deleted."
  can linger above a later account's view (cosmetic).

## Already verified fine (don't re-investigate)

- `AccountExport` session narrowing: the id comes only from the caller's own session cookie, the
  query is parameterized, and no other code path reads the `sessions` table unfiltered.
- Removing `forgetLists`: every former call site now calls `endSession`, every per-user key uses
  `accountKey`, and there is no import cycle.
- The 401 `onError` → `['me']` invalidate path terminates. It isn't a loop, just N redundant `['me']`
  calls.
- The `SessionQueryClient` mount counter is StrictMode-safe.

## Suggested order

Fix #3 first: it adds the shared cancel-then-invalidate helper. Then #2 and #1 (same subscriber
logic, test them together). Then #4, which can reuse a session generation counter, then #5–#7,
then the back-end items #8–#10. Commit each fix with its test. Run the full checks before every push.
