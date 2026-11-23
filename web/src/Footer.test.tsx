// The PokéPrices footer: the display settings strip, then the site links (About, Source, Built by,
// Contact). No email address anywhere: contact goes through the builder's website.
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from './App';
import { FORBIDDEN_WORDS, stubFetch } from './test/fixtures';

const SITE_LINKS: readonly (readonly [name: string, href: string])[] = [
  ['About', '/about'],
  ['Source', 'https://github.com/AndresThePerez/PokePrices'],
  ['Built by Andres Perez', 'https://andrestheperez.com'],
  ['Contact', 'https://andrestheperez.com'],
];

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function footer(): HTMLElement {
  return screen.getByRole('contentinfo');
}

function siteLinks(): HTMLElement {
  return within(footer()).getByRole('navigation', { name: 'Site' });
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('the footer site links', () => {
  it('names About, Source, Built by and Contact with their addresses, after the settings', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/nowhere');
    render(<App />);
    const links = within(siteLinks()).getAllByRole('link');
    expect(links.map((link) => [link.textContent, link.getAttribute('href')])).toEqual(SITE_LINKS);
    const theme = within(footer()).getByRole('combobox', { name: 'Theme' });
    expect(
      theme.compareDocumentPosition(siteLinks()) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    for (const link of links) {
      expect(link.className.split(/\s+/)).toContain('min-h-11');
    }
  });

  it('opens the About page in place and sends no request', () => {
    const calls = stubFetch(() => new Error('no request expected'));
    visit('/nowhere');
    render(<App />);
    fireEvent.click(within(siteLinks()).getByRole('link', { name: 'About' }));
    expect(window.location.pathname).toBe('/about');
    expect(calls).toEqual([]);
  });

  it('carries no mailto link and no email address', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/nowhere');
    render(<App />);
    expect(footer().querySelectorAll('a[href^="mailto:" i]')).toHaveLength(0);
    expect(footer().innerHTML).not.toMatch(/mailto:|@[a-z0-9-]+\.[a-z]/i);
  });

  it('uses no forbidden word', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/nowhere');
    render(<App />);
    for (const pattern of FORBIDDEN_WORDS) expect(footer().textContent).not.toMatch(pattern);
  });

  it.each([
    ['/', true],
    ['/about', true],
    ['/search?q=x', true],
    ['/card/base1-4', true],
    ['/movers', true],
    ['/sign-in', true],
    ['/watchlist', true],
    ['/account', false],
    ['/account/verify-email', true],
    ['/alerts', true],
    ['/no-such-page', true],
  ] as const)(
    'shows the links on %s, with the settings only where the route keeps them',
    (path, settings) => {
      stubFetch(() => new Error('no answer'));
      visit(path);
      render(<App />);
      expect(
        within(siteLinks())
          .getAllByRole('link')
          .map((link) => [link.textContent, link.getAttribute('href')]),
      ).toEqual(SITE_LINKS);
      expect(within(footer()).queryAllByRole('combobox')).toHaveLength(settings ? 2 : 0);
    },
  );

  it('shows the same links on the home page and the About page', () => {
    stubFetch(() => new Error('no answer'));
    for (const path of ['/', '/about']) {
      visit(path);
      const { unmount } = render(<App />);
      expect(
        within(siteLinks())
          .getAllByRole('link')
          .map((link) => link.getAttribute('href')),
      ).toEqual(SITE_LINKS.map(([, href]) => href));
      unmount();
    }
  });
});
