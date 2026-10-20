import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { PriceLines } from '../account/ListParts';
import Sparkline from '../chart/Sparkline';
import { MoversStamp } from '../pages/MoverList';
import { pointOf } from '../test/fixtures';
import PriceTag, { AsOf, ChangeChip, PriceFigure, type PriceTagFields } from './PriceTag';

afterEach(() => {
  cleanup();
});

const AS_OF = 'as of 2026-10-12 from TCGCSV (daily close)';
const PRICE_LINE = 'Market price $2,257.87';

const UP: PriceTagFields = {
  close: '2257.87',
  day: '2026-10-12',
  previous_close: '2235.80',
  previous_day: '2026-10-11',
  change: '22.07',
  change_pct: '0.99',
};
const UP_LINE = '▲ +$22.07 (+0.99%) vs previous close (2026-10-11)';
const DOWN: PriceTagFields = { ...UP, change: '-22.07', change_pct: '-0.98' };
const DOWN_LINE = '▼ -$22.07 (-0.98%) vs previous close (2026-10-11)';
const FLAT: PriceTagFields = { ...UP, change: '0.00', change_pct: '0.00' };
const FIRST: PriceTagFields = {
  ...UP,
  previous_close: null,
  previous_day: null,
  change: null,
  change_pct: null,
};

const POINTS = [
  pointOf('2026-10-09', '2200.00'),
  pointOf('2026-10-10', '2210.00'),
  pointOf('2026-10-12', '2257.87'),
];

/** The variant `li` of the card page: a label span first, then the primitive (CardPage.tsx). */
function variantItem(price: PriceTagFields, withSparkline: boolean) {
  return render(
    <ul>
      <li>
        <span className="block font-semibold capitalize">finish0, unlimited</span>
        <PriceTag
          price={price}
          size="variant"
          beside={withSparkline ? <Sparkline points={POINTS} /> : undefined}
        />
      </li>
    </ul>,
  );
}

describe('PriceTag on the card page variant li (review)', () => {
  it.each([
    ['up', UP, UP_LINE],
    ['down', DOWN, DOWN_LINE],
    ['no change', FLAT, 'No change vs previous close (2026-10-11)'],
    ['no previous close', FIRST, 'No previous close yet'],
    ['no pct', { ...UP, change_pct: null }, '▲ +$22.07 vs previous close (2026-10-11)'],
  ])(
    'keeps the li text as label, price, change, as-of and the change as the 3rd span, with a sparkline beside (%s)',
    (_name, price, change) => {
      const { container } = variantItem(price, true);
      const item = container.querySelector('li');
      expect(item?.querySelector('svg')).not.toBeNull();
      expect(item?.textContent).toBe(`finish0, unlimitedMarket price $2,257.87${change}${AS_OF}`);
      const spans = Array.from(item?.querySelectorAll('span') ?? []);
      expect(spans.map((span) => span.textContent)).toEqual([
        'finish0, unlimited',
        PRICE_LINE,
        change,
        AS_OF,
      ]);
      expect(item?.querySelector('[aria-hidden]:not(svg)')).toBeNull();
    },
  );
});

describe('PriceTag beside slot (review)', () => {
  it('emits no div at all without a beside slot, and nothing for an absent slot value', () => {
    const none = render(<PriceTag price={UP} size="variant" />);
    expect(none.container.querySelector('div')).toBeNull();
    none.unmount();
    const empty = render(<PriceTag price={UP} size="variant" beside={undefined} />);
    expect(empty.container.querySelector('div')).toBeNull();
    expect(empty.container.textContent).toBe(`${PRICE_LINE}${UP_LINE}${AS_OF}`);
  });

  it('puts only the price span and the slot in the div; the change and as-of spans stay outside it', () => {
    const { container } = variantItem(UP, true);
    const holders = container.querySelectorAll('div');
    expect(holders).toHaveLength(1);
    const holder = holders[0];
    expect(Array.from(holder?.children ?? []).map((child) => child.tagName)).toEqual([
      'SPAN',
      'svg',
    ]);
    const outside = Array.from(container.querySelectorAll('li > span')).map(
      (span) => span.textContent,
    );
    expect(outside).toEqual(['finish0, unlimited', UP_LINE, AS_OF]);
  });
});

