// Adversarial tests for the portfolio page's total: every state of its panel says what the figure is
// (an estimate at daily-close market prices, not a sale value), keeps its source and "as of", shows
// nothing but sentences (no NaN, undefined, null, exponent or negative zero) and no forbidden word, and a
// meta with a wrong kind or a missing field is the page's problem block, never a crash.
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { ESTIMATE_LABEL } from '../format';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  jsonResponse,
  portfolioBody,
  problemResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  type PortfolioBody,
} from '../test/fixtures';

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  clearXsrfCookie();
  window.localStorage.clear();
  window.history.replaceState(null, '', '/');
});

function open(answer: Response): void {
  stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe), 'GET /v1/portfolio': answer });
  window.history.replaceState(null, '', '/portfolio');
  render(<App />);
}

function panel(): HTMLElement {
  const label = screen.getByText(ESTIMATE_LABEL);
  const container = label.parentElement;
  if (container === null) throw new Error('no panel');
  return container;
}

type Meta = Partial<PortfolioBody['meta']>;

describe('every state of the total panel', () => {
  const STATES: readonly (readonly [string, Meta, readonly string[]])[] = [
    [
      'an ordinary change',
      {},
      ['▲ +$16.93 (+1.64%) vs previous close.', '3 cards, 1 without a price.'],
    ],
    [
      'a fall',
      { change: '-1234.50', change_pct: '-0.12' },
      ['▼ -$1,234.50 (-0.12%) vs previous close.'],
    ],
    ['no change at all', { change: '0.00', change_pct: '0.00' }, ['No change vs previous close.']],
    [
      'no previous close anywhere',
      { change: null, change_pct: null, no_previous_close: 2 },
      [
        'No previous close yet, so no change to show.',
        'The change leaves out 2 cards with no previous close.',
      ],
    ],
    [
      'one card with no previous close',
      { no_previous_close: 1 },
      ['The change leaves out 1 card with no previous close.'],
    ],
    [
      'a change with no percent',
      { change: '4.00', change_pct: null },
      ['▲ +$4.00 vs previous close; no percent, as the previous closes add up to $0.00.'],
    ],
    ['one card, all priced', { count: 1, priced: 1, unpriced: 0 }, ['1 card.']],
    [
      'a thousand cards',
      { count: 1000, priced: 999, unpriced: 1 },
      ['1,000 cards, 1 without a price.'],
    ],
    [
      'the largest total',
      { total_value: '199979999999800.02', change: '99989999999900.01', change_pct: '100.00' },
      [
        'Total value $199,979,999,999,800.02',
        '▲ +$99,989,999,999,900.01 (+100.00%) vs previous close.',
      ],
    ],
    [
      'a total of 0.00',
      { total_value: '0.00', change: null, change_pct: null, no_previous_close: 1 },
      ['Total value $0.00'],
    ],
  ];

  it.each(STATES)('%s', async (...row) => {
    const [, meta, lines] = row;
    open(jsonResponse(portfolioBody(undefined, meta)));
    await screen.findByText(ESTIMATE_LABEL);
    const text = panel().textContent;
    for (const line of lines) expect(text).toContain(line);
    expect(text).toContain('from TCGCSV (daily close)');
    expect(text).toMatch(/Closes from 2026-10-11 to 2026-10-12|as of 2026-10-12/);
    expect(text).not.toMatch(/\b(NaN|undefined|null|Infinity)\b/);
    expect(text).not.toMatch(/[0-9]e[+-]?[0-9]/i);
    expect(text).not.toMatch(/-\$?0\.00\b/);
    for (const word of FORBIDDEN_WORDS) expect(text).not.toMatch(word);
    // The label is said once, and the word "sale" only ever as "not a sale value".
    expect(screen.getAllByText(ESTIMATE_LABEL)).toHaveLength(1);
    expect((text.match(/\bsale\b/g) ?? []).length).toBe(1);
  });

  it('with no priced holding: no total, no estimate label, a sentence instead, and no change line', async () => {
    open(
      jsonResponse(
        portfolioBody(undefined, {
          total_value: null,
          change: null,
          change_pct: null,
          priced: 0,
          unpriced: 3,
          first_day: null,
          last_day: null,
        }),
      ),
    );
    await screen.findByText('No holding has a price in the collected snapshots yet.');
    const main = screen.getByRole('main').textContent;
    expect(main).not.toContain('Total value');
    expect(main).not.toMatch(/vs previous close\./);
    expect(main).not.toMatch(/\b(NaN|undefined|null)\b/);
  });
});

describe('a portfolio meta that is wrong is the problem block, not a crash', () => {
  const WRONG: readonly (readonly [string, (body: PortfolioBody) => unknown])[] = [
    ['change is a number', (body) => ({ ...body, meta: { ...body.meta, change: 16.93 } })],
    ['change_pct is a number', (body) => ({ ...body, meta: { ...body.meta, change_pct: 1.64 } })],
    [
      'no_previous_close is a string',
      (body) => ({ ...body, meta: { ...body.meta, no_previous_close: '0' } }),
    ],
    ['count is a string', (body) => ({ ...body, meta: { ...body.meta, count: '3' } })],
    [
      'total_value is a number',
      (body) => ({ ...body, meta: { ...body.meta, total_value: 1047.93 } }),
    ],
    [
      'the change fields are missing (a body from before the change)',
      (body) => ({
        ...body,
        meta: Object.fromEntries(
          Object.entries(body.meta).filter(
            ([key]) => !['change', 'change_pct', 'no_previous_close'].includes(key),
          ),
        ),
      }),
    ],
    ['meta is missing', (body) => ({ data: body.data })],
    ['meta is null', (body) => ({ ...body, meta: null })],
  ];

  it.each(WRONG)('%s', async (...row) => {
    const [, mutate] = row;
    open(jsonResponse(mutate(portfolioBody())));
    await screen.findByText(/Could not load your portfolio/);
    expect(screen.queryByText(ESTIMATE_LABEL)).toBeNull();
    expect(screen.getByRole('main').textContent).not.toMatch(/\b(NaN|undefined)\b/);
  });

  it.each([
    ['a 500', problemResponse(serverErrorProblem('/v1/portfolio'))],
    ['a 429', problemResponse(tooManyRequestsProblem('/v1/portfolio'))],
  ] as const)('%s on the read shows the problem block and no total', async (...row) => {
    const [, answer] = row;
    open(answer);
    await screen.findByText(/Could not load your portfolio/);
    expect(screen.queryByText(/Total value/)).toBeNull();
    expect(screen.queryByText(ESTIMATE_LABEL)).toBeNull();
  });
});
