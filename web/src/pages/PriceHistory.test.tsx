import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import {
  FORBIDDEN_WORDS,
  cardBody,
  cardNotFoundProblem,
  filledOf,
  historyBody,
  isHistoryUrl,
  jsonResponse,
  pointOf,
  pricedCardBody,
  pricedUnownCard,
  problemResponse,
  risingPrice,
  seriesOf,
  stubFetch,
  unmappedCardBody,
  upstreamUnavailableProblem,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const CARD_URL = '/v1/cards/ex10-%3F';
const HISTORY_1M = '/v1/cards/ex10-%3F/prices?range=1M';
const HOLO = 'ex10-?:holo:unlimited:-';
const REVERSE = 'ex10-?:reverse:unlimited:-';
const STAMP = 'as of 2026-10-12 from TCGCSV (daily close)';
const BANNER =
  'History begins 2026-10-09. PokéPrices has no daily close for this variant before that day.';
const ZERO_ELIGIBLE = 'No daily close is held for this card yet.';

const holoPoints = [
  pointOf('2026-10-09', '928.32'),
  pointOf('2026-10-10', '928.06'),
  filledOf('2026-10-11', '928.06', '2026-10-10'),
  pointOf('2026-10-12', '968.91'),
];

/** The Unown card's history: the holo series from 2026-10-09, the reverse holo never priced. */
const history = historyBody([seriesOf(HOLO, holoPoints), seriesOf(REVERSE, [], null)]);

/** The same card with both variants priced, for the variant chooser. */
const twoPricedCard = {
  data: {
    ...pricedUnownCard,
    variants: pricedUnownCard.variants.map((variant) => ({ ...variant, price: risingPrice })),
  },
  meta: { degraded: false },
} satisfies CardDetail;

const twoSeriesHistory = historyBody([
  seriesOf(HOLO, holoPoints),
  seriesOf(REVERSE, [pointOf('2026-10-12', '70.81')], '2026-09-01'),
]);

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

/** Answers the card URL with `card` and every history URL with `reply(url)`. */
function serve(
  reply: (url: string) => Response | Error,
  card: CardDetail = pricedCardBody,
): string[] {
  const urls: string[] = [];
  stubFetch((url) => {
    urls.push(url);
    return isHistoryUrl(url) ? reply(url) : jsonResponse(card);
  });
  return urls;
}

async function openSection(): Promise<HTMLElement> {
  visit('/card/ex10-%3F');
  render(<App />);
  return screen.findByRole('region', { name: 'Price history' });
}

/** Opens the card page and waits for the history answer to show its stamp line. */
async function loaded(): Promise<HTMLElement> {
  const section = await openSection();
  await within(section).findByText(/^as of /);
  return section;
}

function rangeButtons(section: HTMLElement): HTMLElement[] {
  return within(within(section).getByRole('group', { name: 'Chart range' })).getAllByRole('button');
}

function pressed(buttons: readonly HTMLElement[]): string[] {
  return buttons
    .filter((button) => button.getAttribute('aria-pressed') === 'true')
    .map((button) => button.textContent);
}

function summary(section: HTMLElement): string {
  return section.querySelector('p.sr-only')?.textContent ?? '';
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  visit('/');
});

