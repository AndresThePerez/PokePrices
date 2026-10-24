// D2-i1-T04: the shell's per-route tab titles, focus on the new page's h1 after a pathname change,
// the header's Sign in / Account item from a passive ['me'] cache read, and the Menu on Back/Forward.
import { QueryClientProvider, type QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App';
import { ME_KEY, SESSION_SIGNAL_KEY, useKnownSession } from './account/session';
import { createQueryClient } from './query';
import { navigate } from './router';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  cardBody,
  cardNotFoundProblem,
  demoMe,
  isHistoryUrl,
  isMoversUrl,
  jsonResponse,
  problemResponse,
  searchPage,
  signedInMe,
  stubFetch,
  unauthenticatedProblem,
  upstreamUnavailableProblem,
  webhooksBody,
} from './test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('./test/lightweightChartsFake'));

const HOME_H1 = 'Pokémon card prices at the daily close';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function banner(): HTMLElement {
  return screen.getByRole('banner');
}

function navLinks(): [string | null, string | null, string | null][] {
  return within(within(banner()).getByRole('navigation', { name: 'Main' }))
    .getAllByRole('link')
    .map((link) => [
      link.textContent,
      link.getAttribute('href'),
      link.getAttribute('aria-current'),
    ]);
}

function popTo(path: string): void {
  act(() => {
    window.history.pushState(null, '', path);
    window.dispatchEvent(new PopStateEvent('popstate'));
  });
}

/** Signed out everywhere; public answers for the public routes. */
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

/** Signed in as `me` (signedInMe or demoMe); the account pages get what they read. */
function stubSignedIn(me: unknown = signedInMe) {
  return stubFetch((url) => {
    if (url === '/v1/me') return jsonResponse(me);
    if (url === '/v1/webhooks') return jsonResponse(webhooksBody());
    if (isMoversUrl(url)) return answerMovers(url);
    return new Error(`no request expected: ${url}`);
  });
}

function meCalls(calls: readonly { url: string }[]): string[] {
  return calls.map((call) => call.url).filter((url) => url.startsWith('/v1/me'));
}

async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
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

