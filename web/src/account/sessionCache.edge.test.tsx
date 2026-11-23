// Adversarial tests for the passive session readers over the query cache (useKnownSession,
// useReadOnly, useSessionAwaited): a first ['me'] written to an empty cache, the cache cleared or
// the query removed, account A then B, a write to a key that did not exist, an unmount before the
// deferred "added" notice fires (no update on an unmounted reader, no subscription left), and the
// sign-in flows with React's two console complaints watched.
import { QueryClientProvider } from '@tanstack/react-query';
import {
  act,
  cleanup,
  fireEvent,
  render,
  renderHook,
  screen,
  within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { Me } from '../api/client';
import { createQueryClient } from '../query';
import {
  answerMovers,
  clearXsrfCookie,
  demoMe,
  estimateOf,
  freshnessBody,
  isFreshnessUrl,
  isMoversUrl,
  jsonResponse,
  problemResponse,
  rawResponse,
  setXsrfCookie,
  signedInMe,
  unauthenticatedProblem,
  watchlistBody,
} from '../test/fixtures';
import { ME_KEY, useKnownSession, useReadOnly } from './session';
import { useSessionAwaited } from './sessionAsk';

let errors: string[] = [];

beforeEach(() => {
  errors = [];
  vi.spyOn(console, 'error').mockImplementation((...args: unknown[]) => {
    errors.push(String(args[0]));
  });
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  clearXsrfCookie();
  window.localStorage.clear();
  window.history.replaceState(null, '', '/');
});

function complaints(): string[] {
  return errors.filter((line) => /Cannot update a component|not wrapped in act/.test(line));
}

async function flush(): Promise<void> {
  for (let i = 0; i < 4; i += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

function harness() {
  const client = createQueryClient();
  const wrapper = ({ children }: { readonly children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return { client, wrapper };
}

const A: Me = { ...signedInMe.data, email: 'a@example.com', portfolio: estimateOf() };
const B: Me = { ...signedInMe.data, email: 'b@example.com', portfolio: estimateOf({ count: 9 }) };
const DEMO: Me = { ...demoMe.data, portfolio: estimateOf() };

/** The account the known session names: its email, null signed out, 'unknown' before an answer. */
function who(known: 'unknown' | Me | null): string | null {
  if (known === 'unknown' || known === null) return known;
  return known.email;
}

function useBoth() {
  return { known: useKnownSession(), readOnly: useReadOnly() };
}

describe('the readers follow every real change of the cache', () => {
  it('a first [me] written to an empty cache is seen, by the known session and the read-only flag', async () => {
    const { client, wrapper } = harness();
    const { result } = renderHook(useBoth, { wrapper });
    expect(result.current.known).toBe('unknown');
    await act(async () => {
      client.setQueryData(ME_KEY, DEMO);
      await Promise.resolve();
    });
    expect(result.current.known).toBe(DEMO);
    expect(result.current.readOnly).toBe(true);
    await flush();
    expect(complaints()).toEqual([]);
  });

  it('account A, then B, then signed out, then the demo: each change shows at once, nothing lags', async () => {
    const { client, wrapper } = harness();
    const { result } = renderHook(useBoth, { wrapper });
    const steps: readonly (readonly [Me | null, boolean])[] = [
      [A, false],
      [B, false],
      [null, false],
      [DEMO, true],
      [A, false],
    ];
    for (const [next, readOnly] of steps) {
      await act(async () => {
        client.setQueryData(ME_KEY, next);
        await Promise.resolve();
      });
      expect(who(result.current.known)).toBe(next === null ? null : next.email);
      expect(result.current.readOnly).toBe(readOnly);
    }
    await flush();
    expect(complaints()).toEqual([]);
  });

  it('the cache cleared, and the query removed, both put the readers back to unknown and not demo', async () => {
    const { client, wrapper } = harness();
    const { result } = renderHook(useBoth, { wrapper });
    await act(async () => {
      client.setQueryData(ME_KEY, DEMO);
      await Promise.resolve();
    });
    expect(result.current.readOnly).toBe(true);
    await act(async () => {
      client.removeQueries({ queryKey: ME_KEY, exact: true });
      await Promise.resolve();
    });
    expect(result.current.known).toBe('unknown');
    expect(result.current.readOnly).toBe(false);
    await act(async () => {
      client.setQueryData(ME_KEY, DEMO);
      await Promise.resolve();
    });
    expect(result.current.readOnly).toBe(true);
    await act(async () => {
      client.clear();
      await Promise.resolve();
    });
    expect(result.current.known).toBe('unknown');
    expect(result.current.readOnly).toBe(false);
    await flush();
    expect(complaints()).toEqual([]);
  });

  it('an invalidated account is unknown again, and an answer afterwards is seen', async () => {
    const { client, wrapper } = harness();
    const { result } = renderHook(useBoth, { wrapper });
    await act(async () => {
      client.setQueryData(ME_KEY, A);
      await Promise.resolve();
    });
    await act(async () => {
      await client.invalidateQueries({ queryKey: ME_KEY, refetchType: 'none' });
    });
    expect(result.current.known).toBe('unknown');
    await act(async () => {
      client.setQueryData(ME_KEY, B);
      await Promise.resolve();
    });
    expect(who(result.current.known)).toBe(B.email);
  });

  it('a write to a key that did not exist, and one to an unrelated key, change nothing and complain of nothing', async () => {
    const { client, wrapper } = harness();
    const { result } = renderHook(useBoth, { wrapper });
    await act(async () => {
      client.setQueryData(['account', 'watchlist'], { data: [] });
      client.setQueryData(['never', 'seen'], 1);
      await Promise.resolve();
    });
    await flush();
    expect(result.current.known).toBe('unknown');
    expect(complaints()).toEqual([]);
  });
});

describe('an unmount before the deferred notice', () => {
  it('leaves no update on the unmounted reader and no subscription', async () => {
    const { client, wrapper } = harness();
    const { unmount } = renderHook(useBoth, { wrapper });
    const cache = client.getQueryCache();
    expect(cache.hasListeners()).toBe(true);
    // An added query schedules its notice; the reader goes before it fires.
    cache.build(client, { queryKey: ['zz', 1] });
    cache.build(client, { queryKey: ME_KEY });
    unmount();
    await flush();
    expect(complaints()).toEqual([]);
    expect(errors.filter((line) => /unmounted/.test(line))).toEqual([]);
    // createQueryClient's own cache listener stays; the reader's is gone: unmounting twice is harmless.
    client.setQueryData(ME_KEY, A);
    await flush();
    expect(complaints()).toEqual([]);
  });

  it('mounting and unmounting a hundred readers leaves the listener count where it started', async () => {
    const { client, wrapper } = harness();
    const cache = client.getQueryCache();
    // Subscribable keeps its listeners in a Set; count them before and after.
    const listeners = (cache as unknown as { listeners: Set<unknown> }).listeners;
    const start = listeners.size;
    expect(start).toBeGreaterThan(0);
    for (let i = 0; i < 100; i += 1) {
      const { unmount } = renderHook(useBoth, { wrapper });
      expect(listeners.size).toBeGreaterThan(start);
      unmount();
    }
    await flush();
    expect(listeners.size).toBe(start);
    client.setQueryData(['zz', 2], 1);
    await flush();
    expect(complaints()).toEqual([]);
  });
});

describe('useSessionAwaited', () => {
  it('is true only while the [me] read runs with nothing cached: false for an answer, a 401 null, a failure and a cancel', async () => {
    setXsrfCookie();
    window.history.replaceState(null, '', '/card/ex10-%3F');
    const { client, wrapper } = harness();
    const { result } = renderHook(() => useSessionAwaited(), { wrapper });
    expect(result.current).toBe(false);
    let release: (value: Me | null) => void = () => undefined;
    let pending: Promise<Me | null> = Promise.resolve(null);
    const start = (): void => {
      pending = client.query<Me | null>({
        queryKey: ME_KEY,
        queryFn: () =>
          new Promise<Me | null>((resolve) => {
            release = resolve;
          }),
      });
    };
    await act(async () => {
      start();
      await Promise.resolve();
    });
    expect(result.current).toBe(true);
    await act(async () => {
      release(null);
      await pending;
    });
    expect(result.current).toBe(false);
    await act(async () => {
      client.clear();
      await Promise.resolve();
    });
    expect(result.current).toBe(false);
    // A failing read.
    await act(async () => {
      await client
        .query({
          queryKey: ME_KEY,
          queryFn: () => Promise.reject(new Error('down')),
          retry: false,
        })
        .catch(() => undefined);
    });
    expect(result.current).toBe(false);
    await flush();
    expect(complaints()).toEqual([]);
  });

  it('is false on a route that never asks, with the read running and the cookie present', async () => {
    setXsrfCookie();
    window.history.replaceState(null, '', '/watchlist');
    const { client, wrapper } = harness();
    const { result } = renderHook(() => useSessionAwaited(), { wrapper });
    await act(async () => {
      void client
        .query({ queryKey: ME_KEY, queryFn: () => new Promise<Me | null>(() => undefined) })
        .catch(() => undefined);
      await Promise.resolve();
    });
    expect(result.current).toBe(false);
  });

  it('is false without the cookie even with the read running', async () => {
    window.history.replaceState(null, '', '/card/ex10-%3F');
    const { client, wrapper } = harness();
    const { result } = renderHook(() => useSessionAwaited(), { wrapper });
    await act(async () => {
      void client
        .query({ queryKey: ME_KEY, queryFn: () => new Promise<Me | null>(() => undefined) })
        .catch(() => undefined);
      await Promise.resolve();
    });
    expect(result.current).toBe(false);
  });
});

describe('sign-in flows log no render-time update and no missing act', () => {
  function answers(who: () => 'nobody' | 'a' | 'demo') {
    vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
      const url = String(input);
      const name = `${init?.method ?? 'GET'} ${url}`;
      if (name === 'GET /sanctum/csrf-cookie') {
        setXsrfCookie();
        return Promise.resolve(rawResponse(204, null));
      }
      if (isMoversUrl(url)) {
        const movers = answerMovers(url);
        return movers instanceof Response ? Promise.resolve(movers) : Promise.reject(movers);
      }
      if (isFreshnessUrl(url)) return Promise.resolve(jsonResponse(freshnessBody()));
      if (name === 'GET /v1/me') {
        const current = who();
        if (current === 'nobody') return Promise.resolve(problemResponse(unauthenticatedProblem));
        return Promise.resolve(
          jsonResponse({ data: current === 'demo' ? DEMO : { ...A, read_only: false } }),
        );
      }
      if (name === 'GET /v1/watchlist') return Promise.resolve(jsonResponse(watchlistBody([])));
      if (name === 'POST /v1/demo/session') return Promise.resolve(rawResponse(204, null));
      return Promise.reject(new TypeError(`no request expected: ${name}`));
    });
  }

  it('a first [me] added by the demo button on a signed-out watchlist, then the header, then a hop to home', async () => {
    let who: 'nobody' | 'a' | 'demo' = 'nobody';
    vi.stubGlobal('fetch', undefined);
    answers(() => who);
    const original = globalThis.fetch;
    vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit) => {
      if (`${init?.method ?? 'GET'} ${String(input)}` === 'POST /v1/demo/session') who = 'demo';
      return original(input, init);
    });
    window.history.replaceState(null, '', '/watchlist');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Try the read-only demo' }));
    await within(screen.getByRole('banner')).findByRole('link', { name: /^Portfolio estimate/ });
    await flush();
    window.history.pushState(null, '', '/');
    await act(async () => {
      window.dispatchEvent(new PopStateEvent('popstate'));
      await Promise.resolve();
    });
    await flush();
    expect(complaints()).toEqual([]);
  });
});
