// Edge cases of the About page and the footer's site links: the address variants of /about and the
// title each gives and gives back; focus on Back and Forward; the footer once on every route in
// every session state, its outside links safe; no email address or mailto anywhere a visitor sees
// without signing in; the About page's request list; and its wording against the forbidden words.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  clearXsrfCookie,
  demoMe,
  freshnessBody,
  isMoversUrl,
  jsonResponse,
  problemResponse,
  signedInMe,
  stubAccountFetch,
  stubFetch,
} from '../test/fixtures';

const SITE_LINKS = [
  ['About', '/about'],
  ['Source', 'https://github.com/AndresThePerez/PokePrices'],
  ['Built by Andres Perez', 'https://andrestheperez.com'],
  ['Contact', 'https://andrestheperez.com'],
];

const HOME_TITLE = 'PokéPrices · Pokémon card prices at the daily close';
const ABOUT_TITLE = 'About · PokéPrices';
const NOT_FOUND_TITLE = 'Page not found · PokéPrices';

// Built from parts so no scanner reads the pattern's own source as an address.
const AT = String.fromCharCode(64);
const EMAIL_SHAPE = new RegExp(`[A-Za-z0-9._%+-]+${AT}[A-Za-z0-9-]+(\\.[A-Za-z0-9-]+)+`);

const UNAUTHENTICATED = {
  type: 'https://pokeprices.andrestheperez.com/problems/unauthenticated',
  title: 'Unauthenticated',
  status: 401,
  instance: '/v1/me',
  request_id: 'req-edge-0002',
};

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function h1(): string | null {
  return screen.getByRole('heading', { level: 1 }).textContent;
}

function siteLinksOf(): string[][] {
  const footers = screen.getAllByRole('contentinfo');
  expect(footers).toHaveLength(1);
  const navs = within(footers[0] as HTMLElement).getAllByRole('navigation', { name: 'Site' });
  expect(navs).toHaveLength(1);
  return within(navs[0] as HTMLElement)
    .getAllByRole('link')
    .map((link) => [link.textContent, link.getAttribute('href') ?? '']);
}

async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

/** An outside link either stays in the tab or opens a new one with noopener; never a script. */
function expectSafeOutsideLink(link: HTMLAnchorElement): void {
  const href = link.getAttribute('href') ?? '';
  expect(href).toMatch(/^https:\/\/[a-z0-9.-]+(\/|$)/i);
  expect(href).not.toMatch(/^(javascript|data|vbscript|mailto):|^\/\//i);
  const target = link.getAttribute('target');
  if (target !== null && target !== '_self') {
    expect((link.getAttribute('rel') ?? '').split(/\s+/)).toContain('noopener');
  }
}

beforeEach(() => {
  window.localStorage.clear();
});

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

describe('the addresses around /about', () => {
  it.each([
    { path: '/about', heading: 'About', title: ABOUT_TITLE },
    { path: '/about?x=1', heading: 'About', title: ABOUT_TITLE },
    { path: '/about#privacy', heading: 'About', title: ABOUT_TITLE },
    { path: '/about?x=1#privacy', heading: 'About', title: ABOUT_TITLE },
    { path: '/about/', heading: 'Page not found', title: NOT_FOUND_TITLE },
    { path: '/About', heading: 'Page not found', title: NOT_FOUND_TITLE },
    { path: '/ABOUT', heading: 'Page not found', title: NOT_FOUND_TITLE },
    { path: '/aboutx', heading: 'Page not found', title: NOT_FOUND_TITLE },
    { path: '/aboutus', heading: 'Page not found', title: NOT_FOUND_TITLE },
    { path: '/about/x', heading: 'Page not found', title: NOT_FOUND_TITLE },
    { path: '/about/extra', heading: 'Page not found', title: NOT_FOUND_TITLE },
    { path: '/about%20', heading: 'Page not found', title: NOT_FOUND_TITLE },
  ])('$path opens "$heading" titled "$title", and leaving it gives the home title back', (row) => {
    const { path, heading, title } = row;
    stubFetch((url) => (isMoversUrl(url) ? answerMovers(url) : new Error('no answer')));
    visit(path);
    render(<App />);
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(h1()).toBe(heading);
    expect(document.title).toBe(title);
    act(() => {
      navigate('/');
    });
    expect(document.title).toBe(HOME_TITLE);
    expect(siteLinksOf()).toEqual(SITE_LINKS);
  });

  it('moves focus to the h1 on each step, Back and Forward included, between home, About and a card', async () => {
    stubFetch((url) => (isMoversUrl(url) ? answerMovers(url) : new Error('no answer')));
    visit('/');
    render(<App />);
    fireEvent.click(within(screen.getByRole('contentinfo')).getByRole('link', { name: 'About' }));
    expect(window.location.pathname).toBe('/about');
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1 }));
    expect(document.title).toBe(ABOUT_TITLE);
    act(() => {
      navigate('/card/base1-4');
    });
    await waitFor(() => {
      expect(document.activeElement?.tagName).toBe('H1');
    });
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(h1()).toBe('About');
    });
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1 }));
    expect(document.title).toBe(ABOUT_TITLE);
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    await waitFor(() => {
      expect(document.title).toBe(HOME_TITLE);
    });
    act(() => {
      window.history.forward();
    });
    await waitFor(() => {
      expect(h1()).toBe('About');
    });
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1 }));
  });
});

