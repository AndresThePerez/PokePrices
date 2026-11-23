// The PokéPrices API client. Same-origin /v1 only: the SPA never calls PokéSearch or any other host.
import type { components, operations } from './schema';

export type Problem = components['schemas']['Problem'];
export type CardSummary = components['schemas']['CardSummaryResource'];
type CardSearchQuery = operations['searchCards']['parameters']['query'];
/** The orders of a search: relevance (the default), or the headline close high or low first. */
export type SearchSort = NonNullable<CardSearchQuery['sort']>;
export type CardSearchPage =
  operations['searchCards']['responses'][200]['content']['application/json'];

export type Card = components['schemas']['CardResource'];
export type CardDetail = operations['getCard']['responses'][200]['content']['application/json'];

export type CardPrices =
  operations['getCardPrices']['responses'][200]['content']['application/json'];
export type PriceSeries = CardPrices['data'][number];
export type PricePoint = PriceSeries['points'][number];
/** The generated range union: '1W' | '1M' | '3M' | 'YTD' | '1Y' (there is no 1D). */
export type PriceRange = NonNullable<
  NonNullable<operations['getCardPrices']['parameters']['query']>['range']
>;

export type MoversPage = operations['getMovers']['responses'][200]['content']['application/json'];
export type Mover = MoversPage['data'][number];

export type PriceFreshness =
  operations['getPriceFreshness']['responses'][200]['content']['application/json'];
/** The generated direction union: 'up' (rises) | 'down' (falls). */
export type MoversDirection = NonNullable<
  NonNullable<operations['getMovers']['parameters']['query']>['direction']
>;

export type ApiFailure = {
  readonly ok: false;
  /** HTTP status, or 0 when no response arrived. */
  readonly status: number;
  /** The problem type URI, or about:blank when the body was not a problem document. */
  readonly type: string;
  readonly title: string;
  /** The problem's request_id, else the X-Request-Id response header. */
  readonly requestId: string | null;
  /** The whole problem document (detail, errors, catalog_missing) when the API sent one. */
  readonly problem: Problem | null;
};

export type ApiResult<T> = { readonly ok: true; readonly data: T } | ApiFailure;

/**
 * True when a failure is the given status and its problem type ends in /problems/<slug>. Only the
 * suffix is read, so the type's base URL does not matter.
 */
export function hasProblemType(failure: ApiFailure, status: number, slug: string): boolean {
  return failure.status === status && failure.type.endsWith(`/problems/${slug}`);
}

export interface RequestOptions {
  readonly signal?: AbortSignal;
}

const BASE = '/v1';

function isProblem(value: unknown): value is Problem {
  if (typeof value !== 'object' || value === null) return false;
  const fields = value as Record<string, unknown>;
  return (
    typeof fields.type === 'string' &&
    typeof fields.title === 'string' &&
    typeof fields.status === 'number' &&
    typeof fields.instance === 'string' &&
    typeof fields.request_id === 'string'
  );
}

/** A 200 whose JSON is not the shape Fortify or the /v1 contract documents. */
const UNEXPECTED_ANSWER: ApiFailure = {
  ok: false,
  status: 200,
  type: 'about:blank',
  title: 'The PokéPrices API sent an unexpected answer (HTTP 200)',
  requestId: null,
  problem: null,
};

