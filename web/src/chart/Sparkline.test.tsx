import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import {
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
  upstreamUnavailableProblem,
} from '../test/fixtures';
import { resetRecorder } from '../test/lightweightChartsFake';
import Sparkline from './Sparkline';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const CARD_URL = '/v1/cards/ex10-%3F';
const HISTORY_1M = '/v1/cards/ex10-%3F/prices?range=1M';
const HOLO = 'ex10-?:holo:unlimited:-';
const REVERSE = 'ex10-?:reverse:unlimited:-';
const HOLO_ITEM =
  'holo, unlimitedMarket price $70.81▲ +$1.45 (+2.09%) vs previous close (2026-10-11)as of 2026-10-12 from TCGCSV (daily close)';
const REVERSE_ITEM = 'reverse, unlimitedNo price in the collected snapshots';
const ATTRIBUTION = 'Chart by TradingView Lightweight Charts';

const FORBIDDEN = [
  /\blive\b/i,
  /real-?time/i,
  /as of today/i,
  /\btoday\b/i,
  /\bnow\b/i,
  /\b1D\b/i,
  /\b24 ?h/i,
  /20:05/,
];

interface Shape {
  readonly tag: string;
  readonly points?: string | null;
  readonly dash?: string | null;
  readonly cx?: string | null;
  readonly cy?: string | null;
  readonly r?: string | null;
}

/** The drawn shapes of one sparkline, in document order, with their geometry attributes. */
function shapes(svg: Element): Shape[] {
  return Array.from(svg.children).map((child) =>
    child.tagName === 'circle'
      ? {
          tag: 'circle',
          cx: child.getAttribute('cx'),
          cy: child.getAttribute('cy'),
          r: child.getAttribute('r'),
        }
      : {
          tag: child.tagName,
          points: child.getAttribute('points'),
          dash: child.getAttribute('stroke-dasharray'),
        },
  );
}

function solid(points: string): Shape {
  return { tag: 'polyline', points, dash: null };
}

function dashed(points: string): Shape {
  return { tag: 'polyline', points, dash: '2 2' };
}

function svgOf(points: Parameters<typeof Sparkline>[0]['points']): Element {
  const { container } = render(<Sparkline points={points} />);
  const svg = container.querySelector('svg');
  if (svg === null) throw new Error('no sparkline drawn');
  return svg;
}

afterEach(() => {
  cleanup();
});

