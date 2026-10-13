import { describe, expect, it } from 'vitest';

// R203a: the production-source scan may excuse only the one quoted literal D6 requires, so any
// other tradingview URL (a path, a query, another scheme or host) still fails it.
const EXACT = 'href="https://www.tradingview.com/"';
const VARIANTS = [
  'href="https://www.tradingview.com/foo"',
  'href="https://www.tradingview.com/?utm=1"',
  'href="https://www.tradingview.com/#top"',
  'href="http://www.tradingview.com/"',
  'href="https://www.tradingview.com.evil.example/"',
  "href='https://www.tradingview.com/'",
];

/** The carve-out as the implementation first applied it (a bare substring). */
const bare = (text: string): boolean =>
  /https?:\/\//.test(text.replaceAll('https://www.tradingview.com/', ''));
/** The carve-out R203a prescribes (the quoted exact literal only). */
const tight = (text: string): boolean =>
  /https?:\/\//.test(text.replaceAll('"https://www.tradingview.com/"', ''));

const history = import.meta.glob<string>('../pages/PriceHistory.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
});

describe('the tradingview carve-out of the production-source scan (R203a)', () => {
  it('excuses the exact quoted literal and flags every variant under the tight form', () => {
    expect(tight(EXACT)).toBe(false);
    for (const variant of VARIANTS)
      expect({ variant, flagged: tight(variant) }).toEqual({ variant, flagged: true });
  });

  it('shows the bare substring form lets a path-suffixed URL through (the hole)', () => {
    expect(bare('href="https://www.tradingview.com/foo"')).toBe(false);
    expect(bare('href="https://www.tradingview.com/?utm=1"')).toBe(false);
    expect(tight('href="https://www.tradingview.com/foo"')).toBe(true);
  });

  it('still passes the real attribution link under the tight form', () => {
    const text = Object.values(history)[0] ?? '';
    expect(text).toContain(EXACT);
    expect(tight(text)).toBe(false);
  });
});
