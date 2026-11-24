import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App';
import { PRICE_FOOTNOTE } from './format';
import { setPalette, setTheme } from './preferences';
import {
  answerMovers,
  cardBody,
  isMoversUrl,
  jsonResponse,
  problemResponse,
  stubFetch,
  unauthenticatedProblem,
} from './test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('./test/lightweightChartsFake'));

const HOME_SENTENCE =
  'Each price is a TCGplayer market price collected once a day through TCGCSV, shown with its source and the day it is as of.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function stubShell() {
  return stubFetch((url) => {
    if (isMoversUrl(url)) return answerMovers(url);
    if (url.startsWith('/v1/me')) return problemResponse(unauthenticatedProblem);
    return jsonResponse(cardBody);
  });
}

function nav(): HTMLElement {
  return within(screen.getByRole('banner')).getByRole('navigation', { name: 'Main' });
}

function marked(): string[] {
  return within(nav())
    .getAllByRole('link')
    .filter((link) => link.hasAttribute('aria-current'))
    .map((link) => `${link.textContent}=${link.getAttribute('aria-current') ?? ''}`);
}

beforeEach(() => {
  window.localStorage.clear();
  document.documentElement.removeAttribute('data-theme');
  document.documentElement.removeAttribute('data-palette');
});

afterEach(() => {
  cleanup();
  visit('/');
});

