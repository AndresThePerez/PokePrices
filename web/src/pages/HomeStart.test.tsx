// The home page's start: a fixed set of well-known card tiles and example searches above the movers
// strip, the subtitle that says what the heading does not, and no request beyond the page's own.
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  freshnessBody,
  isMoversUrl,
  jsonResponse,
  searchPage,
  stubFetch,
} from '../test/fixtures';

const TILES: readonly (readonly [name: string, href: string])[] = [
  ['Charizard Base', '/card/base1-4'],
  ['Blastoise Base', '/card/base1-2'],
  ['Pikachu Base', '/card/base1-58'],
  ['Lugia Neo Genesis', '/card/neo1-9'],
  ['Charizard ex 151', '/card/sv3pt5-199'],
  ['Umbreon ex Prismatic Evolutions', '/card/sv8pt5-161'],
];

const EXAMPLES: readonly (readonly [text: string, href: string])[] = [
  ['lugia neo genesis', '/search?q=lugia+neo+genesis'],
  ['umbreon vmax', '/search?q=umbreon+vmax'],
  ['umbreon ex', '/search?q=umbreon+ex'],
  ['mewtwo', '/search?q=mewtwo'],
];

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function follows(first: Node, second: Node): boolean {
  return (first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
}

function answerHome(url: string): Response | Error {
  if (isMoversUrl(url)) return answerMovers(url);
  if (url === '/v1/prices/freshness') return jsonResponse(freshnessBody());
  return jsonResponse(searchPage);
}

function tileList(): HTMLElement {
  return screen.getByRole('list', { name: 'Well-known cards' });
}

function exampleList(): HTMLElement {
  return screen.getByRole('list', { name: 'Example searches' });
}

function movers(): HTMLElement {
  return screen.getByRole('region', { name: 'Biggest moves vs previous close' });
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('the home page leads with card tiles and example searches', () => {
  it('puts the examples and the tiles after the search form and the demo entry, and above the movers', () => {
    stubFetch(answerHome);
    visit('/');
    render(<App />);
    const form = within(screen.getByRole('main')).getByRole('search');
    const demo = screen.getByRole('button', { name: 'Try the read-only demo' });
    expect(follows(form, demo)).toBe(true);
    expect(follows(demo, exampleList())).toBe(true);
    expect(follows(exampleList(), tileList())).toBe(true);
    expect(follows(tileList(), movers())).toBe(true);
  });

  it('links each tile to its card page, named by the card and its set', () => {
    stubFetch(answerHome);
    visit('/');
    render(<App />);
    const links = within(tileList()).getAllByRole('link');
    expect(links.map((link) => [link.textContent, link.getAttribute('href')])).toEqual(TILES);
    for (const [name] of TILES) {
      expect(within(tileList()).getByRole('link', { name })).toBeTruthy();
    }
  });

  it('links each example to the search page with its query, in the default order', () => {
    stubFetch(answerHome);
    visit('/');
    render(<App />);
    const links = within(exampleList()).getAllByRole('link');
    expect(links.map((link) => [link.textContent, link.getAttribute('href')])).toEqual(EXAMPLES);
  });

  it('opens the search page for an example in place, asking the catalogue for its query', async () => {
    const calls = stubFetch(answerHome);
    visit('/');
    render(<App />);
    fireEvent.click(within(exampleList()).getByRole('link', { name: 'umbreon vmax' }));
    expect(window.location.pathname + window.location.search).toBe('/search?q=umbreon+vmax');
    await screen.findByRole('list', { name: 'Search results' });
    expect(calls.map((call) => call.url)).toContain('/v1/cards?q=umbreon+vmax');
  });

  it('opens a tile card page in place', () => {
    stubFetch(answerHome);
    visit('/');
    render(<App />);
    fireEvent.click(within(tileList()).getByRole('link', { name: 'Lugia Neo Genesis' }));
    expect(window.location.pathname).toBe('/card/neo1-9');
  });

  it('shows no price on a tile', () => {
    stubFetch(answerHome);
    visit('/');
    render(<App />);
    expect(tileList().textContent).not.toMatch(/\$|as of|price/i);
  });

  it('sends no request beyond the movers and the freshness answer', async () => {
    const calls = stubFetch(answerHome);
    visit('/');
    render(<App />);
    await within(movers()).findByRole('link', { name: 'See all movers' });
    expect(calls.map((call) => call.url).sort()).toEqual([
      '/v1/movers?direction=down&limit=5',
      '/v1/movers?direction=up&limit=5',
      '/v1/prices/freshness',
    ]);
  });
});

describe('a tile picture', () => {
  it('reserves its box, loads lazily and is decorative', () => {
    stubFetch(answerHome);
    visit('/');
    render(<App />);
    const images = Array.from(tileList().querySelectorAll('img'));
    expect(images).toHaveLength(TILES.length);
    for (const img of images) {
      expect(img.getAttribute('loading')).toBe('lazy');
      expect(img.getAttribute('decoding')).toBe('async');
      expect(img.getAttribute('width')).toBe('245');
      expect(img.getAttribute('height')).toBe('342');
      expect(img.getAttribute('aria-hidden')).toBe('true');
      expect(img.className.split(/\s+/)).toEqual(
        expect.arrayContaining(['aspect-[63/88]', 'w-full']),
      );
    }
    expect(images.map((img) => img.getAttribute('src'))).toEqual([
      'https://images.pokemontcg.io/base1/4.png',
      'https://images.pokemontcg.io/base1/2.png',
      'https://images.pokemontcg.io/base1/58.png',
      'https://images.pokemontcg.io/neo1/9.png',
      'https://images.pokemontcg.io/sv3pt5/199.png',
      'https://images.pokemontcg.io/sv8pt5/161.png',
    ]);
  });

  it('swaps to the card back in the same box when it fails to load, keeping the link', () => {
    stubFetch(answerHome);
    visit('/');
    render(<App />);
    const link = within(tileList()).getByRole('link', { name: 'Pikachu Base' });
    const img = link.querySelector('img');
    if (img === null) throw new Error('an image expected');
    fireEvent.error(img);
    expect(link.querySelector('img')).toBeNull();
    const back = link.querySelector('[data-initial]');
    expect(back?.getAttribute('data-initial')).toBe('P');
    expect(back?.textContent).toBe('');
    expect(back?.className.split(/\s+/)).toEqual(
      expect.arrayContaining(['aspect-[63/88]', 'w-full']),
    );
    expect(link.textContent).toBe('Pikachu Base');
    expect(tileList().querySelectorAll('img')).toHaveLength(TILES.length - 1);
  });
});

describe('the subtitle', () => {
  it('follows the heading, says something the heading does not, and passes the forbidden-words list', () => {
    stubFetch(answerHome);
    visit('/');
    render(<App />);
    const h1 = screen.getByRole('heading', { level: 1 });
    const subtitle = h1.nextElementSibling;
    expect(subtitle?.tagName).toBe('P');
    const text = subtitle?.textContent ?? '';
    const heading = h1.textContent.toLowerCase();
    expect(text.toLowerCase()).not.toContain(heading);
    expect(text).toMatch(/TCGCSV/);
    expect(text).toMatch(/as of/);
    expect(text).toMatch(/^[^.]+\.$/);
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
  });
});

describe('forbidden words on the home page', () => {
  it('appear nowhere in the main region, the tiles and the examples included', async () => {
    stubFetch(answerHome);
    visit('/');
    render(<App />);
    await within(movers()).findByRole('link', { name: 'See all movers' });
    const text = screen.getByRole('main').textContent;
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
  });
});
