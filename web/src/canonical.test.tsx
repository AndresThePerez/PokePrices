// index.html's canonical link names the home page; the app points it at the page on screen, on the
// site's public address, so a card page is never declared a copy of the home page.
import { act, cleanup, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import App from './App';
import { navigate } from './router';
import { answerMovers, stubFetch } from './test/fixtures';

const SITE = 'https://pokeprices.andrestheperez.com';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function canonical(): string | null {
  return document.head.querySelector('link[rel="canonical"]')?.getAttribute('href') ?? null;
}

beforeEach(() => {
  const link = document.createElement('link');
  link.rel = 'canonical';
  link.href = `${SITE}/`;
  document.head.append(link);
});

afterEach(() => {
  cleanup();
  document.head.querySelectorAll('link[rel="canonical"]').forEach((link) => {
    link.remove();
  });
  visit('/');
});

describe('the canonical link', () => {
  it('names the home page on the home page', () => {
    stubFetch(answerMovers);
    visit('/');
    render(<App />);
    expect(canonical()).toBe(`${SITE}/`);
  });

  it('follows the pathname, without the query, as the visitor moves', () => {
    stubFetch(() => new Error('no answer'));
    visit('/card/base1-4?variant=base1-4%3Aholo%3A1st%3A-');
    render(<App />);
    expect(canonical()).toBe(`${SITE}/card/base1-4`);
    act(() => {
      navigate('/movers?direction=down');
    });
    expect(canonical()).toBe(`${SITE}/movers`);
    act(() => {
      navigate('/search?q=lugia');
    });
    expect(canonical()).toBe(`${SITE}/search`);
  });
});
