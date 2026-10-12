import { describe, expect, it } from 'vitest';
import {
  cardNotFoundProblem,
  jsonResponse,
  problemResponse,
  searchPage,
  stubFetch,
  upstreamUnavailableProblem,
  validationProblem,
} from '../test/fixtures';
import { getCard, searchCards } from './client';

describe('searchCards', () => {
  it('asks the same-origin /v1/cards endpoint and returns the typed page', async () => {
    const calls = stubFetch(() => jsonResponse(searchPage));
    const result = await searchCards({ q: 'unown ?', page: 2, page_size: 24 });
    expect(calls.map((call) => call.url)).toEqual(['/v1/cards?q=unown+%3F&page=2&page_size=24']);
    expect(new Headers(calls[0]?.init?.headers).get('Accept')).toBe('application/json');
    expect(result).toEqual({ ok: true, data: searchPage });
    expect(result.ok ? result.data.data.map((card) => card.id) : []).toEqual([
      'ex10-?',
      'ex10-!',
      'svp-1',
    ]);
    expect(result.ok ? result.data.meta.pages : 0).toBe(1);
  });

  it('sends only q when page and page_size are absent', async () => {
    const calls = stubFetch(() => jsonResponse(searchPage));
    await searchCards({ q: 'pikachu' });
    expect(calls.map((call) => call.url)).toEqual(['/v1/cards?q=pikachu']);
  });

  it('surfaces a problem response with its type and request_id', async () => {
    stubFetch(() => problemResponse(upstreamUnavailableProblem));
    const result = await searchCards({ q: 'unown' });
    expect(result).toEqual({
      ok: false,
      status: 503,
      type: 'https://pokeprices.andrestheperez.com/problems/upstream-unavailable',
      title: 'Upstream Unavailable',
      requestId: 'req-t46-upstream-0001',
      problem: upstreamUnavailableProblem,
    });
  });

  it('keeps the validation errors of a 422 problem', async () => {
    stubFetch(() => problemResponse(validationProblem));
    const result = await searchCards({ q: 'unown', page: 0 });
    expect(result.ok).toBe(false);
    expect(result.ok ? null : result.problem?.errors).toEqual({
      page: ['The page field must be at least 1.'],
    });
    expect(result.ok ? null : result.requestId).toBe('req-t46-validation-0002');
  });

  it('reports an error that is not problem+json with the response request id', async () => {
    stubFetch(
      () =>
        new Response('<html>Bad Gateway</html>', {
          status: 502,
          headers: { 'Content-Type': 'text/html', 'X-Request-Id': 'req-t46-gateway-0003' },
        }),
    );
    const result = await searchCards({ q: 'unown' });
    expect(result).toEqual({
      ok: false,
      status: 502,
      type: 'about:blank',
      title: 'The PokéPrices API answered HTTP 502',
      requestId: 'req-t46-gateway-0003',
      problem: null,
    });
  });

  it('treats a 200 that is not JSON (the SPA fallback page) as a failure', async () => {
    stubFetch(
      () =>
        new Response('<!doctype html><div id="root"></div>', {
          status: 200,
          headers: { 'Content-Type': 'text/html' },
        }),
    );
    const result = await searchCards({ q: 'unown' });
    expect(result.ok).toBe(false);
    expect(result.ok ? 0 : result.status).toBe(200);
    expect(result.ok ? null : result.requestId).toBeNull();
  });

  it('ignores a JSON error body that is not a complete problem', async () => {
    stubFetch(() =>
      jsonResponse(
        { type: 'https://example.com/problems/x', status: 500 },
        { status: 500, headers: { 'Content-Type': 'application/problem+json' } },
      ),
    );
    const result = await searchCards({ q: 'unown' });
    expect(result.ok ? null : result.type).toBe('about:blank');
    expect(result.ok ? undefined : result.problem).toBeNull();
  });

  it('reports a network failure as status 0', async () => {
    stubFetch(() => new TypeError('Failed to fetch'));
    const result = await searchCards({ q: 'unown' });
    expect(result).toEqual({
      ok: false,
      status: 0,
      type: 'about:blank',
      title: 'The PokéPrices API could not be reached',
      requestId: null,
      problem: null,
    });
  });

  it('passes the abort signal through and rethrows once aborted', async () => {
    const controller = new AbortController();
    const abort = new DOMException('The operation was aborted.', 'AbortError');
    const calls = stubFetch(() => abort);
    controller.abort();
    await expect(searchCards({ q: 'unown' }, { signal: controller.signal })).rejects.toBe(abort);
    expect(calls[0]?.init?.signal).toBe(controller.signal);
  });
});

describe('getCard', () => {
  it('puts the id through encodeURIComponent under /v1/cards/', async () => {
    const calls = stubFetch(() => problemResponse(cardNotFoundProblem));
    for (const id of ['ex10-?', 'ex10-!', 'a/b', '100%', 'base1-4']) {
      await getCard(id);
    }
    expect(calls.map((call) => call.url)).toEqual([
      '/v1/cards/ex10-%3F',
      '/v1/cards/ex10-!',
      '/v1/cards/a%2Fb',
      '/v1/cards/100%25',
      '/v1/cards/base1-4',
    ]);
  });

  it('surfaces card-not-found with catalog_missing', async () => {
    stubFetch(() => problemResponse(cardNotFoundProblem));
    const result = await getCard('ex10-?');
    expect(result.ok ? null : result.type).toBe(
      'https://pokeprices.andrestheperez.com/problems/card-not-found',
    );
    expect(result.ok ? null : result.requestId).toBe('req-t46-notfound-0004');
    expect(result.ok ? null : result.problem?.catalog_missing).toBe(true);
  });

  it('returns the card body on 200', async () => {
    const body = { data: { id: 'ex10-?', name: 'Unown' }, meta: { degraded: false } };
    stubFetch(() => jsonResponse(body));
    expect(await getCard('ex10-?')).toEqual({ ok: true, data: body });
  });
});

describe('production sources', () => {
  const sources = import.meta.glob<string>(
    ['../**/*.{ts,tsx}', '!../**/*.test.{ts,tsx}', '!../test/**', '!../**/*.d.ts'],
    { query: '?raw', import: 'default', eager: true },
  );

  it('never call PokéSearch or any other origin directly', () => {
    const names = Object.keys(sources);
    expect(names).toContain('./client.ts');
    expect(names).toContain('../App.tsx');
    for (const [name, text] of Object.entries(sources)) {
      expect({ name, absoluteUrl: /https?:\/\//.test(text) }).toEqual({ name, absoluteUrl: false });
      expect({ name, port8081: /\b8081\b/.test(text) }).toEqual({ name, port8081: false });
      expect({ name, apiPath: /['"`]\/api\//.test(text) }).toEqual({ name, apiPath: false });
    }
  });
});
