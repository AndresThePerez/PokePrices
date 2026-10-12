import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from './App';
import { cardPath, matchRoute, navigate } from './router';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function heading(): string | null {
  return screen.getByRole('heading', { level: 1 }).textContent;
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('matchRoute', () => {
  it('matches the home, search and card routes', () => {
    expect(matchRoute('/', '')).toEqual({ name: 'home' });
    expect(matchRoute('/card/base1-4', '')).toEqual({ name: 'card', id: 'base1-4' });
    const search = matchRoute('/search', '?q=unown&page=2');
    expect(search.name).toBe('search');
    expect(search.name === 'search' ? search.query.get('q') : null).toBe('unown');
    expect(search.name === 'search' ? search.query.get('page') : null).toBe('2');
  });

  it('decodes a percent-encoded card id exactly once', () => {
    expect(matchRoute('/card/ex10-%3F', '')).toEqual({ name: 'card', id: 'ex10-?' });
    expect(matchRoute('/card/ex10-!', '')).toEqual({ name: 'card', id: 'ex10-!' });
    expect(matchRoute('/card/a%2Fb', '')).toEqual({ name: 'card', id: 'a/b' });
    expect(matchRoute('/card/100%2525', '')).toEqual({ name: 'card', id: '100%25' });
  });

  it('sends every other path, and a malformed escape, to not-found', () => {
    for (const path of [
      '/card',
      '/card/',
      '/card/a/b',
      '/cards/base1-4',
      '/search/',
      '/search/extra',
      '/v1/cards',
      '/card/%E0%A4%A',
    ]) {
      expect(matchRoute(path, '')).toEqual({ name: 'not-found' });
    }
  });
});

describe('cardPath', () => {
  it('encodes the id into one path segment that matchRoute reads back unchanged', () => {
    expect(cardPath('ex10-?')).toBe('/card/ex10-%3F');
    expect(cardPath('a/b')).toBe('/card/a%2Fb');
    for (const id of ['ex10-?', 'ex10-!', 'a/b', '100%', 'base1-4']) {
      expect(matchRoute(cardPath(id), '')).toEqual({ name: 'card', id });
    }
  });
});

describe('App routes', () => {
  it('keeps the daily-close hero at /', () => {
    render(<App />);
    expect(heading()).toBe('PokéPrices');
    expect(screen.getByRole('main').textContent).toContain('daily close');
  });

  it('renders the search route at /search', () => {
    visit('/search?q=unown');
    render(<App />);
    expect(heading()).toBe('Search cards');
  });

  it('renders the card route with the decoded id', () => {
    visit('/card/ex10-%3F');
    render(<App />);
    expect(heading()).toBe('Card ex10-?');
  });

  it('renders not-found for an unknown path, with a link home that navigates in place', () => {
    visit('/no-such-page');
    render(<App />);
    expect(heading()).toBe('Page not found');
    expect(screen.getByRole('main').textContent).toContain('/no-such-page');
    const link = screen.getByRole('link', { name: 'Go to the home page' });
    expect(link.getAttribute('href')).toBe('/');
    expect(fireEvent.click(link)).toBe(false);
    expect(window.location.pathname).toBe('/');
    expect(heading()).toBe('PokéPrices');
  });

  it('leaves a modified click to the browser', () => {
    visit('/no-such-page');
    render(<App />);
    const seen: boolean[] = [];
    const record = (event: MouseEvent): void => {
      seen.push(event.defaultPrevented);
      event.preventDefault();
    };
    window.addEventListener('click', record);
    fireEvent.click(screen.getByRole('link', { name: 'Go to the home page' }), { ctrlKey: true });
    window.removeEventListener('click', record);
    expect(seen).toEqual([false]);
    expect(window.location.pathname).toBe('/no-such-page');
    expect(heading()).toBe('Page not found');
  });

  it('follows navigate() and the browser history', () => {
    render(<App />);
    act(() => {
      navigate(cardPath('ex10-!'));
    });
    expect(window.location.pathname).toBe('/card/ex10-!');
    expect(heading()).toBe('Card ex10-!');
    act(() => {
      visit('/search');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(heading()).toBe('Search cards');
  });
});