describe('PriceTag hostile API strings (review)', () => {
  const HOSTILE = [
    '<img src=x onerror=alert(1)>',
    '&amp;&lt;b&gt;',
    '‮evil‬',
    '9'.repeat(400) + 'x',
    '</span><span>',
  ];

  it.each(HOSTILE)('prints %j as text in the price, change and as-of lines', (hostile) => {
    const price: PriceTagFields = {
      ...UP,
      close: hostile,
      day: hostile,
      previous_day: hostile,
    };
    const { container } = render(<PriceTag price={price} size="row" />);
    expect(container.querySelectorAll('img, b')).toHaveLength(0);
    expect(Array.from(container.querySelectorAll('*')).map((node) => node.tagName)).toEqual([
      'SPAN',
      'SPAN',
      'SPAN',
    ]);
    const [figure, change, asOf] = Array.from(container.querySelectorAll('span'));
    expect(figure?.textContent).toBe(`Market price $${hostile}`);
    expect(change?.textContent).toBe(`▲ +$22.07 (+0.99%) vs previous close (${hostile})`);
    expect(asOf?.textContent).toBe(`as of ${hostile} from TCGCSV (daily close)`);
    for (const span of [figure, change, asOf]) expect(span?.childNodes).toHaveLength(1);
  });
});

describe('AsOf variants (review)', () => {
  it('prints the same bytes as a span and as a paragraph, whatever className is passed', () => {
    const texts: string[] = [];
    for (const as of ['span', 'p'] as const) {
      for (const className of [undefined, '', 'mt-2', 'mt-2 text-down "quoted" > <b>']) {
        const { container, unmount } = render(
          <AsOf day="2026-10-12" as={as} className={className} />,
        );
        expect(container.children).toHaveLength(1);
        expect(container.firstElementChild?.tagName).toBe(as.toUpperCase());
        expect(container.firstElementChild?.childNodes).toHaveLength(1);
        texts.push(container.firstElementChild?.textContent ?? '');
        unmount();
      }
    }
    expect(new Set(texts)).toEqual(new Set([AS_OF]));
  });

  it('renders the MoversStamp paragraph with the exact string, and nothing for no day', () => {
    const stamp = render(<MoversStamp day="2026-10-12" />);
    expect(stamp.container.querySelector('p')?.textContent).toBe(AS_OF);
    expect(stamp.container.children).toHaveLength(1);
    stamp.unmount();
    const none = render(<MoversStamp day={null} />);
    expect(none.container.innerHTML).toBe('');
  });
});

describe('PriceTag tones and silence (review)', () => {
  it('uses text-up, text-down and text-flat on the change element, with no tint on flat states and no motion anywhere', () => {
    const cases: readonly [PriceTagFields, string][] = [
      [UP, 'text-up'],
      [DOWN, 'text-down'],
      [FLAT, 'text-flat'],
      [FIRST, 'text-flat'],
    ];
    for (const [price, tone] of cases) {
      const { container, unmount } = render(<PriceTag price={price} size="variant" />);
      const change = container.querySelectorAll('span')[1];
      expect(change?.classList.contains(tone)).toBe(true);
      for (const other of ['text-up', 'text-down', 'text-flat']) {
        if (other !== tone) expect(change?.classList.contains(other)).toBe(false);
      }
      if (tone === 'text-flat') expect(change?.className).not.toMatch(/(^|\s)bg-/);
      expect(change?.className).not.toMatch(/emerald|red-|slate-|gray-/);
      expect(container.innerHTML).not.toMatch(/transition|animate-|marquee/);
      unmount();
    }
  });

  it('adds no label, symbol, tooltip or hidden element: the parts print exactly the PriceTag text', () => {
    for (const price of [UP, DOWN, FLAT, FIRST]) {
      const whole = render(<PriceTag price={price} size="row" />);
      const parts = render(
        <>
          <PriceFigure close={price.close} size="row" />
          <ChangeChip price={price} />
          <AsOf day={price.day} />
        </>,
      );
      expect(parts.container.textContent).toBe(whole.container.textContent);
      for (const { container } of [whole, parts]) {
        expect(
          container.querySelector(
            '[aria-hidden], [aria-label], [aria-describedby], [title], [role], [data-tooltip], svg, img',
          ),
        ).toBeNull();
      }
      whole.unmount();
      parts.unmount();
    }
  });
});

describe('PriceLines on watchlist and portfolio rows (review)', () => {
  it('prints a missing price as one span with exactly the given line and nothing else', () => {
    const line = 'No price in the collected snapshots; not counted in the total.';
    const { container } = render(<PriceLines price={null} missing={line} />);
    expect(container.children).toHaveLength(1);
    expect(container.firstElementChild?.tagName).toBe('SPAN');
    expect(container.firstElementChild?.childNodes).toHaveLength(1);
    expect(container.textContent).toBe(line);
  });

  it('prints a price as the same three lines as PriceTag, with no extra text', () => {
    const rows = render(<PriceLines price={UP} missing="unused" />);
    const tag = render(<PriceTag price={UP} size="row" />);
    expect(rows.container.innerHTML).toBe(tag.container.innerHTML);
    expect(rows.container.textContent).toBe(`${PRICE_LINE}${UP_LINE}${AS_OF}`);
  });
});
