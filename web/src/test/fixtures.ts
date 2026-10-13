// Typed test fixtures for the PokéPrices SPA. Every fixture is checked with `satisfies` against
// the generated schema (src/api/schema.d.ts), so a contract change breaks tsc (Q6) here first.
import { onTestFinished, vi } from 'vitest';
import type { components, operations } from '../api/schema';
import type { RecoveryCodes, TwoFactorQrCode, TwoFactorSecretKey } from '../api/client';

type Schemas = components['schemas'];
type SearchBody = operations['searchCards']['responses'][200]['content']['application/json'];

export const unownQuestion = {
  id: 'ex10-?',
  name: 'Unown',
  number: '?',
  rarity: 'Rare',
  supertype: 'Pokémon',
  set_id: 'ex10',
  set_name: 'Unseen Forces',
  release_date: '2005-08-22',
  image_small: 'https://images.example.com/ex10/question.png',
} satisfies Schemas['CardSummaryResource'];

export const unownExclamation = {
  id: 'ex10-!',
  name: 'Unown',
  number: '!',
  rarity: 'Rare',
  supertype: 'Pokémon',
  set_id: 'ex10',
  set_name: 'Unseen Forces',
  release_date: '2005-08-22',
  image_small: 'https://images.example.com/ex10/exclamation.png',
} satisfies Schemas['CardSummaryResource'];

export const sprigatitoPromo = {
  id: 'svp-1',
  name: 'Sprigatito',
  number: '1',
  rarity: null,
  supertype: 'Pokémon',
  set_id: 'svp',
  set_name: 'Scarlet & Violet Black Star Promos',
  release_date: '2023-01-01',
  image_small: 'https://images.example.com/svp/1.png',
} satisfies Schemas['CardSummaryResource'];

export const cardSummaries = [
  unownQuestion,
  unownExclamation,
  sprigatitoPromo,
] as const satisfies readonly Schemas['CardSummaryResource'][];

export const searchPage = {
  data: [...cardSummaries],
  meta: { total: 3, page: 1, pages: 1, page_size: 24, degraded: false, cached: false },
} satisfies SearchBody;

export const degradedSearchPage = {
  data: [unownQuestion],
  meta: { total: 1, page: 1, pages: 1, page_size: 24, degraded: true, cached: true },
} satisfies SearchBody;

export const upstreamUnavailableProblem = {
  type: 'https://pokeprices.andrestheperez.com/problems/upstream-unavailable',
  title: 'Upstream Unavailable',
  status: 503,
  instance: '/v1/cards',
  request_id: 'req-t46-upstream-0001',
} satisfies Schemas['Problem'];

export const validationProblem = {
  type: 'https://pokeprices.andrestheperez.com/problems/validation-failed',
  title: 'Validation Failed',
  status: 422,
  instance: '/v1/cards',
  request_id: 'req-t46-validation-0002',
  errors: { page: ['The page field must be at least 1.'] },
} satisfies Schemas['Problem'];

export const cardNotFoundProblem = {
  type: 'https://pokeprices.andrestheperez.com/problems/card-not-found',
  title: 'Card Not Found',
  status: 404,
  instance: '/v1/cards/ex10-%3F',
  request_id: 'req-t46-notfound-0004',
  catalog_missing: true,
} satisfies Schemas['Problem'];

type CardBody = operations['getCard']['responses'][200]['content']['application/json'];

/** Unown ? as GET /v1/cards/{id} serves it: seeded catalog, two variants with one source each. */
export const unownCard = {
  id: 'ex10-?',
  name: 'Unown',
  supertype: 'Pokémon',
  subtypes: ['Basic'],
  hp: 50,
  types: ['Psychic'],
  rarity: 'Rare',
  number: '?',
  artist: 'Kagemaru Himeno',
  set: {
    id: 'ex10',
    name: 'Unseen Forces',
    series: 'EX',
    total: 145,
    release_date: '2005-08-22',
  },
  images: {
    small: 'https://images.example.com/ex10/question.png',
    large: 'https://images.example.com/ex10/question_hires.png',
  },
  variants: [
    {
      variant_key: 'ex10-?:holo:unlimited:-',
      finish: 'holo',
      edition: 'unlimited',
      treatment: '-',
      sources: [
        {
          source: 'tcgplayer',
          product_id: 88123,
          subtype: 'Holofoil',
          group_id: 1399,
          series_epoch: 1,
          map_snapshot_day: '2026-10-11',
        },
      ],
      price: null,
    },
    {
      variant_key: 'ex10-?:reverse:unlimited:-',
      finish: 'reverse',
      edition: 'unlimited',
      treatment: '-',
      sources: [
        {
          source: 'tcgplayer',
          product_id: 88124,
          subtype: 'Reverse Holofoil',
          group_id: 1399,
          series_epoch: 1,
          map_snapshot_day: '2026-10-10',
        },
      ],
      price: null,
    },
  ],
  catalog: {
    seed_ref: '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11',
    seeded_at: '2026-10-11T20:05:45Z',
    status: 'ok',
  },
} satisfies Schemas['CardResource'];

