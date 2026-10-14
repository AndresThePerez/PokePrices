// The query client's session rules (Q0 U1): every per-user key lives under ['account', …]; when
// the account ['me'] names changes, everything under it is dropped; a 401 on a per-user query asks
// GET /v1/me again. Public keys are never touched.
import { describe, expect, it } from 'vitest';
import { createQueryClient } from './query';

const A = { email: 'collector@example.com', email_verified: true, two_factor: false };
const B = { ...A, email: 'second@example.com' };

function seed() {
  const client = createQueryClient();
  client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
  client.setQueryData(['account', 'verify-email', '/email/verify/7/x'], true);
  client.setQueryData(['card', 'ex5-17'], { data: 'public' });
  return client;
}

describe('createQueryClient: identity changes seen in ["me"]', () => {
  it('drops every ["account", …] query when ["me"] changes from A to B, and keeps public ones', () => {
    const client = seed();
    client.setQueryData(['me'], A);
    client.setQueryData(['account', 'portfolio'], { data: ['a-holding'] });
    client.setQueryData(['me'], B);
    expect(client.getQueryCache().findAll({ queryKey: ['account'] })).toEqual([]);
    expect(client.getQueryData(['card', 'ex5-17'])).toEqual({ data: 'public' });
    expect(client.getQueryData(['me'])).toEqual(B);
  });

  it('drops them for A, signed out, then B', () => {
    const client = createQueryClient();
    client.setQueryData(['me'], A);
    client.setQueryData(['account', 'webhooks'], { data: ['a-hook'] });
    client.setQueryData(['me'], null);
    client.setQueryData(['me'], B);
    expect(client.getQueryData(['account', 'webhooks'])).toBeUndefined();
  });

  it('keeps them for A, signed out, then A again, and for a refresh of the same account', () => {
    const client = createQueryClient();
    client.setQueryData(['me'], A);
    client.setQueryData(['account', 'webhooks'], { data: ['a-hook'] });
    client.setQueryData(['me'], { ...A, email_verified: false });
    client.setQueryData(['me'], null);
    client.setQueryData(['me'], A);
    expect(client.getQueryData(['account', 'webhooks'])).toEqual({ data: ['a-hook'] });
  });
});

describe('createQueryClient: a 401 on a per-user query', () => {
  it('invalidates ["me"]; a 401 on a public query does not', async () => {
    const client = createQueryClient();
    client.setQueryData(['me'], A);
    const unauthenticated: unknown = {
      ok: false,
      status: 401,
      type: 'x',
      title: 'Unauthenticated',
    };
    await client
      .query({
        queryKey: ['card', 'ex5-17'],
        queryFn: () => Promise.reject(unauthenticated as Error),
      })
      .catch(() => undefined);
    expect(client.getQueryState(['me'])?.isInvalidated).toBe(false);
    await client
      .query({
        queryKey: ['account', 'watchlist'],
        queryFn: () => Promise.reject(unauthenticated as Error),
      })
      .catch(() => undefined);
    expect(client.getQueryState(['me'])?.isInvalidated).toBe(true);
  });

  it('leaves ["me"] alone for any other failure of a per-user query', async () => {
    const client = createQueryClient();
    client.setQueryData(['me'], A);
    const failed: unknown = { ok: false, status: 500, type: 'x', title: 'Server Error' };
    await client
      .query({
        queryKey: ['account', 'watchlist'],
        queryFn: () => Promise.reject(failed as Error),
      })
      .catch(() => undefined);
    expect(client.getQueryState(['me'])?.isInvalidated).toBe(false);
  });
});
