import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App';
import { navigate } from './router';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  cardBody,
  cardNotFoundProblem,
  degradedCardBody,
  degradedSearchPage,
  historyFor,
  isHistoryUrl,
  isMoversUrl,
  jsonResponse,
  pricedCardBody,
  problemResponse,
  searchPage,
  stubFetch,
  unauthenticatedProblem,
  upstreamUnavailableProblem,
} from './test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('./test/lightweightChartsFake'));
import type { CardSearchPage } from './api/client';

const ROUTES = ['/', '/search?q=unown', '/card/ex10-%3F', '/nowhere'] as const;

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function header(): HTMLElement {
  return screen.getByRole('banner');
}

function footer(): HTMLElement {
  return screen.getByRole('contentinfo');
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

describe('Header (T02 criterion 1; D1-T03)', () => {
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
      ['Movers', '/movers'],
      ['Watchlist', '/watchlist'],
      ['Portfolio', '/portfolio'],
      ['Account', '/account'],
    ]);
    expect(within(banner).queryByRole('heading')).toBeNull();
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
  });

  it.each([
    ['/', 'PokéPrices'],
    ['/search?q=unown', 'Search'],
    ['/card/ex10-%3F', null],
    ['/nowhere', null],
    ['/movers', 'Movers'],
    ['/movers?direction=down', 'Movers'],
    ['/watchlist', 'Watchlist'],
    ['/portfolio', 'Portfolio'],
    ['/account', 'Account'],
    ['/alerts', 'Account'],
    ['/sign-in', null],
    ['/sign-up', null],
  ] as const)('marks the current page on %s as %s, by aria-current only', (path, current) => {
    stubFetch((url) => {
      if (isMoversUrl(url)) return answerMovers(url);
      if (url.startsWith('/v1/me')) return problemResponse(unauthenticatedProblem);
      return jsonResponse(path.startsWith('/search') ? searchPage : cardBody);
    });
    visit(path);
    render(<App />);
    const nav = within(header()).getByRole('navigation', { name: 'Main' });
    const marked = within(nav)
      .getAllByRole('link')
      .filter((link) => link.hasAttribute('aria-current'));
    expect(marked.map((link) => [link.textContent, link.getAttribute('aria-current')])).toEqual(
      current === null ? [] : [[current, 'page']],
    );
  });

  it('moves the current-page mark when a header link navigates in place', () => {
    stubFetch(answerMovers);
    visit('/nowhere');
    render(<App />);
    const nav = within(header()).getByRole('navigation', { name: 'Main' });
    fireEvent.click(within(nav).getByRole('link', { name: 'Movers' }));
    expect(within(nav).getByRole('link', { name: 'Movers' }).getAttribute('aria-current')).toBe(
      'page',
    );
    fireEvent.click(within(nav).getByRole('link', { name: 'PokéPrices' }));
    expect(within(nav).getByRole('link', { name: 'Movers' }).hasAttribute('aria-current')).toBe(
      false,
    );
    expect(within(nav).getByRole('link', { name: 'PokéPrices' }).getAttribute('aria-current')).toBe(
      'page',
    );
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
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
      'Pokémon card prices at the daily close',
    );
  });

  it('opens with a skip link to the content, which takes focus from a script', () => {
    stubFetch(answerMovers);
    render(<App />);
    const anchors = header().querySelectorAll('a, button, select, input');
    const skip = within(header()).getByRole('link', { name: 'Skip to content' });
    expect(anchors[0]).toBe(skip);
    expect(skip.getAttribute('href')).toBe('#content');
    const content = document.getElementById('content');
    expect(content).not.toBeNull();
    expect(content?.tabIndex).toBe(-1);
    expect(content?.contains(screen.getByRole('main'))).toBe(true);
    expect(content?.contains(header())).toBe(false);
    content?.focus();
    expect(document.activeElement).toBe(content);
  });

  it('has a Menu button that shows and hides the link list, closed by Escape and by a link', () => {
    stubFetch(answerMovers);
    render(<App />);
    const menu = within(header()).getByRole('button', { name: 'Menu' });
    expect(menu.getAttribute('type')).toBe('button');
    expect(menu.getAttribute('aria-expanded')).toBe('false');
    const controlled = document.getElementById(menu.getAttribute('aria-controls') ?? '');
    expect(controlled).not.toBeNull();
    const list = controlled as HTMLElement;
    expect(header().contains(list)).toBe(true);
    expect(
      within(list)
        .getAllByRole('link')
        .map((link) => link.textContent),
    ).toEqual(['Search', 'Movers', 'Watchlist', 'Portfolio', 'Account']);

    fireEvent.click(menu);
    expect(menu.getAttribute('aria-expanded')).toBe('true');
    fireEvent.click(menu);
    expect(menu.getAttribute('aria-expanded')).toBe('false');

    fireEvent.click(menu);
    expect(menu.getAttribute('aria-expanded')).toBe('true');
    const watchlist = within(list).getByRole('link', { name: 'Watchlist' });
    watchlist.focus();
    fireEvent.keyDown(watchlist, { key: 'Escape' });
    expect(menu.getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(menu);

    fireEvent.click(menu);
    expect(menu.getAttribute('aria-expanded')).toBe('true');
    expect(fireEvent.click(within(list).getByRole('link', { name: 'Movers' }))).toBe(false);
    expect(window.location.pathname).toBe('/movers');
    expect(menu.getAttribute('aria-expanded')).toBe('false');
  });

  it('keeps the selects out of the bar: the footer, after main, holds them with their exact labels and options', () => {
    render(<App />);
    expect(within(header()).queryByRole('combobox')).toBeNull();
    const footer = screen.getByRole('contentinfo');
    expect(footer.tagName).toBe('FOOTER');
    expect(
      screen.getByRole('main').compareDocumentPosition(footer) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(screen.getByRole('main').contains(footer)).toBe(false);
    const theme = within(footer).getByRole('combobox', { name: 'Theme' });
    expect(options(theme)).toEqual([
      ['system', 'System'],
      ['light', 'Light'],
      ['dark', 'Dark'],
    ]);
    expect((theme as HTMLSelectElement).value).toBe('system');
    const palette = within(footer).getByRole('combobox', { name: 'Up/down colours' });
    expect(options(palette)).toEqual([
      ['green-red', 'Green and red'],
      ['blue-orange', 'Blue and orange'],
    ]);
    expect((palette as HTMLSelectElement).value).toBe('green-red');
    // Visible labels: the label text is on screen, not only in an aria-label.
    expect(within(footer).getByText('Theme').tagName).toBe('LABEL');
    expect(within(footer).getByText('Up/down colours').tagName).toBe('LABEL');
    // A settings strip only: the labels and the option texts are all the footer says.
    expect(footer.textContent).toBe(
      'ThemeSystemLightDarkUp/down coloursGreen and redBlue and orange',
    );
  });

  it('empties the footer on /account only (R297): on /nowhere it is the full settings strip again', async () => {
    stubFetch(() => problemResponse(unauthenticatedProblem));
    visit('/account');
    render(<App />);
    await screen.findByText('You are not signed in.');
    expect(screen.getAllByRole('contentinfo')).toHaveLength(1);
    expect(within(footer()).queryAllByRole('combobox')).toEqual([]);
    expect(footer().textContent).toBe('');
    act(() => {
      navigate('/nowhere');
    });
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Page not found');
    expect(within(screen.getByRole('main')).queryAllByRole('combobox')).toEqual([]);
    expect(options(within(footer()).getByRole('combobox', { name: 'Theme' }))).toEqual([
      ['system', 'System'],
      ['light', 'Light'],
      ['dark', 'Dark'],
    ]);
    expect(options(within(footer()).getByRole('combobox', { name: 'Up/down colours' }))).toEqual([
      ['green-red', 'Green and red'],
      ['blue-orange', 'Blue and orange'],
    ]);
    expect(footer().textContent).toBe(
      'ThemeSystemLightDarkUp/down coloursGreen and redBlue and orange',
    );
  });

  it('gives every header and footer control a 44 px minimum height', () => {
    render(<App />);
    const controls = [
      ...Array.from(header().querySelectorAll('a, select, button, input')),
      ...Array.from(screen.getByRole('contentinfo').querySelectorAll('a, select, button, input')),
    ];
    expect(controls).toHaveLength(10);
    for (const control of controls) {
      expect(control.className.split(/\s+/)).toContain('min-h-11');
    }
  });

  it('applies and stores a choice made in the footer selects', () => {
    render(<App />);
    expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    expect(document.documentElement.getAttribute('data-palette')).toBe('green-red');
    const theme = within(footer()).getByRole('combobox', { name: 'Theme' });
    fireEvent.change(theme, { target: { value: 'dark' } });
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    expect(window.localStorage.getItem('pokeprices:theme')).toBe('dark');
    expect((theme as HTMLSelectElement).value).toBe('dark');
    const palette = within(footer()).getByRole('combobox', { name: 'Up/down colours' });
    fireEvent.change(palette, { target: { value: 'blue-orange' } });
    expect(document.documentElement.getAttribute('data-palette')).toBe('blue-orange');
    expect(window.localStorage.getItem('pokeprices:palette')).toBe('blue-orange');
    fireEvent.change(theme, { target: { value: 'system' } });
    expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    expect(window.localStorage.getItem('pokeprices:theme')).toBe('system');
  });

  it('shows the stored choices in the footer selects after a reload', () => {
    window.localStorage.setItem('pokeprices:theme', 'dark');
    window.localStorage.setItem('pokeprices:palette', 'blue-orange');
    render(<App />);
    const theme = within(footer()).getByRole('combobox', { name: 'Theme' });
    const palette = within(footer()).getByRole('combobox', { name: 'Up/down colours' });
    expect((theme as HTMLSelectElement).value).toBe('dark');
    expect((palette as HTMLSelectElement).value).toBe('blue-orange');
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
  });

  it('never uses a forbidden word or the faked year in the header or footer (D14)', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    render(<App />);
    const text = `${header().textContent} ${footer().textContent}`;
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
    expect(text).not.toContain('2031');
  });

  it('reads no session and sends no request of its own', () => {
    const calls = stubFetch(() => new Error('no request expected'));
    visit('/nowhere');
    render(<App />);
    fireEvent.click(within(header()).getByRole('button', { name: 'Menu' }));
    fireEvent.change(within(footer()).getByRole('combobox', { name: 'Theme' }), {
      target: { value: 'dark' },
    });
    expect(calls).toHaveLength(0);
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
  meta: {
    total: 60,
    page: 2,
    pages: 3,
    page_size: 24,
    degraded: true,
    cached: true,
    sort: 'relevance',
    ordered: null,
  },
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