describe('Sparkline geometry (T07, D12)', () => {
  it('draws the worked example: a solid piece, then a dashed piece into the filled point', () => {
    const svg = svgOf([
      pointOf('2026-10-10', '1.00'),
      pointOf('2026-10-11', '3.00'),
      filledOf('2026-10-12', '2.00', '2026-10-11'),
    ]);
    expect(shapes(svg)).toEqual([solid('2.0,26.0 48.0,2.0'), dashed('48.0,2.0 94.0,14.0')]);
  });

  it('is a 96 x 28 hidden, unfocusable svg stroked in the chart-line colour', () => {
    const svg = svgOf([pointOf('2026-10-11', '1.00'), pointOf('2026-10-12', '3.00')]);
    expect(svg.getAttribute('viewBox')).toBe('0 0 96 28');
    expect(svg.getAttribute('width')).toBe('96');
    expect(svg.getAttribute('height')).toBe('28');
    expect(svg.getAttribute('aria-hidden')).toBe('true');
    expect(svg.getAttribute('focusable')).toBe('false');
    expect(svg.getAttribute('class')?.split(/\s+/)).toContain('text-chart-line');
    for (const child of Array.from(svg.children)) {
      expect(child.getAttribute('stroke')).toBe('currentColor');
      expect(child.getAttribute('fill')).toBe('none');
      expect(child.getAttribute('stroke-width')).toBe('1.5');
    }
  });

  it('breaks the line at a mapping change: two segments of two real points, two polylines', () => {
    const svg = svgOf([
      pointOf('2026-10-09', '1.00'),
      pointOf('2026-10-10', '2.00'),
      pointOf('2026-10-11', '3.00', { series_epoch: 2 }),
      pointOf('2026-10-12', '4.00', { series_epoch: 2 }),
    ]);
    expect(shapes(svg)).toEqual([solid('2.0,26.0 32.7,18.0'), solid('63.3,10.0 94.0,2.0')]);
  });

  it('draws two one-point segments (an epoch change between two real points) as two circles', () => {
    const svg = svgOf([
      pointOf('2026-10-11', '1.00'),
      pointOf('2026-10-12', '3.00', { series_epoch: 2 }),
    ]);
    expect(shapes(svg)).toEqual([
      { tag: 'circle', cx: '2.0', cy: '26.0', r: '2' },
      { tag: 'circle', cx: '94.0', cy: '2.0', r: '2' },
    ]);
  });

  it('draws a one-point series as one circle in the middle', () => {
    const svg = svgOf([pointOf('2026-10-12', '70.81')]);
    expect(shapes(svg)).toEqual([{ tag: 'circle', cx: '48.0', cy: '14.0', r: '2' }]);
  });

  it('draws a flat series at y 14.0', () => {
    const svg = svgOf([
      pointOf('2026-10-10', '5.00'),
      pointOf('2026-10-11', '5.00'),
      pointOf('2026-10-12', '5.00'),
    ]);
    expect(shapes(svg)).toEqual([solid('2.0,14.0 48.0,14.0 94.0,14.0')]);
  });

  it('draws two consecutive filled points as two dashed pieces', () => {
    const svg = svgOf([
      pointOf('2026-10-09', '1.00'),
      pointOf('2026-10-10', '2.00'),
      filledOf('2026-10-11', '2.00', '2026-10-10'),
      filledOf('2026-10-12', '2.00', '2026-10-10'),
    ]);
    expect(shapes(svg)).toEqual([
      solid('2.0,26.0 32.7,2.0'),
      dashed('32.7,2.0 63.3,2.0'),
      dashed('63.3,2.0 94.0,2.0'),
    ]);
  });

  it('starts a new solid run after a dashed piece, left to right', () => {
    const svg = svgOf([
      pointOf('2026-10-08', '1.00'),
      pointOf('2026-10-09', '3.00'),
      filledOf('2026-10-10', '3.00', '2026-10-09'),
      pointOf('2026-10-11', '2.00'),
      pointOf('2026-10-12', '1.00'),
    ]);
    expect(shapes(svg)).toEqual([
      solid('2.0,26.0 25.0,2.0'),
      dashed('25.0,2.0 48.0,2.0'),
      solid('48.0,2.0 71.0,14.0 94.0,26.0'),
    ]);
  });

  it('draws nothing for a series with no point', () => {
    const { container } = render(<Sparkline points={[]} />);
    expect(container.innerHTML).toBe('');
  });
});

