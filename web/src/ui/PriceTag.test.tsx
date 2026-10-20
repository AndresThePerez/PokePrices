import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import PriceTag, { AsOf, type PriceTagFields, type PriceTagSize } from './PriceTag';

afterEach(() => {
  cleanup();
});

const SIZES: readonly PriceTagSize[] = ['variant', 'row'];
const AS_OF = 'as of 2026-10-12 from TCGCSV (daily close)';
const PRICE_LINE = 'Market price $2,257.87';

const BASE: PriceTagFields = {
  close: '2257.87',
  day: '2026-10-12',
  previous_close: '2235.80',
  previous_day: '2026-10-11',
  change: '22.07',
  change_pct: '0.99',
};

/** Each change state of changeLine() and the exact change string it must print. */
const STATES: readonly {
  readonly name: string;
  readonly price: PriceTagFields;
  readonly change: string;
}[] = [
  { name: 'up', price: BASE, change: '▲ +$22.07 (+0.99%) vs previous close (2026-10-11)' },
  {
    name: 'down',
    price: { ...BASE, change: '-1234.50', change_pct: '-35.36' },
    change: '▼ -$1,234.50 (-35.36%) vs previous close (2026-10-11)',
  },
  {
    name: 'no change',
    price: { ...BASE, change: '0.00', change_pct: '0.00' },
    change: 'No change vs previous close (2026-10-11)',
  },
  {
    name: 'no previous close',
    price: { ...BASE, previous_close: null, previous_day: null, change: null, change_pct: null },
    change: 'No previous close yet',
  },
  {
    name: 'no pct',
    price: { ...BASE, change: '-3.10', change_pct: null },
    change: '▼ -$3.10 vs previous close (2026-10-11)',
  },
];

/** Every element that carries text of its own, in document order, with that own text. */
function ownTexts(root: Element): { readonly element: Element; readonly text: string }[] {
  const found: { element: Element; text: string }[] = [];
  for (const element of Array.from(root.querySelectorAll('*'))) {
    const text = Array.from(element.childNodes)
      .filter((node) => node.nodeType === Node.TEXT_NODE)
      .map((node) => node.textContent ?? '')
      .join('');
    if (text.trim() !== '') found.push({ element, text });
  }
  return found;
}

describe('PriceTag (D1 primitive)', () => {
  for (const size of SIZES) {
    for (const state of STATES) {
      it(`${size}, ${state.name}: price, change and as-of lines are one element each with the pinned string, in order, and nothing else`, () => {
        const { container } = render(<PriceTag price={state.price} size={size} />);
        const lines = ownTexts(container);
        expect(lines.map((line) => line.text)).toEqual([PRICE_LINE, state.change, AS_OF]);
        for (const line of lines) {
          expect(line.element.childNodes).toHaveLength(1);
          expect(line.element.textContent).toBe(line.text);
        }
        expect(container.textContent).toBe(`${PRICE_LINE}${state.change}${AS_OF}`);
        expect(container.querySelector('[aria-hidden]')).toBeNull();
        expect(container.querySelector('[role="presentation"], [role="none"]')).toBeNull();
      });
    }
  }

  it('renders the three lines as spans: price, change, as-of', () => {
    const { container } = render(<PriceTag price={BASE} size="variant" />);
    expect(Array.from(container.querySelectorAll('span')).map((span) => span.textContent)).toEqual([
      PRICE_LINE,
      STATES[0]?.change,
      AS_OF,
    ]);
  });

  it('puts the beside slot in a div next to the price span, with no wrapper span before the change', () => {
    const { container } = render(
      <PriceTag price={BASE} size="variant" beside={<svg data-testid="beside-probe" />} />,
    );
    const beside = container.querySelector('[data-testid="beside-probe"]');
    expect(beside).not.toBeNull();
    const holder = beside?.parentElement;
    expect(holder?.tagName).toBe('DIV');
    const priceSpan = holder?.querySelector('span');
    expect(priceSpan?.textContent).toBe(PRICE_LINE);
    expect(priceSpan?.nextElementSibling).toBe(beside);
    const spans = Array.from(container.querySelectorAll('span'));
    expect(spans.map((span) => span.textContent)).toEqual([PRICE_LINE, STATES[0]?.change, AS_OF]);
    expect(container.textContent).toBe(`${PRICE_LINE}${STATES[0]?.change ?? ''}${AS_OF}`);
  });

  it('keeps markup in the API strings as text, never as elements', () => {
    const hostile = '<b>1</b>';
    const { container } = render(
      <PriceTag price={{ ...BASE, close: hostile, day: hostile }} size="row" />,
    );
    expect(container.querySelector('b')).toBeNull();
    expect(container.textContent).toContain(`Market price $${hostile}`);
    expect(container.textContent).toContain(`as of ${hostile} from TCGCSV (daily close)`);
  });
});

describe('AsOf (D1 primitive)', () => {
  it('prints the pinned as-of string as one text node of one element', () => {
    const { container } = render(<AsOf day="2026-10-12" />);
    const lines = ownTexts(container);
    expect(lines.map((line) => line.text)).toEqual([AS_OF]);
    expect(lines[0]?.element.childNodes).toHaveLength(1);
    expect(container.textContent).toBe(AS_OF);
  });

  it('renders as a paragraph when asked, with the same text', () => {
    const { container } = render(<AsOf day="2026-10-12" as="p" />);
    expect(container.firstElementChild?.tagName).toBe('P');
    expect(container.textContent).toBe(AS_OF);
  });
});
