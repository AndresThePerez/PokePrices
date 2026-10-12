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
