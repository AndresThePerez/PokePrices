import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  type AccountAnswer,
  accountValidationProblem,
  clearXsrfCookie,
  demoMe,
  demoSampleUnavailableRoutes,
  emptyPortfolioBody,
  holdingOf,
  jsonResponse,
  lightOnlyClasses,
  millionPrice,
  portfolioBody,
  problemResponse,
  rawResponse,
  sentBody,
  serverErrorProblem,
  shortControls,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  workedHoldings,
} from '../test/fixtures';

const FOOTNOTE = 'TCGplayer market prices via TCGCSV, collected once a day.';
const DEMO_NOTICE =
  'You are signed in to the read-only demo account. Its watchlist and portfolio cannot be changed.';
const EMPTY = 'Your portfolio is empty. Open a card from Search and choose Add to portfolio.';
const UNPRICED_ROW = 'No price in the collected snapshots; not counted in the total.';
const CHARIZARD_KEY = '/v1/portfolio/base1-4%3Aholo%3Aunlimited%3A-';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.useRealTimers();
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function rows(): HTMLElement[] {
  return within(screen.getByRole('list', { name: 'Portfolio' })).getAllByRole('listitem');
}

function row(index: number): HTMLElement {
  const found = rows()[index];
  if (found === undefined) throw new Error(`no row ${String(index)}`);
  return found;
}

function openPortfolio(
  routes: Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>>,
): ReturnType<typeof stubAccountFetch> {
  const calls = stubAccountFetch(routes);
  visit('/portfolio');
  render(<App />);
  return calls;
}

function signedIn(
  body: unknown,
  extra: Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>> = {},
): ReturnType<typeof stubAccountFetch> {
  return openPortfolio({
    'GET /v1/me': jsonResponse(signedInMe),
    'GET /v1/portfolio': jsonResponse(body),
    ...extra,
  });
}

