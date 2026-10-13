import { describe, expect, it } from 'vitest';
import { getCard, type ApiFailure } from './api/client';
import { QUERY_DEFAULTS, createQueryClient, unwrap } from './query';
import {
  cardBody,
  jsonResponse,
  problemResponse,
  stubFetch,
  upstreamUnavailableProblem,
} from './test/fixtures';

describe('query client defaults (D9)', () => {
  it('polls every five minutes while visible, never in the background, and never retries', () => {
    expect(QUERY_DEFAULTS).toEqual({
      refetchInterval: 300_000,
      refetchIntervalInBackground: false,
      staleTime: 300_000,
      refetchOnWindowFocus: true,
      retry: false,
    });
    const queries = createQueryClient().getDefaultOptions().queries;
    expect(queries).toMatchObject(QUERY_DEFAULTS);
  });

  it('creates a fresh client, with an empty cache, on every call', () => {
    const first = createQueryClient();
    first.setQueryData(['card', 'ex10-?'], cardBody);
    const second = createQueryClient();
    expect(second).not.toBe(first);
    expect(second.getQueryData(['card', 'ex10-?'])).toBeUndefined();
  });
});

describe('unwrap (D9, review F-P3-04)', () => {
  it('returns the data of an ok result', async () => {
    stubFetch(() => jsonResponse(cardBody));
    await expect(unwrap(getCard('ex10-?'))).resolves.toEqual(cardBody);
  });

  it('throws the ApiFailure itself for a problem answer', async () => {
    stubFetch(() => problemResponse(upstreamUnavailableProblem));
    const pending = getCard('ex10-?');
    const failure = await pending;
    const thrown: unknown = await unwrap(Promise.resolve(failure)).then(
      () => 'resolved',
      (error: unknown) => error,
    );
    expect(thrown).toBe(failure);
    expect(thrown).toMatchObject({
      ok: false,
      status: 503,
      title: 'Upstream Unavailable',
      requestId: 'req-t46-upstream-0001',
    } satisfies Partial<ApiFailure>);
  });

  it('throws the same ApiFailure object for a status-0 failure', async () => {
    stubFetch(() => new TypeError('Failed to fetch'));
    const failure = await getCard('ex10-?');
    expect(failure.ok).toBe(false);
    const thrown: unknown = await unwrap(Promise.resolve(failure)).then(
      () => 'resolved',
      (error: unknown) => error,
    );
    expect(thrown).toBe(failure);
    expect(thrown).toMatchObject({ status: 0, requestId: null });
  });

  it('rethrows an abort exactly as the client throws it', async () => {
    const abort = new DOMException('The operation was aborted.', 'AbortError');
    stubFetch(() => abort);
    const controller = new AbortController();
    controller.abort();
    const thrown: unknown = await unwrap(getCard('ex10-?', { signal: controller.signal })).then(
      () => 'resolved',
      (error: unknown) => error,
    );
    expect(thrown).toBe(abort);
  });
});
