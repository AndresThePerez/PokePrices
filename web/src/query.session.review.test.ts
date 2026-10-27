// Q1-i2-T01 review: the 401-write listener follows the client's mounts, not one mount call.
import { afterEach, describe, expect, it } from 'vitest';
import { deleteWatchlistItem } from './api/client';
import { createQueryClient } from './query';
import {
  clearXsrfCookie,
  problemResponse,
  setXsrfCookie,
  stubAccountFetch,
  unauthenticatedProblem,
} from './test/fixtures';

const A = { email: 'collector@example.com', email_verified: true, two_factor: false };
const KEY = 'DELETE /v1/watchlist/base1-4%3Aholo%3A1st%3A-';

afterEach(() => {
  clearXsrfCookie();
});

async function writeThatAnswers401(): Promise<void> {
  setXsrfCookie();
  stubAccountFetch({
    [KEY]: problemResponse({ ...unauthenticatedProblem, instance: '/v1/watchlist' }),
  });
  await deleteWatchlistItem('base1-4:holo:1st:-');
}

describe('createQueryClient: the 401-write listener and nested mounts (review)', () => {
  it('keeps hearing a 401 write while one of two mounts remains, and stops after the last', async () => {
    const client = createQueryClient();
    client.mount();
    client.mount();
    client.unmount();
    client.setQueryData(['me'], A);
    await writeThatAnswers401();
    expect(client.getQueryState(['me'])?.isInvalidated).toBe(true);
    client.unmount();
    client.setQueryData(['me'], A);
    await writeThatAnswers401();
    expect(client.getQueryState(['me'])?.isInvalidated).toBe(false);
  });

  it('hears a 401 write again after an unmount and a fresh mount (a strict-mode remount)', async () => {
    const client = createQueryClient();
    client.mount();
    client.unmount();
    client.mount();
    client.setQueryData(['me'], A);
    await writeThatAnswers401();
    expect(client.getQueryState(['me'])?.isInvalidated).toBe(true);
    client.unmount();
  });
});