describe('Price history section (T05)', () => {
  it('asks for 1M by default and shows the heading, five ranges, the stamp, banner and summary', async () => {
    const urls = serve(() => jsonResponse(history));
    const section = await loaded();
    expect(urls).toEqual(['/v1/prices/freshness', CARD_URL, HISTORY_1M]);
    expect(within(section).getByRole('heading', { level: 2 }).textContent).toBe('Price history');
    const buttons = rangeButtons(section);
    expect(buttons.map((button) => button.textContent)).toEqual(['1W', '1M', '3M', 'YTD', '1Y']);
    expect(pressed(buttons)).toEqual(['1M']);
    expect(within(section).getByText(STAMP)).toBeTruthy();
    expect(within(section).getByRole('note').textContent).toBe(BANNER);
    expect(summary(section)).toBe(
      'Holo, unlimited, 1M: 3 daily closes from 2026-10-09 to 2026-10-12. Last close $968.91 on 2026-10-12. High $968.91 on 2026-10-12. Low $928.06 on 2026-10-10. 1 carried-forward day repeats an earlier close.',
    );
  });

  it('asks for range=3M when 3M is chosen and marks only 3M pressed', async () => {
    const urls = serve((url) =>
      jsonResponse(url.endsWith('=3M') ? historyBody(history.data, { range: '3M' }) : history),
    );
    const section = await loaded();
    fireEvent.click(within(section).getByRole('button', { name: '3M' }));
    await within(section).findByText(/^Holo, unlimited, 3M: /);
    expect(urls).toEqual([
      '/v1/prices/freshness',
      CARD_URL,
      HISTORY_1M,
      '/v1/cards/ex10-%3F/prices?range=3M',
    ]);
    expect(pressed(rangeButtons(section))).toEqual(['3M']);
  });

  it('hides the banner when history_begins is on or before meta.from', async () => {
    for (const begins of ['2026-09-13', '2026-08-01']) {
      serve(() =>
        jsonResponse(
          historyBody([seriesOf(HOLO, holoPoints, begins), seriesOf(REVERSE, [], null)]),
        ),
      );
      const section = await loaded();
      expect(within(section).queryByRole('note')).toBeNull();
      expect(section.textContent).not.toContain('History begins');
      cleanup();
    }
  });

  it('offers no variant chooser with one eligible series and never offers a null series', async () => {
    serve(() => jsonResponse(history));
    const section = await loaded();
    expect(within(section).queryByRole('group', { name: 'Variant' })).toBeNull();
    expect(within(section).queryByRole('button', { name: 'reverse, unlimited' })).toBeNull();
  });

  it('offers a variant chooser with two eligible series, the first pressed, and switches only the section', async () => {
    const urls = serve(() => jsonResponse(twoSeriesHistory), twoPricedCard);
    const section = await loaded();
    const chooser = within(section).getByRole('group', { name: 'Variant' });
    const variants = within(chooser).getAllByRole('button');
    expect(variants.map((button) => button.textContent)).toEqual([
      'holo, unlimited',
      'reverse, unlimited',
    ]);
    expect(pressed(variants)).toEqual(['holo, unlimited']);
    const pricesBefore = screen.getByRole('region', { name: 'Prices' }).innerHTML;
    fireEvent.click(within(chooser).getByRole('button', { name: 'reverse, unlimited' }));
    expect(pressed(within(chooser).getAllByRole('button'))).toEqual(['reverse, unlimited']);
    expect(summary(section)).toBe('Reverse, unlimited, 1M: 1 daily close, $70.81 on 2026-10-12.');
    expect(within(section).queryByRole('note')).toBeNull();
    expect(screen.getByRole('region', { name: 'Prices' }).innerHTML).toBe(pricesBefore);
    expect(urls).toEqual(['/v1/prices/freshness', CARD_URL, HISTORY_1M]);
    fireEvent.click(within(section).getByRole('button', { name: 'YTD' }));
    await within(section).findByText(/^Reverse, unlimited, YTD: /);
    expect(screen.getByRole('region', { name: 'Prices' }).innerHTML).toBe(pricesBefore);
  });

  it('renders exactly the zero-eligible line with heading, ranges and stamp, and nothing else', async () => {
    serve(() => jsonResponse(historyBody([seriesOf(HOLO, [], null), seriesOf(REVERSE, [], null)])));
    const section = await loaded();
    expect(within(section).getByText(ZERO_ELIGIBLE)).toBeTruthy();
    expect(within(section).getByRole('heading', { level: 2 }).textContent).toBe('Price history');
    expect(rangeButtons(section)).toHaveLength(5);
    expect(within(section).getByText(STAMP)).toBeTruthy();
    expect(within(section).queryByRole('group', { name: 'Variant' })).toBeNull();
    expect(within(section).queryByRole('button', { name: /data table/ })).toBeNull();
    expect(within(section).queryByRole('note')).toBeNull();
    expect(summary(section)).toBe('');
    expect(section.textContent).not.toContain('$');
  });

  it('omits the stamp in the zero-eligible state when meta.to is null (R199)', async () => {
    serve(() =>
      jsonResponse(historyBody([seriesOf(HOLO, [], null)], { from: null, to: null, as_of: null })),
    );
    const section = await openSection();
    await within(section).findByText(ZERO_ELIGIBLE);
    expect(rangeButtons(section)).toHaveLength(5);
    expect(section.textContent).not.toContain('as of');
    expect(section.textContent).not.toContain('null');
  });

  it('toggles the data table with its caption, columns and notes', async () => {
    const twoEpochs = historyBody([
      seriesOf(HOLO, [
        ...holoPoints.slice(0, 3),
        pointOf('2026-10-12', '1234.50', { series_epoch: 2 }),
      ]),
      seriesOf(REVERSE, [], null),
    ]);
    serve(() => jsonResponse(twoEpochs));
    const section = await loaded();
    const toggle = within(section).getByRole('button', { name: 'Show data table' });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(within(section).queryByRole('table')).toBeNull();
    fireEvent.click(toggle);
    expect(toggle.textContent).toBe('Hide data table');
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    const table = within(section).getByRole('table');
    expect(toggle.getAttribute('aria-controls')).toBe(table.id);
    expect(table.querySelector('caption')?.textContent).toBe(
      'Daily closes of holo, unlimited, 1M, from TCGCSV',
    );
    expect(
      within(table)
        .getAllByRole('columnheader')
        .map((cell) => cell.textContent),
    ).toEqual(['Day', 'Close', 'Note']);
    const rows = within(table)
      .getAllByRole('row')
      .slice(1)
      .map((row) =>
        within(row)
          .getAllByRole('cell')
          .map((cell) => cell.textContent),
      );
    expect(rows).toEqual([
      ['2026-10-09', '$928.32', ''],
      ['2026-10-10', '$928.06', ''],
      ['2026-10-11', '$928.06', 'Carried forward from 2026-10-10'],
      [
        '2026-10-12',
        '$1,234.50',
        'New product mapping from this day: not comparable with earlier closes',
      ],
    ]);
    expect(summary(section)).toBe(
      'Holo, unlimited, 1M: 1 daily close, $1,234.50 on 2026-10-12. 1 carried-forward day repeats an earlier close. The TCGplayer product mapping changed on 2026-10-12: earlier closes are a different series.',
    );
    fireEvent.click(toggle);
    expect(toggle.textContent).toBe('Show data table');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(within(section).queryByRole('table')).toBeNull();
  });

  it('says there is no close in the window when the selected series has no point in it', async () => {
    serve(() =>
      jsonResponse(
        historyBody([seriesOf(HOLO, [], '2026-10-09'), seriesOf(REVERSE, [], null)], {
          range: '1W',
          from: '2026-10-06',
          to: '2026-10-12',
        }),
      ),
    );
    const section = await loaded();
    expect(
      within(section).getByText(
        'No daily close for this variant between 2026-10-06 and 2026-10-12.',
      ),
    ).toBeTruthy();
    expect(summary(section)).toBe('');
    expect(within(section).getByRole('note').textContent).toBe(BANNER);
  });

  it('shows the loading line while the history is in flight', async () => {
    vi.stubGlobal('fetch', (input: string | URL): Promise<Response> =>
      isHistoryUrl(String(input))
        ? new Promise<Response>(() => undefined)
        : Promise.resolve(jsonResponse(pricedCardBody)),
    );
    const section = await openSection();
    expect(within(section).getByRole('status').textContent).toBe('Loading price history…');
    expect(rangeButtons(section)).toHaveLength(5);
    expect(pressed(rangeButtons(section))).toEqual(['1M']);
    for (const pattern of FORBIDDEN_WORDS) expect(section.textContent).not.toMatch(pattern);
    expect(section.textContent).not.toContain('2031');
    vi.unstubAllGlobals();
  });

  it('shows the problem title and request id when the history answers 503', async () => {
    serve(() => problemResponse(upstreamUnavailableProblem));
    const section = await openSection();
    const alert = await within(section).findByRole('alert');
    expect(alert.textContent).toContain('Could not load the price history: Upstream Unavailable');
    expect(alert.textContent).toContain('Request ID req-t46-upstream-0001');
    expect(rangeButtons(section)).toHaveLength(5);
    expect(screen.getByRole('region', { name: 'Prices' }).textContent).toContain(
      'Market price $70.81',
    );
  });

  it('does not throw on a 200 body of an unexpected shape', async () => {
    serve(() => jsonResponse(pricedCardBody));
    const section = await openSection();
    await within(section).findByText(ZERO_ELIGIBLE);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
  });

  it.each([
    ['unpriced', () => jsonResponse(cardBody)],
    ['unmapped', () => jsonResponse(unmappedCardBody)],
    ['not found', () => problemResponse(cardNotFoundProblem)],
    ['failed', () => problemResponse(upstreamUnavailableProblem)],
  ] as const)(
    'makes no history request and shows no section for a %s card',
    async (_kind, answer) => {
      const calls = stubFetch(() => answer());
      visit('/card/ex10-%3F');
      render(<App />);
      await screen.findByText(/No prices yet|Card not found|Could not load this card/);
      expect(calls.map((call) => call.url)).toEqual(['/v1/prices/freshness', CARD_URL]);
      expect(screen.queryByRole('region', { name: 'Price history' })).toBeNull();
    },
  );

  it('never shows a forbidden word or the faked year in any state (D1, D14)', async () => {
    const states: readonly [() => Response | Error, CardDetail][] = [
      [() => jsonResponse(history), pricedCardBody],
      [() => jsonResponse(twoSeriesHistory), twoPricedCard],
      [() => jsonResponse(historyBody([seriesOf(HOLO, [], null)])), pricedCardBody],
      [() => jsonResponse(historyBody([seriesOf(HOLO, [], '2026-10-09')])), pricedCardBody],
      [() => problemResponse(upstreamUnavailableProblem), pricedCardBody],
      [() => new TypeError('offline'), pricedCardBody],
    ];
    for (const [reply, card] of states) {
      serve(reply, card);
      const section = await openSection();
      await within(section).findAllByText(/^as of |^Could not load the price history/);
      const toggle = within(section).queryByRole('button', { name: 'Show data table' });
      if (toggle !== null) fireEvent.click(toggle);
      const text = section.textContent;
      for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
      expect(text).not.toContain('2031');
      cleanup();
      vi.unstubAllGlobals();
    }
  });
});

describe('Price history polling (D9)', () => {
  async function settle(): Promise<void> {
    for (let round = 0; round < 3; round += 1) {
      await act(async () => {
        await new Promise((resolve) => {
          setTimeout(resolve, 0);
        });
      });
    }
  }

  it('keeps the loaded section when a later history poll fails', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    let historyCalls = 0;
    serve(() => {
      historyCalls += 1;
      return historyCalls === 1
        ? jsonResponse(history)
        : problemResponse(upstreamUnavailableProblem);
    });
    visit('/card/ex10-%3F');
    render(<App />);
    await settle();
    const section = screen.getByRole('region', { name: 'Price history' });
    expect(summary(section)).toContain('Holo, unlimited, 1M: 3 daily closes');
    await act(async () => {
      await vi.advanceTimersByTimeAsync(300_000);
    });
    await settle();
    expect(historyCalls).toBe(2);
    expect(summary(section)).toContain('Holo, unlimited, 1M: 3 daily closes');
    expect(within(section).queryByRole('alert')).toBeNull();
    expect(within(section).getByText(STAMP)).toBeTruthy();
  });
});
