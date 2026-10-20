// The query client's session rules (Q0 U1): every per-user key lives under ['account', …]; when
// the account ['me'] names changes, everything under it is dropped; a 401 on a per-user query asks
// GET /v1/me again. Public keys are never touched.
import { describe, expect, it } from 'vitest';
import { endSession } from './account/session';
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

describe('createQueryClient: an ["me"] update without an answer (F4)', () => {
  it('a rebuilt ["me"] query still fetching marks nothing stale and clears nothing', () => {
    const client = createQueryClient();
    client.setQueryData(['me'], A);
    client.setQueryData(['account', 'webhooks'], { data: ['a-hook'] });
    // ['me'] was garbage-collected; the next read builds it again and starts fetching.
    client.removeQueries({ queryKey: ['me'] });
    void client.query({
      queryKey: ['me'],
      queryFn: () => new Promise<never>(() => undefined),
    });
    expect(client.getQueryState(['me'])?.data).toBeUndefined();
    expect(client.getQueryState(['account', 'webhooks'])?.isInvalidated).toBe(false);
    expect(client.getQueryData(['account', 'webhooks'])).toEqual({ data: ['a-hook'] });
  });
});

describe('createQueryClient: another tab signals a session transition (F2, F5)', () => {
  it('drops the account data once, and the next ["me"] answer does not drop what came after', () => {
    const client = createQueryClient();
    client.mount();
    client.setQueryData(['me'], A);
    client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
    client.setQueryData(['card', 'ex5-17'], { data: 'public' });
    window.dispatchEvent(new StorageEvent('storage', { key: 'pokeprices:session', newValue: '1' }));
    expect(client.getQueryData(['account', 'watchlist'])).toBeUndefined();
    expect(client.getQueryData(['card', 'ex5-17'])).toEqual({ data: 'public' });
    expect(client.getQueryState(['me'])?.isInvalidated).toBe(true);
    // B's own list answers before B's ['me'] answer does.
    client.setQueryData(['account', 'watchlist'], { data: ['b-row'] });
    client.setQueryData(['me'], B);
    expect(client.getQueryData(['account', 'watchlist'])).toEqual({ data: ['b-row'] });
    // From then on, B is the account a change is measured against.
    client.setQueryData(['me'], A);
    expect(client.getQueryData(['account', 'watchlist'])).toBeUndefined();
    client.unmount();
  });

  it('ignores another key and listens only while mounted', () => {
    const client = createQueryClient();
    client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
    window.dispatchEvent(new StorageEvent('storage', { key: 'pokeprices:session', newValue: '1' }));
    expect(client.getQueryData(['account', 'watchlist'])).toEqual({ data: ['a-row'] });
    client.mount();
    window.dispatchEvent(
      new StorageEvent('storage', { key: 'pokeprices:theme', newValue: 'dark' }),
    );
    expect(client.getQueryData(['account', 'watchlist'])).toEqual({ data: ['a-row'] });
    client.unmount();
    window.dispatchEvent(new StorageEvent('storage', { key: 'pokeprices:session', newValue: '2' }));
    expect(client.getQueryData(['account', 'watchlist'])).toEqual({ data: ['a-row'] });
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

describe('createQueryClient: an ["me"] request in flight when another tab signals (review)', () => {
  it('an inactive ["me"] fetch answering A after the signal is not taken as the account', async () => {
    const client = createQueryClient();
    client.mount();
    let release: ((me: typeof A) => void) | undefined;
    // Asked with the pre-switch cookie by a page that no longer shows ['me'].
    void client
      .query({
        queryKey: ['me'],
        queryFn: () =>
          new Promise<typeof A>((resolve) => {
            release = resolve;
          }),
      })
      .catch(() => undefined);
    window.dispatchEvent(new StorageEvent('storage', { key: 'pokeprices:session', newValue: '1' }));
    release?.(A);
    await new Promise((resolve) => setTimeout(resolve, 0));
    // A page showing ['me'] asks again (with B's cookie) instead of reading A as fresh.
    expect(client.getQueryData(['me'])).toBeUndefined();
    const read = await client.query({
      queryKey: ['me'],
      queryFn: () => Promise.resolve(B),
      staleTime: 300_000,
    });
    expect(read).toEqual(B);
    // B is the baseline: B's own data stays, and a later A drops it.
    client.setQueryData(['account', 'watchlist'], { data: ['b-row'] });
    client.setQueryData(['me'], B);
    expect(client.getQueryData(['account', 'watchlist'])).toEqual({ data: ['b-row'] });
    client.setQueryData(['me'], A);
    expect(client.getQueryData(['account', 'watchlist'])).toBeUndefined();
    client.unmount();
  });
});

describe('createQueryClient: the first account this client learns of (review)', () => {
  it('drops the per-user data cached before it, and keeps what comes after', () => {
    const client = createQueryClient();
    client.setQueryData(['account', 'portfolio'], { data: ['a-holding'] });
    client.setQueryData(['card', 'ex5-17'], { data: 'public' });
    client.setQueryData(['me'], B);
    expect(client.getQueryData(['account', 'portfolio'])).toBeUndefined();
    expect(client.getQueryData(['card', 'ex5-17'])).toEqual({ data: 'public' });
    client.setQueryData(['account', 'portfolio'], { data: ['b-holding'] });
    client.setQueryData(['me'], { ...B, email_verified: false });
    expect(client.getQueryData(['account', 'portfolio'])).toEqual({ data: ['b-holding'] });
  });

  it('a signed-out first answer sets no account: the next account still drops what came before', () => {
    const client = createQueryClient();
    client.setQueryData(['me'], null);
    client.setQueryData(['account', 'portfolio'], { data: ['a-holding'] });
    client.setQueryData(['me'], B);
    expect(client.getQueryData(['account', 'portfolio'])).toBeUndefined();
  });
});

describe('createQueryClient: a session transition made in this tab (review)', () => {
  it('forgets once: the next ["me"] answer is the baseline, not a second change', () => {
    const client = createQueryClient();
    client.setQueryData(['me'], A);
    client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
    endSession(client);
    expect(client.getQueryData(['account', 'watchlist'])).toBeUndefined();
    // B's list arrives before B's ['me'] answer (a page that showed the old ['me'] still asked).
    client.setQueryData(['account', 'watchlist'], { data: ['b-row'] });
    client.setQueryData(['me'], B);
    expect(client.getQueryData(['account', 'watchlist'])).toEqual({ data: ['b-row'] });
    client.setQueryData(['me'], A);
    expect(client.getQueryData(['account', 'watchlist'])).toBeUndefined();
  });
});