describe('the footer on every route', () => {
  it.each([
    { path: '/', settings: true },
    { path: '/search?q=x', settings: true },
    { path: '/card/base1-4', settings: true },
    { path: '/movers', settings: true },
    { path: '/movers?direction=down', settings: true },
    { path: '/sign-in', settings: true },
    { path: '/sign-up', settings: true },
    { path: '/password/forgot', settings: true },
    { path: '/password/reset?token=t', settings: true },
    { path: '/account', settings: false },
    { path: '/account/verify-email', settings: true },
    { path: '/watchlist', settings: true },
    { path: '/portfolio', settings: true },
    { path: '/alerts', settings: true },
    { path: '/about', settings: true },
    { path: '/no-such-page', settings: true },
  ])(
    'shows the four links once on $path, settings $settings, in every session state',
    async (row) => {
      const { path, settings } = row;
      for (const me of ['loading', null, signedInMe, demoMe] as const) {
        if (me === 'loading') {
          vi.stubGlobal('fetch', () => new Promise<Response>(() => undefined));
          onTestFinished(() => {
            vi.unstubAllGlobals();
          });
        } else {
          stubAccountFetch({
            'GET /v1/me': me === null ? problemResponse(UNAUTHENTICATED) : jsonResponse(me),
          });
        }
        visit(path);
        const { unmount } = render(<App />);
        await settle();
        expect(siteLinksOf()).toEqual(SITE_LINKS);
        const footer = screen.getByRole('contentinfo');
        expect(within(footer).queryAllByRole('combobox')).toHaveLength(settings ? 2 : 0);
        expect(screen.getAllByRole('link', { name: 'Contact' })).toHaveLength(1);
        expect(screen.getAllByRole('link', { name: 'Source' })).toHaveLength(1);
        for (const link of Array.from(footer.querySelectorAll('a'))) {
          if ((link.getAttribute('href') ?? '').startsWith('/')) continue;
          expectSafeOutsideLink(link);
        }
        unmount();
        clearXsrfCookie();
      }
    },
  );
});

describe('outside links on the About page', () => {
  it('are https addresses that stay in the tab or open a new one with noopener, never a script', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    // In-app paths and the skip link's fragment stay on the page; everything else leaves the site.
    const outside = Array.from(document.querySelectorAll('a')).filter((link) => {
      const href = link.getAttribute('href') ?? '';
      return !href.startsWith('/') && !href.startsWith('#');
    });
    expect(outside.map((link) => link.getAttribute('href')).sort()).toEqual([
      'https://andrestheperez.com',
      'https://andrestheperez.com',
      'https://andrestheperez.com',
      'https://andrestheperez.com',
      'https://github.com/AndresThePerez/PokePrices',
      'https://github.com/AndresThePerez/PokePrices',
      'https://tcgcsv.com',
    ]);
    for (const link of outside) expectSafeOutsideLink(link);
  });
});