describe('Header and Footer, adversarial (D1-i1-T03 review)', () => {
  it.each([
    '/password/forgot',
    '/password/reset?token=abc&email=a%40example.com',
    '/account/verify-email?link=x',
    '/card/ex10-%3F',
    '/not/a/page',
  ])('marks no header link as the current page on %s', (path) => {
    stubShell();
    visit(path);
    render(<App />);
    expect(marked()).toEqual([]);
  });

  it('marks Sign in alone as the current page on /sign-in?next=%2Fwatchlist', () => {
    stubShell();
    visit('/sign-in?next=%2Fwatchlist');
    render(<App />);
    expect(marked()).toEqual(['Sign in=page']);
  });

  it('keeps exactly one link marked as history goes back and forward under the open page', () => {
    stubShell();
    visit('/watchlist');
    render(<App />);
    expect(marked()).toEqual(['Watchlist=page']);
    act(() => {
      window.history.pushState(null, '', '/portfolio');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(marked()).toEqual(['Portfolio=page']);
    act(() => {
      window.history.replaceState(null, '', '/card/ex10-%3F');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(marked()).toEqual([]);
    act(() => {
      window.history.replaceState(null, '', '/');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(marked()).toEqual(['PokéPrices=page']);
  });

  it('does nothing on Escape while the Menu is closed, and stops listening once it closes', () => {
    stubShell();
    visit('/movers');
    render(<App />);
    const menu = within(screen.getByRole('banner')).getByRole('button', { name: 'Menu' });
    const theme = screen.getByRole('combobox', { name: 'Theme' });
    theme.focus();
    fireEvent.keyDown(theme, { key: 'Escape' });
    expect(menu.getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(theme);

    fireEvent.click(menu);
    fireEvent.click(menu);
    theme.focus();
    fireEvent.keyDown(theme, { key: 'Escape' });
    expect(document.activeElement).toBe(theme);
    expect(menu.getAttribute('aria-expanded')).toBe('false');

    fireEvent.click(menu);
    fireEvent.keyDown(document.body, { key: 'a' });
    expect(menu.getAttribute('aria-expanded')).toBe('true');
    fireEvent.keyDown(document.body, { key: 'Escape' });
    expect(menu.getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(menu);
  });

  it('closes the open Menu when the wordmark is clicked, and keeps every link in the DOM throughout', () => {
    stubShell();
    visit('/movers');
    render(<App />);
    const menu = within(screen.getByRole('banner')).getByRole('button', { name: 'Menu' });
    fireEvent.click(menu);
    expect(within(nav()).getAllByRole('link')).toHaveLength(6);
    fireEvent.click(within(nav()).getByRole('link', { name: 'PokéPrices' }));
    expect(menu.getAttribute('aria-expanded')).toBe('false');
    expect(window.location.pathname).toBe('/');
    expect(within(nav()).getAllByRole('link')).toHaveLength(6);
    expect(marked()).toEqual(['PokéPrices=page']);
  });

  it('keeps the skip link reachable: first in tab order, not hidden from the accessibility tree, focusable', () => {
    stubShell();
    render(<App />);
    const skip = within(screen.getByRole('banner')).getByRole('link', { name: 'Skip to content' });
    expect(skip.tabIndex).toBe(0);
    expect(skip.hasAttribute('hidden')).toBe(false);
    for (let node: HTMLElement | null = skip; node !== null; node = node.parentElement) {
      expect(node.getAttribute('aria-hidden')).not.toBe('true');
      expect(node.hasAttribute('inert')).toBe(false);
    }
    const tabbable = Array.from(
      document.body.querySelectorAll<HTMLElement>('a[href], button, select, input, [tabindex]'),
    ).filter((el) => el.tabIndex >= 0);
    expect(tabbable[0]).toBe(skip);
    skip.focus();
    expect(document.activeElement).toBe(skip);
    const target = document.getElementById(skip.getAttribute('href')?.slice(1) ?? '');
    expect(target).not.toBeNull();
    expect(target?.getAttribute('tabindex')).toBe('-1');
    expect(document.querySelectorAll('#content')).toHaveLength(1);
  });

  it('leaves the route, the mark and the request count alone when the skip link is followed', () => {
    const calls = stubShell();
    visit('/movers');
    render(<App />);
    const before = calls.length;
    const skip = screen.getByRole('link', { name: 'Skip to content' });
    fireEvent.click(skip);
    expect(window.location.pathname).toBe('/movers');
    expect(marked()).toEqual(['Movers=page']);
    expect(calls).toHaveLength(before);
  });

  it('adds no status, alert or live region to the shell, and repeats no page sentence', () => {
    stubShell();
    visit('/nowhere');
    render(<App />);
    fireEvent.click(within(screen.getByRole('banner')).getByRole('button', { name: 'Menu' }));
    for (const region of [screen.getByRole('banner'), screen.getByRole('contentinfo')]) {
      expect(
        region.querySelectorAll('[role=status], [role=alert], [role=log], [aria-live]'),
      ).toHaveLength(0);
      expect(region.querySelectorAll('output')).toHaveLength(0);
      expect(within(region).queryByText(PRICE_FOOTNOTE)).toBeNull();
      expect(within(region).queryByText(HOME_SENTENCE)).toBeNull();
      expect(region.textContent).not.toContain('TCGCSV');
      expect(region.querySelectorAll('h1, h2, h3, h4, h5, h6')).toHaveLength(0);
    }
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('sends no request from the shell while the visitor moves around signed out and changes settings', () => {
    const calls = stubFetch(() => new Error('no request expected'));
    visit('/nowhere');
    render(<App />);
    const menu = within(screen.getByRole('banner')).getByRole('button', { name: 'Menu' });
    fireEvent.click(menu);
    fireEvent.click(within(nav()).getByRole('link', { name: 'PokéPrices' }));
    fireEvent.click(menu);
    fireEvent.keyDown(document.body, { key: 'Escape' });
    fireEvent.change(screen.getByRole('combobox', { name: 'Up/down colours' }), {
      target: { value: 'blue-orange' },
    });
    expect(calls.map((call) => call.url).filter((url) => url.includes('/v1/me'))).toEqual([]);
  });

  it('keeps the footer selects in step with a preference changed from outside, and ignores a foreign value', () => {
    stubShell();
    visit('/nowhere');
    render(<App />);
    const theme = screen.getByRole<HTMLSelectElement>('combobox', { name: 'Theme' });
    const palette = screen.getByRole<HTMLSelectElement>('combobox', { name: 'Up/down colours' });
    act(() => {
      setTheme('dark');
      setPalette('blue-orange');
    });
    expect(theme.value).toBe('dark');
    expect(palette.value).toBe('blue-orange');
    for (const value of ['light', 'system', 'dark']) {
      fireEvent.change(theme, { target: { value } });
      expect(window.localStorage.getItem('pokeprices:theme')).toBe(value);
      expect(theme.value).toBe(value);
    }
    fireEvent.change(theme, { target: { value: 'sepia' } });
    expect(window.localStorage.getItem('pokeprices:theme')).toBe('dark');
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    fireEvent.change(palette, { target: { value: 'purple' } });
    expect(window.localStorage.getItem('pokeprices:palette')).toBe('blue-orange');
  });

  it('keeps the contentinfo landmark the last thing after main, and the banner first', () => {
    stubShell();
    render(<App />);
    const banner = screen.getByRole('banner');
    const main = screen.getByRole('main');
    const info = screen.getByRole('contentinfo');
    expect(banner.compareDocumentPosition(main) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(main.compareDocumentPosition(info) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.getAllByRole('banner')).toHaveLength(1);
    expect(screen.getAllByRole('contentinfo')).toHaveLength(1);
    expect(screen.getAllByRole('navigation', { name: 'Main' })).toHaveLength(1);
  });
});