describe('the portfolio page (T07 criterion 1, D9, D10)', () => {
  it('without the cookie asks no session: the sample loading line first, then the sign-in prompt with its links and the demo button', async () => {
    const calls = openPortfolio({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      ...demoSampleUnavailableRoutes(),
    });
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Portfolio');
    // The first render: no session loading line, only the sample's own while its read is pending.
    expect(within(main()).getByRole('status').textContent).toBe('Loading the sample portfolio…');
    expect(main().textContent).not.toContain('Loading your portfolio…');
    expect(await screen.findByText('Sign in to keep a portfolio.')).toBeTruthy();
    expect(within(main()).queryByRole('status')).toBeNull();
    const links = within(main())
      .getAllByRole('link')
      .map((link) => [link.textContent, link.getAttribute('href')]);
    expect(links).toEqual([
      ['Sign in', '/sign-in?next=/portfolio'],
      ['Create an account', '/sign-up'],
    ]);
    expect(within(main()).getByRole('button', { name: 'Try the read-only demo' })).toBeTruthy();
    await waitFor(() => {
      expect(calls.map((call) => call.key)).toEqual([
        'GET /v1/prices/freshness',
        'GET /v1/demo/portfolio',
      ]);
    });
  });

  it("renders D9's worked example: the API's values, total, days and the excluded holding", async () => {
    signedIn(portfolioBody());
    expect(await screen.findByText('Total value $1,047.93')).toBeTruthy();
    expect(
      screen.getByText('Closes from 2026-10-11 to 2026-10-12 from TCGCSV (daily close)'),
    ).toBeTruthy();
    expect(
      screen.getByText('1 holding has no price in the collected snapshots and is not counted.'),
    ).toBeTruthy();

    const unpriced = row(0);
    expect(within(unpriced).getByText('Quantity 2')).toBeTruthy();
    expect(within(unpriced).getByText(UNPRICED_ROW)).toBeTruthy();
    expect(within(unpriced).queryByText(/^Value/)).toBeNull();

    const charizard = row(1);
    expect(within(charizard).getByRole('link', { name: 'Charizard' }).getAttribute('href')).toBe(
      '/card/base1-4?variant=base1-4%3Aholo%3Aunlimited%3A-',
    );
    expect(within(charizard).getByText('Base No. 4 · Holo, unlimited')).toBeTruthy();
    expect(within(charizard).getByText('Quantity 1')).toBeTruthy();
    expect(within(charizard).getByText('Market price $968.91')).toBeTruthy();
    expect(within(charizard).getByText('Value $968.91 (1 × $968.91)')).toBeTruthy();
    expect(within(charizard).getByText('as of 2026-10-12 from TCGCSV (daily close)')).toBeTruthy();

    const chimecho = row(2);
    expect(within(chimecho).getByText('Quantity 3')).toBeTruthy();
    expect(within(chimecho).getByText('Value $79.02 (3 × $26.34)')).toBeTruthy();
    const down = within(chimecho).getByText('▼ -$0.66 (-2.44%) vs previous close (2026-10-10)');
    expect(down.className).toContain('sr-only');
    expect(within(chimecho).getByText('▼ -2.44%').className).toContain('text-down');
    expect(within(chimecho).getByText('as of 2026-10-11 from TCGCSV (daily close)')).toBeTruthy();

    expect(
      within(charizard).getByRole('button', {
        name: 'Remove Charizard, holo, unlimited from your portfolio',
      }),
    ).toBeTruthy();
    expect(main().textContent.endsWith(FOOTNOTE)).toBe(true);
  });

  it('names one day with its as-of line and counts several unpriced holdings', async () => {
    signedIn(
      portfolioBody(
        [
          holdingOf({
            variant_key: 'base1-4:holo:1st:-',
            edition: '1st',
            price: null,
            value: null,
          }),
          holdingOf(),
          holdingOf({
            variant_key: 'base1-4:holo:shadowless:-',
            edition: 'shadowless',
            price: null,
            value: null,
          }),
        ],
        {
          total_value: '968.91',
          priced: 1,
          unpriced: 2,
          first_day: '2026-10-12',
          last_day: '2026-10-12',
        },
      ),
    );
    const total = await screen.findByText('Total value $968.91');
    const block = total.parentElement as HTMLElement;
    expect(within(block).getByText('as of 2026-10-12 from TCGCSV (daily close)')).toBeTruthy();
    expect(screen.queryByText(/^Closes from/)).toBeNull();
    expect(
      screen.getByText('2 holdings have no price in the collected snapshots and are not counted.'),
    ).toBeTruthy();
  });

  it('says no holding is priced instead of a total of zero', async () => {
    signedIn(
      portfolioBody([holdingOf({ price: null, value: null })], {
        total_value: null,
        priced: 0,
        unpriced: 1,
        first_day: null,
        last_day: null,
      }),
    );
    expect(
      await screen.findByText('No holding has a price in the collected snapshots yet.'),
    ).toBeTruthy();
    expect(screen.queryByText(/Total value/)).toBeNull();
    expect(main().textContent).not.toMatch(/\$0(?:\.00)?\b/);
    expect(screen.getByText(UNPRICED_ROW)).toBeTruthy();
  });

  it('prints the exact money strings $1,000,000.00 and $9,999,999,999.99, never recomputed', async () => {
    signedIn(
      portfolioBody([holdingOf({ price: millionPrice, quantity: 9999, value: '9999999999.99' })], {
        total_value: '9999999999.99',
        priced: 1,
        unpriced: 0,
        first_day: '2026-10-12',
        last_day: '2026-10-12',
      }),
    );
    expect(await screen.findByText('Total value $9,999,999,999.99')).toBeTruthy();
    expect(screen.getByText('Market price $1,000,000.00')).toBeTruthy();
    expect(screen.getByText('Value $9,999,999,999.99 (9999 × $1,000,000.00)')).toBeTruthy();
    expect(screen.getByText('Quantity 9999')).toBeTruthy();
    // 9999 × 1000000.00 is 9999000000.00: the page shows the API's value, it never multiplies.
    expect(main().textContent).not.toContain('9,999,000,000');
    expect(main().textContent).not.toMatch(/e\+|NaN|Infinity/);
  });

  it('shows the empty line without a total, a list or a footnote', async () => {
    signedIn(emptyPortfolioBody);
    expect(await screen.findByText(EMPTY)).toBeTruthy();
    expect(screen.queryByText(/Total value/)).toBeNull();
    expect(screen.queryByText(/No holding has a price/)).toBeNull();
    expect(screen.queryByText(FOOTNOTE)).toBeNull();
  });

  it('shows the degraded catalog line when meta.degraded is true', async () => {
    signedIn(portfolioBody(workedHoldings, { degraded: true }));
    expect(
      await screen.findByText('Catalog data may be out of date: PokéSearch is unavailable'),
    ).toBeTruthy();
  });

  it('shows the problem block when the portfolio cannot be loaded', async () => {
    openPortfolio({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/portfolio': problemResponse(serverErrorProblem('/v1/portfolio')),
    });
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not load your portfolio: Internal Server Error');
    expect(alert.textContent).toContain('Request ID req-p4-server-0007');
  });
});