describe('sparklines in the Prices list (T07)', () => {
  const holoPoints = [
    pointOf('2026-10-09', '928.32'),
    pointOf('2026-10-10', '928.06'),
    filledOf('2026-10-11', '928.06', '2026-10-10'),
    pointOf('2026-10-12', '968.91'),
  ];
  const history = historyBody([seriesOf(HOLO, holoPoints), seriesOf(REVERSE, [], null)]);

  beforeEach(() => {
    resetRecorder();
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
  });

  afterEach(() => {
    vi.useRealTimers();
    window.history.replaceState(null, '', '/');
  });

  function serve(reply: (url: string) => Response | Error, card: CardDetail = pricedCardBody) {
    const urls: string[] = [];
    stubFetch((url) => {
      urls.push(url);
      return isHistoryUrl(url) ? reply(url) : jsonResponse(card);
    });
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
    return urls;
  }

  function items(): HTMLElement[] {
    return within(screen.getByRole('region', { name: 'Prices' })).getAllByRole('listitem');
  }

  it('draws a sparkline beside the priced variant from the one 1M history request', async () => {
    const urls = serve(() => jsonResponse(history));
    const section = await screen.findByRole('region', { name: 'Price history' });
    await within(section).findByRole('link', { name: ATTRIBUTION });
    const [holo, reverse] = items();
    const svg = holo?.querySelector('svg');
    expect(svg).toBeTruthy();
    expect(shapes(svg ?? document.createElement('svg'))).toEqual([
      solid('2.0,25.8 32.7,26.0'),
      dashed('32.7,26.0 63.3,26.0'),
      solid('63.3,26.0 94.0,2.0'),
    ]);
    const price = within(holo ?? document.body).getByText('Market price $70.81');
    expect(price.compareDocumentPosition(svg ?? price) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    );
    expect(reverse?.querySelector('svg')).toBeNull();
    expect(holo?.textContent).toBe(HOLO_ITEM);
    expect(reverse?.textContent).toBe(REVERSE_ITEM);
    expect(urls).toEqual([CARD_URL, HISTORY_1M]);
  });

  it('keeps the 1M sparkline when the chart shows another range', async () => {
    const urls = serve((url) =>
      jsonResponse(url.endsWith('=3M') ? historyBody([seriesOf(HOLO, [], '2026-10-09')]) : history),
    );
    const section = await screen.findByRole('region', { name: 'Price history' });
    await within(section).findByRole('link', { name: ATTRIBUTION });
    const before = screen.getByRole('region', { name: 'Prices' }).innerHTML;
    fireEvent.click(within(section).getByRole('button', { name: '3M' }));
    await within(section).findByText(/^No daily close for this variant between /);
    expect(urls).toEqual([CARD_URL, HISTORY_1M, '/v1/cards/ex10-%3F/prices?range=3M']);
    expect(screen.getByRole('region', { name: 'Prices' }).innerHTML).toBe(before);
    expect(items()[0]?.querySelector('svg')).toBeTruthy();
  });

  it('shows no sparkline while the history loads, and the Prices text is unchanged', async () => {
    vi.stubGlobal('fetch', (input: string | URL): Promise<Response> =>
      isHistoryUrl(String(input))
        ? new Promise<Response>(() => undefined)
        : Promise.resolve(jsonResponse(pricedCardBody)),
    );
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
    await screen.findByText('Loading price history…');
    expect(screen.getByRole('region', { name: 'Prices' }).querySelector('svg')).toBeNull();
    expect(items().map((item) => item.textContent)).toEqual([HOLO_ITEM, REVERSE_ITEM]);
    vi.unstubAllGlobals();
  });

  it('shows no sparkline when the history fails, and the Prices text is unchanged', async () => {
    serve(() => problemResponse(upstreamUnavailableProblem));
    await screen.findByText(/^Could not load the price history/);
    expect(screen.getByRole('region', { name: 'Prices' }).querySelector('svg')).toBeNull();
    expect(items().map((item) => item.textContent)).toEqual([HOLO_ITEM, REVERSE_ITEM]);
  });

  it('shows no sparkline for a priced variant whose series has no point', async () => {
    const bothPriced = {
      data: {
        ...pricedUnownCard,
        variants: pricedUnownCard.variants.map((variant) => ({ ...variant, price: risingPrice })),
      },
      meta: { degraded: false },
    } satisfies CardDetail;
    serve(
      () => jsonResponse(historyBody([seriesOf(HOLO, holoPoints), seriesOf(REVERSE, [], null)])),
      bothPriced,
    );
    const section = await screen.findByRole('region', { name: 'Price history' });
    await within(section).findByRole('link', { name: ATTRIBUTION });
    const [holo, reverse] = items();
    expect(holo?.querySelector('svg')).toBeTruthy();
    expect(reverse?.querySelector('svg')).toBeNull();
  });

  it('shows no sparkline for a history answer of an unexpected shape', async () => {
    serve(() =>
      jsonResponse({
        data: [{ variant_key: HOLO, points: [{ day: '2026-10-12', close: 'n/a' }] }],
        meta: {},
      }),
    );
    await screen.findByText('No daily close is held for this card yet.');
    expect(screen.getByRole('region', { name: 'Prices' }).querySelector('svg')).toBeNull();
    expect(items().map((item) => item.textContent)).toEqual([HOLO_ITEM, REVERSE_ITEM]);
  });

  it('never shows a forbidden word or the faked year over a priced card with chart and sparklines (D1, D14)', async () => {
    serve(() => jsonResponse(history));
    const section = await screen.findByRole('region', { name: 'Price history' });
    await within(section).findByRole('link', { name: ATTRIBUTION });
    fireEvent.click(within(section).getByRole('button', { name: 'Show data table' }));
    expect(items()[0]?.querySelector('svg')).toBeTruthy();
    const text = screen.getByRole('main').textContent;
    for (const pattern of FORBIDDEN) expect(text).not.toMatch(pattern);
    expect(text).not.toContain('2031');
    expect(document.body.innerHTML).not.toContain('2031');
  });
});
