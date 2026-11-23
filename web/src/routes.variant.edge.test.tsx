import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from './App';
import { signInPath } from './account/lists';
import { CARD_VARIANT_PARAM, matchRoute, safeNext } from './router';
import { cardVariantPath } from './routes';
import {
  type AccountAnswer,
  clearXsrfCookie,
  holdingOf,
  isMoversUrl,
  jsonResponse,
  moverOf,
  moversBody,
  portfolioBody,
  signedInMe,
  stubAccountFetch,
  stubFetch,
  watchItemOf,
  watchlistBody,
} from './test/fixtures';

vi.mock('lightweight-charts', () => import('./test/lightweightChartsFake'));

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

/** The route the browser itself parses out of a built path, as a real click would land it. */
function landed(path: string): ReturnType<typeof matchRoute> {
  visit(path);
  return matchRoute(window.location.pathname, window.location.search);
}

describe('cardVariantPath round-trips through the address and matchRoute', () => {
  const keys = [
    'ex10-?:holo:unlimited:-',
    'a&b=c:d',
    'hash#key:-:-',
    '100%:pure:-:-',
    'plus+sign:-:-',
    'with space:-:-',
    'ex13-3δ:reverse:-:-',
    'slash/inside:-:-',
    '%3A:%253A:-',
    'é:ü:日本:🃏',
    `${'k'.repeat(4000)}:-:-`,
  ];

  it.each(keys)('carries the key %s back exactly, in the browser address too', (key) => {
    for (const id of ['ex13-3', 'ex10-?', 'a b&c#d', 'δ-1']) {
      const path = cardVariantPath(id, key);
      expect(path.startsWith('/card/')).toBe(true);
      expect(path.split('?')).toHaveLength(2);
      expect(path).not.toContain('#');
      const route = matchRoute(path.slice(0, path.indexOf('?')), path.slice(path.indexOf('?')));
      expect(route).toEqual({ name: 'card', id, variant: key });
      expect(landed(path)).toEqual({ name: 'card', id, variant: key });
      expect(new URLSearchParams(window.location.search).getAll(CARD_VARIANT_PARAM)).toEqual([key]);
    }
  });

  it('builds a link that opens the default variant for an empty key', () => {
    const path = cardVariantPath('ex13-3', '');
    expect(landed(path)).toEqual({ name: 'card', id: 'ex13-3' });
  });

  it('builds distinct links for two variants of one card', () => {
    expect(cardVariantPath('base1-4', 'base1-4:holo:1st:-')).not.toBe(
      cardVariantPath('base1-4', 'base1-4:holo:unlimited:-'),
    );
  });
});

describe('the other routes ignore a stray variant parameter', () => {
  it.each([
    ['/', '?variant=x', { name: 'home' }],
    ['/movers', '?variant=x&direction=down', { name: 'movers', direction: 'down' }],
    ['/movers', '?direction=up&variant=down', { name: 'movers', direction: 'up' }],
    ['/watchlist', '?variant=x', { name: 'watchlist' }],
    ['/portfolio', '?variant=x', { name: 'portfolio' }],
    ['/alerts', '?variant=x', { name: 'alerts' }],
    ['/account', '?variant=x', { name: 'account' }],
    ['/sign-up', '?variant=x', { name: 'sign-up' }],
    ['/nope', '?variant=x', { name: 'not-found' }],
    ['/card/x/y', '?variant=x', { name: 'not-found' }],
    ['/card/%E0%A4%A', '?variant=x', { name: 'not-found' }],
  ])('%s%s', (pathname, search, expected) => {
    expect(matchRoute(pathname, search)).toEqual(expected);
  });

  it('keeps a card address with a variant as a safe sign-in return path', () => {
    const path = cardVariantPath('ex10-?', 'ex10-?:holo:unlimited:-');
    expect(safeNext(path)).toBe(path);
    expect(matchRoute('/sign-in', `?next=${encodeURIComponent(path)}`)).toEqual({
      name: 'sign-in',
      next: path,
    });
    // The card page's sign-in prompt still returns to an in-app card address.
    const next = new URLSearchParams(signInPath('ex10-?').split('?')[1]).get('next');
    expect(safeNext(next)).toBe(next);
  });

  it('opens the search page for /search?variant=, not a card', () => {
    const route = matchRoute('/search', '?variant=x&q=a');
    expect(route.name).toBe('search');
    expect(route.name === 'search' ? route.query.get('q') : null).toBe('a');
  });
});

