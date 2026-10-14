import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from './App';
import { cardPath, matchRoute, navigate } from './router';
import { safeNext } from './router';
import { answerMovers, stubFetch } from './test/fixtures';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function heading(): string | null {
  return screen.getByRole('heading', { level: 1 }).textContent;
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('matchRoute', () => {
  it('matches the home, search and card routes', () => {
    expect(matchRoute('/', '')).toEqual({ name: 'home' });
    expect(matchRoute('/card/base1-4', '')).toEqual({ name: 'card', id: 'base1-4' });
    const search = matchRoute('/search', '?q=unown&page=2');
    expect(search.name).toBe('search');
    expect(search.name === 'search' ? search.query.get('q') : null).toBe('unown');
    expect(search.name === 'search' ? search.query.get('page') : null).toBe('2');
  });

  it('decodes a percent-encoded card id exactly once', () => {
    expect(matchRoute('/card/ex10-%3F', '')).toEqual({ name: 'card', id: 'ex10-?' });
    expect(matchRoute('/card/ex10-!', '')).toEqual({ name: 'card', id: 'ex10-!' });
    expect(matchRoute('/card/a%2Fb', '')).toEqual({ name: 'card', id: 'a/b' });
    expect(matchRoute('/card/100%2525', '')).toEqual({ name: 'card', id: '100%25' });
  });

  it('sends every other path, and a malformed escape, to not-found', () => {
    for (const path of [
      '/card',
      '/card/',
      '/card/a/b',
      '/cards/base1-4',
      '/search/',
      '/search/extra',
      '/v1/cards',
      '/card/%E0%A4%A',
    ]) {
      expect(matchRoute(path, '')).toEqual({ name: 'not-found' });
    }
  });
});

describe('the movers route (T09 criterion 1)', () => {
  it('matches /movers with the direction up unless the query asks for down', () => {
    expect(matchRoute('/movers', '')).toEqual({ name: 'movers', direction: 'up' });
    expect(matchRoute('/movers', '?direction=down')).toEqual({ name: 'movers', direction: 'down' });
    expect(matchRoute('/movers', '?direction=up')).toEqual({ name: 'movers', direction: 'up' });
    for (const search of ['?direction=DOWN', '?direction=sideways', '?direction=', '?limit=5']) {
      expect(matchRoute('/movers', search)).toEqual({ name: 'movers', direction: 'up' });
    }
  });

  it('keeps /movers/ and /movers/x not-found', () => {
    for (const path of ['/movers/', '/movers/x', '/movers/down']) {
      expect(matchRoute(path, '')).toEqual({ name: 'not-found' });
    }
  });

  it('renders the board at /movers', () => {
    stubFetch(answerMovers);
    visit('/movers?direction=down');
    render(<App />);
    expect(heading()).toBe('Biggest moves');
  });
});

describe('the account routes (P4 T03 criterion 2, D10)', () => {
  it('maps each account path to its route', () => {
    expect(matchRoute('/sign-in', '')).toEqual({ name: 'sign-in', next: '/account' });
    expect(matchRoute('/sign-up', '')).toEqual({ name: 'sign-up' });
    expect(matchRoute('/password/forgot', '')).toEqual({ name: 'forgot-password' });
    expect(matchRoute('/password/reset', '?token=fake-reset-0000&email=a%40example.com')).toEqual({
      name: 'reset-password',
      token: 'fake-reset-0000',
      email: 'a@example.com',
    });
    expect(matchRoute('/password/reset', '')).toEqual({
      name: 'reset-password',
      token: null,
      email: null,
    });
    expect(matchRoute('/account', '')).toEqual({ name: 'account' });
    expect(matchRoute('/account/verify-email', '?link=%2Femail%2Fverify%2F7%2Fab%3Fx%3D1')).toEqual(
      { name: 'verify-email', link: '/email/verify/7/ab?x=1' },
    );
    expect(matchRoute('/account/verify-email', '')).toEqual({ name: 'verify-email', link: null });
  });

  it('carries a valid next into the sign-in route', () => {
    expect(matchRoute('/sign-in', `?next=${encodeURIComponent('/card/ex10-%3F')}`)).toEqual({
      name: 'sign-in',
      next: '/card/ex10-%3F',
    });
    expect(matchRoute('/sign-in', '?next=%2F%2Fevil.example')).toEqual({
      name: 'sign-in',
      next: '/account',
    });
  });

  it('keeps trailing-slash and extra-segment variants not-found', () => {
    for (const path of [
      '/sign-in/',
      '/sign-up/',
      '/account/',
      '/account/x',
      '/account/verify-email/',
      '/password',
      '/password/',
      '/password/forgot/',
      '/password/forgot/x',
      '/password/reset/',
      '/password/reset/x',
    ]) {
      expect(matchRoute(path, '')).toEqual({ name: 'not-found' });
    }
  });

  it.each([
    '/movers',
    '/search?q=unown',
    '/card/ex10-%3F',
    `/account/verify-email?link=${encodeURIComponent('/email/verify/7/ab?expires=1&signature=0')}`,
    '/account',
  ])('accepts next %j', (next) => {
    expect(safeNext(next)).toBe(next);
  });

  it.each([
    '//evil.example',
    '/\\evil.example',
    'https://evil.example',
    'javascript:alert(1)',
    '/nowhere',
    '',
    'account',
  ])('refuses next %j and lands on /account', (next) => {
    expect(safeNext(next)).toBe('/account');
  });

  it('refuses a missing next', () => {
    expect(safeNext(null)).toBe('/account');
  });
});

describe('the watchlist and portfolio routes (P4 T07, D10)', () => {
  it('maps /watchlist and /portfolio to their routes', () => {
    expect(matchRoute('/watchlist', '')).toEqual({ name: 'watchlist' });
    expect(matchRoute('/portfolio', '')).toEqual({ name: 'portfolio' });
    expect(matchRoute('/watchlist', '?x=1')).toEqual({ name: 'watchlist' });
  });

  it('keeps trailing-slash and extra-segment variants not-found', () => {
    for (const path of [
      '/watchlist/',
      '/watchlist/x',
      '/portfolio/',
      '/portfolio/base1-4',
      '/Watchlist',
      '/PORTFOLIO',
    ]) {
      expect(matchRoute(path, '')).toEqual({ name: 'not-found' });
    }
  });

  it.each(['/watchlist', '/portfolio'])('accepts next %j now that the route exists', (next) => {
    expect(safeNext(next)).toBe(next);
    expect(matchRoute('/sign-in', `?next=${encodeURIComponent(next)}`)).toEqual({
      name: 'sign-in',
      next,
    });
  });

  it.each(['/watchlist/', '//watchlist', '/portfolio/x'])('refuses next %j', (next) => {
    expect(safeNext(next)).toBe('/account');
  });
});

describe('cardPath', () => {
  it('encodes the id into one path segment that matchRoute reads back unchanged', () => {
    expect(cardPath('ex10-?')).toBe('/card/ex10-%3F');
    expect(cardPath('a/b')).toBe('/card/a%2Fb');
    for (const id of ['ex10-?', 'ex10-!', 'a/b', '100%', 'base1-4']) {
      expect(matchRoute(cardPath(id), '')).toEqual({ name: 'card', id });
    }
  });
});

describe('App routes', () => {
  it('keeps the daily-close hero at /', () => {
    render(<App />);
    expect(heading()).toBe('PokéPrices');
    expect(screen.getByRole('main').textContent).toContain('daily close');
  });

  it('renders the search route at /search', () => {
    visit('/search?q=unown');
    render(<App />);
    expect(heading()).toBe('Search cards');
  });

  it('renders the card route with the decoded id', () => {
    visit('/card/ex10-%3F');
    render(<App />);
    expect(heading()).toBe('Card ex10-?');
  });

  it('renders not-found for an unknown path, with a link home that navigates in place', () => {
    stubFetch(answerMovers);
    visit('/no-such-page');
    render(<App />);
    expect(heading()).toBe('Page not found');
    expect(screen.getByRole('main').textContent).toContain('/no-such-page');
    const link = screen.getByRole('link', { name: 'Go to the home page' });
    expect(link.getAttribute('href')).toBe('/');
    expect(fireEvent.click(link)).toBe(false);
    expect(window.location.pathname).toBe('/');
    expect(heading()).toBe('PokéPrices');
  });

  it('leaves a modified click to the browser', () => {
    visit('/no-such-page');
    render(<App />);
    const seen: boolean[] = [];
    const record = (event: MouseEvent): void => {
      seen.push(event.defaultPrevented);
      event.preventDefault();
    };
    window.addEventListener('click', record);
    fireEvent.click(screen.getByRole('link', { name: 'Go to the home page' }), { ctrlKey: true });
    window.removeEventListener('click', record);
    expect(seen).toEqual([false]);
    expect(window.location.pathname).toBe('/no-such-page');
    expect(heading()).toBe('Page not found');
  });

  it('follows navigate() and the browser history', () => {
    render(<App />);
    act(() => {
      navigate(cardPath('ex10-!'));
    });
    expect(window.location.pathname).toBe('/card/ex10-!');
    expect(heading()).toBe('Card ex10-!');
    act(() => {
      visit('/search');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(heading()).toBe('Search cards');
  });
});

describe('the alerts route (P5 T04, D10)', () => {
  it('maps /alerts to its route, with or without a query', () => {
    expect(matchRoute('/alerts', '')).toEqual({ name: 'alerts' });
    expect(matchRoute('/alerts', '?x=1')).toEqual({ name: 'alerts' });
  });

  it('keeps trailing-slash, extra-segment and other-case variants not-found', () => {
    for (const path of ['/alerts/', '/alerts/x', '/Alerts', '/alert', '/webhooks']) {
      expect(matchRoute(path, '')).toEqual({ name: 'not-found' });
    }
  });

  it('accepts next=/alerts, so sign-in brings the visitor back', () => {
    expect(safeNext('/alerts')).toBe('/alerts');
    expect(matchRoute('/sign-in', '?next=%2Falerts')).toEqual({ name: 'sign-in', next: '/alerts' });
  });

  it.each(['/alerts/', '//alerts', '/alerts/x'])('refuses next %j', (next) => {
    expect(safeNext(next)).toBe('/account');
  });
});
