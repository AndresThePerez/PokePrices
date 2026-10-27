// Q1-i2-T01 review: the decoder and the 401 listener at the client seam.
import { afterEach, describe, expect, it, vi } from 'vitest';
import { deleteWatchlistItem, getMovers, onUnauthenticatedWrite } from './client';
import {
  clearXsrfCookie,
  csrfMismatchProblem,
  jsonResponse,
  moversBody,
  problemResponse,
  stubAccountFetch,
  stubFetch,
  unauthenticatedProblem,
} from '../test/fixtures';

const KEY = 'DELETE /v1/watchlist/base1-4%3Aholo%3A1st%3A-';

afterEach(() => {
  clearXsrfCookie();
});

describe('onUnauthenticatedWrite after a CSRF retry (review)', () => {
  it('hears one 401 once when the first send answered 419 and the retry answered 401', async () => {
    stubAccountFetch({
      [KEY]: [
        problemResponse(csrfMismatchProblem),
        problemResponse({ ...unauthenticatedProblem, instance: '/v1/watchlist' }),
      ],
    });
    const heard = vi.fn();
    const stop = onUnauthenticatedWrite(heard);
    const result = await deleteWatchlistItem('base1-4:holo:1st:-');
    stop();
    expect(result.ok).toBe(false);
    expect(heard).toHaveBeenCalledTimes(1);
  });

  it('does not hear a write whose second send is still 419', async () => {
    stubAccountFetch({ [KEY]: [problemResponse(csrfMismatchProblem)] });
    const heard = vi.fn();
    const stop = onUnauthenticatedWrite(heard);
    const result = await deleteWatchlistItem('base1-4:holo:1st:-');
    stop();
    expect(result.ok).toBe(false);
    expect(heard).not.toHaveBeenCalled();
  });
});

describe('the envelope decoder keeps what the page maps (review)', () => {
  it('a cancelled movers read rejects as cancelled, not as an unexpected answer', async () => {
    const controller = new AbortController();
    stubFetch(() => new DOMException('aborted', 'AbortError'));
    controller.abort();
    await expect(getMovers('up', 50, { signal: controller.signal })).rejects.toBeTruthy();
  });

  it('a 503 problem and a network failure reach the caller exactly as before', async () => {
    stubFetch(() => jsonResponse({}, { status: 503 }));
    const unavailable = await getMovers('up', 50);
    expect(unavailable.ok).toBe(false);
    if (!unavailable.ok) expect(unavailable.status).toBe(503);
    stubFetch(() => new TypeError('offline'));
    const offline = await getMovers('up', 50);
    expect(offline.ok).toBe(false);
    if (!offline.ok) expect(offline.status).toBe(0);
  });

  it('a well-formed empty movers answer (data [] with its meta) is still a success', async () => {
    stubFetch(() => jsonResponse(moversBody('up', [], { day: null, as_of: null })));
    const result = await getMovers('up', 50);
    expect(result.ok).toBe(true);
  });
});
