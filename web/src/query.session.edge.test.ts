// Adversarial edge cases for the cross-tab signal and the ["me"] guard (Q0-i1-T03): a real
// signed-out answer is `null` (never `undefined`) and still marks the account data stale in this
// tab, a failed read changes nothing, signal spam and hostile values stay bounded, the storage
// listener never leaks, a signal during a ["me"] fetch ends on the newest answer, the "forgotten"
// flag never sticks, and the one-time read keeps its meta.
import { QueryObserver } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { ME_KEY, ONE_TIME, SESSION_SIGNAL_KEY, endSession, fetchMe } from './account/session';
import { createQueryClient } from './query';
import {
  jsonResponse,
  problemResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
} from './test/fixtures';

const A = { email: 'collector@example.com', email_verified: true, two_factor: false };
const B = { ...A, email: 'second@example.com' };

function signal(value: string | null = '1'): void {
  window.dispatchEvent(new StorageEvent('storage', { key: SESSION_SIGNAL_KEY, newValue: value }));
}

async function tick(): Promise<void> {
  for (let round = 0; round < 5; round += 1) await Promise.resolve();
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('the ["me"] guard keeps the real signed-out answer (F4 must not swallow D1)', () => {
  it('a 401 from GET /v1/me becomes data null, and that marks the last account data stale in this tab', async () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    const client = createQueryClient();
    client.setQueryData(['me'], A);
    client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
    await client.query({ queryKey: ME_KEY, queryFn: () => fetchMe(), staleTime: 0 });
    expect(client.getQueryState(ME_KEY)?.data).toBeNull();
    expect(client.getQueryState(['account', 'watchlist'])?.isInvalidated).toBe(true);
    expect(client.getQueryData(['account', 'watchlist'])).toEqual({ data: ['a-row'] });
  });

  it('A, then a 401, then B still drops A: the null answer was recorded as the baseline', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [
        jsonResponse(signedInMe),
        problemResponse(unauthenticatedProblem),
        jsonResponse({ data: { ...signedInMe.data, email: B.email } }),
      ],
    });
    const client = createQueryClient();
    const read = () => client.query({ queryKey: ME_KEY, queryFn: () => fetchMe(), staleTime: 0 });
    await read();
    client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
    await read();
    expect(client.getQueryData(['account', 'watchlist'])).toEqual({ data: ['a-row'] });
    await read();
    expect(calls.length).toBe(3);
    expect(client.getQueryData(['account', 'watchlist'])).toBeUndefined();
  });

  it('a failed (500) read keeps the last answer and neither drops nor stales the account data', async () => {
    stubAccountFetch({
      'GET /v1/me': problemResponse(serverErrorProblem('/v1/me')),
    });
    const client = createQueryClient();
    client.setQueryData(['me'], A);
    client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
    await client
      .query({ queryKey: ME_KEY, queryFn: () => fetchMe(), staleTime: 0 })
      .catch(() => undefined);
    expect(client.getQueryState(ME_KEY)?.data).toEqual(A);
    expect(client.getQueryState(ME_KEY)?.error).not.toBeNull();
    expect(client.getQueryState(['account', 'watchlist'])?.isInvalidated).toBe(false);
  });
});