/** The same card while PokéSearch has no seed stamp: no catalog version to name. */
export const unseededUnownCard = {
  ...unownCard,
  catalog: { seed_ref: null, seeded_at: null, status: 'unverified' },
} satisfies Schemas['CardResource'];

/**
 * The same card after two ingested days (P2-i1 T05): the holo variant has a close and a previous close, the
 * reverse holo variant has no close in the collected snapshots.
 */
export const pricedUnownCard = {
  ...unownCard,
  variants: [
    {
      variant_key: 'ex10-?:holo:unlimited:-',
      finish: 'holo',
      edition: 'unlimited',
      treatment: '-',
      sources: [
        {
          source: 'tcgplayer',
          product_id: 88123,
          subtype: 'Holofoil',
          group_id: 1399,
          series_epoch: 1,
          map_snapshot_day: '2026-10-11',
        },
      ],
      price: {
        source: 'tcgplayer',
        feed: 'tcgcsv',
        basis: 'market',
        currency: 'USD',
        cadence: 'daily',
        close: '70.81',
        day: '2026-10-12',
        as_of: '2026-10-12T20:05:44Z',
        previous_close: '69.36',
        previous_day: '2026-10-11',
        change: '1.45',
        change_pct: '2.09',
        series_epoch: 1,
      },
    },
    {
      variant_key: 'ex10-?:reverse:unlimited:-',
      finish: 'reverse',
      edition: 'unlimited',
      treatment: '-',
      sources: [
        {
          source: 'tcgplayer',
          product_id: 88124,
          subtype: 'Reverse Holofoil',
          group_id: 1399,
          series_epoch: 1,
          map_snapshot_day: '2026-10-10',
        },
      ],
      price: null,
    },
  ],
} satisfies Schemas['CardResource'];

export const pricedCardBody = {
  data: pricedUnownCard,
  meta: { degraded: false },
} satisfies CardBody;

type Variant = Schemas['CardResource']['variants'][number];
export type VariantPrice = NonNullable<Variant['price']>;

/** A daily close (P2-i1 T06) with only the fields a test sets changed; the rest is provenance. */
function closeOf(fields: Partial<VariantPrice>): VariantPrice {
  return {
    source: 'tcgplayer',
    feed: 'tcgcsv',
    basis: 'market',
    currency: 'USD',
    cadence: 'daily',
    close: '70.81',
    day: '2026-10-12',
    as_of: '2026-10-12T20:05:44Z',
    previous_close: '69.36',
    previous_day: '2026-10-11',
    change: '1.45',
    change_pct: '2.09',
    series_epoch: 1,
    ...fields,
  };
}

/** Up against the previous close of 2026-10-11. */
export const risingPrice = closeOf({});

/** Down against the previous close of 2026-10-11. */
export const fallingPrice = closeOf({ close: '68.06', change: '-1.30', change_pct: '-1.87' });

/** The same close as the day before. */
export const unchangedPrice = closeOf({
  close: '2257.87',
  previous_close: '2257.87',
  change: '0.00',
  change_pct: '0.00',
});

/** The variant's first real close: no previous close within 14 days of the same epoch. */
export const firstClosePrice = closeOf({
  previous_close: null,
  previous_day: null,
  change: null,
  change_pct: null,
});

/** Up from a previous close of 0.00: the change is known, the percentage is not (D4). */
export const upFromZeroPrice = closeOf({
  close: '0.25',
  previous_close: '0.00',
  change: '0.25',
  change_pct: null,
});

/** Still 0.00 after a previous close of 0.00: no change, no percentage. */
export const flatAtZeroPrice = closeOf({
  close: '0.00',
  previous_close: '0.00',
  change: '0.00',
  change_pct: null,
});

