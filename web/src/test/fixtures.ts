// Typed test fixtures for the PokéPrices SPA. Every fixture is checked with `satisfies` against
// the generated schema (src/api/schema.d.ts), so a contract change breaks tsc (Q6) here first.
import { onTestFinished, vi } from 'vitest';
import type { components, operations } from '../api/schema';

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
