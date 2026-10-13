import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from './App';
import Link from './Link';
import { cardPath, matchRoute, navigate } from './router';
import { answerMovers, stubFetch } from './test/fixtures';

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

describe('router edge cases', () => {
  it('decodes %253F to the literal text %3F, never twice to ?', () => {
    visit('/card/ex10-%253F');
    render(<App />);
    expect(heading()).toBe('Card ex10-%3F');
    expect(matchRoute('/card/ex10-%253F', '')).toEqual({ name: 'card', id: 'ex10-%3F' });
  });

  it('ignores a query string and a hash on a card URL', () => {
    visit('/card/ex10-%3F?ref=search#top');
    render(<App />);
    expect(heading()).toBe('Card ex10-?');
  });

  it('keeps an encoded query on /search intact through navigate()', () => {
    render(<App />);
    act(() => {
      navigate('/search?q=unown%20%3F&page=2');
    });
    expect(heading()).toBe('Search cards');
    expect(window.location.search).toBe('?q=unown%20%3F&page=2');
    const route = matchRoute(window.location.pathname, window.location.search);
    expect(route.name === 'search' ? route.query.get('q') : null).toBe('unown ?');
  });

  it('sends the empty and slash-only card ids to not-found, not to a blank card', () => {
    for (const path of ['/card/', '/card//', '/card/%2F/x']) {
      expect(matchRoute(path, '')).toEqual({ name: 'not-found' });
    }
    expect(matchRoute('/card/%2F', '')).toEqual({ name: 'card', id: '/' });
    expect(matchRoute(cardPath(''), '')).toEqual({ name: 'not-found' });
  });

  it('re-renders on a real history.back() and history.forward()', async () => {
    visit('/');
    render(<App />);
    act(() => {
      navigate('/search?q=a');
    });
    act(() => {
      navigate(cardPath('ex10-?'));
    });
    expect(heading()).toBe('Card ex10-?');
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(heading()).toBe('Search cards');
    });
    act(() => {
      window.history.forward();
    });
    await waitFor(() => {
      expect(heading()).toBe('Card ex10-?');
    });
  });

  it('intercepts only a plain left click on a Link', () => {
    visit('/start');
    render(
      <>
        <Link to="/search?q=a">plain</Link>
        <Link to="/search?q=b" target="_blank">
          blank
        </Link>
      </>,
    );
    const plain = screen.getByRole('link', { name: 'plain' });
    const blank = screen.getByRole('link', { name: 'blank' });
    const swallow = (event: MouseEvent): void => {
      event.preventDefault();
    };
    window.addEventListener('click', swallow);
    const fallThrough = [
      fireEvent.click(plain, { button: 1 }),
      fireEvent.click(plain, { button: 2 }),
      fireEvent.click(plain, { shiftKey: true }),
      fireEvent.click(plain, { metaKey: true }),
      fireEvent.click(plain, { altKey: true }),
      fireEvent.click(blank),
    ];
    window.removeEventListener('click', swallow);
    expect(window.location.pathname).toBe('/start');
    expect(fallThrough).toEqual([false, false, false, false, false, false]);
    // A left click now navigates and the router sees the query.
    fireEvent.click(plain);
    expect(window.location.pathname + window.location.search).toBe('/search?q=a');
  });

  it('shows no live or real-time wording on any route', () => {
    stubFetch(answerMovers);
    for (const path of ['/', '/search', '/card/ex10-%3F', '/nowhere']) {
      visit(path);
      const { unmount } = render(<App />);
      expect(screen.getByRole('main').textContent).not.toMatch(/real-?time|\blive\b/i);
      unmount();
    }
  });
});