/** A fall with no percentage: the page must print the change and omit the percentage. */
export const downWithoutPctPrice = closeOf({
  close: '0.75',
  previous_close: '1.00',
  change: '-0.25',
  change_pct: null,
});

/** Four- and seven-digit money and sub-dollar money, as the API strings them. */
export const thousandsPrice = closeOf({
  close: '1234.50',
  previous_close: '1234.57',
  change: '-0.07',
  change_pct: '-0.01',
});
export const centsPrice = closeOf({
  close: '0.07',
  previous_close: '1234.57',
  change: '-1234.50',
  change_pct: '-99.99',
});
export const millionPrice = closeOf({
  close: '1000000.00',
  previous_close: '999999.90',
  change: '0.10',
  change_pct: '0.00',
});

/** The Unown card with one variant per given price (null = no close in the snapshots). */
export function cardWithPrices(...prices: readonly (VariantPrice | null)[]): CardBody {
  const variants: Variant[] = prices.map((price, index) => ({
    variant_key: `ex10-?:finish${String(index)}:unlimited:-`,
    finish: `finish${String(index)}`,
    edition: 'unlimited',
    treatment: '-',
    sources: [
      {
        source: 'tcgplayer',
        product_id: 88123 + index,
        subtype: 'Holofoil',
        group_id: 1399,
        series_epoch: 1,
        map_snapshot_day: '2026-10-11',
      },
    ],
    price,
  }));
  return { data: { ...unownCard, variants }, meta: { degraded: false } };
}

/** The same card before any product map build: no variant rows. */
export const unmappedUnownCard = {
  ...unownCard,
  variants: [],
} satisfies Schemas['CardResource'];

export const cardBody = { data: unownCard, meta: { degraded: false } } satisfies CardBody;

export const unseededCardBody = {
  data: unseededUnownCard,
  meta: { degraded: false },
} satisfies CardBody;

export const unmappedCardBody = {
  data: unmappedUnownCard,
  meta: { degraded: false },
} satisfies CardBody;

/** The last good copy while PokéSearch is down; stale_since is a cache time the page never shows. */
export const degradedCardBody = {
  data: unownCard,
  meta: { degraded: true, stale_since: '2026-10-12T06:30:00Z' },
} satisfies CardBody;

export const cardNotFoundPlainProblem = {
  type: 'https://pokeprices.andrestheperez.com/problems/card-not-found',
  title: 'Card Not Found',
  status: 404,
  instance: '/v1/cards/ex10-%3F',
  request_id: 'req-t48-notfound-0005',
} satisfies Schemas['Problem'];

export type PricesBody =
  operations['getCardPrices']['responses'][200]['content']['application/json'];
export type PriceSeries = PricesBody['data'][number];
export type PricePoint = PriceSeries['points'][number];

/** One daily point of a price series; a real close of epoch 1 unless `fields` say otherwise. */
export function pointOf(day: string, close: string, fields: Partial<PricePoint> = {}): PricePoint {
  return {
    day,
    close,
    is_filled: false,
    filled_from: null,
    as_of: `${day}T20:05:44Z`,
    series_epoch: 1,
    ...fields,
  };
}

/** A filled point: it repeats the close of `from` (P2 D3). */
export function filledOf(day: string, close: string, from: string, epoch = 1): PricePoint {
  return pointOf(day, close, {
    is_filled: true,
    filled_from: from,
    as_of: `${from}T20:05:44Z`,
    series_epoch: epoch,
  });
}

/** A series named like the Unown card's variants; history_begins defaults to its first point. */
export function seriesOf(
  variantKey: string,
  points: readonly PricePoint[],
  historyBegins: string | null = points[0]?.day ?? null,
): PriceSeries {
  const [, finish = '-', edition = '-', treatment = '-'] = variantKey.split(':');
  return {
    variant_key: variantKey,
    finish,
    edition,
    treatment,
    history_begins: historyBegins,
    points: [...points],
  };
}

/** GET /v1/cards/{id}/prices over the given series; the window defaults to 1M ending 2026-10-12. */
export function historyBody(
  series: readonly PriceSeries[],
  meta: Partial<PricesBody['meta']> = {},
): PricesBody {
  return {
    data: [...series],
    meta: {
      source: 'tcgplayer',
      feed: 'tcgcsv',
      basis: 'market',
      currency: 'USD',
      cadence: 'daily',
      range: '1M',
      from: '2026-09-13',
      to: '2026-10-12',
      as_of: '2026-10-12T20:05:44Z',
      ...meta,
    },
  } satisfies PricesBody;
}

