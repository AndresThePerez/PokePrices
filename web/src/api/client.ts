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
  return request<CardDetail>(`/cards/${segment}`, options);
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
  return request<MoversPage>(`/movers?${params.toString()}`, options);
}
