import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App';
import {
  cardBody,
  cardNotFoundProblem,
  degradedCardBody,
  degradedSearchPage,
  historyFor,
  isHistoryUrl,
  jsonResponse,
  pricedCardBody,
  problemResponse,
  searchPage,
  stubFetch,
  upstreamUnavailableProblem,
} from './test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('./test/lightweightChartsFake'));
import type { CardSearchPage } from './api/client';

const ROUTES = ['/', '/search?q=unown', '/card/ex10-%3F', '/nowhere'] as const;

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

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function header(): HTMLElement {
  return screen.getByRole('banner');
}

function options(select: HTMLElement): [string, string][] {
  return Array.from(select.querySelectorAll('option')).map((option) => [
    option.value,
    option.textContent,
  ]);
}

beforeEach(() => {
  window.localStorage.clear();
  document.documentElement.removeAttribute('data-theme');
  document.documentElement.removeAttribute('data-palette');
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  window.localStorage.clear();
  visit('/');
});

describe('Header (T02 criterion 1)', () => {
  it.each(ROUTES)('is on %s, outside main, with the brand and Search links and one h1', (path) => {
    stubFetch(() => jsonResponse(path.startsWith('/search') ? searchPage : cardBody));
    visit(path);
    render(<App />);
    const banner = header();
    expect(banner.tagName).toBe('HEADER');
    expect(screen.getByRole('main').contains(banner)).toBe(false);
    const nav = within(banner).getByRole('navigation', { name: 'Main' });
    const links = within(nav)
      .getAllByRole('link')
      .map((link) => [link.textContent, link.getAttribute('href')]);
    expect(links).toEqual([
      ['PokéPrices', '/'],
      ['Search', '/search'],
    ]);
    expect(within(banner).queryByRole('heading')).toBeNull();
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
  });

  it('navigates in place from the brand and the Search link', () => {
    stubFetch(() => jsonResponse(searchPage));
    visit('/nowhere');
    render(<App />);
    expect(fireEvent.click(within(header()).getByRole('link', { name: 'Search' }))).toBe(false);
    expect(window.location.pathname).toBe('/search');
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Search cards');
    expect(fireEvent.click(within(header()).getByRole('link', { name: 'PokéPrices' }))).toBe(false);
    expect(window.location.pathname).toBe('/');
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('PokéPrices');
  });

  it('offers the Theme and Up/down colours selects with their exact labels and options', () => {
    render(<App />);
    const theme = within(header()).getByRole('combobox', { name: 'Theme' });
    expect(options(theme)).toEqual([
      ['system', 'System'],
      ['light', 'Light'],
      ['dark', 'Dark'],
    ]);
    expect((theme as HTMLSelectElement).value).toBe('system');
    const palette = within(header()).getByRole('combobox', { name: 'Up/down colours' });
    expect(options(palette)).toEqual([
      ['green-red', 'Green and red'],
      ['blue-orange', 'Blue and orange'],
    ]);
    expect((palette as HTMLSelectElement).value).toBe('green-red');
    // Visible labels: the label text is on screen, not only in an aria-label.
    expect(within(header()).getByText('Theme').tagName).toBe('LABEL');
    expect(within(header()).getByText('Up/down colours').tagName).toBe('LABEL');
  });

  it('gives every header link and select a 44 px minimum height and wraps its rows', () => {
    render(<App />);
    const controls = header().querySelectorAll('a, select, button');
    expect(controls).toHaveLength(4);
    for (const control of controls) {
      expect(control.className.split(/\s+/)).toContain('min-h-11');
    }
    const wrapping = Array.from(header().querySelectorAll('*')).filter((node) =>
      node.className.split(/\s+/).includes('flex-wrap'),
    );
    expect(wrapping.length).toBeGreaterThan(0);
  });

  it('applies and stores a choice made in the selects', () => {
    render(<App />);
    expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    expect(document.documentElement.getAttribute('data-palette')).toBe('green-red');
    const theme = within(header()).getByRole('combobox', { name: 'Theme' });
    fireEvent.change(theme, { target: { value: 'dark' } });
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    expect(window.localStorage.getItem('pokeprices:theme')).toBe('dark');
    expect((theme as HTMLSelectElement).value).toBe('dark');
    const palette = within(header()).getByRole('combobox', { name: 'Up/down colours' });
    fireEvent.change(palette, { target: { value: 'blue-orange' } });
    expect(document.documentElement.getAttribute('data-palette')).toBe('blue-orange');
    expect(window.localStorage.getItem('pokeprices:palette')).toBe('blue-orange');
    fireEvent.change(theme, { target: { value: 'system' } });
    expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    expect(window.localStorage.getItem('pokeprices:theme')).toBe('system');
  });

  it('shows the stored choices in the selects after a reload', () => {
    window.localStorage.setItem('pokeprices:theme', 'dark');
    window.localStorage.setItem('pokeprices:palette', 'blue-orange');
    render(<App />);
    const theme = within(header()).getByRole('combobox', { name: 'Theme' });
    const palette = within(header()).getByRole('combobox', { name: 'Up/down colours' });
    expect((theme as HTMLSelectElement).value).toBe('dark');
    expect((palette as HTMLSelectElement).value).toBe('blue-orange');
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
  });

  it('never uses a forbidden word or the faked year (D14)', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    render(<App />);
    const text = header().textContent;
    for (const pattern of FORBIDDEN) expect(text).not.toMatch(pattern);
    expect(text).not.toContain('2031');
  });
});

