// D2-i1-T04 review: edge cases for the shell's passive session read, route focus and tab titles.
import { QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App';
import { ME_KEY, useKnownSession } from './account/session';
import { createQueryClient } from './query';
import { navigate } from './router';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  cardBody,
  demoMe,
  isHistoryUrl,
  isMoversUrl,
  jsonResponse,
  problemResponse,
  searchPage,
  stubFetch,
  unauthenticatedProblem,
} from './test/fixtures';

vi.mock('lightweight-charts', () => import('./test/lightweightChartsFake'));

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function banner(): HTMLElement {
  return screen.getByRole('banner');
}

function lastNavName(): string | null | undefined {
  return within(within(banner()).getByRole('navigation', { name: 'Main' }))
    .getAllByRole('link')
    .at(-1)?.textContent;
}

function popTo(path: string): void {
  act(() => {
    window.history.pushState(null, '', path);
    window.dispatchEvent(new PopStateEvent('popstate'));
  });
}

async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

function stubSignedOut() {
  return stubFetch((url) => {
    if (url.startsWith('/v1/me')) return problemResponse(unauthenticatedProblem);
    if (isMoversUrl(url)) return answerMovers(url);
    if (url.startsWith('/v1/cards?')) return jsonResponse(searchPage);
    if (isHistoryUrl(url)) return new Error('no history in this test');
    if (url.startsWith('/v1/cards/')) return jsonResponse(cardBody);
    return new Error(`no request expected: ${url}`);
  });
}

beforeEach(() => {
  window.localStorage.clear();
  document.title = 'PokéPrices';
});

afterEach(() => {
  cleanup();
  window.localStorage.clear();
  visit('/');
});

describe('the passive session read asks nothing on any public route (A1-7, RULING a)', () => {
  it.each(['/', '/movers', '/search?q=x', '/card/ex10-%3F', '/nowhere'])(
    'mounting on %s and walking the public routes sends no /v1/me and keeps Account',
    async (start) => {
      const calls = stubSignedOut();
      visit(start);
      render(<App />);
      await settle();
      for (const name of ['Search', 'Movers']) {
        fireEvent.click(within(banner()).getByRole('link', { name }));
        await settle();
      }
      popTo('/nowhere');
      popTo('/card/ex10-%3F');
      await settle();
      expect(calls.map((call) => call.url).filter((url) => url.startsWith('/v1/me'))).toEqual([]);
      expect(lastNavName()).toBe('Account');
    },
  );
});

describe('useKnownSession against a changing cache (A1-7)', () => {
  const renders: unknown[] = [];

  function Probe() {
    renders.push(useKnownSession());
    return null;
  }

  function mountWith(client: ReturnType<typeof createQueryClient>) {
    return render(
      <QueryClientProvider client={client}>
        <Probe />
      </QueryClientProvider>,
    );
  }

  beforeEach(() => {
    renders.length = 0;
  });

  it('falls back to unknown, never throws, when the cached query is removed or cleared', async () => {
    stubFetch(() => new Error('no request expected'));
    const client = createQueryClient();
    client.setQueryData(ME_KEY, demoMe.data);
    mountWith(client);
    expect(renders.at(-1)).toBe(demoMe.data);
    await act(async () => {
      client.removeQueries({ queryKey: ME_KEY });
      await Promise.resolve();
    });
    expect(renders.at(-1)).toBe('unknown');
    act(() => {
      client.setQueryData(ME_KEY, null);
    });
    expect(renders.at(-1)).toBeNull();
    act(() => {
      client.clear();
    });
    expect(renders.at(-1)).toBe('unknown');
  });

  it('falls back to unknown, without a request, once the unobserved query is garbage collected', () => {
    vi.useFakeTimers();
    try {
      const calls = stubFetch(() => new Error('no request expected'));
      const client = createQueryClient();
      client.setQueryData(ME_KEY, null);
      mountWith(client);
      expect(renders.at(-1)).toBeNull();
      act(() => {
        vi.advanceTimersByTime(60 * 60 * 1000);
      });
      expect(renders.at(-1)).toBe('unknown');
      expect(calls).toHaveLength(0);
    } finally {
      vi.useRealTimers();
    }
  });

  it('does not read a key with extra parts as the session', () => {
    const client = createQueryClient();
    client.setQueryData(['me', 'x'], null);
    client.setQueryData(['me', 'x'], demoMe.data);
    mountWith(client);
    expect(renders.at(-1)).toBe('unknown');
  });

  it('does not re-render the reader for an unrelated cache change (stable snapshot)', () => {
    const client = createQueryClient();
    client.setQueryData(ME_KEY, demoMe.data);
    mountWith(client);
    const before = renders.length;
    act(() => {
      client.setQueryData(['card', 'a'], { n: 1 });
      client.setQueryData(['card', 'a'], { n: 2 });
      client.setQueryData(['card', 'b'], { n: 3 });
    });
    expect(renders).toHaveLength(before);
  });

  it('leaves no cache listener behind across mount and unmount cycles', () => {
    const client = createQueryClient();
    const cache = client.getQueryCache();
    const original = cache.subscribe.bind(cache);
    let live = 0;
    vi.spyOn(cache, 'subscribe').mockImplementation((listener) => {
      live += 1;
      const off = original(listener);
      let done = false;
      return () => {
        if (!done) live -= 1;
        done = true;
        off();
      };
    });
    for (let cycle = 0; cycle < 4; cycle += 1) {
      const view = mountWith(client);
      expect(live).toBeGreaterThan(0);
      view.unmount();
      expect(live).toBe(0);
    }
  });
});