/**
 * The history the API would serve beside a card body: one series per variant, a priced variant
 * holding its previous close (when there is one) and its close, an unpriced one no points and
 * history_begins null. Used by the fetch stubs that route `/prices?range=` URLs (T05).
 */
export function historyFor(card: CardBody): PricesBody {
  const series = card.data.variants.map((variant) => {
    const price = variant.price;
    const points: PricePoint[] = [];
    if (price !== null) {
      if (price.previous_close !== null && price.previous_day !== null) {
        points.push(pointOf(price.previous_day, price.previous_close));
      }
      points.push(pointOf(price.day, price.close));
    }
    return {
      variant_key: variant.variant_key,
      finish: variant.finish,
      edition: variant.edition,
      treatment: variant.treatment,
      history_begins: points[0]?.day ?? null,
      points,
    };
  });
  const days = card.data.variants.flatMap((variant) =>
    variant.price === null ? [] : [variant.price.day],
  );
  const to = days.length === 0 ? null : days.reduce((a, b) => (b > a ? b : a));
  return historyBody(series, { to, from: to === null ? null : '2026-09-13' });
}

export type MoversBody = operations['getMovers']['responses'][200]['content']['application/json'];
export type Mover = Schemas['MoverResource'];

/** A mover row (GET /v1/movers): Chimecho's reverse holo up 361.30 %, unless `fields` say otherwise. */
export function moverOf(fields: Partial<Mover> = {}): Mover {
  return {
    rank: 1,
    card_id: 'ex5-17',
    card: { name: 'Chimecho', set_name: 'Hidden Legends', number: '17' },
    variant_key: 'ex5-17:reverse:-:-',
    finish: 'reverse',
    edition: '-',
    treatment: '-',
    close: '26.34',
    previous_close: '5.71',
    previous_day: '2026-10-11',
    change: '20.63',
    change_pct: '361.30',
    series_epoch: 1,
    ...fields,
  } satisfies Mover;
}

/** The biggest rise of 2026-10-12. */
export const risingMover = moverOf();

/** The biggest fall of 2026-10-12: Cacturne ex, holo, down 60.54 %. */
export const fallingMover = moverOf({
  card_id: 'ex9-91',
  card: { name: 'Cacturne ex', set_name: 'Emerald', number: '91' },
  variant_key: 'ex9-91:holo:-:-',
  finish: 'holo',
  close: '65.11',
  previous_close: '165.00',
  change: '-99.89',
  change_pct: '-60.54',
});

/** GET /v1/movers for one direction over the given rows; the day defaults to 2026-10-12. */
export function moversBody(
  direction: 'up' | 'down',
  rows: readonly Mover[],
  meta: Partial<MoversBody['meta']> = {},
): MoversBody {
  return {
    data: [...rows],
    meta: {
      day: '2026-10-12',
      as_of: '2026-10-12T20:05:44Z',
      source: 'tcgplayer',
      feed: 'tcgcsv',
      basis: 'market',
      currency: 'USD',
      window: 'previous_close',
      min_previous_close: '2.00',
      direction,
      limit: 50,
      degraded: false,
      ...meta,
    },
  } satisfies MoversBody;
}

/** True for a GET /v1/movers URL. */
export function isMoversUrl(url: string): boolean {
  return url.startsWith('/v1/movers?');
}

/**
 * A fetch reply for tests that render the home page: a movers URL gets one rising or one falling
 * row (by its direction), any other URL fails like a request that reached no server.
 */
export function answerMovers(url: string): Response | Error {
  if (!isMoversUrl(url)) return new Error('no request expected');
  return url.includes('direction=down')
    ? jsonResponse(moversBody('down', [fallingMover]))
    : jsonResponse(moversBody('up', [risingMover]));
}

/** True for a GET /v1/cards/{id}/prices URL (the history request). */
export function isHistoryUrl(url: string): boolean {
  return url.includes('/prices?range=');
}

/** A JSON response; Content-Type defaults to application/json and `headers` may override it. */
export function jsonResponse(
  body: unknown,
  init: { readonly status?: number; readonly headers?: Record<string, string> } = {},
): Response {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { 'Content-Type': 'application/json', ...init.headers },
  });
}

