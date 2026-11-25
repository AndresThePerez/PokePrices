// Edge cases of the home page's start: the example searches and the well-known card tiles in every
// state of the movers strip, the stale-prices banner and the session; the tile pictures through a
// failing image and a failing fallback; the example links' addresses; and the home page's exact
// request list, which the tiles and the examples must not grow.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import App from '../App';
import { matchRoute, navigate } from '../router';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  demoMe,
  fallingMover,
  freshnessBody,
  isMoversUrl,
  jsonResponse,
  moversBody,
  problemResponse,
  risingMover,
  searchPage,
  signedInMe,
  stubAccountFetch,
  stubFetch,
  upstreamUnavailableProblem,
} from '../test/fixtures';

const TILE_NAMES = [
  'Charizard Base',
  'Blastoise Base',
  'Pikachu Base',
  'Lugia Neo Genesis',
  'Charizard ex 151',
  'Umbreon ex Prismatic Evolutions',
];

const EXAMPLES = ['lugia neo genesis', 'umbreon vmax', 'umbreon ex', 'mewtwo'];

const HOME_REQUESTS = [
  '/v1/movers?direction=down&limit=5',
  '/v1/movers?direction=up&limit=5',
  '/v1/prices/freshness',
];

const BANNER = /Prices are out of date/;

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function follows(first: Node, second: Node): boolean {
  return (first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
}

function tileLists(): HTMLElement[] {
  return screen.queryAllByRole('list', { name: 'Well-known cards' });
}

function exampleLists(): HTMLElement[] {
  return screen.queryAllByRole('list', { name: 'Example searches' });
}

function moversRegion(): HTMLElement {
  return screen.getByRole('region', { name: 'Biggest moves vs previous close' });
}

async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

/** The examples and the six tiles, each once, in that order, above the movers strip. */
function expectStartAboveMovers(): void {
  const examples = exampleLists();
  const tiles = tileLists();
  expect(examples).toHaveLength(1);
  expect(tiles).toHaveLength(1);
  const exampleList = examples[0] as HTMLElement;
  const tileList = tiles[0] as HTMLElement;
  expect(
    within(exampleList)
      .getAllByRole('link')
      .map((link) => link.textContent),
  ).toEqual(EXAMPLES);
  expect(
    within(tileList)
      .getAllByRole('link')
      .map((link) => link.textContent),
  ).toEqual(TILE_NAMES);
  expect(follows(exampleList, tileList)).toBe(true);
  expect(follows(tileList, moversRegion())).toBe(true);
  const main = screen.getByRole('main');
  expect(main.contains(exampleList)).toBe(true);
  expect(main.contains(tileList)).toBe(true);
  for (const name of TILE_NAMES) {
    expect(screen.getAllByRole('link', { name })).toHaveLength(1);
  }
  expect(main.textContent).not.toMatch(/undefined|NaN|\[object/);
}

function moversAnswer(url: string): Response | Error {
  return url.includes('direction=down')
    ? jsonResponse(moversBody('down', [fallingMover]))
    : jsonResponse(moversBody('up', [risingMover]));
}

beforeEach(() => {
  window.localStorage.clear();
});

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

describe('the start of the home page in every state of the movers strip', () => {
  it('is there while the movers are loading', () => {
    vi.stubGlobal('fetch', () => new Promise<Response>(() => undefined));
    onTestFinished(() => {
      vi.unstubAllGlobals();
    });
    visit('/');
    render(<App />);
    expectStartAboveMovers();
    expect(screen.queryAllByRole('alert')).toHaveLength(0);
  });

  it.each([
    { state: 'answered', answer: moversAnswer },
    {
      state: 'empty',
      answer: (url: string) =>
        jsonResponse(moversBody(url.includes('direction=down') ? 'down' : 'up', [])),
    },
    { state: 'failed', answer: () => new Error('network down') },
    { state: 'a 503 problem', answer: () => problemResponse(upstreamUnavailableProblem) },
    { state: 'a wrong-shaped 200', answer: () => jsonResponse({ data: 'x' }) },
    {
      state: 'degraded',
      answer: (url: string) =>
        url.includes('direction=down')
          ? jsonResponse(moversBody('down', [fallingMover], { degraded: true }))
          : jsonResponse(moversBody('up', [risingMover], { degraded: true })),
    },
  ])('is there, once, above the movers when the movers are $state', async (row) => {
    const { answer } = row;
    stubFetch((url) => {
      if (url === '/v1/prices/freshness') return jsonResponse(freshnessBody());
      if (isMoversUrl(url)) return answer(url);
      return new Error('no request expected');
    });
    visit('/');
    render(<App />);
    await settle();
    expectStartAboveMovers();
    // A movers problem stays inside the movers strip: the start of the page carries none.
    for (const alert of screen.queryAllByRole('alert')) {
      expect(moversRegion().contains(alert)).toBe(true);
    }
    expect(document.body.textContent).not.toMatch(/undefined|NaN/);
  });

  it.each([
    { banner: true, body: freshnessBody() },
    { banner: false, body: freshnessBody({ within_slo: true, age_hours: 3.5 }) },
  ])('is there, once, with the stale-prices banner showing: $banner', async (row) => {
    const { banner, body } = row;
    stubFetch((url) => {
      if (url === '/v1/prices/freshness') return jsonResponse(body);
      if (isMoversUrl(url)) return moversAnswer(url);
      return new Error('no request expected');
    });
    visit('/');
    render(<App />);
    await settle();
    if (banner) {
      expect(await screen.findAllByText(BANNER)).toHaveLength(1);
    } else {
      expect(screen.queryByText(BANNER)).toBeNull();
    }
    expectStartAboveMovers();
    const text = screen.getByRole('main').textContent;
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
  });
});

describe('the home page in every session state', () => {
  it.each([
    { session: 'signed out', me: null },
    { session: 'signed in', me: signedInMe },
    { session: 'the read-only demo', me: demoMe },
  ])('keeps the start above the movers and adds no request when $session', async (row) => {
    const { me } = row;
    const calls = stubAccountFetch({
      'GET /v1/me':
        me === null
          ? problemResponse({
              type: 'https://pokeprices.andrestheperez.com/problems/unauthenticated',
              title: 'Unauthenticated',
              status: 401,
              instance: '/v1/me',
              request_id: 'req-edge-0001',
            })
          : jsonResponse(me),
      'GET /v1/movers?direction=up&limit=5': jsonResponse(moversBody('up', [risingMover])),
      'GET /v1/movers?direction=down&limit=5': jsonResponse(moversBody('down', [fallingMover])),
      'GET /v1/prices/freshness': jsonResponse(freshnessBody()),
    });
    // The account page asks who is signed in when the browser holds the cookie (a signed-out
    // browser holds none and is signed out at once); the header then knows the session on the home
    // page.
    visit('/account');
    render(<App />);
    await settle();
    expect(calls.map((call) => call.key).filter((key) => key === 'GET /v1/me')).toEqual(
      me === null ? [] : ['GET /v1/me'],
    );
    const before = calls.length;
    act(() => {
      navigate('/');
    });
    await settle();
    expectStartAboveMovers();
    // The header knows the session the row sets up.
    const signIn = within(screen.getByRole('banner')).queryByRole('link', { name: 'Sign in' });
    expect(signIn === null).toBe(me !== null);
    expect(screen.queryAllByText(/read-only demo account/)).toHaveLength(me === demoMe ? 1 : 0);
    const added = calls.slice(before).map((call) => call.url);
    for (const url of added) expect(HOME_REQUESTS).toContain(url);
    expect(added.filter((url) => url.startsWith('/v1/cards'))).toEqual([]);
    expect(added.filter(isMoversUrl).sort()).toEqual(HOME_REQUESTS.slice(0, 2));
    if (me !== null) {
      expect(screen.getByRole('main').innerHTML).not.toContain(me.data.email);
    }
  });

  it('sends exactly the movers and the freshness requests on a cold load, each once', async () => {
    const calls = stubFetch((url) => {
      if (url === '/v1/prices/freshness') return jsonResponse(freshnessBody());
      if (isMoversUrl(url)) return moversAnswer(url);
      return jsonResponse(searchPage);
    });
    visit('/');
    render(<App />);
    await settle();
    expect(calls.map((call) => call.url).sort()).toEqual(HOME_REQUESTS);
  });
});

describe('a tile picture', () => {
  it('swaps to the card back once on a repeated error and never loops back to the image', async () => {
    stubFetch(moversAnswer);
    visit('/');
    render(<App />);
    const link = screen.getByRole('link', { name: 'Charizard Base' });
    const img = link.querySelector('img');
    if (img === null) throw new Error('an image expected');
    fireEvent.error(img);
    fireEvent.error(img);
    await settle();
    expect(link.querySelector('img')).toBeNull();
    const backs = link.querySelectorAll('[data-initial]');
    expect(backs).toHaveLength(1);
    const back = backs[0] as HTMLElement;
    // The back draws no picture of its own, so it has nothing to fail on and cannot loop.
    expect(back.tagName).not.toBe('IMG');
    expect(back.querySelectorAll('img, image, picture, [src]')).toHaveLength(0);
    expect(back.getAttribute('style') ?? '').not.toMatch(/url\(/);
    await settle();
    expect(link.querySelectorAll('[data-initial]')).toHaveLength(1);
    expect(link.querySelector('img')).toBeNull();
    expect(link.textContent).toBe('Charizard Base');
  });

  it('fails each tile on its own: every picture failing leaves six card backs and six links', () => {
    stubFetch(moversAnswer);
    visit('/');
    render(<App />);
    const list = tileLists()[0] as HTMLElement;
    for (const img of Array.from(list.querySelectorAll('img'))) fireEvent.error(img);
    expect(list.querySelectorAll('img')).toHaveLength(0);
    expect(list.querySelectorAll('[data-initial]')).toHaveLength(6);
    expect(
      within(list)
        .getAllByRole('link')
        .map((link) => link.textContent),
    ).toEqual(TILE_NAMES);
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('is never announced: an empty alt, no role or label, and its name sits beside it once', () => {
    stubFetch(moversAnswer);
    visit('/');
    render(<App />);
    const list = tileLists()[0] as HTMLElement;
    expect(within(list).queryAllByRole('img')).toHaveLength(0);
    for (const img of Array.from(list.querySelectorAll('img'))) {
      expect(img.getAttribute('alt')).toBe('');
      expect(img.hasAttribute('aria-label')).toBe(false);
      expect(img.hasAttribute('title')).toBe(false);
      expect(img.hasAttribute('role')).toBe(false);
      expect(img.getAttribute('src')).toMatch(
        /^https:\/\/images\.pokemontcg\.io\/[a-z0-9]+\/\d+\.png$/,
      );
    }
  });

  it('shows no price, currency, change or date on any tile', async () => {
    stubFetch((url) => {
      if (url === '/v1/prices/freshness') return jsonResponse(freshnessBody());
      if (isMoversUrl(url)) return moversAnswer(url);
      return new Error('no request expected');
    });
    visit('/');
    render(<App />);
    await settle();
    const list = tileLists()[0] as HTMLElement;
    expect(list.textContent).not.toMatch(/[$€£%+−]|\d\.\d|as of|20\d\d-\d\d|price|close/i);
  });
});

describe('the tile links', () => {
  it('each open a card path the router matches, on the id the address encodes', () => {
    stubFetch(moversAnswer);
    visit('/');
    render(<App />);
    const list = tileLists()[0] as HTMLElement;
    const ids = within(list)
      .getAllByRole('link')
      .map((link) => {
        const href = link.getAttribute('href') ?? '';
        const route = matchRoute(href, '');
        expect(route.name).toBe('card');
        return route.name === 'card' ? route.id : null;
      });
    expect(ids).toEqual(['base1-4', 'base1-2', 'base1-58', 'neo1-9', 'sv3pt5-199', 'sv8pt5-161']);
  });
});

describe('the example searches', () => {
  it('each link to the search page with its words encoded, in the default order', () => {
    stubFetch(moversAnswer);
    visit('/');
    render(<App />);
    const list = exampleLists()[0] as HTMLElement;
    for (const link of within(list).getAllByRole('link')) {
      const href = link.getAttribute('href') ?? '';
      const url = new URL(href, 'https://pokeprices.andrestheperez.com');
      expect(url.origin).toBe('https://pokeprices.andrestheperez.com');
      expect(url.pathname).toBe('/search');
      expect(Array.from(url.searchParams.keys())).toEqual(['q']);
      expect(url.searchParams.get('q')).toBe(link.textContent);
      expect(href).not.toMatch(/[\s#&'"<>]|sort=|page=/);
      const words = (url.searchParams.get('q') ?? '').trim().split(/\s+/);
      expect(words.length).toBeGreaterThan(0);
      expect(words.length).toBeLessThanOrEqual(32);
      const route = matchRoute(url.pathname, url.search);
      expect(route.name).toBe('search');
      expect(route.name === 'search' ? route.query.get('sort') : 'x').toBeNull();
    }
  });

  it('opens the search page from the keyboard: a focusable link whose activation lands in place', async () => {
    const calls = stubFetch((url) =>
      isMoversUrl(url) ? moversAnswer(url) : jsonResponse(searchPage),
    );
    visit('/');
    render(<App />);
    const link = within(exampleLists()[0] as HTMLElement).getByRole('link', {
      name: 'lugia neo genesis',
    });
    expect(link.tagName).toBe('A');
    expect(link.hasAttribute('tabindex')).toBe(false);
    link.focus();
    expect(document.activeElement).toBe(link);
    // Enter on a focused link fires a click with no pointer detail.
    fireEvent.click(link, { detail: 0, button: 0 });
    expect(window.location.pathname + window.location.search).toBe('/search?q=lugia+neo+genesis');
    await waitFor(() => {
      expect(calls.map((call) => call.url)).toContain('/v1/cards?q=lugia+neo+genesis');
    });
    expect(calls.some((call) => call.url.includes('sort='))).toBe(false);
  });
});
