// The home page's "See it working" section: the demo's portfolio total and its change on the day,
// three watched cards and one alert sample, read from the three public demo routes (no cookie, no
// session), each part linking to its page. Its space is reserved from the first frame; when any
// read fails the section is absent and the rest of the page is what it was without it.
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  demoAlertsRoutes,
  demoAlertsUnavailableRoutes,
  demoSampleRoutes,
  demoSampleUnavailableRoutes,
  demoSamplesBody,
  emptyPortfolioBody,
  jsonResponse,
  moversBody,
  portfolioBody,
  problemResponse,
  risingMover,
  fallingMover,
  signedInMe,
  stubAccountFetch,
  watchItemOf,
  watchlistBody,
  type AccountAnswer,
} from '../test/fixtures';
import { SEE_IT_RESERVE } from './SeeItWorking';

const WATCHLIST = 'GET /v1/demo/watchlist';
const PORTFOLIO = 'GET /v1/demo/portfolio';
const ALERTS = 'GET /v1/demo/alerts';
const PUBLIC_READS = [ALERTS, PORTFOLIO, WATCHLIST];
const HEADING = 'See it working';
const SAMPLE_WORDS = 'Sample data from the read-only demo collection, the same for every visitor.';

const MOVERS: Record<string, AccountAnswer> = {
  'GET /v1/movers?direction=up&limit=5': jsonResponse(moversBody('up', [risingMover])),
  'GET /v1/movers?direction=down&limit=5': jsonResponse(moversBody('down', [fallingMover])),
};

/** Five watched cards, all closing on 2026-10-12: the section shows the first three. */
const FIVE = ['Charizard', 'Blastoise', 'Pikachu', 'Mewtwo', 'Lugia'].map((name, index) =>
  watchItemOf({
    variant_key: `sample${String(index)}:holo:unlimited:-`,
    card_id: `sample${String(index)}`,
    card: { name, set_name: 'Base', number: String(index + 1) },
  }),
);

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

function sampleRoutes(): Record<string, AccountAnswer> {
  return {
    ...MOVERS,
    ...demoSampleRoutes(watchlistBody(FIVE), portfolioBody()),
    ...demoAlertsRoutes(),
  };
}

