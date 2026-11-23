// The card page's image and listing links: the image offers the small picture first and the large
// one only for a slot or a pixel density that needs it, in a box of a fixed size, and turns into the
// shared card back when it fails; each mapped TCGplayer product links to its listing in a new tab.
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { Card, CardDetail } from '../api/client';
import {
  FORBIDDEN_WORDS,
  cardBody,
  historyFor,
  isHistoryUrl,
  jsonResponse,
  pricedCardBody,
  stubFetch,
  unownCard,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const SMALL = 'https://images.example.com/ex10/question.png';
const LARGE = 'https://images.example.com/ex10/question_hires.png';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  visit('/');
});

async function show(body: CardDetail): Promise<HTMLElement> {
  const calls = stubFetch((url) =>
    isHistoryUrl(url) ? jsonResponse(historyFor(body)) : jsonResponse(body),
  );
  visit('/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: body.data.name });
  expect(calls.length).toBeGreaterThan(0);
  return screen.getByRole('main');
}

function withCard(fields: Partial<Card>): CardDetail {
  return { data: { ...unownCard, ...fields }, meta: { degraded: false } };
}

function onlyImage(main: HTMLElement): HTMLImageElement {
  const images = main.querySelectorAll('img');
  expect(images).toHaveLength(1);
  return images[0] as HTMLImageElement;
}

describe('the card image sizes', () => {
  it('offers the small image first, the large one by width, sizes for the slot, and a fixed box', async () => {
    const image = onlyImage(await show(cardBody));
    expect(image.getAttribute('srcset')).toBe(`${SMALL} 240w, ${LARGE} 600w`);
    expect(image.getAttribute('sizes')).toBe('(min-width: 640px) 222px, 94px');
    expect(image.getAttribute('width')).toBe('245');
    expect(image.getAttribute('height')).toBe('342');
    expect(image.getAttribute('fetchpriority')).toBe('high');
    expect(image.hasAttribute('loading')).toBe(false);
  });

  it('writes spaces and commas in an address so they cannot split the srcset', async () => {
    const image = onlyImage(
      await show(
        withCard({
          images: {
            small: 'https://images.example.com/a b,c.png',
            large: 'https://images.example.com/a b,c_hires.png',
          },
        }),
      ),
    );
    expect(image.getAttribute('srcset')).toBe(
      'https://images.example.com/a%20b%2Cc.png 240w, https://images.example.com/a%20b%2Cc_hires.png 600w',
    );
  });

  it('gives a card with one image, or the same address twice, no srcset and no sizes', async () => {
    for (const images of [
      { small: SMALL, large: '' },
      { small: '', large: LARGE },
      { small: LARGE, large: LARGE },
    ]) {
      const image = onlyImage(await show(withCard({ images })));
      expect(image.hasAttribute('srcset')).toBe(false);
      expect(image.hasAttribute('sizes')).toBe(false);
      expect(image.getAttribute('width')).toBe('245');
      expect(image.getAttribute('height')).toBe('342');
      cleanup();
    }
  });
});

describe('a card image that fails to load', () => {
  it('turns into the shared card back with the initial, and stays there on a second error', async () => {
    const main = await show(cardBody);
    const image = onlyImage(main);
    fireEvent.error(image);
    expect(main.querySelectorAll('img')).toHaveLength(0);
    const backs = main.querySelectorAll('[data-initial]');
    expect(backs).toHaveLength(1);
    expect(backs[0]?.getAttribute('data-initial')).toBe('U');
    expect(backs[0]?.textContent).toBe('');
    fireEvent.error(image);
    expect(main.querySelectorAll('img')).toHaveLength(0);
    expect(main.querySelectorAll('[data-initial]')).toHaveLength(1);
  });
});

describe('the TCGplayer listing links', () => {
  it('links each mapped product to its plain product page, in a new tab, named for where it goes', async () => {
    const main = await show(pricedCardBody);
    const sources = within(main).getByRole('region', { name: 'Sources' });
    const links = within(sources).getAllByRole('link', { name: /on TCGplayer/ });
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      'https://www.tcgplayer.com/product/88123',
      'https://www.tcgplayer.com/product/88124',
    ]);
    expect(links.map((link) => link.textContent)).toEqual([
      'Product 88123 on TCGplayer (opens in a new tab)',
      'Product 88124 on TCGplayer (opens in a new tab)',
    ]);
    for (const link of links) {
      expect(link.getAttribute('target')).toBe('_blank');
      expect((link.getAttribute('rel') ?? '').split(/\s+/)).toContain('noopener');
      expect(link.getAttribute('href')).not.toMatch(/[?#]/);
    }
    for (const pattern of FORBIDDEN_WORDS) expect(main.textContent).not.toMatch(pattern);
  });

  it('shows no link for a variant with no product, nor for a product id that is not a positive whole number', async () => {
    const [holo, reverse] = unownCard.variants;
    const source = holo?.sources[0];
    if (holo === undefined || reverse === undefined || source === undefined) {
      throw new Error('the fixture lost a variant');
    }
    const main = await show(
      withCard({
        variants: [
          { ...holo, sources: [] },
          {
            ...reverse,
            sources: [
              { ...source, product_id: 0 },
              { ...source, product_id: 1.5 },
              { ...source, product_id: Number.MAX_SAFE_INTEGER + 2 },
            ],
          },
        ],
      }),
    );
    const sources = within(main).getByRole('region', { name: 'Sources' });
    expect(within(sources).queryAllByRole('link')).toHaveLength(0);
    expect(main.querySelectorAll('a[href*="tcgplayer"]')).toHaveLength(0);
  });
});
