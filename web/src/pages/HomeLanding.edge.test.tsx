// D2-i1-T03 review (round 1a): edge cases of the home landing, the shared search form and the
// movers board bar: request counts, hostile queries, Back/Forward, the bar's identity and focus.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  clearXsrfCookie,
  demoMe,
  fallingMover,
  isMoversUrl,
  jsonResponse,
  moversBody,
  problemResponse,
  rawResponse,
  risingMover,
  searchPage,
  stubAccountFetch,
  stubFetch,
  watchlistBody,
  upstreamUnavailableProblem,
} from '../test/fixtures';

const STAMP_1012 = 'as of 2026-10-12 from TCGCSV (daily close)';
const SCOPE =
  'Ranked by change vs previous close among variants whose previous close was at least $2.00, that moved at least 5% and at least $1.00, had four different closes among their last five, and whose two closes each sat between half the lowest listing and 1.5 times the mid listing of their own day.';
const FOOTNOTE = 'TCGplayer market prices via TCGCSV, collected once a day.';
const DEGRADED = 'Catalog data may be out of date: PokéSearch is unavailable';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function goTo(path: string): void {
  act(() => {
    visit(path);
    window.dispatchEvent(new PopStateEvent('popstate'));
  });
}

function follows(first: Node, second: Node): boolean {
  return (first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

describe('home search form: requests and hostile text (D2-i1-T03 review)', () => {
  it.each([
    ['empty', ''],
    ['whitespace only', '   \t  '],
  ])('sends no catalog request for an %s submit from the home form', async (_name, text) => {
    const calls = stubFetch((url) =>
      isMoversUrl(url) ? answerMovers(url) : jsonResponse(searchPage),
    );
    visit('/');
    render(<App />);
    const box = await within(screen.getByRole('main')).findByRole('searchbox', {
      name: 'Card name',
    });
    await screen.findByRole('link', { name: 'See all movers' });
    fireEvent.change(box, { target: { value: text } });
    fireEvent.submit(box);
    expect(window.location.pathname + window.location.search).toBe('/search?q=');
    await waitFor(() => {
      expect(screen.queryByRole('link', { name: 'See all movers' })).toBeNull();
    });
    await new Promise((done) => setTimeout(done, 20));
    expect(calls.filter((call) => call.url.startsWith('/v1/cards'))).toEqual([]);
  });

  it.each([
    ['markup and entities', '<img src=x onerror=alert(1)> &amp; %41 + #frag?x=1'],
    ['a plus and a percent', 'a+b%2Bc%'],
    ['200 accented characters', 'é'.repeat(200)],
    ['right-to-left text', 'שלום עולם'],
  ])(
    'carries %s from the home form into one encoded request and back into the field as text',
    async (_name, hostile) => {
      const calls = stubFetch((url) =>
        isMoversUrl(url) ? answerMovers(url) : jsonResponse(searchPage),
      );
      visit('/');
      render(<App />);
      const box = await within(screen.getByRole('main')).findByRole('searchbox', {
        name: 'Card name',
      });
      fireEvent.change(box, { target: { value: `  ${hostile}  ` } });
      fireEvent.submit(box);
      expect(new URLSearchParams(window.location.search).get('q')).toBe(hostile);
      expect(window.location.hash).toBe('');
      await screen.findByRole('list', { name: 'Search results' });
      const cards = calls.filter((call) => call.url.startsWith('/v1/cards'));
      expect(cards).toHaveLength(1);
      expect(new URL(cards[0]?.url ?? '', 'http://example.test').searchParams.get('q')).toBe(
        hostile,
      );
      const field = screen.getByRole<HTMLInputElement>('searchbox', { name: 'Card name' });
      expect(field.value).toBe(hostile);
      expect(document.querySelector('main img[src="x"]')).toBeNull();
    },
  );

  it('keeps exactly one search landmark and one Search submit on the home page and on /search', async () => {
    stubFetch((url) => (isMoversUrl(url) ? answerMovers(url) : jsonResponse(searchPage)));
    visit('/');
    render(<App />);
    await screen.findByRole('link', { name: 'See all movers' });
    expect(screen.getAllByRole('search')).toHaveLength(1);
    expect(screen.getAllByRole('button', { name: 'Search' })).toHaveLength(1);
    expect(screen.getAllByRole('searchbox')).toHaveLength(1);
    goTo('/search?q=unown');
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.getAllByRole('search')).toHaveLength(1);
    expect(screen.getAllByRole('button', { name: 'Search' })).toHaveLength(1);
    expect(screen.getAllByRole('searchbox')).toHaveLength(1);
  });

  it('refills the /search field from the URL on Back and Forward, discarding unsent typing', async () => {
    stubFetch(() => jsonResponse(searchPage));
    visit('/search?q=alpha');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const field = (): HTMLInputElement =>
      screen.getByRole<HTMLInputElement>('searchbox', { name: 'Card name' });
    expect(field().value).toBe('alpha');
    fireEvent.change(field(), { target: { value: 'half typed' } });
    goTo('/search?q=beta');
    await waitFor(() => {
      expect(field().value).toBe('beta');
    });
    goTo('/search?q=alpha');
    await waitFor(() => {
      expect(field().value).toBe('alpha');
    });
  });

  it('says nothing live, real-time, today, now or 24h on the home page or the board', async () => {
    stubFetch(answerMovers);
    visit('/');
    render(<App />);
    await screen.findByText('Market price $26.34');
    const home = screen.getByRole('main').textContent;
    for (const word of FORBIDDEN_WORDS) expect(home).not.toMatch(word);
    const tiles = screen.getByRole('list', { name: 'Well-known cards' });
    expect(document.querySelectorAll('#root img, main img, main svg')).toHaveLength(
      tiles.querySelectorAll('img').length,
    );
    expect(document.querySelectorAll('main svg')).toHaveLength(0);
    goTo('/movers');
    await screen.findByText(STAMP_1012);
    const board = screen.getByRole('main').textContent;
    for (const word of FORBIDDEN_WORDS) expect(board).not.toMatch(word);
  });
});

describe('movers board bar (D2-i1-T03 review)', () => {
  function reply(url: string): Response {
    return url.includes('direction=down')
      ? jsonResponse(moversBody('down', [fallingMover]))
      : jsonResponse(moversBody('up', [risingMover]));
  }

  it('points Back to top at the element the skip link targets, and following it changes neither route nor requests', async () => {
    const calls = stubFetch(reply);
    visit('/movers?direction=down');
    render(<App />);
    await screen.findByText('Market price $65.11');
    const skip = screen.getByRole('link', { name: /skip/i });
    const back = within(screen.getByRole('main')).getByRole('link', { name: 'Back to top' });
    expect(back.getAttribute('href')).toBe(skip.getAttribute('href'));
    const target = document.getElementById((back.getAttribute('href') ?? '').slice(1));
    expect(target).not.toBeNull();
    expect(target?.contains(screen.getByRole('main'))).toBe(true);
    const before = calls.length;
    fireEvent.click(back);
    await new Promise((done) => setTimeout(done, 20));
    expect(window.location.pathname + window.location.search).toMatch(
      /^\/movers\?direction=down(#content)?$/,
    );
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Biggest moves');
    expect(screen.getByRole('button', { name: 'Falls' }).getAttribute('aria-pressed')).toBe('true');
    expect(calls).toHaveLength(before);
  });

  it('keeps one bar node and focus on Falls when the Falls request fails, then shows the alert after the bar', async () => {
    stubFetch((url) =>
      url.includes('direction=down')
        ? problemResponse(upstreamUnavailableProblem)
        : jsonResponse(moversBody('up', [risingMover])),
    );
    visit('/movers');
    render(<App />);
    await screen.findByText('Market price $26.34');
    const group = screen.getByRole('group', { name: 'Direction' });
    const bar = group.parentElement as HTMLElement;
    const falls = within(group).getByRole('button', { name: 'Falls' });
    falls.focus();
    fireEvent.click(falls);
    expect(document.activeElement).toBe(falls);
    const alert = await screen.findByRole('alert');
    expect(screen.getByRole('group', { name: 'Direction' }).parentElement).toBe(bar);
    expect(falls.isConnected).toBe(true);
    expect(document.activeElement).toBe(falls);
    expect(follows(bar, alert)).toBe(true);
    expect(bar.contains(alert)).toBe(false);
    expect(
      within(group)
        .getAllByRole('button')
        .filter((button) => button.getAttribute('aria-pressed') === 'true'),
    ).toHaveLength(1);
    expect(screen.queryByText(STAMP_1012)).toBeNull();
    expect(screen.queryByText(FOOTNOTE)).toBeNull();
    expect(screen.queryByRole('link', { name: 'Back to top' })).toBeNull();
  });

  it('asks each direction once across Rises, Falls, Rises and keeps the stamp unique throughout', async () => {
    const calls = stubFetch(reply);
    visit('/movers');
    render(<App />);
    await screen.findByText('Market price $26.34');
    fireEvent.click(screen.getByRole('button', { name: 'Falls' }));
    await screen.findByText('Market price $65.11');
    expect(screen.getAllByText(STAMP_1012)).toHaveLength(1);
    fireEvent.click(screen.getByRole('button', { name: 'Rises' }));
    await screen.findByText('Market price $26.34');
    expect(screen.getAllByText(STAMP_1012)).toHaveLength(1);
    expect(calls.map((call) => call.url)).toEqual([
      '/v1/prices/freshness',
      '/v1/movers?direction=up&limit=50',
      '/v1/movers?direction=down&limit=50',
    ]);
  });

  it('orders degraded notice, scope, bar with the one stamp, rows, footnote, Back to top on a degraded board', async () => {
    stubFetch(() => jsonResponse(moversBody('up', [risingMover], { degraded: true })));
    visit('/movers');
    render(<App />);
    const rows = await screen.findByRole('list', { name: 'Rises' });
    const degraded = screen.getByText(DEGRADED);
    const scope = screen.getByText(SCOPE);
    const bar = screen.getByRole('group', { name: 'Direction' }).parentElement as HTMLElement;
    const stamp = within(bar).getByText(STAMP_1012);
    const footnote = screen.getByText(FOOTNOTE);
    const back = screen.getByRole('link', { name: 'Back to top' });
    expect(follows(screen.getByRole('heading', { level: 1 }), degraded)).toBe(true);
    expect(follows(degraded, scope)).toBe(true);
    expect(follows(scope, stamp)).toBe(true);
    expect(follows(stamp, rows)).toBe(true);
    expect(follows(rows, footnote)).toBe(true);
    expect(follows(footnote, back)).toBe(true);
    expect(screen.getAllByText(STAMP_1012)).toHaveLength(1);
  });

  it('keeps the stamp in the bar but drops footnote, list and Back to top on a day with no falls', async () => {
    stubFetch((url) =>
      url.includes('direction=down')
        ? jsonResponse(moversBody('down', []))
        : jsonResponse(moversBody('up', [risingMover])),
    );
    visit('/movers?direction=down');
    render(<App />);
    await screen.findByText('No falls ranked for 2026-10-12');
    const bar = screen.getByRole('group', { name: 'Direction' }).parentElement as HTMLElement;
    expect(within(bar).getAllByText(STAMP_1012)).toHaveLength(1);
    expect(screen.getAllByText(STAMP_1012)).toHaveLength(1);
    expect(within(screen.getByRole('main')).queryByRole('list')).toBeNull();
    expect(screen.queryByText(FOOTNOTE)).toBeNull();
    expect(screen.queryByRole('link', { name: 'Back to top' })).toBeNull();
  });
});

describe('demo entry on the home page (D2-i1-T03 review)', () => {
  it('starts the demo from the home placement in the unchanged order, once, and opens /watchlist', async () => {
    const calls = stubAccountFetch({
      'GET /v1/movers?direction=up&limit=5': jsonResponse(moversBody('up', [risingMover])),
      'GET /v1/movers?direction=down&limit=5': jsonResponse(moversBody('down', [fallingMover])),
      'GET /v1/me': jsonResponse(demoMe),
      'POST /v1/demo/session': rawResponse(204, null),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    visit('/');
    render(<App />);
    await screen.findByRole('link', { name: 'See all movers' });
    expect(calls.map((call) => call.key).filter((key) => !key.includes('/v1/movers'))).toEqual([
      'GET /v1/prices/freshness',
    ]);
    const button = within(screen.getByRole('main')).getByRole('button', {
      name: 'Try the read-only demo',
    });
    fireEvent.click(button);
    fireEvent.click(button);
    await waitFor(() => {
      expect(window.location.pathname).toBe('/watchlist');
    });
    const keys = calls.map((call) => call.key).filter((key) => !key.includes('/v1/movers'));
    // The home page reads no session, so the first request after the click is the CSRF cookie.
    expect(keys.slice(0, 4)).toEqual([
      'GET /v1/prices/freshness',
      'GET /sanctum/csrf-cookie',
      'POST /v1/demo/session',
      'GET /v1/me',
    ]);
    expect(keys.filter((key) => key === 'POST /v1/demo/session')).toHaveLength(1);
  });
});

describe('landing and board under slow, failing and hash-bearing conditions (D2-i1-T03 review)', () => {
  const STAMP_1011 = 'as of 2026-10-11 from TCGCSV (daily close)';

  it('shows no Rises stamp, rows or footnote while the Falls answer is in flight, then only the Falls day', async () => {
    let release: (answer: Response) => void = () => undefined;
    const slowFalls = new Promise<Response>((done) => {
      release = done;
    });
    vi.stubGlobal('fetch', (input: string | URL): Promise<Response> => {
      const url = String(input);
      return url.includes('direction=down')
        ? slowFalls
        : Promise.resolve(jsonResponse(moversBody('up', [risingMover])));
    });
    onTestFinished(() => {
      vi.unstubAllGlobals();
    });
    visit('/movers');
    render(<App />);
    await screen.findByText('Market price $26.34');
    expect(screen.getAllByText(STAMP_1012)).toHaveLength(1);
    fireEvent.click(screen.getByRole('button', { name: 'Falls' }));
    await screen.findByRole('status');
    expect(screen.queryByText(STAMP_1012)).toBeNull();
    expect(screen.queryByText('Market price $26.34')).toBeNull();
    expect(screen.queryByText(FOOTNOTE)).toBeNull();
    expect(screen.queryByRole('link', { name: 'Back to top' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Falls' }).getAttribute('aria-pressed')).toBe('true');
    act(() => {
      release(jsonResponse(moversBody('down', [fallingMover], { day: '2026-10-11' })));
    });
    await screen.findByText('Market price $65.11');
    expect(screen.getAllByText(/^as of .* from TCGCSV \(daily close\)$/)).toHaveLength(1);
    expect(screen.getByText(STAMP_1011)).toBeTruthy();
    expect(screen.queryByText(STAMP_1012)).toBeNull();
  });

  it('keeps the same board, direction and request list when the URL gains the #content fragment', async () => {
    const calls = stubFetch((url) =>
      url.includes('direction=down')
        ? jsonResponse(moversBody('down', [fallingMover]))
        : jsonResponse(moversBody('up', [risingMover])),
    );
    visit('/movers?direction=down');
    render(<App />);
    await screen.findByText('Market price $65.11');
    const rows = screen.getByRole('list', { name: 'Falls' });
    const before = calls.length;
    goTo('/movers?direction=down#content');
    await new Promise((done) => setTimeout(done, 20));
    expect(screen.getByRole('list', { name: 'Falls' })).toBe(rows);
    expect(screen.getByRole('button', { name: 'Falls' }).getAttribute('aria-pressed')).toBe('true');
    expect(screen.getAllByText(STAMP_1012)).toHaveLength(1);
    expect(calls).toHaveLength(before);
    expect(calls.map((call) => call.url)).toEqual([
      '/v1/prices/freshness',
      '/v1/movers?direction=down&limit=50',
    ]);
  });

  it('keeps the search form and the demo button working when both home movers requests fail', async () => {
    const calls = stubFetch((url) =>
      isMoversUrl(url) ? problemResponse(upstreamUnavailableProblem) : jsonResponse(searchPage),
    );
    visit('/');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(screen.getAllByRole('alert')).toHaveLength(1);
    const main = within(screen.getByRole('main'));
    expect(main.getByRole('heading', { level: 1 }).textContent).toBe(
      'Pokémon card prices at the daily close',
    );
    expect(main.getByRole('button', { name: 'Try the read-only demo' })).toBeTruthy();
    expect(screen.queryByText(FOOTNOTE)).toBeNull();
    expect(screen.queryByRole('link', { name: 'See all movers' })).toBeNull();
    expect(follows(main.getByRole('search'), alert)).toBe(true);
    const box = main.getByRole('searchbox', { name: 'Card name' });
    fireEvent.change(box, { target: { value: ' unown ' } });
    fireEvent.submit(box);
    expect(window.location.pathname + window.location.search).toBe('/search?q=unown');
    await screen.findByRole('list', { name: 'Search results' });
    expect(calls.filter((call) => call.url.startsWith('/v1/cards'))).toHaveLength(1);
  });
});
