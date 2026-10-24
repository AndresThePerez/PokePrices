import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import {
  cardBody,
  cardNotFoundPlainProblem,
  cardNotFoundProblem,
  cardWithPrices,
  degradedCardBody,
  fallingPrice,
  firstClosePrice,
  historyFor,
  isHistoryUrl,
  jsonResponse,
  pricedCardBody,
  problemResponse,
  stubFetch,
  unchangedPrice,
  unmappedCardBody,
  upFromZeroPrice,
  unownCard,
  unseededCardBody,
  upstreamUnavailableProblem,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const DEGRADED_LINE = 'Catalog data may be out of date: PokéSearch is unavailable';
const SEEDED_LINE = 'Catalog: PokéSearch dataset 39a26a1, indexed 2026-10-11 (UTC)';
const HOLO_SOURCE =
  'TCGplayer product 88123 (Holofoil) via TCGCSV, mapped from the collected snapshot of 2026-10-11';
const REVERSE_SOURCE =
  'TCGplayer product 88124 (Reverse Holofoil) via TCGCSV, mapped from the collected snapshot of 2026-10-10';
const CATALOG_MISSING_LINE = 'This card is no longer in the PokéSearch catalog';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

/** Renders /card/ex10-%3F against one canned API answer and waits for the loaded heading. */
async function renderCard(answer: Response, settledHeading = 'Unown'): Promise<HTMLElement> {
  const text = await answer.text();
  const card = () => new Response(text, { status: answer.status, headers: answer.headers });
  stubFetch((url) =>
    isHistoryUrl(url) ? jsonResponse(historyFor(JSON.parse(text) as CardDetail)) : card(),
  );
  visit('/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: settledHeading });
  return screen.getByRole('main');
}

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  visit('/');
});