/** An RFC 9457 problem response the way the PokéPrices API sends it. */
export function problemResponse(problem: Schemas['Problem']): Response {
  return new Response(JSON.stringify(problem), {
    status: problem.status,
    headers: { 'Content-Type': 'application/problem+json', 'X-Request-Id': problem.request_id },
  });
}

export interface FetchCall {
  readonly url: string;
  readonly init: RequestInit | undefined;
}

// ---- Accounts (P4 T03) -------------------------------------------------------------------------

type MeBody = operations['getMe']['responses'][200]['content']['application/json'];

const PROBLEM_BASE = 'https://pokeprices.andrestheperez.com/problems/';

/** GET /v1/me for a verified account without two-factor. */
export const signedInMe = {
  data: {
    email: 'collector@example.com',
    email_verified: true,
    two_factor: false,
    read_only: false,
  },
} satisfies MeBody;

/** GET /v1/me for an account whose email address is not verified yet. */
export const unverifiedMe = {
  data: { ...signedInMe.data, email: 'newcomer@example.com', email_verified: false },
} satisfies MeBody;

/** The 401 GET /v1/me answers when nobody is signed in. */
export const unauthenticatedProblem = {
  type: `${PROBLEM_BASE}unauthenticated`,
  title: 'Unauthenticated',
  status: 401,
  instance: '/v1/me',
  request_id: 'req-p4-unauthenticated-0001',
} satisfies Schemas['Problem'];

/** A 422 validation-failed problem for an account form, with the given field errors. */
export function accountValidationProblem(
  instance: string,
  errors: Record<string, string[]>,
): Schemas['Problem'] {
  return {
    type: `${PROBLEM_BASE}validation-failed`,
    title: 'Validation Failed',
    status: 422,
    instance,
    request_id: 'req-p4-validation-0002',
    errors,
  };
}

/** The 419 a stateful write answers without a valid XSRF token. */
export const csrfMismatchProblem = {
  type: `${PROBLEM_BASE}csrf-token-mismatch`,
  title: 'CSRF Token Mismatch',
  status: 419,
  instance: '/login',
  request_id: 'req-p4-csrf-0003',
} satisfies Schemas['Problem'];

/** The 409 a guest route answers to a signed-in session (JsonGuest). */
export const alreadySignedInProblem = {
  type: `${PROBLEM_BASE}already-signed-in`,
  title: 'Already Signed In',
  status: 409,
  instance: '/login',
  request_id: 'req-p4-signed-in-0004',
} satisfies Schemas['Problem'];

/** A 429 too-many-requests problem for the given path. */
export function tooManyRequestsProblem(instance: string): Schemas['Problem'] {
  return {
    type: `${PROBLEM_BASE}too-many-requests`,
    title: 'Too Many Requests',
    status: 429,
    instance,
    request_id: 'req-p4-throttle-0005',
  };
}

/** The 403 a verification link with a bad signature answers. */
export const invalidSignatureProblem = {
  type: `${PROBLEM_BASE}http-403`,
  title: 'Forbidden',
  status: 403,
  instance: '/email/verify/7/0a1b2c',
  request_id: 'req-p4-signature-0006',
} satisfies Schemas['Problem'];

/** A server failure on an account path. */
export function serverErrorProblem(instance: string): Schemas['Problem'] {
  return {
    type: `${PROBLEM_BASE}http-500`,
    title: 'Internal Server Error',
    status: 500,
    instance,
    request_id: 'req-p4-server-0007',
  };
}

/** A 2xx answer with a raw body: '' (no body), '""' (Fortify's JsonResponse('')) or HTML. */
export function rawResponse(status: number, body: string | null, contentType?: string): Response {
  const headers: Record<string, string> = {};
  if (contentType !== undefined) headers['Content-Type'] = contentType;
  return new Response(status === 204 ? null : body, { status, headers });
}

/** The XSRF-TOKEN cookie value the csrf-cookie stub sets (percent-encoded, as Laravel sends it). */
export const XSRF_COOKIE = 'fake-xsrf%2B0000%3D';
/** XSRF_COOKIE decoded: what the client must send as X-XSRF-TOKEN. */
export const XSRF_HEADER = 'fake-xsrf+0000=';

export function setXsrfCookie(value: string = XSRF_COOKIE): void {
  document.cookie = `XSRF-TOKEN=${value}; path=/`;
}

export function clearXsrfCookie(): void {
  document.cookie = 'XSRF-TOKEN=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
}

