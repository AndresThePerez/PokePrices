// The About page at /about: what PokéPrices is, where its prices come from, who built it, the
// source, the affiliation disclaimer, the thank-you to TCGCSV, the privacy paragraph and the contact
// link. It shows no price and sends no request.
import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import { matchRoute, navigate } from '../router';
import { FORBIDDEN_WORDS, stubFetch } from '../test/fixtures';

const DISCLAIMER =
  'PokéPrices is not affiliated with or endorsed by Nintendo, The Pokémon Company, Game Freak, Creatures or TCGplayer. Pokémon card images and names belong to their owners.';

const PRIVACY =
  'Opening and reading the site sets no cookies. Sending a sign-in, sign-up or password form, or pressing the demo button, sets two: a session cookie, which keeps you signed in once you are, and a security token that protects the site’s forms. The security token is not removed when you sign out, so a later visit from a browser that still holds it asks, on opening a page, whether you are signed in. An account stores its email address, a hash of its password, its two-factor setting, its watchlist and portfolio, its alert settings and webhooks, a record of the alerts sent to it, and for each signed-in session its IP address, browser and last activity. You can download that data or delete your account on the Account page. Display settings stay in your browser. Card pictures load from a third-party image host, which receives your browser’s request for each picture. The site is served through Cloudflare, which carries every request on its way to PokéPrices.';

const PROJECT_STATEMENT =
  'PokéPrices is a personal portfolio project, not a business. It has no ads, no affiliate links and no paid features, and it sells no data. It exists to show how I build and run software.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

function section(name: string): HTMLElement {
  return within(main()).getByRole('region', { name });
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('the About page', () => {
  it('is the about route, titled, with one h1', () => {
    expect(matchRoute('/about', '')).toEqual({ name: 'about' });
    expect(matchRoute('/about', '?x=1')).toEqual({ name: 'about' });
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    expect(document.title).toBe('About · PokéPrices');
    expect(screen.getAllByRole('heading', { level: 1 }).map((h) => h.textContent)).toEqual([
      'About',
    ]);
  });

  it('says what the site is: daily closes from TCGplayer through TCGCSV, not a running quote', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    const text = main().textContent;
    expect(text).toContain('daily close');
    expect(text).toContain('TCGplayer');
    expect(text).toContain('not a running quote');
  });

  it('thanks TCGCSV with a link to it, as the source of the price data', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    const prices = section('Where the prices come from');
    expect(within(prices).getByRole('link', { name: 'TCGCSV' }).getAttribute('href')).toBe(
      'https://tcgcsv.com',
    );
    expect(prices.textContent).toContain('Thank you to TCGCSV');
  });

  it('names who built it and links to the source', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    const who = section('Who built it');
    expect(within(who).getByRole('link', { name: 'Andres Perez' }).getAttribute('href')).toBe(
      'https://andrestheperez.com',
    );
    expect(
      within(who).getByRole('link', { name: 'source code on GitHub' }).getAttribute('href'),
    ).toBe('https://github.com/AndresThePerez/PokePrices');
  });

  it('carries the affiliation disclaimer word for word', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    expect(within(section('Not affiliated')).getByText(DISCLAIMER).tagName).toBe('P');
  });

  it('carries the privacy paragraph word for word', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    expect(within(section('Privacy')).getByText(PRIVACY).tagName).toBe('P');
  });

  it('says in its own section that it is a personal portfolio project, not a business, word for word', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    const project = section('A personal portfolio project, not a business');
    expect(within(project).getByText(PROJECT_STATEMENT).tagName).toBe('P');
  });

  it('says the code is published to be read and all rights are reserved', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    const text = section('A personal portfolio project, not a business').textContent;
    expect(text).toContain('published to be read');
    expect(text).toContain('all rights are reserved');
  });

  it('links to the contact website and has no mailto link or email address', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    const contact = section('Contact');
    expect(
      within(contact).getByRole('link', { name: 'andrestheperez.com' }).getAttribute('href'),
    ).toBe('https://andrestheperez.com');
    expect(main().querySelectorAll('a[href^="mailto:" i]')).toHaveLength(0);
    expect(main().innerHTML).not.toMatch(/mailto:|@[a-z0-9-]+\.[a-z]/i);
  });

  it('uses no forbidden word', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
  });

  it('sends no request and shows no stale-prices banner', () => {
    const calls = stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    expect(calls).toEqual([]);
    expect(screen.queryByText(/Prices are out of date/)).toBeNull();
  });

  it('leaves an unknown path under it to the not-found page', () => {
    stubFetch(() => new Error('no request expected'));
    for (const path of ['/about/', '/about/x', '/About']) {
      expect(matchRoute(path, '').name).toBe('not-found');
    }
    visit('/about');
    render(<App />);
    act(() => {
      navigate('/about/x');
    });
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Page not found');
    expect(document.title).toBe('Page not found · PokéPrices');
  });
});
