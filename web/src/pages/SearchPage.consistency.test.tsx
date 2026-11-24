// An answer whose page lies outside its own pages is never drawn as "Page X of Y" with X past Y:
// the page shows its problem block for an unexpected answer, sends nothing more and leaves the
// address alone.
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { jsonResponse, searchBodyOf, stubFetch, unownQuestion } from '../test/fixtures';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  visit('/');
});

describe('a search answer whose page is outside its pages', () => {
  it.each([
    ['page 54 of 10', 54, 10, '/search?q=unown&page=4800'],
    ['page 0 of 3', 0, 3, '/search?q=unown'],
  ])(
    'shows the unexpected-answer problem for %s, with no pager and no request more',
    async (_name, page, pages, address) => {
      const calls = stubFetch(() =>
        jsonResponse(searchBodyOf([unownQuestion], { total: pages * 24, page, pages })),
      );
      visit(address);
      render(<App />);
      const alert = await screen.findByRole('alert');
      expect(alert.textContent).toContain('Search failed');
      expect(alert.textContent).toContain(
        'The PokéPrices API sent an unexpected answer (HTTP 200)',
      );
      expect(screen.queryByRole('navigation', { name: 'Result pages' })).toBeNull();
      expect(screen.queryByRole('list', { name: 'Search results' })).toBeNull();
      expect(screen.getByRole('main').textContent).not.toMatch(/Page \d+ of \d+/);
      expect(window.location.pathname + window.location.search).toBe(address);
      expect(
        calls.map((call) => call.url).filter((url) => url.startsWith('/v1/cards')),
      ).toHaveLength(1);
    },
  );
});
