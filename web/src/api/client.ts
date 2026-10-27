// The PokéPrices API client. Same-origin /v1 only: the SPA never calls PokéSearch or any other host.
import type { components, operations } from './schema';

export type Problem = components['schemas']['Problem'];
export type CardSummary = components['schemas']['CardSummaryResource'];
export type CardSearchQuery = operations['searchCards']['parameters']['query'];
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

async function readJson(response: Response, signal: AbortSignal | undefined): Promise<unknown> {
  try {
    return (await response.json()) as unknown;
  } catch (error) {
    // A cancelled request must look cancelled, not failed: rethrow an abort during the body read.
    if (signal?.aborted === true) throw error;
    return undefined;
  }
}

async function request<T>(path: string, options: RequestOptions): Promise<ApiResult<T>> {
  const init: RequestInit = { headers: { Accept: 'application/json' } };
  if (options.signal !== undefined) init.signal = options.signal;
  let response: Response;
  try {
    response = await fetch(BASE + path, init);
  } catch (error) {
    if (options.signal?.aborted === true) throw error;
    return {
      ok: false,
      status: 0,
      type: 'about:blank',
      title: 'The PokéPrices API could not be reached',
      requestId: null,
      problem: null,
    };
  }
  const body = await readJson(response, options.signal);
  if (response.ok && body !== undefined) return { ok: true, data: body as T };
  if (!response.ok && isProblem(body)) {
    return {
      ok: false,
      status: response.status,
      type: body.type,
      title: body.title,
      requestId: body.request_id,
      problem: body,
    };
  }
  return {
    ok: false,
    status: response.status,
    type: 'about:blank',
    title: `The PokéPrices API answered HTTP ${String(response.status)}`,
    requestId: response.headers.get('X-Request-Id'),
    problem: null,
  };
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

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** The top level a page dereferences: `data` as a list or an object, and `meta` when it has one. */
interface Envelope {
  readonly data: 'list' | 'object';
  readonly meta: boolean;
}

function hasEnvelope(body: unknown, envelope: Envelope): boolean {
  if (!isRecord(body)) return false;
  const data = envelope.data === 'list' ? Array.isArray(body.data) : isRecord(body.data);
  return data && (!envelope.meta || isRecord(body.meta));
}

/**
 * The envelope decoder: a success whose top level is not the documented envelope is the
 * unexpected-answer failure, so the page shows its problem block instead of throwing in render.
 * Failures pass through unchanged; the fields inside data are still trusted to the contract.
 */
async function decoded<T>(
  pending: Promise<ApiResult<unknown>>,
  envelope: Envelope,
): Promise<ApiResult<T>> {
  const result = await pending;
  if (!result.ok) return result;
  return hasEnvelope(result.data, envelope)
    ? { ok: true, data: result.data as T }
    : { ...UNEXPECTED_ANSWER };
}

const LIST_ENVELOPE: Envelope = { data: 'list', meta: true };

/** GET /v1/cards?q=&page=&page_size= (the PokéSearch search, proxied by the API). */
export function searchCards(
  query: CardSearchQuery,
  options: RequestOptions = {},
): Promise<ApiResult<CardSearchPage>> {
  const params = new URLSearchParams({ q: query.q });
  if (query.page !== undefined) params.set('page', String(query.page));
  if (query.page_size !== undefined) params.set('page_size', String(query.page_size));
  return request<CardSearchPage>(`/cards?${params.toString()}`, options);
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
  return decoded<CardDetail>(request(`/cards/${segment}`, options), { data: 'object', meta: true });
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
  return request<CardPrices>(`/cards/${segment}/prices?${params.toString()}`, options);
}

/** GET /v1/movers?direction=&limit= (the newest movers day, ranked; limit 1 to 50). */
export function getMovers(
  direction: MoversDirection,
  limit: number,
  options: RequestOptions = {},
): Promise<ApiResult<MoversPage>> {
  const params = new URLSearchParams({ direction, limit: String(limit) });
  return decoded<MoversPage>(request(`/movers?${params.toString()}`, options), LIST_ENVELOPE);
}

// ---- Accounts (P4 D11) --------------------------------------------------------------------------
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
export type TwoFactorChallengeForm = { readonly code: string } | { readonly recovery_code: string };

export interface SignInForm {
  readonly email: string;
  readonly password: string;
}

export interface SignUpForm {
  readonly invite_code: string;
  readonly email: string;
  readonly password: string;
  readonly password_confirmation: string;
}

export interface ResetPasswordForm {
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
  /^\/v1\/(?:me|watchlist|portfolio|webhooks|alerts)(?:[/?]|$)|^\/v1\/demo\/session$/;

/** D10: a verification link is /email/verify/<id>/<hash>?<query>, nothing else. */
const VERIFY_LINK = /^\/email\/verify\/([^/?#]+)\/([^/?#]+)\?/;

/** A path segment that URL resolution would walk with ('.', '..', also percent-encoded). */
function walksPath(segment: string): boolean {
  return /^(?:\.|%2e){1,2}$/i.test(segment);
}

/** True for a verification link that matches D10's pattern and stays under /email/verify/. */
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

/** Reads an account answer: an empty 2xx is ok without data, a non-JSON 2xx is a failure. */
async function settle<T>(
  response: Response,
  signal: AbortSignal | undefined,
): Promise<ApiResult<T>> {
  let text: string;
  try {
    text = await response.text();
  } catch (error) {
    if (signal?.aborted === true) throw error;
    text = '';
  }
  let body: unknown = undefined;
  let json = text.trim() === '';
  if (!json) {
    try {
      body = JSON.parse(text) as unknown;
      json = true;
    } catch {
      json = false;
    }
  }
  if (response.ok && json) {
    // Fortify answers 201/202/204 with no body or with JsonResponse('') ('""'): no data.
    return { ok: true, data: (body === '' ? undefined : body) as T };
  }
  if (!response.ok && isProblem(body)) {
    return {
      ok: false,
      status: response.status,
      type: body.type,
      title: body.title,
      requestId: body.request_id,
      problem: body,
    };
  }
  return {
    ok: false,
    status: response.status,
    type: 'about:blank',
    title: response.ok
      ? `The PokéPrices API sent an unexpected answer (HTTP ${String(response.status)})`
      : `The PokéPrices API answered HTTP ${String(response.status)}`,
    requestId: response.headers.get('X-Request-Id'),
    problem: null,
  };
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

/**
 * The account request (D11). A write first fetches the CSRF cookie when it is missing; a 419
 * answer fetches a fresh cookie and retries once, and a second 419 is the failure. A write that
 * answers 401 tells the onUnauthenticatedWrite listeners.
 */
async function accountRequest<T>(path: string, init: AccountInit): Promise<ApiResult<T>> {
  if (!isAccountPath(path)) return { ...INVALID_PATH };
  const write = init.method !== 'GET';
  if (write && xsrfToken() === null) await refreshCsrfCookie(init.signal);
  let response = await send(path, init);
  if (write && response?.status === 419) {
    await refreshCsrfCookie(init.signal);
    response = await send(path, init);
  }
  if (response === null) return { ...UNREACHABLE };
  const result = await settle<T>(response, init.signal);
  if (write && response.status === 401) {
    for (const listener of [...unauthenticatedWriteListeners]) listener();
  }
  return result;
}

/** GET /v1/me: who is signed in. Signed out, the answer is a 401 failure. */
export function getMe(options: RequestOptions = {}): Promise<ApiResult<MeBody>> {
  return accountRequest<MeBody>('/v1/me', { method: 'GET', signal: options.signal });
}

/** The value of `key` when data is an object holding it, else undefined. */
function field(data: unknown, key: string): unknown {
  return typeof data === 'object' && data !== null && key in data
    ? (data as Record<string, unknown>)[key]
    : undefined;
}

/** POST /login: 200 { two_factor } (true when the authenticator-app step comes next). */
export async function signIn(form: SignInForm): Promise<ApiResult<SignInAnswer>> {
  const result = await accountRequest<unknown>('/login', { method: 'POST', body: form });
  if (!result.ok) return result;
  const twoFactor = field(result.data, 'two_factor');
  return typeof twoFactor === 'boolean'
    ? { ok: true, data: { two_factor: twoFactor } }
    : { ...UNEXPECTED_ANSWER };
}

/** POST /register with the invite code: 201, signed in. */
export function signUp(form: SignUpForm): Promise<ApiResult<undefined>> {
  return accountRequest<undefined>('/register', { method: 'POST', body: form });
}

/** POST /logout: 204. */
export function signOut(): Promise<ApiResult<undefined>> {
  return accountRequest<undefined>('/logout', { method: 'POST' });
}

/** POST /forgot-password: 200 with the same message whether or not the account exists. */
export async function requestPasswordReset(form: {
  readonly email: string;
}): Promise<ApiResult<MessageAnswer>> {
  const result = await accountRequest<unknown>('/forgot-password', { method: 'POST', body: form });
  if (!result.ok) return result;
  const message = field(result.data, 'message');
  return typeof message === 'string' ? { ok: true, data: { message } } : { ...UNEXPECTED_ANSWER };
}

/** POST /reset-password with the link's token: 200. */
export function resetPassword(form: ResetPasswordForm): Promise<ApiResult<MessageAnswer>> {
  return accountRequest<MessageAnswer>('/reset-password', { method: 'POST', body: form });
}

/**
 * GET of a verification link (204 once verified). A link outside D10's pattern never reaches
 * fetch: it resolves as a failure with status 0.
 */
export function verifyEmail(
  link: string,
  options: RequestOptions = {},
): Promise<ApiResult<undefined>> {
  if (!isVerificationLink(link)) return Promise.resolve({ ...INVALID_PATH });
  return accountRequest<undefined>(link, { method: 'GET', signal: options.signal });
}

/** POST /email/verification-notification: 202 sent, 204 when already verified. */
export function resendVerification(): Promise<ApiResult<undefined>> {
  return accountRequest<undefined>('/email/verification-notification', { method: 'POST' });
}

// ---- Two-factor (P4 T04) -----------------------------------------------------------------------
// Fortify's two-factor routes all sit behind RequirePassword (confirmPassword: true): any of them
// may answer 423 {"message": "Password confirmation required."}, which surfaces as an ApiFailure
// with status 423 and type about:blank. The QR code, the setup key and the recovery codes are
// returned to the caller only: they are never cached or stored here.

/** POST /user/confirm-password: 201 (Fortify's PasswordConfirmedResponse); 422 on a wrong one. */
export function confirmPassword(form: {
  readonly password: string;
}): Promise<ApiResult<undefined>> {
  return accountRequest<undefined>('/user/confirm-password', { method: 'POST', body: form });
}

/** POST /user/two-factor-authentication: 200 with an empty body; a new, unconfirmed secret. */
export function enableTwoFactor(): Promise<ApiResult<undefined>> {
  return accountRequest<undefined>('/user/two-factor-authentication', { method: 'POST' });
}

/** GET /user/two-factor-qr-code: 200 { svg, url } (Fortify answers [] when no secret exists). */
export async function twoFactorQrCode(): Promise<ApiResult<TwoFactorQrCode>> {
  const result = await accountRequest<unknown>('/user/two-factor-qr-code', { method: 'GET' });
  if (!result.ok) return result;
  const svg = field(result.data, 'svg');
  const url = field(result.data, 'url');
  return typeof svg === 'string' && typeof url === 'string'
    ? { ok: true, data: { svg, url } }
    : { ...UNEXPECTED_ANSWER };
}

/** GET /user/two-factor-secret-key: 200 { secretKey } (404 when no secret exists). */
export async function twoFactorSecretKey(): Promise<ApiResult<TwoFactorSecretKey>> {
  const result = await accountRequest<unknown>('/user/two-factor-secret-key', { method: 'GET' });
  if (!result.ok) return result;
  const secretKey = field(result.data, 'secretKey');
  return typeof secretKey === 'string'
    ? { ok: true, data: { secretKey } }
    : { ...UNEXPECTED_ANSWER };
}

/** POST /user/confirmed-two-factor-authentication { code }: 200 empty; 422 errors.code. */
export function confirmTwoFactor(form: { readonly code: string }): Promise<ApiResult<undefined>> {
  return accountRequest<undefined>('/user/confirmed-two-factor-authentication', {
    method: 'POST',
    body: form,
  });
}

/** GET /user/two-factor-recovery-codes: 200, a JSON array of strings. */
export async function recoveryCodes(): Promise<ApiResult<RecoveryCodes>> {
  const result = await accountRequest<unknown>('/user/two-factor-recovery-codes', {
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
  return accountRequest<undefined>('/user/two-factor-recovery-codes', { method: 'POST' });
}

/** DELETE /user/two-factor-authentication: 200 empty; the secret and the codes are removed. */
export function disableTwoFactor(): Promise<ApiResult<undefined>> {
  return accountRequest<undefined>('/user/two-factor-authentication', { method: 'DELETE' });
}

/** POST /two-factor-challenge { code } or { recovery_code }: 204, signed in. */
export function twoFactorChallenge(form: TwoFactorChallengeForm): Promise<ApiResult<undefined>> {
  return accountRequest<undefined>('/two-factor-challenge', { method: 'POST', body: form });
}

// ---- Watchlist, portfolio and the demo (P4 T07) -------------------------------------------------
// Every price, value and total in these answers is the API's decimal string: the pages print them
// and never compute one. The variant key travels as one encoded path segment.

export type WatchlistBody =
  operations['getWatchlist']['responses'][200]['content']['application/json'];
export type WatchlistItem = components['schemas']['WatchlistItemResource'];
export type WatchlistEntry =
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
  return decoded<WatchlistBody>(
    accountRequest('/v1/watchlist', { method: 'GET', signal: options.signal }),
    LIST_ENVELOPE,
  );
}

/** PUT /v1/watchlist/{variantKey}: 200 whether it was added or already there. */
export function putWatchlistItem(variantKey: string): Promise<ApiResult<WatchlistEntry>> {
  const path = variantPath('watchlist', variantKey);
  if (path === null) return Promise.resolve({ ...INVALID_PATH });
  return accountRequest<WatchlistEntry>(path, { method: 'PUT' });
}

/** DELETE /v1/watchlist/{variantKey}: 204, also when it was not there. */
export function deleteWatchlistItem(variantKey: string): Promise<ApiResult<undefined>> {
  const path = variantPath('watchlist', variantKey);
  if (path === null) return Promise.resolve({ ...INVALID_PATH });
  return accountRequest<undefined>(path, { method: 'DELETE' });
}

/** GET /v1/portfolio: the holdings, their values and the total, all computed by the API. */
export function getPortfolio(options: RequestOptions = {}): Promise<ApiResult<PortfolioBody>> {
  return decoded<PortfolioBody>(
    accountRequest('/v1/portfolio', { method: 'GET', signal: options.signal }),
    LIST_ENVELOPE,
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
  return accountRequest<HoldingEntry>(path, { method: 'PUT', body: { quantity } });
}

/** DELETE /v1/portfolio/{variantKey}: 204, also when it was not there. */
export function deleteHolding(variantKey: string): Promise<ApiResult<undefined>> {
  const path = variantPath('portfolio', variantKey);
  if (path === null) return Promise.resolve({ ...INVALID_PATH });
  return accountRequest<undefined>(path, { method: 'DELETE' });
}

/** POST /v1/demo/session: 204, this browser is signed in to the read-only demo account. */
export function startDemo(): Promise<ApiResult<undefined>> {
  return accountRequest<undefined>('/v1/demo/session', { method: 'POST' });
}

// ---- Your data (P4 T08) ------------------------------------------------------------------------
// The export is not fetched here: /account links to GET /v1/me/export and the browser downloads it.

/** The deletion body: the current password, in the JSON body and never in the URL (D4). */
export type DeleteAccountForm =
  operations['deleteMe']['requestBody']['content']['application/json'];

/** POST /v1/me/deletion { password }: 204, the account is gone and this browser is signed out. */
export function deleteAccount(form: DeleteAccountForm): Promise<ApiResult<undefined>> {
  return accountRequest<undefined>('/v1/me/deletion', { method: 'POST', body: form });
}

// ---- Webhooks (P5 T04) -------------------------------------------------------------------------
// The signing secret of a new signed webhook arrives once, in the 201 answer: it is returned to the
// caller only, never cached or stored here. A webhook is addressed by its numeric id.

export type WebhooksBody =
  operations['getWebhooks']['responses'][200]['content']['application/json'];
export type WebhookEndpoint = components['schemas']['WebhookEndpointResource'];
export type WebhookForm = operations['createWebhook']['requestBody']['content']['application/json'];
export type WebhookCreated =
  operations['createWebhook']['responses'][201]['content']['application/json'];
export type WebhookTestAnswer =
  operations['testWebhook']['responses'][202]['content']['application/json'];

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
  return decoded<WebhooksBody>(
    accountRequest('/v1/webhooks', { method: 'GET', signal: options.signal }),
    LIST_ENVELOPE,
  );
}

/** POST /v1/webhooks { kind, url, description? }: 201 with the secret of a signed webhook, once. */
export function createWebhook(form: WebhookForm): Promise<ApiResult<WebhookCreated>> {
  return accountRequest<WebhookCreated>('/v1/webhooks', { method: 'POST', body: form });
}

/** DELETE /v1/webhooks/{id}: 204, also when the account has no webhook with this id. */
export function deleteWebhook(id: number): Promise<ApiResult<undefined>> {
  const path = webhookPath(id);
  if (path === null) return Promise.resolve({ ...INVALID_WEBHOOK });
  return accountRequest<undefined>(path, { method: 'DELETE' });
}

/** POST /v1/webhooks/{id}/test: 202 { message_id }; the message is sent by the next sweep. */
export function testWebhook(id: number): Promise<ApiResult<WebhookTestAnswer>> {
  const path = webhookPath(id);
  if (path === null) return Promise.resolve({ ...INVALID_WEBHOOK });
  return accountRequest<WebhookTestAnswer>(`${path}/test`, { method: 'POST' });
}

// ---- Alert settings and per-card alerts (P5 T07) -----------------------------------------------

export type NotificationsBody =
  operations['getNotifications']['responses'][200]['content']['application/json'];
export type NotificationSettings = NotificationsBody['data'];
export type NotificationsForm =
  operations['putNotifications']['requestBody']['content']['application/json'];
export type AlertEntry = operations['putAlert']['responses'][200]['content']['application/json'];

/** GET /v1/me/notifications: where alerts and the digest go, and whether Discord can be chosen. */
export function getNotifications(
  options: RequestOptions = {},
): Promise<ApiResult<NotificationsBody>> {
  return decoded<NotificationsBody>(
    accountRequest('/v1/me/notifications', { method: 'GET', signal: options.signal }),
    { data: 'object', meta: false },
  );
}

/** PUT /v1/me/notifications { channel, digest }: 200 with the saved settings. */
export function putNotifications(form: NotificationsForm): Promise<ApiResult<NotificationsBody>> {
  return accountRequest<NotificationsBody>('/v1/me/notifications', {
    method: 'PUT',
    body: { channel: form.channel, digest: form.digest },
  });
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
  return accountRequest<AlertEntry>(path, { method: 'PUT', body: { pct } });
}

/** DELETE /v1/alerts/{variantKey}: 204, the item has no threshold (also when it had none). */
export function deleteAlert(variantKey: string): Promise<ApiResult<undefined>> {
  const path = alertPath(variantKey);
  if (path === null) return Promise.resolve({ ...INVALID_PATH });
  return accountRequest<undefined>(path, { method: 'DELETE' });
}
