import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from './App';
import { answerMovers, stubFetch } from './test/fixtures';

afterEach(() => {
  cleanup();
});

describe('shell page honesty', () => {
  it('states where prices come from and the date they are as of', () => {
    stubFetch(answerMovers);
    const { container } = render(<App />);
    const text = container.textContent.toLowerCase();
    expect(text).toContain('source');
    expect(text).toContain('as of');
  });

  it('has exactly one h1 inside a main landmark and no template images, the Menu, Search and demo buttons and the header links', () => {
    stubFetch(answerMovers);
    const { container } = render(<App />);
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('main')).toBeTruthy();
    expect(container.querySelectorAll('svg')).toHaveLength(0);
    const tiles = screen.getByRole('list', { name: 'Well-known cards' });
    expect(container.querySelectorAll('img')).toHaveLength(tiles.querySelectorAll('img').length);
    expect(Array.from(container.querySelectorAll('button')).map((b) => b.textContent)).toEqual([
      'Menu',
      'Search',
      'Try the read-only demo',
    ]);
    expect(
      Array.from(container.querySelectorAll('a')).map((a) => [
        a.textContent,
        a.getAttribute('href'),
      ]),
    ).toEqual([
      ['Skip to content', '#content'],
      ['PokéPrices', '/'],
      ['Search', '/search'],
      ['Movers', '/movers'],
      ['Watchlist', '/watchlist'],
      ['Portfolio', '/portfolio'],
      ['Account', '/account'],
      ['lugia neo genesis', '/search?q=lugia+neo+genesis'],
      ['umbreon vmax', '/search?q=umbreon+vmax'],
      ['umbreon ex', '/search?q=umbreon+ex'],
      ['mewtwo', '/search?q=mewtwo'],
      ['Charizard Base', '/card/base1-4'],
      ['Blastoise Base', '/card/base1-2'],
      ['Pikachu Base', '/card/base1-58'],
      ['Lugia Neo Genesis', '/card/neo1-9'],
      ['Charizard ex 151', '/card/sv3pt5-199'],
      ['Umbreon ex Prismatic Evolutions', '/card/sv8pt5-161'],
      ['About', '/about'],
      ['Source', 'https://github.com/AndresThePerez/PokePrices'],
      ['Built by Andres Perez', 'https://andrestheperez.com'],
      ['Contact', 'https://andrestheperez.com'],
    ]);
  });

  it('keeps the accent as the precomposed U+00E9, not e plus a combining mark', () => {
    stubFetch(answerMovers);
    render(<App />);
    const wordmark = within(screen.getByRole('banner')).getByRole('link', {
      name: 'PokéPrices',
    }).textContent;
    expect(wordmark).toBe('PokéPrices');
    expect(wordmark).toHaveLength(10);
  });
});