describe('per-route tab titles (D2-i1-T04, D0-F13)', () => {
  it.each([
    ['/', `PokéPrices · ${HOME_H1}`, HOME_H1],
    ['/search', 'Search cards · PokéPrices', 'Search cards'],
    ['/search?q=unown', 'Search cards · PokéPrices', 'Search cards'],
    ['/movers', 'Biggest moves · PokéPrices', 'Biggest moves'],
    ['/movers?direction=down', 'Biggest moves · PokéPrices', 'Biggest moves'],
    ['/sign-in', 'Sign in · PokéPrices', 'Sign in'],
    ['/sign-up', 'Create an account · PokéPrices', 'Create an account'],
    ['/password/forgot', 'Reset your password · PokéPrices', 'Reset your password'],
    [
      '/password/reset?token=fake-token&email=a%40example.com',
      'Choose a new password · PokéPrices',
      'Choose a new password',
    ],
    ['/account/verify-email', 'Verify your email · PokéPrices', 'Verify your email'],
    ['/account', 'Account · PokéPrices', 'Account'],
    ['/watchlist', 'Watchlist · PokéPrices', 'Watchlist'],
    ['/portfolio', 'Portfolio · PokéPrices', 'Portfolio'],
    ['/alerts', 'Alerts and webhooks · PokéPrices', 'Alerts and webhooks'],
    ['/nowhere', 'Page not found · PokéPrices', 'Page not found'],
  ] as const)('titles %s as %s, from its h1', (path, title, h1) => {
    stubSignedOut();
    visit(path);
    render(<App />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(h1);
    expect(document.title).toBe(title);
    for (const pattern of FORBIDDEN_WORDS) expect(document.title).not.toMatch(pattern);
  });

  it('titles a card by its id while loading, then by its name once loaded', async () => {
    stubSignedOut();
    visit('/card/ex10-%3F');
    render(<App />);
    expect(document.title).toBe('Card ex10-? · PokéPrices');
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    expect(document.title).toBe('Unown · PokéPrices');
  });

  it('titles a card that failed to load by its id, and a missing card as not found', async () => {
    stubFetch(() => problemResponse(upstreamUnavailableProblem));
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('alert');
    expect(document.title).toBe('Card ex10-? · PokéPrices');
    cleanup();
    stubFetch(() => problemResponse(cardNotFoundProblem));
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Card not found' });
    expect(document.title).toBe('Card not found · PokéPrices');
  });

  it('follows the route through in-place navigation and Back', () => {
    stubSignedOut();
    visit('/nowhere');
    render(<App />);
    fireEvent.click(within(banner()).getByRole('link', { name: 'Movers' }));
    expect(document.title).toBe('Biggest moves · PokéPrices');
    popTo('/search');
    expect(document.title).toBe('Search cards · PokéPrices');
    popTo('/');
    expect(document.title).toBe(`PokéPrices · ${HOME_H1}`);
  });
});

describe('focus on route change (D2-i1-T04, D0-F13)', () => {
  it('leaves focus where the browser put it on first mount', () => {
    stubSignedOut();
    visit('/movers');
    render(<App />);
    expect(document.activeElement).toBe(document.body);
    expect(screen.getByRole('heading', { level: 1 }).hasAttribute('tabindex')).toBe(false);
  });

  it('moves focus to the new page h1 after a header link, without making it tabbable', () => {
    const calls = stubSignedOut();
    visit('/nowhere');
    render(<App />);
    fireEvent.click(within(banner()).getByRole('link', { name: 'Movers' }));
    const h1 = screen.getByRole('heading', { level: 1, name: 'Biggest moves' });
    expect(document.activeElement).toBe(h1);
    expect(h1.getAttribute('tabindex')).toBe('-1');
    expect(document.getElementById('content')?.querySelector('main h1')).toBe(h1);
    const tabbable = Array.from(
      document.body.querySelectorAll<HTMLElement>('a[href], button, select, input, [tabindex]'),
    ).filter((el) => el.tabIndex >= 0);
    expect(tabbable[0]).toBe(within(banner()).getByRole('link', { name: 'Skip to content' }));
    expect(tabbable).not.toContain(h1);
    expect(
      document.querySelectorAll('[role=status], [role=alert], [role=log], [aria-live], output'),
    ).toHaveLength(screen.queryAllByRole('status').length);
    expect(meCalls(calls)).toEqual([]);
  });

  it('moves focus to the new page h1 on Back and Forward', () => {
    stubSignedOut();
    visit('/nowhere');
    render(<App />);
    popTo('/sign-up');
    expect(document.activeElement).toBe(
      screen.getByRole('heading', { level: 1, name: 'Create an account' }),
    );
    popTo('/');
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1, name: HOME_H1 }));
  });

  it('lands on the final h1 of a card page once the card resolves (A1-8)', async () => {
    stubSignedOut();
    visit('/nowhere');
    render(<App />);
    act(() => {
      navigate('/card/ex10-%3F');
    });
    const h1 = await screen.findByRole('heading', { level: 1, name: 'Unown' });
    await waitFor(() => {
      expect(document.activeElement).toBe(h1);
    });
    expect(h1.getAttribute('tabindex')).toBe('-1');
  });

  it('keeps focus on the pressed Direction segment on a query-only change', async () => {
    stubSignedOut();
    visit('/nowhere');
    render(<App />);
    fireEvent.click(within(banner()).getByRole('link', { name: 'Movers' }));
    const falls = within(screen.getByRole('group', { name: 'Direction' })).getByRole('button', {
      name: 'Falls',
    });
    falls.focus();
    fireEvent.click(falls);
    expect(window.location.search).toBe('?direction=down');
    expect(document.activeElement).toBe(falls);
    await settle();
    expect(document.activeElement).toBe(falls);
  });
});

