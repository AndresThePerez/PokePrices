// Review tests for the signed-out sample on /watchlist and /portfolio: states a real visitor
// reaches (a leftover cookie, a throttled or malformed public read, an empty or unpriced demo,
// the keyboard walk, and the hand-over to a session) must still show no control that writes and
// never show the sample's rows to a signed-in account.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  clearXsrfCookie,
  demoSampleRoutes,
  holdingOf,
  jsonResponse,
  portfolioBody,
  problemResponse,
  rawResponse,
  setXsrfCookie,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
  type AccountAnswer,
} from '../test/fixtures';

const FOCUSABLE = 'a[href], button, input, select, textarea, [tabindex]';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

function open(path: string, routes: Readonly<Record<string, AccountAnswer>>) {
  const calls = stubAccountFetch(routes);
  visit(path);
  render(<App />);
  return calls;
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

function accountKeys(calls: readonly { readonly key: string }[]): string[] {
  return calls
    .map((call) => call.key)
    .filter((key) => /\/v1\/(me|watchlist|portfolio|demo)|sanctum|POST/.test(key));
}

function focusOrder(region: HTMLElement): string[] {
  return [...region.querySelectorAll<HTMLElement>(FOCUSABLE)]
    .filter((node) => !node.hasAttribute('disabled') && node.getAttribute('tabindex') !== '-1')
    .map((node) => {
      const tag = node.tagName.toLowerCase();
      if (tag === 'a') return `link:${node.getAttribute('href') ?? ''}`;
      return `${tag}:${node.textContent}`;
    });
}

describe('the signed-out sample, states a visitor really reaches', () => {
  it('a leftover cookie answered 401 shows the portfolio sample and reads no account list', async () => {
    setXsrfCookie();
    const calls = open('/portfolio', {
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      ...demoSampleRoutes(),
    });
    expect(await screen.findByRole('list', { name: 'Portfolio' })).toBeTruthy();
    expect(within(main()).getByRole('region', { name: 'Sample data' })).toBeTruthy();
    expect(within(main()).getByText('Total value $1,047.93')).toBeTruthy();
    expect(accountKeys(calls)).toEqual(['GET /v1/me', 'GET /v1/demo/portfolio']);
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it.each([
    ['a 429 from the public read', () => problemResponse(tooManyRequestsProblem('/v1/demo/x'))],
    ['a 200 whose body is not a list', () => jsonResponse({ data: 'nothing', meta: null })],
    ['a 200 that is HTML', () => rawResponse(200, '<html></html>', 'text/html')],
  ])(
    '%s: both pages show the earlier signed-out view, no strip, no error block',
    async (_n, answer) => {
      for (const [path, prompt] of [
        ['/watchlist', 'Sign in to keep a watchlist.'],
        ['/portfolio', 'Sign in to keep a portfolio.'],
      ] as const) {
        open(path, {
          'GET /v1/demo/watchlist': answer(),
          'GET /v1/demo/portfolio': answer(),
        });
        expect(await screen.findByText(prompt)).toBeTruthy();
        expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
        expect(screen.queryByRole('alert')).toBeNull();
        expect(within(main()).queryByRole('status')).toBeNull();
        expect(within(main()).getByRole('button', { name: 'Try the read-only demo' })).toBeTruthy();
        cleanup();
      }
    },
  );

  it('an empty demo watchlist shows the sign-in prompt, never the strip or the empty-list sentence', async () => {
    open('/watchlist', demoSampleRoutes(watchlistBody([]), portfolioBody()));
    expect(await screen.findByText('Sign in to keep a watchlist.')).toBeTruthy();
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(main().textContent).not.toContain('Your watchlist is empty');
  });

  it('unpriced rows with an alert and an unknown card still show the strip and no control that writes', async () => {
    const unpriced = watchItemOf({ price: null, card: null, alert_pct: 10 });
    const none = holdingOf({ price: null, card: null, value: null, quantity: 4 });
    const routes = demoSampleRoutes(
      watchlistBody([unpriced]),
      portfolioBody([none], {
        total_value: null,
        change: null,
        change_pct: null,
        priced: 0,
        unpriced: 1,
        first_day: null,
        last_day: null,
      }),
    );
    open('/watchlist', routes);
    expect(await screen.findByRole('list', { name: 'Watchlist' })).toBeTruthy();
    expect(within(main()).getByRole('region', { name: 'Sample data' })).toBeTruthy();
    expect(focusOrder(main())).toEqual([
      'link:/sign-in?next=/watchlist',
      'button:Try the read-only demo',
      'link:/card/base1-4?variant=base1-4%3Aholo%3Aunlimited%3A-',
    ]);
    cleanup();
    open('/portfolio', routes);
    expect(await screen.findByRole('list', { name: 'Portfolio' })).toBeTruthy();
    expect(
      within(main()).getByText('No holding has a price in the collected snapshots yet.'),
    ).toBeTruthy();
    expect(within(main()).getByRole('region', { name: 'Sample data' })).toBeTruthy();
    expect(within(main()).queryAllByRole('spinbutton')).toHaveLength(0);
  });

  it('the keyboard walk on both pages is the strip first, then the rows, with nothing that writes', async () => {
    open('/watchlist', demoSampleRoutes());
    await screen.findByRole('list', { name: 'Watchlist' });
    expect(focusOrder(main())).toEqual([
      'link:/sign-in?next=/watchlist',
      'button:Try the read-only demo',
      'link:/card/base1-4?variant=base1-4%3Aholo%3A1st%3A-',
      'link:/card/base1-4?variant=base1-4%3Aholo%3Aunlimited%3A-',
      'link:/card/ex5-17?variant=ex5-17%3Areverse%3A-%3A-',
    ]);
    cleanup();
    open('/portfolio', demoSampleRoutes());
    await screen.findByRole('list', { name: 'Portfolio' });
    const order = focusOrder(main());
    expect(order.slice(0, 2)).toEqual([
      'link:/sign-in?next=/portfolio',
      'button:Try the read-only demo',
    ]);
    expect(order.slice(2).every((entry) => entry.startsWith('link:/card/'))).toBe(true);
    expect(order).toHaveLength(5);
  });

  it('starting the demo from the sample never shows the sample next to the signed-in header', async () => {
    const own = watchlistBody([watchItemOf({ variant_key: 'zz1-1:holo:-:-', card_id: 'zz1-1' })]);
    stubAccountFetch({
      'GET /v1/me': () => jsonResponse({ data: { ...signedInMe.data, read_only: true } }),
      'POST /v1/demo/session': rawResponse(204, null),
      'GET /v1/watchlist': jsonResponse(own),
      ...demoSampleRoutes(),
    });
    visit('/watchlist');
    render(<App />);
    let flash = false;
    const banner = screen.getByRole('banner');
    const watch = new MutationObserver(() => {
      const signedIn = within(banner).queryAllByRole('link', { name: 'Account' }).length > 0;
      if (signedIn && screen.queryByRole('region', { name: 'Sample data' }) !== null) flash = true;
    });
    watch.observe(document.body, { childList: true, subtree: true, characterData: true });
    fireEvent.click(
      await within(main())
        .findByRole('list', { name: 'Watchlist' })
        .then(() => within(main()).getByRole('button', { name: 'Try the read-only demo' })),
    );
    await waitFor(() => {
      expect(within(banner).queryAllByRole('link', { name: 'Account' }).length).toBeGreaterThan(0);
    });
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 20));
    });
    watch.disconnect();
    expect(flash).toBe(false);
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(screen.queryByText('Sign in to keep a watchlist.')).toBeNull();
  });
});