/** One stubbed answer: a Response, an Error (network failure) or a function of the request. */
export type AccountAnswer =
  Response | Error | ((init: RequestInit | undefined) => Response | Error);

export interface AccountCall extends FetchCall {
  /** 'GET /v1/me', 'POST /login', … */
  readonly key: string;
}

/**
 * The Fortify-answer stub router: answers `METHOD url` keys from `routes` (an array answers its
 * entries in order and then repeats its last one). GET /sanctum/csrf-cookie is routed by default
 * (204, and it sets the XSRF-TOKEN cookie like the real answer does). Any other request is a
 * network failure. Restored when the test finishes; returns the recorded calls.
 */
export function stubAccountFetch(
  routes: Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>>,
): AccountCall[] {
  const calls: AccountCall[] = [];
  const used = new Map<string, number>();
  vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
    const url = String(input);
    const key = `${init?.method ?? 'GET'} ${url}`;
    calls.push({ url, init, key });
    let route = routes[key];
    if (route === undefined && key === 'GET /sanctum/csrf-cookie') {
      route = () => {
        setXsrfCookie();
        return rawResponse(204, null);
      };
    }
    if (route === undefined) return Promise.reject(new TypeError(`no request expected: ${key}`));
    let answer: AccountAnswer;
    if (Array.isArray(route)) {
      const list = route as readonly AccountAnswer[];
      const index = used.get(key) ?? 0;
      used.set(key, index + 1);
      const picked = list[Math.min(index, list.length - 1)];
      if (picked === undefined) return Promise.reject(new TypeError(`empty route: ${key}`));
      answer = picked;
    } else {
      answer = route as AccountAnswer;
    }
    const settled = typeof answer === 'function' ? answer(init) : answer;
    // A Response body can be read once: hand out a copy so a repeated answer stays readable.
    return settled instanceof Response ? Promise.resolve(settled.clone()) : Promise.reject(settled);
  });
  onTestFinished(() => {
    vi.unstubAllGlobals();
  });
  return calls;
}

/** The parsed JSON body a recorded call sent. */
export function sentBody(call: FetchCall | undefined): unknown {
  const body = call?.init?.body;
  return typeof body === 'string' ? (JSON.parse(body) as unknown) : undefined;
}

/** The P3 D14 forbidden words (the faked year 2031 is checked separately). */
export const FORBIDDEN_WORDS: readonly RegExp[] = [
  /\blive\b/i,
  /real-?time/i,
  /as of today/i,
  /\btoday\b/i,
  /\bnow\b/i,
  /\b1D\b/i,
  /\b24 ?h/i,
  /20:05/,
];

/** Colour utilities that need a dark counterpart (the lightOnly rule of Header.test.tsx). */
const COLOUR_CLASS =
  /^((?:hover:|focus-visible:)*)(text|bg|border|divide|outline)-(slate|gray|red|amber|emerald|sky|white|black)(?:-\d+)?$/;

/** Every colour class under root without a dark: class for the same property. */
export function lightOnlyClasses(root: Element): string[] {
  const missing: string[] = [];
  for (const element of [root, ...Array.from(root.querySelectorAll('*'))]) {
    const classes = element.getAttribute('class')?.split(/\s+/) ?? [];
    for (const name of classes) {
      const match = COLOUR_CLASS.exec(name);
      if (match === null) continue;
      const counterpart = `dark:${match[1] ?? ''}${match[2] ?? ''}-`;
      if (!classes.some((other) => other.startsWith(counterpart))) {
        missing.push(`${element.tagName.toLowerCase()} ${name}`);
      }
    }
  }
  return missing;
}

/** Every link, button, input and select under root without the 44 px min-h-11 class. */
export function shortControls(root: Element): string[] {
  return Array.from(root.querySelectorAll('a, button, input, select'))
    .filter((control) => !(control.getAttribute('class') ?? '').split(/\s+/).includes('min-h-11'))
    .map((control) => `${control.tagName.toLowerCase()} ${control.textContent}`);
}

/** True when `secret` is anywhere in the document: its markup or an input's current value. */
export function domHolds(secret: string): boolean {
  if (document.documentElement.outerHTML.includes(secret)) return true;
  return Array.from(document.querySelectorAll('input')).some((input) =>
    input.value.includes(secret),
  );
}

/**
 * Replaces the global fetch for the current test only: the real fetch comes back when the test
 * finishes. `reply` answers each call with a Response (resolved) or an Error (rejected, like a
 * network failure). Returns the recorded calls.
 */