function open(routes: Readonly<Record<string, AccountAnswer>>) {
  const calls = stubAccountFetch(routes);
  visit('/');
  render(<App />);
  return calls;
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

function section(): HTMLElement {
  return within(main()).getByRole('region', { name: HEADING });
}

/** The page's main with the ids React made up for this render taken out, so renders compare. */
function shape(): string {
  return main().innerHTML.replace(/_r_[0-9a-z]+_/g, 'ID');
}

describe('See it working: the three public reads', () => {
  it('signed out with no cookie asks the three demo routes and no account route, and shows the parts', async () => {
    const calls = open(sampleRoutes());
    const region = await waitFor(section);
    const keys = calls.map((call) => call.key);
    for (const read of PUBLIC_READS) expect(keys.filter((key) => key === read)).toHaveLength(1);
    for (const key of keys) {
      expect(key).not.toMatch(/^GET \/v1\/(me|watchlist|portfolio|webhooks|alerts)/);
      expect(key).not.toMatch(/sanctum|session|token/);
    }
    expect(document.cookie).toBe('');
    expect(region.querySelector('h2')?.textContent).toBe(HEADING);
    expect(within(region).getByText('Total value $1,047.93')).toBeTruthy();
    expect(within(region).getByText('▲ +$16.93 (+1.64%) vs previous close.')).toBeTruthy();
    expect(
      within(region).getByText('Closes from 2026-10-11 to 2026-10-12 from TCGCSV (daily close)'),
    ).toBeTruthy();
    const cards = within(within(region).getByRole('list', { name: 'Sample watchlist' }));
    expect(cards.getAllByRole('listitem')).toHaveLength(3);
    expect(cards.getAllByRole('link').map((link) => link.textContent)).toEqual([
      'Charizard',
      'Blastoise',
      'Pikachu',
    ]);
    // The list's one visible stamp (each row also names its day for assistive technology only).
    const stamps = within(region)
      .getAllByText('as of 2026-10-12 from TCGCSV (daily close)')
      .filter((element) => !element.classList.contains('sr-only'));
    expect(stamps).toHaveLength(1);
    expect(region.textContent).not.toContain('Mewtwo');
    expect(within(region).getByText(/An alert at 10% \(and at least \$1\.00\)/)).toBeTruthy();
    expect(within(region).getByText('Chimecho · reverse')).toBeTruthy();
    expect(within(region).getAllByText(SAMPLE_WORDS)).toHaveLength(3);
    for (const word of FORBIDDEN_WORDS) expect(region.textContent).not.toMatch(word);
  });

  it('links each part to its page, and a guest reaches all three', async () => {
    open(sampleRoutes());
    const region = await waitFor(section);
    const hrefs = (name: string) => within(region).getByRole('link', { name }).getAttribute('href');
    expect(hrefs('Open the sample portfolio')).toBe('/portfolio');
    expect(hrefs('Open the sample watchlist')).toBe('/watchlist');
    expect(hrefs('Open the sample alert')).toBe('/alerts');
    const cards = within(region).getByRole('list', { name: 'Sample watchlist' });
    expect(
      within(cards)
        .getAllByRole('link')
        .map((link) => link.getAttribute('href')),
    ).toEqual([
      '/card/sample0?variant=sample0%3Aholo%3Aunlimited%3A-',
      '/card/sample1?variant=sample1%3Aholo%3Aunlimited%3A-',
      '/card/sample2?variant=sample2%3Aholo%3Aunlimited%3A-',
    ]);
    // The header already offers a guest the watchlist and the portfolio.
    const nav = screen.getByRole('navigation', { name: 'Main' });
    expect(within(nav).getByRole('link', { name: 'Watchlist' }).getAttribute('href')).toBe(
      '/watchlist',
    );
    expect(within(nav).getByRole('link', { name: 'Portfolio' }).getAttribute('href')).toBe(
      '/portfolio',
    );
  });

  it('keeps the section for a signed-in visitor, labelled as the demo collection', async () => {
    const calls = open({ ...sampleRoutes(), 'GET /v1/me': jsonResponse(signedInMe) });
    const region = await waitFor(section);
    expect(within(region).getAllByText(SAMPLE_WORDS)).toHaveLength(3);
    const keys = calls.map((call) => call.key);
    for (const read of PUBLIC_READS) expect(keys).toContain(read);
    expect(keys).not.toContain('GET /v1/watchlist');
    expect(keys).not.toContain('GET /v1/portfolio');
  });
});

describe('See it working: the reserved space', () => {
  /** Movers answer; the three demo reads stay pending until `release` is called. */
  function held() {
    const waiting: (() => void)[] = [];
    const answers = sampleRoutes();
    vi.stubGlobal('fetch', (input: string | URL): Promise<Response> => {
      const url = String(input);
      const answer = answers[`GET ${url}`];
      if (answer === undefined || !(answer instanceof Response)) {
        return Promise.reject(new TypeError(`no request expected: ${url}`));
      }
      if (!url.startsWith('/v1/demo/')) return Promise.resolve(answer.clone());
      return new Promise<Response>((resolve) => {
        waiting.push(() => {
          resolve(answer.clone());
        });
      });
    });
    onTestFinished(() => {
      vi.unstubAllGlobals();
    });
    return {
      release: () => {
        for (const settle of waiting.splice(0)) settle();
      },
    };
  }

  it('holds the loaded section height at 390 and at 1280 from the first frame, in the same node', async () => {
    const reads = held();
    visit('/');
    render(<App />);
    await screen.findByRole('region', { name: 'Biggest moves vs previous close' });
    const reserve = main().lastElementChild as HTMLElement;
    expect(reserve.className).toBe(SEE_IT_RESERVE);
    expect(SEE_IT_RESERVE).toMatch(/(^| )min-h-\[\d+rem\]( |$)/);
    expect(SEE_IT_RESERVE).toMatch(/(^| )sm:min-h-\[\d+rem\]( |$)/);
    expect(reserve.childElementCount).toBe(0);
    expect(reserve.textContent).toBe('');
    expect(within(main()).queryByRole('region', { name: HEADING })).toBeNull();
    reads.release();
    const region = await waitFor(section);
    expect(main().lastElementChild).toBe(reserve);
    expect(reserve.contains(region)).toBe(true);
    expect(reserve.className).toBe(SEE_IT_RESERVE);
    expect(reserve.childElementCount).toBe(1);
  });

  it('sits after the movers strip, so releasing it moves nothing the visitor reads', async () => {
    open(sampleRoutes());
    const region = await waitFor(section);
    const strip = screen.getByRole('region', { name: 'Biggest moves vs previous close' });
    expect(strip.compareDocumentPosition(region) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(region.parentElement?.nextElementSibling).toBeNull();
  });
});

describe('See it working: a read that fails', () => {
  const failures: readonly (readonly [string, (route: string) => AccountAnswer])[] = [
    ['a network failure', () => new TypeError('Failed to fetch')],
    [
      'a 404 demo-unavailable',
      (route) =>
        route === ALERTS
          ? demoAlertsUnavailableRoutes()[ALERTS]
          : demoSampleUnavailableRoutes()[route as typeof WATCHLIST],
    ],
    [
      'a 429',
      (route) =>
        problemResponse({
          type: 'https://pokeprices.example/problems/http-429',
          title: 'Too Many Requests',
          status: 429,
          instance: route.replace('GET ', ''),
          request_id: 'req-sample-0429',
        }),
    ],
    ['a malformed body', () => jsonResponse({ data: 'not a list' })],
  ];

  async function reference(): Promise<string> {
    open(sampleRoutes());
    const region = await waitFor(section);
    await within(main()).findByRole('link', { name: 'See all movers' });
    region.parentElement?.remove();
    const rest = shape();
    cleanup();
    return rest;
  }

  for (const [kind, answer] of failures) {
    for (const route of PUBLIC_READS) {
      it(`leaves no section and the page as it was when ${route.replace('GET /v1/demo/', '')} answers ${kind}`, async () => {
        const rest = await reference();
        const calls = open({ ...sampleRoutes(), [route]: answer(route) });
        await waitFor(() => {
          expect(calls.map((call) => call.key)).toEqual(expect.arrayContaining(PUBLIC_READS));
          expect(main().lastElementChild?.className).not.toBe(SEE_IT_RESERVE);
        });
        await within(main()).findByRole('link', { name: 'See all movers' });
        expect(within(main()).queryByRole('region', { name: HEADING })).toBeNull();
        expect(main().textContent).not.toContain(HEADING);
        expect(shape()).toBe(rest);
      });
    }
  }
});

describe('See it working: what the demo holds', () => {
  it('shows one watched card, an empty portfolio and no alert as they are', async () => {
    open({
      ...MOVERS,
      ...demoSampleRoutes(watchlistBody(FIVE.slice(0, 1)), emptyPortfolioBody),
      ...demoAlertsRoutes(demoSamplesBody({ alert: null, message: null, webhook: null })),
    });
    const region = await waitFor(section);
    const cards = within(within(region).getByRole('list', { name: 'Sample watchlist' }));
    expect(cards.getAllByRole('listitem')).toHaveLength(1);
    expect(within(region).getByText('The demo portfolio holds no priced cards.')).toBeTruthy();
    expect(within(region).getByText('The demo has no alert sample.')).toBeTruthy();
    expect(region.textContent).not.toMatch(/undefined|null|NaN/);
    expect(within(region).getByRole('link', { name: 'Open the sample portfolio' })).toBeTruthy();
    expect(within(region).getByRole('link', { name: 'Open the sample alert' })).toBeTruthy();
  });

  it('shows an empty watchlist as a sentence, not a list', async () => {
    open({
      ...MOVERS,
      ...demoSampleRoutes(watchlistBody([]), portfolioBody()),
      ...demoAlertsRoutes(),
    });
    const region = await waitFor(section);
    expect(within(region).getByText('The demo watchlist is empty.')).toBeTruthy();
    expect(within(region).queryByRole('list', { name: 'Sample watchlist' })).toBeNull();
    expect(region.textContent).not.toMatch(/undefined|null|NaN/);
  });

  it('names a watched card with no price as one, and prints no stamp for it', async () => {
    open({
      ...MOVERS,
      ...demoSampleRoutes(watchlistBody([watchItemOf({ price: null })]), portfolioBody()),
      ...demoAlertsRoutes(),
    });
    const region = await waitFor(section);
    const cards = within(region).getByRole('list', { name: 'Sample watchlist' });
    expect(within(cards).getByText('No price in the collected snapshots')).toBeTruthy();
    expect(within(cards).queryByText(/as of /)).toBeNull();
    expect(region.textContent).not.toMatch(/undefined|null|NaN/);
  });

  it('prints a large total and long names whole', async () => {
    const long = 'Pikachu with a very long name that goes on '.repeat(3).trim();
    open({
      ...MOVERS,
      ...demoSampleRoutes(
        watchlistBody([watchItemOf({ card: { name: long, set_name: 'Base', number: '1' } })]),
        portfolioBody(undefined, { total_value: '123456789.50' }),
      ),
      ...demoAlertsRoutes(),
    });
    const region = await waitFor(section);
    expect(within(region).getByText('Total value $123,456,789.50')).toBeTruthy();
    expect(within(region).getByRole('link', { name: long })).toBeTruthy();
  });
});