describe('changing the portfolio (T07 criterion 2)', () => {
  function quantityField(index: number): HTMLInputElement {
    return within(row(index)).getByLabelText<HTMLInputElement>('Quantity owned');
  }

  it('carries min, max and step on the quantity field, prefilled with the holding', async () => {
    signedIn(portfolioBody());
    await screen.findByRole('list', { name: 'Portfolio' });
    const field = quantityField(2);
    expect(field.value).toBe('3');
    expect(field.getAttribute('type')).toBe('number');
    expect(field.getAttribute('min')).toBe('1');
    expect(field.getAttribute('max')).toBe('9999');
    expect(field.getAttribute('step')).toBe('1');
    expect(within(row(2)).getByText('Quantity owned').tagName).toBe('LABEL');
  });

  it.each([
    ['0', 0, 'The quantity field must be at least 1.'],
    ['10000', 10000, 'The quantity field must not be greater than 9999.'],
    ['abc', null, 'The quantity field is required.'],
  ] as const)(
    'sends %j and shows the API 422 under Quantity owned, keeping the list',
    async (typed, sent, message) => {
      const calls = signedIn(portfolioBody(), {
        [`PUT ${CHARIZARD_KEY}`]: problemResponse(
          accountValidationProblem(CHARIZARD_KEY, { quantity: [message] }),
        ),
      });
      await screen.findByRole('list', { name: 'Portfolio' });
      fireEvent.change(quantityField(1), { target: { value: typed } });
      fireEvent.click(within(row(1)).getByRole('button', { name: 'Save' }));
      const alert = await screen.findByRole('alert');
      expect(alert.textContent).toContain(
        'Could not change your portfolio: check the fields below.',
      );
      const field = quantityField(1);
      expect(field.getAttribute('aria-invalid')).toBe('true');
      const describedBy = field.getAttribute('aria-describedby') ?? '';
      expect(document.getElementById(describedBy)?.textContent).toBe(message);
      const put = calls.filter((call) => call.key === `PUT ${CHARIZARD_KEY}`);
      expect(put).toHaveLength(1);
      expect(sentBody(put[0])).toEqual({ quantity: sent });
      expect(rows()).toHaveLength(3);
      expect(screen.getByText('Total value $1,047.93')).toBeTruthy();
      expect(screen.getByText('Value $968.91 (1 × $968.91)')).toBeTruthy();
    },
  );

  it('sets the quantity with PUT, then shows the refetched values of the API', async () => {
    const calls = signedIn(portfolioBody(), {
      'GET /v1/portfolio': [
        jsonResponse(portfolioBody()),
        jsonResponse(
          portfolioBody(
            workedHoldings.map((holding, index) =>
              index === 1 ? { ...holding, quantity: 5, value: '4844.55' } : holding,
            ),
            { total_value: '4923.57' },
          ),
        ),
      ],
      [`PUT ${CHARIZARD_KEY}`]: jsonResponse({
        data: { variant_key: 'base1-4:holo:unlimited:-', quantity: 5 },
      }),
    });
    await screen.findByRole('list', { name: 'Portfolio' });
    fireEvent.change(quantityField(1), { target: { value: '5' } });
    fireEvent.click(within(row(1)).getByRole('button', { name: 'Save' }));
    expect(await screen.findByText('Value $4,844.55 (5 × $968.91)')).toBeTruthy();
    expect(screen.getByText('Total value $4,923.57')).toBeTruthy();
    expect(within(row(1)).getByText('Quantity 5')).toBeTruthy();
    expect(quantityField(1).value).toBe('5');
    const put = calls.filter((call) => call.key === `PUT ${CHARIZARD_KEY}`);
    expect(sentBody(put[0])).toEqual({ quantity: 5 });
    expect(calls.filter((call) => call.key === 'GET /v1/portfolio')).toHaveLength(2);
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('removes a holding with DELETE, then refetches the list', async () => {
    const calls = signedIn(portfolioBody(), {
      'GET /v1/portfolio': [
        jsonResponse(portfolioBody()),
        jsonResponse(
          portfolioBody(
            workedHoldings.filter((_, index) => index !== 1),
            { total_value: '79.02', priced: 1, first_day: '2026-10-11', last_day: '2026-10-11' },
          ),
        ),
      ],
      [`DELETE ${CHARIZARD_KEY}`]: rawResponse(204, null),
    });
    fireEvent.click(
      await screen.findByRole('button', {
        name: 'Remove Charizard, holo, unlimited from your portfolio',
      }),
    );
    // Two rows and exactly one "Removed." line, where the removed row was.
    await waitFor(() => {
      expect(rows().map((item) => item.textContent.startsWith('Removed.'))).toEqual([
        false,
        true,
        false,
      ]);
    });
    expect(screen.getByText('Total value $79.02')).toBeTruthy();
    expect(calls.filter((call) => call.key === `DELETE ${CHARIZARD_KEY}`)).toHaveLength(1);
  });

  it('keeps the last good list on screen when a removal fails', async () => {
    signedIn(portfolioBody(), {
      [`DELETE ${CHARIZARD_KEY}`]: problemResponse(serverErrorProblem(CHARIZARD_KEY)),
    });
    fireEvent.click(
      await screen.findByRole('button', {
        name: 'Remove Charizard, holo, unlimited from your portfolio',
      }),
    );
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not change your portfolio: Internal Server Error');
    expect(rows()).toHaveLength(3);
    expect(screen.getByText('Total value $1,047.93')).toBeTruthy();
  });
});

describe('the portfolio of the read-only demo (T07 criterion 4)', () => {
  it('says it is the read-only demo and renders no Remove, Save or quantity field', async () => {
    openPortfolio({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/portfolio': jsonResponse(portfolioBody()),
    });
    expect(await screen.findByText(DEMO_NOTICE)).toBeTruthy();
    await screen.findByText('Total value $1,047.93');
    expect(within(main()).queryAllByRole('button')).toEqual([]);
    expect(within(main()).queryByLabelText('Quantity owned')).toBeNull();
    expect(screen.getByText('Value $79.02 (3 × $26.34)')).toBeTruthy();
  });
});

describe('portfolio accessibility and honesty (T07 criterion 6)', () => {
  const states = [
    [
      'signed out',
      { 'GET /v1/me': problemResponse(unauthenticatedProblem) },
      'Sign in to keep a portfolio.',
    ],
    [
      'worked example',
      {
        'GET /v1/me': jsonResponse(signedInMe),
        'GET /v1/portfolio': jsonResponse(portfolioBody(workedHoldings, { degraded: true })),
      },
      FOOTNOTE,
    ],
    [
      'demo',
      { 'GET /v1/me': jsonResponse(demoMe), 'GET /v1/portfolio': jsonResponse(portfolioBody()) },
      FOOTNOTE,
    ],
    [
      'nothing priced',
      {
        'GET /v1/me': jsonResponse(signedInMe),
        'GET /v1/portfolio': jsonResponse(
          portfolioBody([holdingOf({ price: null, value: null })], {
            total_value: null,
            priced: 0,
            unpriced: 1,
            first_day: null,
            last_day: null,
          }),
        ),
      },
      'No holding has a price in the collected snapshots yet.',
    ],
    [
      'empty',
      {
        'GET /v1/me': jsonResponse(signedInMe),
        'GET /v1/portfolio': jsonResponse(emptyPortfolioBody),
      },
      EMPTY,
    ],
    [
      'failed',
      {
        'GET /v1/me': jsonResponse(signedInMe),
        'GET /v1/portfolio': problemResponse(serverErrorProblem('/v1/portfolio')),
      },
      /Could not load your portfolio/,
    ],
  ] as const;

  it.each(states)(
    '%s: dark counterparts, 44 px controls, no forbidden word, no clock',
    async (_name, routes, ready) => {
      vi.useFakeTimers({ toFake: ['Date'] });
      vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
      stubAccountFetch(routes);
      visit('/portfolio');
      const { container } = render(<App />);
      const check = (): void => {
        expect(lightOnlyClasses(container)).toEqual([]);
        expect(shortControls(main())).toEqual([]);
        for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
        expect(main().textContent).not.toContain('2031');
      };
      check();
      await screen.findAllByText(ready);
      check();
      expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    },
  );

  it('keeps a failed save dark-ready and free of forbidden words', async () => {
    signedIn(portfolioBody(), {
      [`PUT ${CHARIZARD_KEY}`]: problemResponse(
        accountValidationProblem(CHARIZARD_KEY, { quantity: ['The quantity field is required.'] }),
      ),
    });
    const { container } = { container: document.body };
    await screen.findByRole('list', { name: 'Portfolio' });
    fireEvent.click(within(row(1)).getByRole('button', { name: 'Save' }));
    await screen.findByRole('alert');
    expect(lightOnlyClasses(container)).toEqual([]);
    expect(shortControls(main())).toEqual([]);
    for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
  });
});