export function stubFetch(reply: (url: string) => Response | Error): FetchCall[] {
  const calls: FetchCall[] = [];
  vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
    const url = String(input);
    calls.push({ url, init });
    const answer = reply(url);
    return answer instanceof Response ? Promise.resolve(answer) : Promise.reject(answer);
  });
  onTestFinished(() => {
    vi.unstubAllGlobals();
  });
  return calls;
}

// ---- Two-factor (P4 T04) -----------------------------------------------------------------------
// Fortify's two-factor answers are not in the /v1 contract: they are typed by client.ts's
// hand-written types. Every value is an obvious fake (never a real setup key or recovery code).

/** GET /v1/me for the verified account once two-factor authentication is confirmed. */
export const twoFactorMe = {
  data: { ...signedInMe.data, two_factor: true },
} satisfies MeBody;

/** A stand-in for Fortify's QR SVG (the real one is 192 px, no margin, white background). */
export const FAKE_QR_SVG =
  '<svg width="192" height="192" viewBox="0 0 4 4"><rect width="4" height="4" fill="#ffffff"/><rect width="1" height="1" fill="#2d3748"/></svg>';

/** GET /user/two-factor-qr-code (TwoFactorQrCodeController::show). */
export const twoFactorQrAnswer = {
  svg: FAKE_QR_SVG,
  url: 'otpauth://totp/PokePrices:collector%40example.com?issuer=PokePrices',
} satisfies TwoFactorQrCode;

/** The setup key the fake QR stands for: an obvious fake, never a real base32 secret. */
export const FAKE_SETUP_KEY = 'FAKE-SETUP-KEY-0000';

/** GET /user/two-factor-secret-key (TwoFactorSecretKeyController::show). */
export const twoFactorSecretAnswer = { secretKey: FAKE_SETUP_KEY } satisfies TwoFactorSecretKey;

/** GET /user/two-factor-recovery-codes right after confirming (RecoveryCodeController::index). */
export const fakeRecoveryCodes = [
  'fake-recovery-0001',
  'fake-recovery-0002',
  'fake-recovery-0003',
] satisfies RecoveryCodes;

/** The same GET after POST /user/two-factor-recovery-codes replaced them. */
export const fakeNewRecoveryCodes = [
  'fake-renewed-0004',
  'fake-renewed-0005',
  'fake-renewed-0006',
] satisfies RecoveryCodes;

/** RequirePassword's 423 (a plain JSON body, not a problem document). */
export function passwordRequiredResponse(): Response {
  return jsonResponse({ message: 'Password confirmation required.' }, { status: 423 });
}

/** Fortify's empty JSON answers: JsonResponse('', 200|201|204) sends '""' (204: no body). */
export function emptyJsonResponse(status: number): Response {
  return rawResponse(status, '""', 'application/json');
}

// ---- Watchlist, portfolio and the demo (P4 T07) ------------------------------------------------
// Bodies of GET /v1/watchlist and GET /v1/portfolio, checked against the generated schema. Every
// money value is the API's decimal string; the portfolio's values and total are the API's sums
// (D9's worked example), never recomputed here or in the pages.

export type WatchlistBody =
  operations['getWatchlist']['responses'][200]['content']['application/json'];
export type WatchlistItem = Schemas['WatchlistItemResource'];
export type PortfolioBody =
  operations['getPortfolio']['responses'][200]['content']['application/json'];
export type Holding = Schemas['HoldingResource'];

const charizard = { name: 'Charizard', set_name: 'Base', number: '4' };
const chimecho = { name: 'Chimecho', set_name: 'Hidden Legends', number: '17' };

/** Charizard holo unlimited: 968.91 on 2026-10-12, up from 950.00 on 2026-10-11. */
export const charizardPrice = closeOf({
  close: '968.91',
  previous_close: '950.00',
  change: '18.91',
  change_pct: '1.99',
});

/** Chimecho reverse holo: 26.34 on 2026-10-11, down from 27.00 on 2026-10-10. */
export const chimechoPrice = closeOf({
  close: '26.34',
  day: '2026-10-11',
  as_of: '2026-10-11T20:05:44Z',
  previous_close: '27.00',
  previous_day: '2026-10-10',
  change: '-0.66',
  change_pct: '-2.44',
});