describe('no email address anywhere a visitor sees without signing in', () => {
  it.each([
    '/',
    '/about',
    '/search?q=x',
    '/movers',
    '/sign-in',
    '/sign-up',
    '/password/forgot',
    '/account',
    '/account/verify-email',
    '/watchlist',
    '/portfolio',
    '/alerts',
    '/no-such-page',
  ])('%s carries no mailto and nothing shaped like an email address', async (path) => {
    stubAccountFetch({
      'GET /v1/me': problemResponse(UNAUTHENTICATED),
      'GET /v1/prices/freshness': jsonResponse(freshnessBody()),
    });
    visit(path);
    render(<App />);
    await settle();
    const html = document.body.innerHTML;
    expect(html).not.toMatch(/mailto:/i);
    expect(document.body.textContent).not.toMatch(EMAIL_SHAPE);
    for (const element of Array.from(document.body.querySelectorAll('*'))) {
      for (const attribute of Array.from(element.attributes)) {
        expect({ attribute: attribute.name, email: EMAIL_SHAPE.test(attribute.value) }).toEqual({
          attribute: attribute.name,
          email: false,
        });
      }
    }
  });
});

describe('the About page requests', () => {
  it.each([
    { session: 'signed out', me: null },
    { session: 'signed in', me: signedInMe },
    { session: 'the read-only demo', me: demoMe },
  ])('adds no request, freshness included, when $session', async (row) => {
    const { me } = row;
    const calls = stubAccountFetch({
      'GET /v1/me': me === null ? problemResponse(UNAUTHENTICATED) : jsonResponse(me),
    });
    // The account page makes the session known first, as a visitor coming back would have it.
    visit('/account');
    render(<App />);
    await settle();
    const before = calls.length;
    act(() => {
      navigate('/about');
    });
    await settle();
    expect(h1()).toBe('About');
    expect(calls.slice(before).map((call) => call.key)).toEqual([]);
    expect(screen.queryByText(/Prices are out of date/)).toBeNull();
    if (me !== null) expect(screen.getByRole('main').innerHTML).not.toContain(me.data.email);
  });

  it('sends nothing on a cold load', async () => {
    const calls = stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    await settle();
    expect(calls).toEqual([]);
  });
});

describe('the wording of the About page and the footer', () => {
  it('passes the forbidden-words list, says daily close and its source, and writes PokéPrices with the é', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(demoMe) });
    visit('/about');
    render(<App />);
    await settle();
    const main = screen.getByRole('main').textContent;
    const footer = screen.getByRole('contentinfo').textContent;
    for (const text of [main, footer, document.title]) {
      for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
      expect(text).not.toMatch(/instant|up-to-the-minute|streaming|minute by minute|every minute/i);
      expect(text).not.toMatch(/Pok[eE]Prices|Pokemon|Poké/);
    }
    expect(main).toMatch(/daily close/);
    expect(main).toMatch(/TCGplayer/);
    expect(main).toMatch(/TCGCSV/);
    expect(main).toMatch(/not a running quote/);
    expect(main.match(/PokéPrices/g)?.length ?? 0).toBeGreaterThanOrEqual(1);
  });

  it('keeps the home subtitle free of the forbidden words and the misspelt name, with the banner both ways', async () => {
    for (const body of [freshnessBody(), freshnessBody({ within_slo: true, age_hours: 2 })]) {
      stubFetch((url) => {
        if (url === '/v1/prices/freshness') return jsonResponse(body);
        if (isMoversUrl(url)) return answerMovers(url);
        return new Error('no request expected');
      });
      visit('/');
      const { unmount } = render(<App />);
      await settle();
      const heading = screen.getByRole('heading', { level: 1 });
      const subtitle = heading.nextElementSibling?.textContent ?? '';
      expect(subtitle).not.toBe('');
      for (const pattern of FORBIDDEN_WORDS) expect(subtitle).not.toMatch(pattern);
      expect(subtitle).not.toMatch(/instant|streaming|minute|Pok[eE]Prices|Pokemon/i);
      expect(subtitle).toMatch(/once a day/);
      for (const pattern of FORBIDDEN_WORDS) {
        expect(screen.getByRole('contentinfo').textContent).not.toMatch(pattern);
      }
      unmount();
    }
  });
});