describe('route focus edge cases (A1-8, D0-F13)', () => {
  it('ignores a hash-only change: focus stays on the element the visitor chose', () => {
    stubSignedOut();
    visit('/movers');
    render(<App />);
    const falls = within(screen.getByRole('group', { name: 'Direction' })).getByRole('button', {
      name: 'Falls',
    });
    falls.focus();
    popTo('/movers#content');
    expect(document.activeElement).toBe(falls);
  });

  it('after two rapid pathname changes, focuses only the last page h1', () => {
    stubSignedOut();
    visit('/nowhere');
    render(<App />);
    act(() => {
      navigate('/movers');
      navigate('/sign-up');
    });
    expect(document.activeElement).toBe(
      screen.getByRole('heading', { level: 1, name: 'Create an account' }),
    );
    expect(document.querySelectorAll('h1[tabindex]')).toHaveLength(1);
  });

  it('never steals focus when a card resolves late and the visitor has moved to another control', async () => {
    let release: (() => void) | undefined;
    const gate = new Promise<void>((resolve) => {
      release = resolve;
    });
    vi.stubGlobal('fetch', async (input: string | URL): Promise<Response> => {
      const url = String(input);
      if (url.startsWith('/v1/me')) return problemResponse(unauthenticatedProblem);
      if (isHistoryUrl(url)) throw new Error('no history');
      await gate;
      return jsonResponse(cardBody);
    });
    visit('/nowhere');
    render(<App />);
    act(() => {
      navigate('/card/ex10-%3F');
    });
    const loadingH1 = screen.getByRole('heading', { level: 1, name: 'Card ex10-?' });
    expect(document.activeElement).toBe(loadingH1);
    const menu = within(banner()).getByRole('button', { name: 'Menu' });
    menu.focus();
    await act(async () => {
      release?.();
      await gate;
    });
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    await settle();
    expect(document.activeElement).toBe(menu);
    vi.unstubAllGlobals();
  });

  it.each([
    ['/search?q=x', 'Search cards'],
    ['/portfolio', 'Portfolio'],
    ['/watchlist', 'Watchlist'],
    ['/account', 'Account'],
    ['/alerts', 'Alerts and webhooks'],
    ['/sign-in', 'Sign in'],
    ['/account/verify-email', 'Verify your email'],
    ['/password/forgot', 'Reset your password'],
  ])('ends on the connected h1 of %s once its data has settled', async (path, h1) => {
    stubSignedOut();
    visit('/nowhere');
    render(<App />);
    act(() => {
      navigate(path);
    });
    await settle();
    const heading = screen.getByRole('heading', { level: 1, name: h1 });
    expect(heading.isConnected).toBe(true);
    expect(document.activeElement).toBe(heading);
  });
});

describe('tab titles edge cases (D0-F13)', () => {
  it('writes a hostile card name into the title as plain text and restores the next route', async () => {
    const name = `<script>x</script>‮${'W'.repeat(300)}`;
    stubFetch((url) => {
      if (url.startsWith('/v1/me')) return problemResponse(unauthenticatedProblem);
      if (isHistoryUrl(url)) return new Error('no history');
      if (url.startsWith('/v1/cards/')) {
        return jsonResponse({ ...cardBody, data: { ...cardBody.data, name } });
      }
      return new Error(`no request expected: ${url}`);
    });
    visit('/card/ex10-%3F');
    render(<App />);
    await waitFor(() => {
      expect(document.title).toBe(`${name} · PokéPrices`);
    });
    expect(document.head.querySelector('script')).toBeNull();
    popTo('/nowhere');
    expect(document.title).toBe('Page not found · PokéPrices');
    popTo('/card/ex10-%3F');
    await waitFor(() => {
      expect(document.title).toBe(`${name} · PokéPrices`);
    });
    popTo('/movers?direction=down');
    expect(document.title).toBe('Biggest moves · PokéPrices');
    for (const pattern of FORBIDDEN_WORDS) expect(document.title).not.toMatch(pattern);
  });
});