/** Colour utilities that need a dark counterpart: text, background, border, divide, outline. */
const COLOUR =
  /^((?:hover:|focus-visible:)*)(text|bg|border|divide|outline)-(slate|gray|red|amber|emerald|sky|white|black)(?:-\d+)?$/;

/** Every colour class of every element under root that has no dark: class for the same property. */
function lightOnly(root: HTMLElement): string[] {
  const missing: string[] = [];
  for (const element of [root, ...Array.from(root.querySelectorAll('*'))]) {
    const classes = element.getAttribute('class')?.split(/\s+/) ?? [];
    for (const name of classes) {
      const match = COLOUR.exec(name);
      if (match === null) continue;
      const counterpart = `dark:${match[1] ?? ''}${match[2] ?? ''}-`;
      if (!classes.some((other) => other.startsWith(counterpart))) {
        missing.push(`${element.tagName.toLowerCase()} ${name}`);
      }
    }
  }
  return missing;
}

const pagedSearch = {
  data: [...searchPage.data],
  meta: { total: 60, page: 2, pages: 3, page_size: 24, degraded: true, cached: true },
} satisfies CardSearchPage;

describe('dark theme on the existing pages (T02 criterion 6)', () => {
  it.each([
    ['/', null],
    ['/nowhere', null],
    ['/search', null],
    ['/search?q=unown&page=2', () => jsonResponse(pagedSearch)],
    ['/search?q=unown', () => jsonResponse(degradedSearchPage)],
    ['/search?q=unown', () => problemResponse(upstreamUnavailableProblem)],
    ['/card/ex10-%3F', () => jsonResponse(pricedCardBody)],
    ['/card/ex10-%3F', () => jsonResponse(degradedCardBody)],
    ['/card/ex10-%3F', () => problemResponse(upstreamUnavailableProblem)],
    ['/card/ex10-%3F', () => problemResponse(cardNotFoundProblem)],
  ] as const)('gives every colour on %s a dark counterpart', async (path, answer) => {
    stubFetch((url) => {
      if (isHistoryUrl(url)) return jsonResponse(historyFor(pricedCardBody));
      return answer === null ? new Error('no request expected') : answer();
    });
    visit(path);
    const { container } = render(<App />);
    if (answer !== null) {
      await screen.findAllByText(
        /Market price|out of date|failed|Could not load|Card not found|cards match|Page 2 of 3/,
      );
    }
    expect(lightOnly(container)).toEqual([]);
  });
});

describe('tap targets on the existing controls (T02 criterion 7)', () => {
  function classesOf(element: HTMLElement): string[] {
    return element.className.split(/\s+/);
  }

  it('gives the search box, Search button and pager links 44 px', async () => {
    stubFetch(() => jsonResponse(pagedSearch));
    visit('/search?q=unown&page=2');
    render(<App />);
    const main = screen.getByRole('main');
    expect(classesOf(within(main).getByRole('searchbox', { name: 'Card name' }))).toContain(
      'min-h-11',
    );
    const submit = within(main).getByRole('button', { name: 'Search' });
    expect(classesOf(submit)).toEqual(expect.arrayContaining(['min-h-11', 'min-w-11']));
    const pager = await screen.findByRole('navigation', { name: 'Result pages' });
    for (const name of ['Previous page', 'Next page']) {
      expect(classesOf(within(pager).getByRole('link', { name }))).toContain('min-h-11');
    }
  });

  it('gives Search cards and Go to the home page 44 px', async () => {
    stubFetch(() => problemResponse(cardNotFoundProblem));
    visit('/card/ex10-%3F');
    render(<App />);
    const search = await screen.findByRole('link', { name: 'Search cards' });
    expect(classesOf(search)).toContain('min-h-11');
    cleanup();
    visit('/nowhere');
    render(<App />);
    const home = screen.getByRole('link', { name: 'Go to the home page' });
    expect(classesOf(home)).toContain('min-h-11');
  });
});