describe('the storage listener (F2)', () => {
  it('a hostile signal value (null, empty, huge, script-like) never throws and still forgets the account', () => {
    const client = createQueryClient();
    client.mount();
    for (const value of [null, '', 'x'.repeat(2_000_000), '<script>alert(1)</script>', '\u0000']) {
      client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
      expect(() => {
        signal(value);
      }).not.toThrow();
      expect(client.getQueryData(['account', 'watchlist'])).toBeUndefined();
    }
    client.unmount();
  });

  it('adds one listener while mounted, keeps it through a StrictMode mount-unmount-mount, and leaks none after many cycles', () => {
    const add = vi.spyOn(window, 'addEventListener');
    const remove = vi.spyOn(window, 'removeEventListener');
    const storage = (spy: typeof add) => spy.mock.calls.filter(([type]) => type === 'storage');
    const client = createQueryClient();
    for (let cycle = 0; cycle < 25; cycle += 1) {
      client.mount();
      client.unmount();
    }
    // StrictMode: mount, unmount, mount.
    client.mount();
    client.unmount();
    client.mount();
    client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
    signal();
    expect(client.getQueryData(['account', 'watchlist'])).toBeUndefined();
    // A second mount (nested providers) holds the listener until the last unmount.
    client.mount();
    client.unmount();
    client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
    signal();
    expect(client.getQueryData(['account', 'watchlist'])).toBeUndefined();
    client.unmount();
    expect(storage(add).length).toBe(storage(remove).length);
    expect(storage(add).length).toBeLessThanOrEqual(30);
    client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
    signal();
    expect(client.getQueryData(['account', 'watchlist'])).toEqual({ data: ['a-row'] });
    add.mockRestore();
    remove.mockRestore();
  });

  it('an unmounted client never reacts while another mounted one does', () => {
    const gone = createQueryClient();
    const live = createQueryClient();
    gone.mount();
    gone.unmount();
    live.mount();
    gone.setQueryData(['account', 'watchlist'], { data: ['gone'] });
    live.setQueryData(['account', 'watchlist'], { data: ['live'] });
    signal();
    expect(gone.getQueryData(['account', 'watchlist'])).toEqual({ data: ['gone'] });
    expect(live.getQueryData(['account', 'watchlist'])).toBeUndefined();
    live.unmount();
  });

  it('a burst of 100 signals asks ["me"] a bounded number of times and then goes quiet', async () => {
    let asked = 0;
    const client = createQueryClient();
    client.mount();
    client.setQueryData(['me'], A);
    const observer = new QueryObserver(client, {
      queryKey: ME_KEY,
      queryFn: () => {
        asked += 1;
        return Promise.resolve(B);
      },
    });
    const unsubscribe = observer.subscribe(() => undefined);
    await tick();
    const before = asked;
    for (let n = 0; n < 100; n += 1) signal(String(n));
    await tick();
    await tick();
    const after = asked;
    expect(after - before).toBeLessThanOrEqual(101);
    await tick();
    await tick();
    expect(asked).toBe(after);
    expect(client.getQueryData(ME_KEY)).toEqual(B);
    unsubscribe();
    client.unmount();
  });

  it('a public page (no ["me"] observer) sends nothing on a signal, however many arrive', async () => {
    let asked = 0;
    const client = createQueryClient();
    client.mount();
    client.setQueryData(['me'], A);
    client.setQueryDefaults(ME_KEY, {
      queryFn: () => {
        asked += 1;
        return Promise.resolve(B);
      },
    });
    for (let n = 0; n < 10; n += 1) signal(String(n));
    await tick();
    expect(asked).toBe(0);
    expect(client.getQueryState(ME_KEY)?.isInvalidated).toBe(true);
    client.unmount();
  });

  it('a signal during a ["me"] fetch ends on the newest answer, and the account data seeded after it survives', async () => {
    const releases: ((value: typeof A) => void)[] = [];
    let asked = 0;
    const client = createQueryClient();
    client.mount();
    client.setQueryData(['me'], A);
    const observer = new QueryObserver(client, {
      queryKey: ME_KEY,
      staleTime: 0,
      queryFn: () => {
        asked += 1;
        if (asked === 1) {
          return new Promise<typeof A>((resolve) => {
            releases.push(resolve);
          });
        }
        return Promise.resolve(B);
      },
    });
    const unsubscribe = observer.subscribe(() => undefined);
    await tick();
    expect(asked).toBe(1);
    client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
    signal();
    // B's own list answers while ["me"] is still being asked.
    client.setQueryData(['account', 'watchlist'], { data: ['b-row'] });
    await tick();
    // The first (pre-switch) request finally answers with A.
    for (const release of releases) release(A);
    await tick();
    expect(client.getQueryData(ME_KEY)).toEqual(B);
    expect(client.getQueryData(['account', 'watchlist'])).toEqual({ data: ['b-row'] });
    unsubscribe();
    client.unmount();
  });
});

describe('the "forgotten" flag never sticks (F5)', () => {
  it('two signals back to back, then a signed-out answer, then A, then B: B still drops A', () => {
    const client = createQueryClient();
    client.mount();
    client.setQueryData(['me'], A);
    signal('1');
    signal('2');
    client.setQueryData(['me'], null);
    client.setQueryData(['me'], A);
    client.setQueryData(['account', 'webhooks'], { data: ['a-hook'] });
    client.setQueryData(['me'], B);
    expect(client.getQueryData(['account', 'webhooks'])).toBeUndefined();
    client.unmount();
  });

  it('a failed ["me"] read after a signal does not consume the flag: the next answer is still the baseline', () => {
    const client = createQueryClient();
    client.mount();
    client.setQueryData(['me'], A);
    signal();
    void client
      .query({ queryKey: ME_KEY, queryFn: () => Promise.reject(new Error('x')) })
      .catch(() => undefined);
    client.setQueryData(['account', 'watchlist'], { data: ['b-row'] });
    client.setQueryData(['me'], B);
    expect(client.getQueryData(['account', 'watchlist'])).toEqual({ data: ['b-row'] });
    client.setQueryData(['me'], A);
    expect(client.getQueryData(['account', 'watchlist'])).toBeUndefined();
    client.unmount();
  });
});

describe('the one-time read (F3)', () => {
  it('is never staled by A, signed out, A again; a different link is its own key; endSession still removes it', async () => {
    const client = createQueryClient();
    const link = (name: string) => ['account', 'verify-email', name] as const;
    let asked = 0;
    const read = (name: string) =>
      client.query({
        queryKey: link(name),
        queryFn: () => {
          asked += 1;
          return Promise.resolve(true);
        },
        staleTime: Infinity,
        meta: ONE_TIME,
      });
    client.setQueryData(['me'], A);
    client.setQueryData(['account', 'watchlist'], { data: ['a-row'] });
    await read('first');
    client.setQueryData(['me'], null);
    client.setQueryData(['me'], A);
    await read('first');
    expect(asked).toBe(1);
    expect(client.getQueryState(link('first'))?.isInvalidated).toBe(false);
    expect(client.getQueryState(['account', 'watchlist'])?.isInvalidated).toBe(true);
    await read('second');
    expect(asked).toBe(2);
    endSession(client);
    expect(client.getQueryState(link('first'))).toBeUndefined();
    expect(client.getQueryState(link('second'))).toBeUndefined();
  });
});