function unexpectedAnswer(status: number): ApiFailure {
  return {
    ...UNEXPECTED_ANSWER,
    status,
    title: `The PokéPrices API sent an unexpected answer (HTTP ${String(status)})`,
  };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

// ---- The decoder table (C6) ---------------------------------------------------------------------
// Each read decodes the fields its page reads without a guard. A success whose fields are not the
// documented ones is the unexpected-answer failure, so the page shows its problem block instead of
// throwing in render. A decoder checks and never rebuilds: a well-shaped answer passes through as
// the same value. The writes' answers are checked the same way, on the fields their callers read;
// only the empty answers (204 and Fortify's '') stay unchecked (`trusted`), and the Fortify answers
// that carry fields are checked where they are read.

/** One check of an unknown value. */
type Check = (value: unknown) => boolean;

const text: Check = (value) => typeof value === 'string';
const whole: Check = (value) => typeof value === 'number';
const flag: Check = (value) => typeof value === 'boolean';
const record: Check = isRecord;

function orNull(check: Check): Check {
  return (value) => value === null || check(value);
}

function oneOf(...allowed: readonly string[]): Check {
  return (value) => typeof value === 'string' && allowed.includes(value);
}

function listOf(check: Check): Check {
  return (value) => Array.isArray(value) && value.every(check);
}

/** An object holding at least these fields, each passing its check (other fields are not read). */
function fields(shape: Readonly<Record<string, Check>>): Check {
  return (value) =>
    isRecord(value) && Object.entries(shape).every(([key, check]) => check(value[key]));
}

/** A field the answer may leave out, checked when it is there. */
function ifPresent(check: Check): Check {
  return (value) => value === undefined || check(value);
}

/** The naming fields of a variant (variantLabel). */
const VARIANT_NAMING = {
  variant_key: text,
  finish: text,
  edition: text,
  treatment: text,
};

/** A price block: the market price, its day and the change vs the previous close (PriceTag). */
const PRICE = fields({
  close: text,
  day: text,
  previous_close: orNull(text),
  previous_day: orNull(text),
  change: orNull(text),
  change_pct: orNull(text),
});

/**
 * The card page's price block: PRICE plus the lowest listing of the close's observation (a decimal
 * string or null) and the thin-market flag, each checked when it is there.
 */
const CARD_PRICE = (value: unknown): boolean =>
  PRICE(value) &&
  fields({ lowest_listing: ifPresent(orNull(text)), thin_market: ifPresent(flag) })(value);

/** The lowest listing of a variant without a close, with its own day and marker (an optional field). */
const LISTING = fields({
  source: text,
  feed: text,
  currency: text,
  lowest_listing: text,
  day: text,
  as_of: text,
});

/**
 * The catalog naming of a list row (movers, watchlist, portfolio), with the card's small image URL
 * when the catalog gives one (CardThumbnail); null when not in the catalog.
 */
const ROW_CARD = orNull(
  fields({ name: text, set_name: text, number: text, image_small: ifPresent(text) }),
);

/**
 * A search page: each row's catalog fields, its headline variant and that variant's close (both
 * null without a priced variant), and the order the rows are in with how many matches it ordered.
 */
const SEARCH = fields({
  data: listOf(
    fields({
      id: text,
      name: text,
      number: text,
      rarity: orNull(text),
      set_name: text,
      image_small: text,
      variant: orNull(fields(VARIANT_NAMING)),
      price: orNull(PRICE),
    }),
  ),
  meta: fields({
    total: whole,
    page: whole,
    pages: whole,
    degraded: flag,
    sort: oneOf('relevance', 'price_desc', 'price_asc'),
    ordered: orNull(whole),
  }),
});

/**
 * The card: its id and name always; the other fields the page reads are checked whenever they are
 * there (client.test.ts pins a card of only an id and a name as a success).
 */
const CARD = fields({
  data: fields({
    id: text,
    name: text,
    number: ifPresent(text),
    rarity: ifPresent(orNull(text)),
    set: ifPresent(fields({ name: text })),
    images: ifPresent(fields({ small: text, large: text })),
    variants: ifPresent(
      listOf(
        fields({
          ...VARIANT_NAMING,
          sources: listOf(
            fields({ source: text, product_id: whole, subtype: text, map_snapshot_day: text }),
          ),
          price: orNull(CARD_PRICE),
          listing: ifPresent(LISTING),
        }),
      ),
    ),
    catalog: ifPresent(fields({ seed_ref: orNull(text), seeded_at: orNull(text), status: text })),
  }),
  meta: fields({ degraded: flag }),
});

const POINT = fields({ day: text, close: text, is_filled: flag, series_epoch: whole });

/**
 * The history page guards the answer's shape itself (an unexpected one, a JSON null included,
 * reads as "nothing held"), so only a series it would draw is checked: one with a points list and
 * a history_begins of null or a day. Its points and naming are read without a guard.
 */
function pricesAnswer(value: unknown): boolean {
  if (!isRecord(value) || !Array.isArray(value.data)) return true;
  const drawn = fields({ points: listOf(() => true), history_begins: orNull(text) });
  const series = fields({ ...VARIANT_NAMING, points: listOf(POINT) });
  return value.data.every((one: unknown) => !drawn(one) || series(one));
}

const MOVERS = fields({
  data: listOf(
    fields({
      ...VARIANT_NAMING,
      rank: whole,
      card_id: text,
      card: ROW_CARD,
      close: text,
      previous_close: text,
      previous_day: text,
      change: text,
      change_pct: text,
    }),
  ),
  meta: fields({ day: orNull(text), min_previous_close: text, degraded: flag }),
});

/** The freshness answer: the newest collected day, its age in hours and the SLO verdict (the banner). */
const FRESHNESS = fields({
  data: fields({ day: orNull(text), age_hours: orNull(whole), within_slo: flag }),
});

/**
 * The portfolio's figures: the estimated total, the day's change in dollars and percent (decimal
 * strings or null), the card counts, the days the total rests on and its provenance.
 */
const ESTIMATE = {
  count: whole,
  priced: whole,
  unpriced: whole,
  no_previous_close: whole,
  total_value: orNull(text),
  change: orNull(text),
  change_pct: orNull(text),
  first_day: orNull(text),
  last_day: orNull(text),
};

/**
 * Who is signed in, with the account's portfolio estimate (the header's), checked when it is
 * there: an answer without it shows no estimate.
 */
const ME = fields({
  data: fields({
    email: text,
    email_verified: flag,
    two_factor: flag,
    read_only: flag,
    portfolio: ifPresent(fields({ ...ESTIMATE, source: text, feed: text, currency: text })),
  }),
});

const LIST_ROW = {
  ...VARIANT_NAMING,
  card_id: text,
  card: ROW_CARD,
  price: orNull(PRICE),
};

const WATCHLIST = fields({
  data: listOf(fields({ ...LIST_ROW, alert_pct: orNull(whole) })),
  meta: fields({ degraded: flag }),
});

const PORTFOLIO = fields({
  data: listOf(fields({ ...LIST_ROW, quantity: whole, value: orNull(text) })),
  meta: fields({ ...ESTIMATE, degraded: flag }),
});

const WEBHOOKS = fields({
  data: listOf(
    fields({
      id: whole,
      kind: oneOf('discord', 'standard'),
      url_hint: text,
      description: orNull(text),
      created_at: text,
      disabled: flag,
      last_delivery: orNull(fields({ status: oneOf('pending', 'delivered', 'failed'), at: text })),
    }),
  ),
  meta: record,
});

const NOTIFICATIONS = fields({
  data: fields({ channel: oneOf('discord', 'email'), digest: flag, discord_available: flag }),
});

const DEMO_SAMPLES = fields({
  data: fields({
    sample_secret: text,
    alert: orNull(
      fields({
        ...VARIANT_NAMING,
        card_id: text,
        card: orNull(fields({ name: text, set_name: text, number: text })),
        alert_pct: whole,
      }),
    ),
    message: orNull(fields({ delivered_at: text, subject: text, text })),
    webhook: orNull(
      fields({
        url_hint: text,
        delivered_at: text,
        headers: fields({
          'webhook-id': text,
          'webhook-timestamp': text,
          'webhook-signature': text,
        }),
        body: text,
      }),
    ),
  }),
});

// The write answers: the fields their callers read. Where a caller reads none, the answer's
// envelope (a data object) is checked and its fields are not.

/** POST /reset-password: Fortify's one message (MessageAnswer, as requestPasswordReset reads it). */
const MESSAGE = fields({ message: text });

/** PUT /v1/watchlist/{variantKey}: nothing is read (the list is asked again). */
const WATCHLIST_ENTRY = fields({ data: record });

/** PUT /v1/portfolio/{variantKey}: the quantity now held (the card page's outcome line shows it). */
const HOLDING_ENTRY = fields({ data: fields({ quantity: whole }) });

/** POST /v1/webhooks: the kind added and its signing secret, null for Discord (the add form). */
const WEBHOOK_CREATED = fields({
  data: fields({ kind: oneOf('discord', 'standard'), secret: orNull(text) }),
});

/** POST /v1/webhooks/{id}/test: nothing is read (the row says the message is queued). */
const WEBHOOK_TEST = fields({ data: record });

/** PUT /v1/me/notifications: the saved channel and digest (the settings form holds them). */
const NOTIFICATIONS_SAVED = fields({
  data: fields({ channel: oneOf('discord', 'email'), digest: flag }),
});

/** PUT /v1/alerts/{variantKey}: nothing is read (the watchlist is asked again). */
const ALERT_ENTRY = fields({ data: record });

/*
 * The field decoders, one per read: each states its check as the type it proves, so the answer
 * passes through as the same value (a predicate rather than `T | null`, because the history answer
 * may be a JSON null that its page reads as "nothing held").
 */
const isCardSearchPage = (value: unknown): value is CardSearchPage => SEARCH(value);
const isCardDetail = (value: unknown): value is CardDetail => CARD(value);
const isCardPrices = (value: unknown): value is CardPrices => pricesAnswer(value);
const isMoversPage = (value: unknown): value is MoversPage => MOVERS(value);
const isPriceFreshness = (value: unknown): value is PriceFreshness => FRESHNESS(value);
const isMeBody = (value: unknown): value is MeBody => ME(value);
const isWatchlistBody = (value: unknown): value is WatchlistBody => WATCHLIST(value);
const isPortfolioBody = (value: unknown): value is PortfolioBody => PORTFOLIO(value);
const isWebhooksBody = (value: unknown): value is WebhooksBody => WEBHOOKS(value);
const isNotificationsBody = (value: unknown): value is NotificationsBody => NOTIFICATIONS(value);
const isDemoSamplesBody = (value: unknown): value is DemoSamplesBody => DEMO_SAMPLES(value);
const isMessageAnswer = (value: unknown): value is MessageAnswer => MESSAGE(value);
const isWatchlistEntry = (value: unknown): value is WatchlistEntry => WATCHLIST_ENTRY(value);
const isHoldingEntry = (value: unknown): value is HoldingEntry => HOLDING_ENTRY(value);
const isWebhookCreated = (value: unknown): value is WebhookCreated => WEBHOOK_CREATED(value);
const isWebhookTestAnswer = (value: unknown): value is WebhookTestAnswer => WEBHOOK_TEST(value);
const isNotificationsSaved = (value: unknown): value is NotificationsBody =>
  NOTIFICATIONS_SAVED(value);
const isAlertEntry = (value: unknown): value is AlertEntry => ALERT_ENTRY(value);

/** The success, or the unexpected-answer failure when its fields do not decode; failures pass. */
async function decoded<T>(
  pending: Promise<ApiResult<unknown>>,
  decode: (value: unknown) => value is T,
  status = 200,
): Promise<ApiResult<T>> {
  const result = await pending;
  if (!result.ok) return result;
  const data = result.data;
  return decode(data) ? { ok: true, data } : unexpectedAnswer(status);
}

/** An answer the contract types as empty (204 or Fortify's ''), passed through unchecked. */
async function trusted(pending: Promise<ApiResult<unknown>>): Promise<ApiResult<undefined>> {
  const result = await pending;
  return result.ok ? { ok: true, data: result.data as undefined } : result;
}

/** GET /v1/cards?q=&page=&page_size=&sort= (the PokéSearch search, proxied by the API). */
export function searchCards(
  query: CardSearchQuery,
  options: RequestOptions = {},
): Promise<ApiResult<CardSearchPage>> {
  const params = new URLSearchParams({ q: query.q });
  if (query.page !== undefined) params.set('page', String(query.page));
  if (query.page_size !== undefined) params.set('page_size', String(query.page_size));
  if (query.sort !== undefined) params.set('sort', query.sort);
  return decoded(publicRead(`/cards?${params.toString()}`, options), isCardSearchPage);
}

/** Segments that URL resolution removes, so /v1/cards/{id} would turn into another URL. */
const COLLAPSING_SEGMENTS: ReadonlySet<string> = new Set(['', '.', '..']);

/**
 * The id as one encoded path segment, or null when it collapses under URL resolution ('', '.',
 * '..'), so /v1/cards/{id} would turn into another URL.
 */
function cardSegment(id: string): string | null {
  const segment = encodeURIComponent(id);
  return COLLAPSING_SEGMENTS.has(segment) ? null : segment;
}

const INVALID_ID: ApiFailure = {
  ok: false,
  status: 0,
  type: 'about:blank',
  title: 'The card id is not valid',
  requestId: null,
  problem: null,
};

/**
 * GET /v1/cards/{id}; the id is one encoded path segment, so ex10-? and a/b arrive intact.
 * An id that collapses under URL resolution ('', '.', '..') never reaches fetch: it resolves
 * as a failure with status 0, because ids also come from API data, not only from the router.
 */
export function getCard(id: string, options: RequestOptions = {}): Promise<ApiResult<CardDetail>> {
  const segment = cardSegment(id);
  if (segment === null) return Promise.resolve({ ...INVALID_ID });
  return decoded(publicRead(`/cards/${segment}`, options), isCardDetail);
}

/** GET /v1/cards/{id}/prices?range=; the same one-segment id and collapsing guard as getCard. */
export function getCardPrices(
  id: string,
  range: PriceRange,
  options: RequestOptions = {},
): Promise<ApiResult<CardPrices>> {
  const segment = cardSegment(id);
  if (segment === null) return Promise.resolve({ ...INVALID_ID });
  const params = new URLSearchParams({ range });
  return decoded(
    publicRead(`/cards/${segment}/prices?${params.toString()}`, options),
    isCardPrices,
  );
}

/** GET /v1/movers?direction=&limit= (the newest movers day, ranked; limit 1 to 50). */
export function getMovers(
  direction: MoversDirection,
  limit: number,
  options: RequestOptions = {},
): Promise<ApiResult<MoversPage>> {
  const params = new URLSearchParams({ direction, limit: String(limit) });
  return decoded(publicRead(`/movers?${params.toString()}`, options), isMoversPage);
}

/** GET /v1/prices/freshness (how old the newest collected prices are, against the SLO). */
export function getPriceFreshness(
  options: RequestOptions = {},
): Promise<ApiResult<PriceFreshness>> {
  return decoded(publicRead('/prices/freshness', options), isPriceFreshness);
}

// ---- Accounts --------------------------------------------------------------------------
// The account layer serves /v1/me and the session paths Fortify and Sanctum answer at the root of
// the same origin. Every account request sends the session cookie (same-origin credentials), a
// same-origin Referer (Sanctum's first-party check) and refuses redirects, so a redirect never
// lands on the SPA's index.html. Every write carries the XSRF-TOKEN cookie as X-XSRF-TOKEN.

export type Me = components['schemas']['MeResource'];
export type MeBody = operations['getMe']['responses'][200]['content']['application/json'];

/** Fortify's sign-in answer (LoginResponse, RedirectIfTwoFactorAuthenticatable): 200 JSON. */
export interface SignInAnswer {
  readonly two_factor: boolean;
}

/** Fortify's reset answers (forgot-password and reset-password): 200 JSON with one message. */
export interface MessageAnswer {
  readonly message: string;
}

/** Fortify's QR answer (TwoFactorQrCodeController::show): 200 JSON { svg, url }. */
export interface TwoFactorQrCode {
  readonly svg: string;
  readonly url: string;
}

/** Fortify's setup key answer (TwoFactorSecretKeyController::show): 200 JSON { secretKey }. */
export interface TwoFactorSecretKey {
  readonly secretKey: string;
}

/** Fortify's recovery codes (RecoveryCodeController::index): 200, a JSON array of strings. */
export type RecoveryCodes = readonly string[];

/** The two-factor challenge sends one of the two: an authentication code or a recovery code. */
type TwoFactorChallengeForm = { readonly code: string } | { readonly recovery_code: string };

export interface SignInForm {
  readonly email: string;
  readonly password: string;
}

interface SignUpForm {
  readonly invite_code: string;
  readonly email: string;
  readonly password: string;
  readonly password_confirmation: string;
}

interface ResetPasswordForm {
  readonly token: string;
  readonly email: string;
  readonly password: string;
  readonly password_confirmation: string;
}

type Method = 'GET' | 'POST' | 'PUT' | 'DELETE';

/** The root paths the account layer may call (Fortify and Sanctum; nginx routes each to Laravel). */
const SESSION_PATHS: ReadonlySet<string> = new Set([
  '/sanctum/csrf-cookie',
  '/login',
  '/logout',
  '/register',
  '/forgot-password',
  '/reset-password',
  '/email/verification-notification',
  '/user/confirm-password',
  '/user/two-factor-authentication',
  '/user/confirmed-two-factor-authentication',
  '/user/two-factor-qr-code',
  '/user/two-factor-secret-key',
  '/user/two-factor-recovery-codes',
  '/two-factor-challenge',
]);

/** The /v1 account paths: the account itself, the lists, the webhooks and alerts, the demo. */
const ACCOUNT_API =
  /^\/v1\/(?:me|watchlist|portfolio|webhooks|alerts)(?:[/?]|$)|^\/v1\/demo\/(?:session|samples)$/;

/** A verification link is /email/verify/<id>/<hash>?<query>, nothing else. */
const VERIFY_LINK = /^\/email\/verify\/([^/?#]+)\/([^/?#]+)\?/;

/** A path segment that URL resolution would walk with ('.', '..', also percent-encoded). */
function walksPath(segment: string): boolean {
  return /^(?:\.|%2e){1,2}$/i.test(segment);
}

/** True for a verification link that matches that pattern and stays under /email/verify/. */
export function isVerificationLink(link: string): boolean {
  const match = VERIFY_LINK.exec(link);
  if (match === null) return false;
  const segments = [match[1] ?? '', match[2] ?? ''];
  return !link.includes('\\') && !segments.some(walksPath);
}

function isAccountPath(path: string): boolean {
  return SESSION_PATHS.has(path) || ACCOUNT_API.test(path) || isVerificationLink(path);
}

const UNREACHABLE: ApiFailure = {
  ok: false,
  status: 0,
  type: 'about:blank',
  title: 'The PokéPrices API could not be reached',
  requestId: null,
  problem: null,
};

const INVALID_PATH: ApiFailure = {
  ok: false,
  status: 0,
  type: 'about:blank',
  title: 'The account link is not valid',
  requestId: null,
  problem: null,
};

/** The XSRF-TOKEN cookie, decoded, or null when it is missing or malformed. */
function xsrfToken(): string | null {
  for (const pair of document.cookie.split(';')) {
    const at = pair.indexOf('=');
    if (at < 0 || pair.slice(0, at).trim() !== 'XSRF-TOKEN') continue;
    const raw = pair.slice(at + 1).trim();
    if (raw === '') return null;
    try {
      return decodeURIComponent(raw);
    } catch {
      return null;
    }
  }
  return null;
}

interface AccountInit {
  readonly method: Method;
  readonly body?: unknown;
  readonly signal?: AbortSignal | undefined;
}

/** One fetch with the account flags; a network failure or a refused redirect resolves as null. */
async function send(path: string, init: AccountInit): Promise<Response | null> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  const request: RequestInit = {
    method: init.method,
    headers,
    credentials: 'same-origin',
    referrerPolicy: 'same-origin',
    redirect: 'error',
  };
  if (init.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    request.body = JSON.stringify(init.body);
  }
  if (init.method !== 'GET') {
    const token = xsrfToken();
    if (token !== null) headers['X-XSRF-TOKEN'] = token;
  }
  if (init.signal !== undefined) request.signal = init.signal;
  try {
    return await fetch(path, request);
  } catch (error) {
    if (init.signal?.aborted === true) throw error;
    return null;
  }
}

/** GET /sanctum/csrf-cookie (204): sets a fresh XSRF-TOKEN cookie. */
function refreshCsrfCookie(signal: AbortSignal | undefined): Promise<Response | null> {
  return send('/sanctum/csrf-cookie', { method: 'GET', signal });
}

const unauthenticatedWriteListeners = new Set<() => void>();

/**
 * Hears every non-GET account request that answers 401 (the session ended under a write), after
 * its answer is read. The query client registers one while it is mounted and asks GET /v1/me again,
 * as it does for a 401 query. Returns the function that stops listening.
 */
export function onUnauthenticatedWrite(listener: () => void): () => void {
  unauthenticatedWriteListeners.add(listener);
  return () => {
    unauthenticatedWriteListeners.delete(listener);
  };
}

/** Which side of the API a request is on; the two keep their own small differences (see core). */
type Side = 'public' | 'account';

interface CoreInit extends AccountInit {
  readonly side: Side;
}

/** The fetch of one request: the account flags on the account side, Accept alone on /v1 reads. */
async function fetchOnce(path: string, init: CoreInit): Promise<Response | null> {
  if (init.side === 'account') return send(path, init);
  const request: RequestInit = { headers: { Accept: 'application/json' } };
  if (init.signal !== undefined) request.signal = init.signal;
  try {
    return await fetch(path, request);
  } catch (error) {
    if (init.signal?.aborted === true) throw error;
    return null;
  }
}

/**
 * Reads an answer. The account side takes an empty 2xx (and Fortify's JsonResponse('')) as ok
 * without data, a body it cannot read as empty, and names a non-JSON 2xx an unexpected answer; the
 * public side takes an empty or unreadable body as not JSON and names any such answer by its status.
 */
async function readAnswer(response: Response, init: CoreInit): Promise<ApiResult<unknown>> {
  const account = init.side === 'account';
  let body: string | null;
  try {
    body = await response.text();
  } catch (error) {
    if (init.signal?.aborted === true) throw error;
    body = account ? '' : null;
  }
  let data: unknown = undefined;
  let json = account && body !== null && body.trim() === '';
  if (body !== null && body.trim() !== '') {
    try {
      data = JSON.parse(body) as unknown;
      json = true;
    } catch {
      json = false;
    }
  }
  if (response.ok && json) {
    return { ok: true, data: account && data === '' ? undefined : data };
  }
  if (!response.ok && isProblem(data)) {
    return {
      ok: false,
      status: response.status,
      type: data.type,
      title: data.title,
      requestId: data.request_id,
      problem: data,
    };
  }
  return {
    ok: false,
    status: response.status,
    type: 'about:blank',
    title:
      account && response.ok
        ? `The PokéPrices API sent an unexpected answer (HTTP ${String(response.status)})`
        : `The PokéPrices API answered HTTP ${String(response.status)}`,
    requestId: response.headers.get('X-Request-Id'),
    problem: null,
  };
}

/**
 * The one request pipeline (C6). An account path outside the allowed set never reaches fetch. An
 * account write first fetches the CSRF cookie when it is missing; a 419 answer fetches a fresh
 * cookie and retries once, and a second 419 is the failure. A network failure is the unreachable
 * failure on both sides; a cancelled request rejects as cancelled. An account write that answers
 * 401 tells the onUnauthenticatedWrite listeners, after its answer is read.
 */
async function core(path: string, init: CoreInit): Promise<ApiResult<unknown>> {
  if (init.side === 'account' && !isAccountPath(path)) return { ...INVALID_PATH };
  const write = init.side === 'account' && init.method !== 'GET';
  if (write && xsrfToken() === null) await refreshCsrfCookie(init.signal);
  let response = await fetchOnce(path, init);
  if (write && response?.status === 419) {
    await refreshCsrfCookie(init.signal);
    response = await fetchOnce(path, init);
  }
  if (response === null) return { ...UNREACHABLE };
  const result = await readAnswer(response, init);
  if (write && response.status === 401) {
    for (const listener of [...unauthenticatedWriteListeners]) listener();
  }
  return result;
}

/** A public /v1 read (GET, no session flags). */
function publicRead(path: string, options: RequestOptions): Promise<ApiResult<unknown>> {
  return core(BASE + path, { side: 'public', method: 'GET', signal: options.signal });
}

/** An account request as the raw answer, for a read's decoder. */
function accountAnswer(path: string, init: AccountInit): Promise<ApiResult<unknown>> {
  return core(path, { ...init, side: 'account' });
}

/** An account request whose answer the contract types as empty (see trusted). */
function accountRequest(path: string, init: AccountInit): Promise<ApiResult<undefined>> {
  return trusted(accountAnswer(path, init));
}

/** GET /v1/me: who is signed in. Signed out, the answer is a 401 failure. */
export function getMe(options: RequestOptions = {}): Promise<ApiResult<MeBody>> {
  return decoded(accountAnswer('/v1/me', { method: 'GET', signal: options.signal }), isMeBody);
}

/** The value of `key` when data is an object holding it, else undefined. */
function field(data: unknown, key: string): unknown {
  return typeof data === 'object' && data !== null && key in data
    ? (data as Record<string, unknown>)[key]
    : undefined;
}

/** POST /login: 200 { two_factor } (true when the authenticator-app step comes next). */
export async function signIn(form: SignInForm): Promise<ApiResult<SignInAnswer>> {
  const result = await accountAnswer('/login', { method: 'POST', body: form });
  if (!result.ok) return result;
  const twoFactor = field(result.data, 'two_factor');
  return typeof twoFactor === 'boolean'
    ? { ok: true, data: { two_factor: twoFactor } }
    : { ...UNEXPECTED_ANSWER };
}

/** POST /register with the invite code: 201, signed in. */
export function signUp(form: SignUpForm): Promise<ApiResult<undefined>> {
  return accountRequest('/register', { method: 'POST', body: form });
}

/** POST /logout: 204. */
export function signOut(): Promise<ApiResult<undefined>> {
  return accountRequest('/logout', { method: 'POST' });
}

/** POST /forgot-password: 200 with the same message whether or not the account exists. */
export async function requestPasswordReset(form: {
  readonly email: string;
}): Promise<ApiResult<MessageAnswer>> {
  const result = await accountAnswer('/forgot-password', { method: 'POST', body: form });
  if (!result.ok) return result;
  const message = field(result.data, 'message');
  return typeof message === 'string' ? { ok: true, data: { message } } : { ...UNEXPECTED_ANSWER };
}

/** POST /reset-password with the link's token: 200. */
export function resetPassword(form: ResetPasswordForm): Promise<ApiResult<MessageAnswer>> {
  return decoded(accountAnswer('/reset-password', { method: 'POST', body: form }), isMessageAnswer);
}

/**
 * GET of a verification link (204 once verified). A link outside that pattern never reaches
 * fetch: it resolves as a failure with status 0.
 */
export function verifyEmail(
  link: string,
  options: RequestOptions = {},
): Promise<ApiResult<undefined>> {
  if (!isVerificationLink(link)) return Promise.resolve({ ...INVALID_PATH });
  return accountRequest(link, { method: 'GET', signal: options.signal });
}

/** POST /email/verification-notification: 202 sent, 204 when already verified. */
export function resendVerification(): Promise<ApiResult<undefined>> {
  return accountRequest('/email/verification-notification', { method: 'POST' });
}

// ---- Two-factor -----------------------------------------------------------------------
// Fortify's two-factor routes all sit behind RequirePassword (confirmPassword: true): any of them
// may answer 423 {"message": "Password confirmation required."}, which surfaces as an ApiFailure
// with status 423 and type about:blank. The QR code, the setup key and the recovery codes are
// returned to the caller only: they are never cached or stored here.

/** POST /user/confirm-password: 201 (Fortify's PasswordConfirmedResponse); 422 on a wrong one. */
export function confirmPassword(form: {
  readonly password: string;
}): Promise<ApiResult<undefined>> {
  return accountRequest('/user/confirm-password', { method: 'POST', body: form });
}

/** POST /user/two-factor-authentication: 200 with an empty body; a new, unconfirmed secret. */
export function enableTwoFactor(): Promise<ApiResult<undefined>> {
  return accountRequest('/user/two-factor-authentication', { method: 'POST' });
}

/** GET /user/two-factor-qr-code: 200 { svg, url } (Fortify answers [] when no secret exists). */
export async function twoFactorQrCode(): Promise<ApiResult<TwoFactorQrCode>> {
  const result = await accountAnswer('/user/two-factor-qr-code', { method: 'GET' });
  if (!result.ok) return result;
  const svg = field(result.data, 'svg');
  const url = field(result.data, 'url');
  return typeof svg === 'string' && typeof url === 'string'
    ? { ok: true, data: { svg, url } }
    : { ...UNEXPECTED_ANSWER };
}

/** GET /user/two-factor-secret-key: 200 { secretKey } (404 when no secret exists). */
export async function twoFactorSecretKey(): Promise<ApiResult<TwoFactorSecretKey>> {
  const result = await accountAnswer('/user/two-factor-secret-key', { method: 'GET' });
  if (!result.ok) return result;
  const secretKey = field(result.data, 'secretKey');
  return typeof secretKey === 'string'
    ? { ok: true, data: { secretKey } }
    : { ...UNEXPECTED_ANSWER };
}

/** POST /user/confirmed-two-factor-authentication { code }: 200 empty; 422 errors.code. */
export function confirmTwoFactor(form: { readonly code: string }): Promise<ApiResult<undefined>> {
  return accountRequest('/user/confirmed-two-factor-authentication', {
    method: 'POST',
    body: form,
  });
}

/** GET /user/two-factor-recovery-codes: 200, a JSON array of strings. */
export async function recoveryCodes(): Promise<ApiResult<RecoveryCodes>> {
  const result = await accountAnswer('/user/two-factor-recovery-codes', {
    method: 'GET',
  });
  if (!result.ok) return result;
  const codes: unknown = result.data;
  return Array.isArray(codes) && codes.every((code): code is string => typeof code === 'string')
    ? { ok: true, data: codes }
    : { ...UNEXPECTED_ANSWER };
}

/** POST /user/two-factor-recovery-codes: 200 empty; the old codes stop working. */
export function regenerateRecoveryCodes(): Promise<ApiResult<undefined>> {
  return accountRequest('/user/two-factor-recovery-codes', { method: 'POST' });
}

/** DELETE /user/two-factor-authentication: 200 empty; the secret and the codes are removed. */
export function disableTwoFactor(): Promise<ApiResult<undefined>> {
  return accountRequest('/user/two-factor-authentication', { method: 'DELETE' });
}

/** POST /two-factor-challenge { code } or { recovery_code }: 204, signed in. */
export function twoFactorChallenge(form: TwoFactorChallengeForm): Promise<ApiResult<undefined>> {
  return accountRequest('/two-factor-challenge', { method: 'POST', body: form });
}

// ---- Watchlist, portfolio and the demo -------------------------------------------------
// Every price, value and total in these answers is the API's decimal string: the pages print them
// and never compute one. The variant key travels as one encoded path segment.

export type WatchlistBody =
  operations['getWatchlist']['responses'][200]['content']['application/json'];
export type WatchlistItem = components['schemas']['WatchlistItemResource'];
type WatchlistEntry =
  operations['putWatchlistItem']['responses'][200]['content']['application/json'];
export type PortfolioBody =
  operations['getPortfolio']['responses'][200]['content']['application/json'];
export type Holding = components['schemas']['HoldingResource'];
export type HoldingEntry =
  operations['putHolding']['responses'][200]['content']['application/json'];

/** The variant key as one encoded path segment, or null when it would collapse ('', '.', '..'). */
function variantPath(list: 'watchlist' | 'portfolio', variantKey: string): string | null {
  const segment = cardSegment(variantKey);
  return segment === null ? null : `/v1/${list}/${segment}`;
}

/** GET /v1/watchlist: the signed-in account's variants with their prices (401 signed out). */
export function getWatchlist(options: RequestOptions = {}): Promise<ApiResult<WatchlistBody>> {
  return decoded(
    accountAnswer('/v1/watchlist', { method: 'GET', signal: options.signal }),
    isWatchlistBody,
  );
}

/** PUT /v1/watchlist/{variantKey}: 200 whether it was added or already there. */
export function putWatchlistItem(variantKey: string): Promise<ApiResult<WatchlistEntry>> {
  const path = variantPath('watchlist', variantKey);
  if (path === null) return Promise.resolve({ ...INVALID_PATH });
  return decoded(accountAnswer(path, { method: 'PUT' }), isWatchlistEntry);
}

/** DELETE /v1/watchlist/{variantKey}: 204, also when it was not there. */
export function deleteWatchlistItem(variantKey: string): Promise<ApiResult<undefined>> {
  const path = variantPath('watchlist', variantKey);
  if (path === null) return Promise.resolve({ ...INVALID_PATH });
  return accountRequest(path, { method: 'DELETE' });
}

/** GET /v1/portfolio: the holdings, their values and the total, all computed by the API. */
export function getPortfolio(options: RequestOptions = {}): Promise<ApiResult<PortfolioBody>> {
  return decoded(
    accountAnswer('/v1/portfolio', { method: 'GET', signal: options.signal }),
    isPortfolioBody,
  );
}

/**
 * PUT /v1/portfolio/{variantKey} { quantity }: sets (never adds to) the quantity held; 200. A
 * quantity the browser could not read as a whole number is sent as null, so the API's 422 names it.
 */
export function putHolding(
  variantKey: string,
  quantity: number | null,
): Promise<ApiResult<HoldingEntry>> {
  const path = variantPath('portfolio', variantKey);
  if (path === null) return Promise.resolve({ ...INVALID_PATH });
  return decoded(accountAnswer(path, { method: 'PUT', body: { quantity } }), isHoldingEntry);
}

/** DELETE /v1/portfolio/{variantKey}: 204, also when it was not there. */
export function deleteHolding(variantKey: string): Promise<ApiResult<undefined>> {
  const path = variantPath('portfolio', variantKey);
  if (path === null) return Promise.resolve({ ...INVALID_PATH });
  return accountRequest(path, { method: 'DELETE' });
}

/** POST /v1/demo/session: 204, this browser is signed in to the read-only demo account. */
export function startDemo(): Promise<ApiResult<undefined>> {
  return accountRequest('/v1/demo/session', { method: 'POST' });
}

// ---- Your data ------------------------------------------------------------------------
// The export is not fetched here: /account links to GET /v1/me/export and the browser downloads it.

/** The deletion body: the current password, in the JSON body and never in the URL. */
type DeleteAccountForm = operations['deleteMe']['requestBody']['content']['application/json'];

/** POST /v1/me/deletion { password }: 204, the account is gone and this browser is signed out. */
export function deleteAccount(form: DeleteAccountForm): Promise<ApiResult<undefined>> {
  return accountRequest('/v1/me/deletion', { method: 'POST', body: form });
}

// ---- Webhooks -------------------------------------------------------------------------
// The signing secret of a new signed webhook arrives once, in the 201 answer: it is returned to the
// caller only, never cached or stored here. A webhook is addressed by its numeric id.

export type WebhooksBody =
  operations['getWebhooks']['responses'][200]['content']['application/json'];
export type WebhookEndpoint = components['schemas']['WebhookEndpointResource'];
export type WebhookForm = operations['createWebhook']['requestBody']['content']['application/json'];
type WebhookCreated = operations['createWebhook']['responses'][201]['content']['application/json'];
type WebhookTestAnswer = operations['testWebhook']['responses'][202]['content']['application/json'];

const INVALID_WEBHOOK: ApiFailure = {
  ok: false,
  status: 0,
  type: 'about:blank',
  title: 'The webhook id is not valid',
  requestId: null,
  problem: null,
};

/** /v1/webhooks/{id} for a whole, non-negative, safe id; null for anything else. */
function webhookPath(id: number): string | null {
  return Number.isSafeInteger(id) && id >= 0 ? `/v1/webhooks/${String(id)}` : null;
}

/** GET /v1/webhooks: the signed-in account's webhooks, never a URL or a secret (401 signed out). */
export function getWebhooks(options: RequestOptions = {}): Promise<ApiResult<WebhooksBody>> {
  return decoded(
    accountAnswer('/v1/webhooks', { method: 'GET', signal: options.signal }),
    isWebhooksBody,
  );
}

/** POST /v1/webhooks { kind, url, description? }: 201 with the secret of a signed webhook, once. */
export function createWebhook(form: WebhookForm): Promise<ApiResult<WebhookCreated>> {
  return decoded(
    accountAnswer('/v1/webhooks', { method: 'POST', body: form }),
    isWebhookCreated,
    201,
  );
}

/** DELETE /v1/webhooks/{id}: 204, also when the account has no webhook with this id. */
export function deleteWebhook(id: number): Promise<ApiResult<undefined>> {
  const path = webhookPath(id);
  if (path === null) return Promise.resolve({ ...INVALID_WEBHOOK });
  return accountRequest(path, { method: 'DELETE' });
}

/** POST /v1/webhooks/{id}/test: 202 { message_id }; the message is sent by the next sweep. */
export function testWebhook(id: number): Promise<ApiResult<WebhookTestAnswer>> {
  const path = webhookPath(id);
  if (path === null) return Promise.resolve({ ...INVALID_WEBHOOK });
  return decoded(accountAnswer(`${path}/test`, { method: 'POST' }), isWebhookTestAnswer, 202);
}

// ---- Alert settings and per-card alerts -----------------------------------------------

export type NotificationsBody =
  operations['getNotifications']['responses'][200]['content']['application/json'];
export type NotificationSettings = NotificationsBody['data'];
type NotificationsForm =
  operations['putNotifications']['requestBody']['content']['application/json'];
type AlertEntry = operations['putAlert']['responses'][200]['content']['application/json'];

/** GET /v1/me/notifications: where alerts and the digest go, and whether Discord can be chosen. */
export function getNotifications(
  options: RequestOptions = {},
): Promise<ApiResult<NotificationsBody>> {
  return decoded(
    accountAnswer('/v1/me/notifications', { method: 'GET', signal: options.signal }),
    isNotificationsBody,
  );
}

// ---- The read-only demo's samples -----------------------------------------------------

export type DemoSamplesBody =
  operations['getDemoSamples']['responses'][200]['content']['application/json'];
export type DemoSamples = DemoSamplesBody['data'];

/**
 * GET /v1/demo/samples: the demo's alert, its sample delivered email and its sample signed webhook
 * delivery with the published sample secret (404 for any other account, 401 signed out).
 */
export function getDemoSamples(options: RequestOptions = {}): Promise<ApiResult<DemoSamplesBody>> {
  return decoded(
    accountAnswer('/v1/demo/samples', { method: 'GET', signal: options.signal }),
    isDemoSamplesBody,
  );
}

/** PUT /v1/me/notifications { channel, digest }: 200 with the saved settings. */
export function putNotifications(form: NotificationsForm): Promise<ApiResult<NotificationsBody>> {
  return decoded(
    accountAnswer('/v1/me/notifications', {
      method: 'PUT',
      body: { channel: form.channel, digest: form.digest },
    }),
    isNotificationsSaved,
  );
}

/** /v1/alerts/{variantKey}: the key as one segment, encoded once; null when it would collapse. */
function alertPath(variantKey: string): string | null {
  const segment = cardSegment(variantKey);
  return segment === null ? null : `/v1/alerts/${segment}`;
}

/** PUT /v1/alerts/{variantKey} { pct }: sets the whole-percent threshold of a watchlist item. */
export function putAlert(variantKey: string, pct: number): Promise<ApiResult<AlertEntry>> {
  const path = alertPath(variantKey);
  if (path === null) return Promise.resolve({ ...INVALID_PATH });
  return decoded(accountAnswer(path, { method: 'PUT', body: { pct } }), isAlertEntry);
}

/** DELETE /v1/alerts/{variantKey}: 204, the item has no threshold (also when it had none). */
export function deleteAlert(variantKey: string): Promise<ApiResult<undefined>> {
  const path = alertPath(variantKey);
  if (path === null) return Promise.resolve({ ...INVALID_PATH });
  return accountRequest(path, { method: 'DELETE' });
}