describe('the header session item (D2-i1-T04, RULING a, D0-F01)', () => {
  it.each([
    ['/sign-in', 'page'],
    ['/account', null],
    ['/watchlist', null],
  ] as const)(
    'reads Sign in once the visitor is known to be signed out, on %s',
    async (path, mark) => {
      stubSignedOut();
      visit(path);
      render(<App />);
      await waitFor(() => {
        expect(navLinks().at(-1)).toEqual(['Sign in', '/sign-in', mark]);
      });
      expect(navLinks().map(([name, href]) => [name, href])).toEqual([
        ['PokéPrices', '/'],
        ['Search', '/search'],
        ['Movers', '/movers'],
        ['Watchlist', '/watchlist'],
        ['Portfolio', '/portfolio'],
        ['Sign in', '/sign-in'],
      ]);
      const menu = within(banner()).getByRole('button', { name: 'Menu' });
      fireEvent.click(menu);
      const sheet = document.getElementById(menu.getAttribute('aria-controls') ?? '');
      const last = within(sheet as HTMLElement)
        .getAllByRole('link')
        .at(-1);
      expect([last?.textContent, last?.getAttribute('aria-current')]).toEqual(['Sign in', mark]);
    },
  );

  it.each(['/account', '/alerts'])(
    'reads Account, marked current, when signed in on %s',
    async (path) => {
      stubSignedIn();
      visit(path);
      render(<App />);
      await screen.findByText(/Signed in as|No webhooks yet\./);
      expect(navLinks().at(-1)).toEqual(['Account', '/account', 'page']);
    },
  );

  it('keeps Account while the session is unknown on a public page, asking nothing', async () => {
    const calls = stubSignedOut();
    visit('/movers');
    render(<App />);
    await settle();
    expect(navLinks().at(-1)).toEqual(['Account', '/account', null]);
    expect(meCalls(calls)).toEqual([]);
  });

  it('follows the session into public pages after it is known, still asking nothing more', async () => {
    const calls = stubSignedOut();
    visit('/account');
    render(<App />);
    await waitFor(() => {
      expect(navLinks().at(-1)?.[0]).toBe('Sign in');
    });
    const before = meCalls(calls).length;
    fireEvent.click(within(banner()).getByRole('link', { name: 'Movers' }));
    await settle();
    expect(navLinks().at(-1)).toEqual(['Sign in', '/sign-in', null]);
    expect(meCalls(calls)).toHaveLength(before);
  });

  it('shows Account and asks nothing when another tab signals a session change on /movers (A1-7)', async () => {
    const calls = stubSignedIn(demoMe);
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as demo@example.com');
    fireEvent.click(within(banner()).getByRole('link', { name: 'Movers' }));
    await settle();
    const before = meCalls(calls).length;
    act(() => {
      window.dispatchEvent(
        new StorageEvent('storage', { key: SESSION_SIGNAL_KEY, newValue: '1.1' }),
      );
    });
    await settle();
    expect(navLinks().at(-1)).toEqual(['Account', '/account', null]);
    expect(meCalls(calls)).toHaveLength(before);
  });
});

describe('useKnownSession, the passive ["me"] read (A1-7)', () => {
  let client: QueryClient;
  const seen: unknown[] = [];

  function Probe() {
    seen.push(useKnownSession());
    return null;
  }

  function mount(): void {
    render(
      <QueryClientProvider client={client}>
        <Probe />
      </QueryClientProvider>,
    );
  }

  beforeEach(() => {
    client = createQueryClient();
    seen.length = 0;
  });

  it('is unknown with nothing cached, and adds no observer and no request', async () => {
    const calls = stubFetch(() => new Error('no request expected'));
    mount();
    await settle();
    expect(seen.at(-1)).toBe('unknown');
    expect(client.getQueryCache().find({ queryKey: ME_KEY })?.getObserversCount() ?? 0).toBe(0);
    expect(calls).toHaveLength(0);
  });

  it('is the cached account itself, then unknown once another tab signals a change', async () => {
    const calls = stubFetch(() => new Error('no request expected'));
    client.setQueryData(ME_KEY, demoMe.data);
    mount();
    expect(seen.at(-1)).toBe(client.getQueryData(ME_KEY));
    act(() => {
      window.dispatchEvent(
        new StorageEvent('storage', { key: SESSION_SIGNAL_KEY, newValue: '1.1' }),
      );
    });
    await settle();
    expect(seen.at(-1)).toBe('unknown');
    expect(calls).toHaveLength(0);
    expect(client.getQueryCache().find({ queryKey: ME_KEY })?.getObserversCount()).toBe(0);
  });

  it('stays signed out (null) for a cached null, even once it is invalidated', async () => {
    stubFetch(() => new Error('no request expected'));
    client.setQueryData(ME_KEY, null);
    mount();
    expect(seen.at(-1)).toBeNull();
    await act(async () => {
      await client.invalidateQueries({ queryKey: ME_KEY, refetchType: 'none' });
    });
    expect(seen.at(-1)).toBeNull();
  });
});

describe('the Menu on Back and Forward (D1 cf 3)', () => {
  it('closes an open Menu when the pathname changes by popstate', () => {
    stubSignedOut();
    visit('/movers');
    render(<App />);
    const menu = within(banner()).getByRole('button', { name: 'Menu' });
    fireEvent.click(menu);
    expect(menu.getAttribute('aria-expanded')).toBe('true');
    popTo('/portfolio');
    expect(menu.getAttribute('aria-expanded')).toBe('false');
  });
});