/** One watchlist item; Charizard holo unlimited unless `fields` say otherwise. */
export function watchItemOf(fields: Partial<WatchlistItem> = {}): WatchlistItem {
  return {
    variant_key: 'base1-4:holo:unlimited:-',
    card_id: 'base1-4',
    finish: 'holo',
    edition: 'unlimited',
    treatment: '-',
    card: charizard,
    price: charizardPrice,
    ...fields,
  } satisfies WatchlistItem;
}

/** The demo's three watchlist rows: an unpriced first edition, a rise and a fall. */
export const watchlistItems = [
  watchItemOf({
    variant_key: 'base1-4:holo:1st:-',
    edition: '1st',
    price: null,
  }),
  watchItemOf(),
  watchItemOf({
    variant_key: 'ex5-17:reverse:-:-',
    card_id: 'ex5-17',
    finish: 'reverse',
    edition: '-',
    card: chimecho,
    price: chimechoPrice,
  }),
] satisfies WatchlistItem[];

/** GET /v1/watchlist over the given items. */
export function watchlistBody(
  items: readonly WatchlistItem[] = watchlistItems,
  meta: Partial<WatchlistBody['meta']> = {},
): WatchlistBody {
  return {
    data: [...items],
    meta: { count: items.length, limit: 100, degraded: false, ...meta },
  } satisfies WatchlistBody;
}

/** One holding; Charizard holo unlimited × 1 at 968.91 unless `fields` say otherwise. */
export function holdingOf(fields: Partial<Holding> = {}): Holding {
  return {
    ...watchItemOf(),
    quantity: 1,
    value: '968.91',
    ...fields,
  } satisfies Holding;
}

/**
 * D9's worked example: Charizard × 1 at 968.91 (value 968.91), Chimecho × 3 at 26.34 (value
 * 79.02, a close of the day before) and an unpriced first edition × 2 (value null).
 */
export const workedHoldings = [
  holdingOf({
    variant_key: 'base1-4:holo:1st:-',
    edition: '1st',
    price: null,
    quantity: 2,
    value: null,
  }),
  holdingOf(),
  holdingOf({
    variant_key: 'ex5-17:reverse:-:-',
    card_id: 'ex5-17',
    finish: 'reverse',
    edition: '-',
    card: chimecho,
    price: chimechoPrice,
    quantity: 3,
    value: '79.02',
  }),
] satisfies Holding[];

/** GET /v1/portfolio over the given holdings; meta defaults to D9's worked example. */
export function portfolioBody(
  holdings: readonly Holding[] = workedHoldings,
  meta: Partial<PortfolioBody['meta']> = {},
): PortfolioBody {
  return {
    data: [...holdings],
    meta: {
      count: holdings.length,
      limit: 100,
      total_value: '1047.93',
      priced: 2,
      unpriced: 1,
      first_day: '2026-10-11',
      last_day: '2026-10-12',
      source: 'tcgplayer',
      feed: 'tcgcsv',
      basis: 'market',
      currency: 'USD',
      degraded: false,
      ...meta,
    },
  } satisfies PortfolioBody;
}

/** An empty portfolio: no holding, no total, no days. */
export const emptyPortfolioBody = portfolioBody([], {
  total_value: null,
  priced: 0,
  unpriced: 0,
  first_day: null,
  last_day: null,
});

/** GET /v1/me for the read-only demo account. */
export const demoMe = {
  data: { email: 'demo@example.com', email_verified: true, two_factor: false, read_only: true },
} satisfies MeBody;

/** The 403 every write of the demo account answers (DemoReadOnly). */
export function demoReadOnlyProblem(instance: string): Schemas['Problem'] {
  return {
    type: `${PROBLEM_BASE}demo-read-only`,
    title: 'Demo Account Is Read-Only',
    status: 403,
    instance,
    request_id: 'req-p4-demo-0008',
  };
}

/** The 404 POST /v1/demo/session answers when the demo is off or not seeded. */
export const demoUnavailableProblem = {
  type: `${PROBLEM_BASE}demo-unavailable`,
  title: 'Demo Account Unavailable',
  status: 404,
  instance: '/v1/demo/session',
  request_id: 'req-p4-demo-0009',
} satisfies Schemas['Problem'];

/** The 503 an account route answers while a dependency is down. */
export function serviceUnavailableProblem(instance: string): Schemas['Problem'] {
  return {
    type: `${PROBLEM_BASE}http-503`,
    title: 'Service Unavailable',
    status: 503,
    instance,
    request_id: 'req-p4-unavailable-0010',
  };
}