describe('every list row links to its own variant', () => {
  const holo = moverOf({
    rank: 1,
    card_id: 'ex10-?',
    card: { name: 'Unown', set_name: 'Unseen Forces', number: '?' },
    variant_key: 'ex10-?:holo:unlimited:-',
    finish: 'holo',
    edition: 'unlimited',
  });
  const reverse = moverOf({
    rank: 2,
    card_id: 'ex10-?',
    card: { name: 'Unown', set_name: 'Unseen Forces', number: '?' },
    variant_key: 'ex10-?:reverse:1st:stamped',
    finish: 'reverse',
    edition: '1st',
    treatment: 'stamped',
  });
  const odd = moverOf({
    rank: 3,
    card_id: 'ex5-17',
    variant_key: 'ex5-17:a&b#c%d+e f:δ:-',
    finish: 'odd',
  });
  const down = moverOf({
    rank: 1,
    card_id: 'ex9-91',
    card: { name: 'Cacturne ex', set_name: 'Emerald', number: '91' },
    variant_key: 'ex9-91:holo:-:-',
    finish: 'holo',
    change: '-1.00',
    change_pct: '-1.00',
  });

  function serveMovers(): void {
    stubFetch((url) => {
      if (!isMoversUrl(url)) return new Error(`no request expected: ${url}`);
      return url.includes('direction=down')
        ? jsonResponse(moversBody('down', [down]))
        : jsonResponse(moversBody('up', [holo, reverse, odd]));
    });
  }

  function variantsOf(list: HTMLElement): (string | null | undefined)[] {
    return within(list)
      .getAllByRole('listitem')
      .map((row) => {
        const href = within(row).getAllByRole('link')[0]?.getAttribute('href') ?? '';
        const at = href.indexOf('?');
        const route = matchRoute(
          href.slice(0, at < 0 ? href.length : at),
          at < 0 ? '' : href.slice(at),
        );
        return route.name === 'card' ? route.variant : null;
      });
  }

  it('gives both home strip lists and the board each row’s own variant', async () => {
    serveMovers();
    visit('/');
    render(<App />);
    const rises = await screen.findByRole('list', { name: 'Rises' });
    const falls = await screen.findByRole('list', { name: 'Falls' });
    await within(rises).findAllByRole('link', { name: 'Unown' });
    expect(variantsOf(rises)).toEqual([holo.variant_key, reverse.variant_key, odd.variant_key]);
    await within(falls).findByRole('link', { name: 'Cacturne ex' });
    expect(variantsOf(falls)).toEqual([down.variant_key]);
    const [first, second] = within(rises).getAllByRole('link', { name: 'Unown' });
    expect(first?.getAttribute('href')).not.toBe(second?.getAttribute('href'));
    cleanup();

    serveMovers();
    visit('/movers');
    render(<App />);
    const board = await screen.findByRole('list', { name: 'Rises' });
    await within(board).findAllByRole('link', { name: 'Unown' });
    expect(variantsOf(board)).toEqual([holo.variant_key, reverse.variant_key, odd.variant_key]);
    cleanup();

    serveMovers();
    visit('/movers?direction=down&variant=ex9-91%3Aholo%3A-%3A-');
    render(<App />);
    const falling = await screen.findByRole('list', { name: 'Falls' });
    await within(falling).findByRole('link', { name: 'Cacturne ex' });
    expect(variantsOf(falling)).toEqual([down.variant_key]);
  });

  it('gives watchlist and portfolio rows of one card different, round-tripping links', async () => {
    const rows = [
      { variant_key: 'base1-4:holo:1st:-', edition: '1st' },
      { variant_key: 'base1-4:holo:unlimited:-', edition: 'unlimited' },
      { variant_key: 'base1-4:holo:unlimited:δ #1&2', edition: 'unlimited', treatment: 'δ #1&2' },
    ];
    const routes: Record<string, AccountAnswer> = {
      'GET /v1/me': () => jsonResponse(signedInMe),
      'GET /v1/watchlist': () => jsonResponse(watchlistBody(rows.map((row) => watchItemOf(row)))),
      'GET /v1/portfolio': () =>
        jsonResponse(portfolioBody(rows.map((row) => holdingOf({ ...row, quantity: 1 })))),
    };
    for (const [path, name] of [
      ['/watchlist', 'Watchlist'],
      ['/portfolio', 'Portfolio'],
    ] as const) {
      stubAccountFetch(routes);
      visit(path);
      render(<App />);
      const list = await screen.findByRole('list', { name });
      expect(variantsOf(list)).toEqual(rows.map((row) => row.variant_key));
      const links = within(list).getAllByRole('link', { name: 'Charizard' });
      expect(new Set(links.map((link) => link.getAttribute('href'))).size).toBe(3);
      cleanup();
    }
  });
});
