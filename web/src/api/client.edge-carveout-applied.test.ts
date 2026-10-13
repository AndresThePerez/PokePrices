import { describe, expect, it } from 'vitest';

const own = import.meta.glob<string>('./client.test.ts', {
  query: '?raw',
  import: 'default',
  eager: true,
});

describe('the production-source scan in client.test.ts (R203a)', () => {
  it('excuses the quoted tradingview literal only, not the bare substring', () => {
    const text = Object.values(own)[0] ?? '';
    expect(text).toContain(`replaceAll('"https://www.tradingview.com/"', '')`);
    expect(text).not.toContain(`replaceAll('https://www.tradingview.com/', '')`);
  });
});
