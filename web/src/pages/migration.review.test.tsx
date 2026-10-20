import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import {
  historyBody,
  isHistoryUrl,
  jsonResponse,
  moversBody,
  pointOf,
  pricedUnownCard,
  risingMover,
  risingPrice,
  searchPage,
  seriesOf,
  stubFetch,
  unownQuestion,
  cardBody,
  unownCard,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake.
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const CHECK = /[✓✓✔]/u;
const HOLO = 'ex10-?:holo:unlimited:-';
const REVERSE = 'ex10-?:reverse:unlimited:-';

const twoPricedCard = {
  data: {
    ...pricedUnownCard,
    variants: pricedUnownCard.variants.map((variant) => ({ ...variant, price: risingPrice })),
  },
  meta: { degraded: false },
} satisfies CardDetail;

const twoSeries = historyBody([
  seriesOf(HOLO, [pointOf('2026-10-11', '928.06'), pointOf('2026-10-12', '1234.50')]),
  seriesOf(REVERSE, [pointOf('2026-10-12', '70.81')], '2026-09-01'),
]);

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function classesOf(element: Element): string[] {
  return element.className.split(/\s+/);
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.unstubAllGlobals();
  visit('/');
});

describe('T07 migration invariants', () => {
  it('never puts the selected check in any button text on the card page, in any state', async () => {
    stubFetch((url) => (isHistoryUrl(url) ? jsonResponse(twoSeries) : jsonResponse(twoPricedCard)));
    visit('/card/ex10-%3F');
    render(<App />);
    const section = await screen.findByRole('region', { name: 'Price history' });
    await within(section).findByText(/^as of /);
    const ranges = within(within(section).getByRole('group', { name: 'Chart range' })).getAllByRole(
      'button',
    );
    expect(ranges.map((button) => button.textContent)).toEqual(['1W', '1M', '3M', 'YTD', '1Y']);
    const variants = within(within(section).getByRole('group', { name: 'Variant' })).getAllByRole(
      'button',
    );
    expect(variants).toHaveLength(2);
    const noCheck = () => {
      for (const button of screen.getAllByRole('button')) {
        expect(button.textContent).not.toMatch(CHECK);
      }
    };
    noCheck();
    fireEvent.click(variants[1] as HTMLElement);
    fireEvent.click(within(section).getByRole('button', { name: /data table/ }));
    noCheck();
    // Exactly one pressed segment per group, whatever the click order.
    for (const name of ['Chart range', 'Variant']) {
      const group = within(section).getByRole('group', { name });
      expect(within(group).getAllByRole('button', { pressed: true })).toHaveLength(1);
    }
    fireEvent.click(ranges[2] as HTMLElement);
    noCheck();
  });

  it('keeps the movers Direction group at two segments, Rises and Falls, one pressed, no check text', async () => {
    stubFetch(() => jsonResponse(moversBody('up', [risingMover])));
    visit('/movers');
    render(<App />);
    await screen.findByText('Market price $26.34');
    const group = screen.getByRole('group', { name: 'Direction' });
    const buttons = within(group).getAllByRole('button');
    expect(buttons.map((button) => button.textContent)).toEqual(['Rises', 'Falls']);
    expect(buttons.map((button) => button.getAttribute('type'))).toEqual(['button', 'button']);
    expect(within(group).getAllByRole('button', { pressed: true })).toHaveLength(1);
    for (const button of buttons) expect(button.textContent).not.toMatch(CHECK);
  });

  it('keeps the search form: searchbox Card name, one submit button named Search, 44 px pager links', async () => {
    const many = {
      data: [unownQuestion],
      meta: { total: 100, page: 2, pages: 4, page_size: 24, degraded: false, cached: false },
    };
    stubFetch(() => jsonResponse(many));
    visit('/search?q=unown&page=2');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const form = screen.getByRole('search');
    const box = within(form).getByRole('searchbox', { name: 'Card name' });
    expect((box as HTMLInputElement).value).toBe('unown');
    const submits = form.querySelectorAll('button[type=submit], input[type=submit]');
    expect(submits).toHaveLength(1);
    expect(submits[0]?.tagName).toBe('BUTTON');
    expect(submits[0]?.textContent).toBe('Search');
    expect(within(form).getByRole('button', { name: 'Search' })).toBe(submits[0]);
    const pager = screen.getByRole('navigation', { name: 'Result pages' });
    const links = within(pager).getAllByRole('link');
    expect(links.map((link) => link.textContent)).toEqual(['Previous page', 'Next page']);
    for (const link of links) expect(classesOf(link)).toContain('min-h-11');
  });

  it('keeps exactly one img per search result, alt-empty, lazy, with the API src and no frame sibling', async () => {
    stubFetch(() => jsonResponse(searchPage));
    visit('/search?q=unown');
    render(<App />);
    const list = await screen.findByRole('list', { name: 'Search results' });
    const items = within(list).getAllByRole('listitem');
    expect(items).toHaveLength(searchPage.data.length);
    items.forEach((item, index) => {
      const images = item.querySelectorAll('img');
      expect(images).toHaveLength(1);
      expect(images[0]?.getAttribute('src')).toBe(searchPage.data[index]?.image_small);
      expect(images[0]?.getAttribute('loading')).toBe('lazy');
      expect(images[0]?.getAttribute('alt')).toBe('');
    });
    expect(list.querySelectorAll('img')).toHaveLength(searchPage.data.length);
  });

  it('keeps the card image as the only img in its figure with alt, src, size and caption in order', async () => {
    stubFetch((url) => (isHistoryUrl(url) ? jsonResponse(twoSeries) : jsonResponse(cardBody)));
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    const images = screen.getByRole('main').querySelectorAll('img');
    expect(images).toHaveLength(1);
    const image = images[0] as HTMLImageElement;
    expect(image.getAttribute('alt')).toBe('Unown, Unseen Forces No. ?');
    expect(image.getAttribute('src')).toBe(unownCard.images.large);
    expect(image.getAttribute('width')).toBe('245');
    expect(image.getAttribute('height')).toBe('342');
    const figure = image.parentElement as HTMLElement;
    expect(figure.tagName).toBe('FIGURE');
    expect(Array.from(figure.children).map((child) => child.tagName)).toEqual([
      'IMG',
      'FIGCAPTION',
    ]);
    expect(figure.querySelector('figcaption')?.textContent).toBe(
      'Card image from images.example.com, linked as the PokéSearch catalog lists it.',
    );
  });

  it('keeps the not-found page: one h1, one home link with the exact text and a 44 px target', () => {
    visit('/no/such/page');
    render(<App />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Page not found');
    const links = within(screen.getByRole('main')).getAllByRole('link');
    expect(links).toHaveLength(1);
    expect(links[0]?.textContent).toBe('Go to the home page');
    expect(links[0]?.getAttribute('href')).toBe('/');
    expect(classesOf(links[0] as Element)).toContain('min-h-11');
  });

  it('right-aligns only the Close column of the data table, header and cells', async () => {
    stubFetch((url) => (isHistoryUrl(url) ? jsonResponse(twoSeries) : jsonResponse(twoPricedCard)));
    visit('/card/ex10-%3F');
    render(<App />);
    const section = await screen.findByRole('region', { name: 'Price history' });
    await within(section).findByText(/^as of /);
    await act(async () => {
      fireEvent.click(within(section).getByRole('button', { name: 'Show data table' }));
      await Promise.resolve();
    });
    const table = within(section).getByRole('table');
    const aligned = (cell: Element) => classesOf(cell).includes('text-right');
    expect(within(table).getAllByRole('columnheader').map(aligned)).toEqual([false, true, false]);
    const rows = within(table).getAllByRole('row').slice(1);
    expect(rows.length).toBeGreaterThan(0);
    for (const row of rows) {
      expect(within(row).getAllByRole('cell').map(aligned)).toEqual([false, true, false]);
    }
  });
});
