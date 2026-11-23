// index.html's canonical link names the home page; the app points it at the page on screen, on the
// site's public address, so a card page is never declared a copy of the home page.
import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as SignUpModule from './account/SignUpPage';
import App from './App';
import { navigate } from './router';
import { answerMovers, stubFetch } from './test/fixtures';

const SITE = 'https://pokeprices.andrestheperez.com';

// The sign-up page stands in for any page that throws while it renders; a flag turns it on.
const crash = vi.hoisted(() => ({ on: false }));
vi.mock('./account/SignUpPage', async (importOriginal) => {
  const actual = await importOriginal<typeof SignUpModule>();
  const Page = actual.default;
  return {
    default: function MaybeCrash() {
      if (crash.on) throw new Error('render failed');
      return <Page />;
    },
  };
});

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
  crash.on = false;
  vi.restoreAllMocks();
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

describe('the canonical link when a page cannot render', () => {
  it('names the failing page on a first load, not the home page', () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    crash.on = true;
    stubFetch(() => new Error('no request expected'));
    visit('/sign-up');
    render(<App />);
    expect(screen.getByText('This page could not be shown.')).toBeTruthy();
    expect(canonical()).toBe(`${SITE}/sign-up`);
  });

  it('moves off the previous page when the next one fails', () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    expect(canonical()).toBe(`${SITE}/about`);
    crash.on = true;
    act(() => {
      navigate('/sign-up');
    });
    expect(screen.getByText('This page could not be shown.')).toBeTruthy();
    expect(canonical()).toBe(`${SITE}/sign-up`);
  });
});