describe('CardPage', () => {
  it('fetches the encoded id and shows the seeded catalog line verbatim', async () => {
    const calls = stubFetch(() => jsonResponse(cardBody));
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    expect(calls.map((call) => call.url)).toEqual(['/v1/cards/ex10-%3F']);
    expect(screen.getByText(SEEDED_LINE)).toBeTruthy();
    expect(screen.queryByText('catalog version unknown')).toBeNull();
  });

  it('dates the catalog line by seeded_at in UTC, not by the offset it was written in', async () => {
    const lateEvening = {
      data: {
        ...unownCard,
        catalog: { ...unownCard.catalog, seeded_at: '2026-10-11T22:15:00-03:00' },
      },
      meta: { degraded: false },
    } satisfies CardDetail;
    await renderCard(jsonResponse(lateEvening));
    expect(
      screen.getByText('Catalog: PokéSearch dataset 39a26a1, indexed 2026-10-12 (UTC)'),
    ).toBeTruthy();
  });

  it('drops the date for a calendar day that does not exist instead of rolling it over', async () => {
    const impossibleDay = {
      data: {
        ...unownCard,
        catalog: { ...unownCard.catalog, seeded_at: '2026-02-30T10:00:00Z' },
      },
      meta: { degraded: false },
    } satisfies CardDetail;
    const main = await renderCard(jsonResponse(impossibleDay));
    expect(screen.getByText('Catalog: PokéSearch dataset 39a26a1')).toBeTruthy();
    expect(main.textContent).not.toContain('2026-03-02');
  });

  it('shows exactly "catalog version unknown" while seed_ref is null', async () => {
    const main = await renderCard(jsonResponse(unseededCardBody));
    expect(screen.getByText('catalog version unknown')).toBeTruthy();
    expect(main.textContent).not.toContain('Catalog: PokéSearch dataset');
  });

  it('shows one source line per mapped TCGplayer product, verbatim', async () => {
    await renderCard(jsonResponse(cardBody));
    const sources = screen.getAllByText(/^TCGplayer product /);
    expect(sources.map((line) => line.textContent)).toEqual([HOLO_SOURCE, REVERSE_SOURCE]);
    expect(screen.queryByText('Not yet mapped')).toBeNull();
    expect(screen.getByText('No prices yet')).toBeTruthy();
  });

  it('shows exactly "Not yet mapped" and "No prices yet" with no variant rows', async () => {
    const main = await renderCard(jsonResponse(unmappedCardBody));
    expect(screen.getByText('Not yet mapped')).toBeTruthy();
    expect(screen.getByText('No prices yet')).toBeTruthy();
    expect(screen.queryAllByText(/^TCGplayer product \d/)).toHaveLength(0);
    expect(main.textContent).not.toContain('via TCGCSV');
    expect(screen.getByText(SEEDED_LINE)).toBeTruthy();
  });

  it('shows the degraded line verbatim and never the stale_since cache time', async () => {
    const main = await renderCard(jsonResponse(degradedCardBody));
    expect(screen.getByText(DEGRADED_LINE)).toBeTruthy();
    expect(screen.getByText(SEEDED_LINE)).toBeTruthy();
    expect(screen.getByText('No prices yet')).toBeTruthy();
    expect(main.textContent).not.toContain('2026-10-12');
    expect(main.textContent).not.toContain('06:30');
  });

  it('omits the degraded line when meta.degraded is false', async () => {
    await renderCard(jsonResponse(cardBody));
    expect(screen.queryByText(DEGRADED_LINE)).toBeNull();
  });

  it('shows "Card not found" for a 404 card-not-found without catalog_missing', async () => {
    const main = await renderCard(problemResponse(cardNotFoundPlainProblem), 'Card not found');
    expect(main.textContent).not.toContain(CATALOG_MISSING_LINE);
    expect(main.textContent).not.toContain('No prices yet');
  });

  it('adds the catalog_missing line to "Card not found"', async () => {
    await renderCard(problemResponse(cardNotFoundProblem), 'Card not found');
    expect(screen.getByText(CATALOG_MISSING_LINE)).toBeTruthy();
  });

  it('shows the problem title and request id for any other failure', async () => {
    stubFetch(() => problemResponse(upstreamUnavailableProblem));
    visit('/card/ex10-%3F');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Upstream Unavailable');
    expect(alert.textContent).toContain('req-t46-upstream-0001');
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Card ex10-?');
  });

  it('names the card id and says it is loading while the request is in flight', () => {
    const calls = stubFetch(() => new Error('not answered before the assertions'));
    visit('/card/ex10-%3F');
    render(<App />);
    expect(calls).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Card ex10-?');
    expect(screen.getByRole('status').textContent).toBe('Loading card ex10-?…');
  });

  it('uses the API image URL verbatim with a one-line attribution for its source', async () => {
    const main = await renderCard(jsonResponse(cardBody));
    const image = screen.getByRole('img', { name: 'Unown, Unseen Forces No. ?' });
    expect(image.getAttribute('src')).toBe('https://images.example.com/ex10/question_hires.png');
    expect(main.querySelectorAll('img')).toHaveLength(1);
    const attribution = screen.getByText(/^Card image from images\.example\.com/);
    expect(attribution.textContent).not.toContain('\n');
  });

  it('never shows live, real-time, as of today, a 1D range, a render date or a pokemontcg.io date', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    const answers: readonly [Response, string, 'unpriced' | 'priced'][] = [
      [jsonResponse(cardBody), 'Unown', 'unpriced'],
      [jsonResponse(unseededCardBody), 'Unown', 'unpriced'],
      [jsonResponse(unmappedCardBody), 'Unown', 'unpriced'],
      [jsonResponse(degradedCardBody), 'Unown', 'unpriced'],
      [problemResponse(cardNotFoundProblem), 'Card not found', 'unpriced'],
      [jsonResponse(pricedCardBody), 'Unown', 'priced'],
      [
        jsonResponse(
          cardWithPrices(fallingPrice, unchangedPrice, firstClosePrice, upFromZeroPrice),
        ),
        'Unown',
        'priced',
      ],
    ];
    for (const [answer, settled, kind] of answers) {
      const main = await renderCard(answer, settled);
      const text = main.textContent;
      expect(text).not.toMatch(/\blive\b/i);
      expect(text).not.toMatch(/real-?time/i);
      expect(text).not.toMatch(/as of today/i);
      expect(text).not.toMatch(/\b1D\b/i);
      expect(text).not.toMatch(/\b24 ?h/i);
      expect(text).not.toContain('2031');
      expect(text).not.toContain('2005-08-22');
      if (kind === 'unpriced') {
        expect(text).not.toMatch(/\$|USD|€/);
      } else {
        // The P2 $ carve-out: a $ only opens a digit, on a Market price or change line.
        expect(text).not.toMatch(/USD|€/);
        expect(text).not.toContain('20:05');
        expect(text).toMatch(/\$\d/);
        expect(text).not.toMatch(/\$(?!\d)/);
        const dollarLines = Array.from(main.querySelectorAll('*'))
          .filter((node) => node.children.length === 0 && node.textContent.includes('$'))
          .map((node) => node.textContent);
        expect(dollarLines.length).toBeGreaterThan(0);
        for (const line of dollarLines) {
          expect(line).toMatch(
            /^Market price \$\d|^\$\d[\d,]*\.\d{2}$|^[▲▼] [+-]\$\d.* vs previous close \(|^[A-Z][^:]*, (?:1W|1M|3M|YTD|1Y): /,
          );
        }
      }
      cleanup();
      vi.unstubAllGlobals();
    }
  });
});
