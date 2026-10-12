import { describe, expect, it } from 'vitest';
import {
  cardSummaries,
  degradedSearchPage,
  jsonResponse,
  problemResponse,
  searchPage,
  stubFetch,
  upstreamUnavailableProblem,
} from './fixtures';

const realFetch = globalThis.fetch;
const REQUEST_ID = /^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/;

describe('stubFetch', () => {
  it('installs a stub that records each call and answers from the reply function', async () => {
    const calls = stubFetch((url) =>
      url === '/v1/fail'
        ? new TypeError('Failed to fetch')
        : jsonResponse({ url }, { status: 201 }),
    );
    expect(globalThis.fetch).not.toBe(realFetch);
    const response = await fetch('/v1/cards?q=a', { method: 'GET' });
    expect(response.status).toBe(201);
    expect(await response.json()).toEqual({ url: '/v1/cards?q=a' });
    await expect(fetch(new URL('/v1/fail', 'http://192.0.2.10'))).resolves.toBeInstanceOf(Response);
    await expect(fetch('/v1/fail')).rejects.toThrow('Failed to fetch');
    expect(calls.map((call) => call.url)).toEqual([
      '/v1/cards?q=a',
      'http://192.0.2.10/v1/fail',
      '/v1/fail',
    ]);
    expect(calls[0]?.init?.method).toBe('GET');
  });

  it('restores the real fetch once the previous test finished', () => {
    expect(typeof realFetch).toBe('function');
    expect(globalThis.fetch).toBe(realFetch);
  });
});

describe('fixtures', () => {
  it('builds problem responses as application/problem+json carrying the request id', async () => {
    const response = problemResponse(upstreamUnavailableProblem);
    expect(response.status).toBe(503);
    expect(response.headers.get('Content-Type')).toBe('application/problem+json');
    expect(response.headers.get('X-Request-Id')).toBe(upstreamUnavailableProblem.request_id);
    expect(await response.json()).toEqual(upstreamUnavailableProblem);
  });

  it('keeps search pages consistent with their meta and the request-id pattern', () => {
    expect(searchPage.data).toEqual([...cardSummaries]);
    expect(searchPage.meta.total).toBe(cardSummaries.length);
    expect(degradedSearchPage.meta.degraded).toBe(true);
    expect(upstreamUnavailableProblem.request_id).toMatch(REQUEST_ID);
    expect(cardSummaries.map((card) => card.id)).toContain('ex10-?');
    expect(cardSummaries.some((card) => card.rarity === null)).toBe(true);
  });
});
