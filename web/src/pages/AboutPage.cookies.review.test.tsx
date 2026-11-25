// Review edge cases for the About page's privacy paragraph: each key claim pinned as its own phrase,
// the paragraph the same signed out, signed in and in the demo, the forbidden-words list over it, and
// what a visitor who still holds the security token really sends for a page left open past the
// polling interval (the paragraph says it asks "on opening a page").
import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  clearXsrfCookie,
  demoMe,
  freshnessBody,
  isMoversUrl,
  jsonResponse,
  problemResponse,
  setXsrfCookie,
  signedInMe,
  stubAccountFetch,
  stubFetch,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const ME = '/v1/me';
const FIVE_MINUTES = 300_000;

const UNAUTHENTICATED = {
  type: 'https://pokeprices.andrestheperez.com/problems/unauthenticated',
  title: 'Unauthenticated',
  status: 401,
  instance: '/v1/me',
  request_id: 'req-review-cookies-0001',
};

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

async function settle(): Promise<void> {
  for (let round = 0; round < 6; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

function privacyText(): string {
  const region = within(screen.getByRole('main')).getByRole('region', { name: 'Privacy' });
  return region.querySelector('p')?.textContent ?? '';
}

function cookieSentences(): string {
  return privacyText().split(' An account stores')[0] ?? '';
}

beforeEach(() => {
  window.localStorage.clear();
  clearXsrfCookie();
});

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.useRealTimers();
  visit('/');
});

describe('each cookie claim is its own exact phrase', () => {
  it('the paragraph holds every key phrase, so an edit that drops one fails', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    const text = privacyText();
    const phrases = [
      'Opening and reading the site sets no cookies.',
      'Sending a sign-in, sign-up or password form, or pressing the demo button, sets two:',
      'a session cookie, which keeps you signed in once you are,',
      'and a security token that protects the site’s forms.',
      'The security token is not removed when you sign out,',
      'a later visit from a browser that still holds it asks',
      'on opening a page, whether you are signed in.',
    ];
    for (const phrase of phrases) expect(text).toContain(phrase);
  });

  it('never says no cookies without its condition, and never promises cookies only at sign-in', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    const text = privacyText();
    expect(text.match(/no cookies?/gi)).toHaveLength(1);
    expect(text).not.toMatch(/never (set|send)|unless you sign in|only when you sign in/i);
    expect(text).not.toMatch(/Browsing prices sets no cookies/);
  });
});

describe('the cookie sentences do not depend on who is signed in', () => {
  it('read the same signed out, signed in and in the read-only demo', async () => {
    const seen: string[] = [];
    for (const me of [null, signedInMe, demoMe]) {
      clearXsrfCookie();
      if (me === null) {
        stubFetch(() => new Error('no request expected'));
      } else {
        stubAccountFetch({ 'GET /v1/me': jsonResponse(me) });
      }
      visit('/about');
      const { unmount } = render(<App />);
      await settle();
      seen.push(privacyText());
      unmount();
      vi.unstubAllGlobals();
    }
    expect(seen).toHaveLength(3);
    expect(seen[1]).toBe(seen[0]);
    expect(seen[2]).toBe(seen[0]);
  });

  it('pass the forbidden-words list and name no real-time promise', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    const text = cookieSentences() || privacyText();
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
    expect(text).not.toMatch(/\b(instant|real-time|live|streaming)\b|every minute/i);
    expect(text).toContain('Opening and reading the site sets no cookies.');
  });
});

describe('what a visitor who still holds the security token sends past the polling interval', () => {
  function answerSignedOut(url: string): Response | Error {
    if (url === ME) return problemResponse(UNAUTHENTICATED);
    if (isMoversUrl(url)) return answerMovers(url);
    if (url === '/v1/prices/freshness') return jsonResponse(freshnessBody());
    return new Error('no request expected');
  }

  async function meAsksAfter(path: string, minutes: number): Promise<number> {
    vi.useFakeTimers({
      toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'],
    });
    const calls = stubFetch(answerSignedOut);
    visit(path);
    render(<App />);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(minutes * 60_000 + 1000);
    });
    return calls.filter((call) => call.url === ME).length;
  }

  it('a public page asks once and not again while it stays open', async () => {
    setXsrfCookie();
    expect(await meAsksAfter('/', 12)).toBe(1);
  });

  it('a signed-out visitor with no cookie, on the sign-in page for twelve minutes, sends no session request', async () => {
    expect(await meAsksAfter('/sign-in', 12)).toBe(0);
  });

  it('the interval is five minutes, so a sign-in page left open asks again', async () => {
    setXsrfCookie();
    const opened = await meAsksAfter('/sign-in', 0);
    expect(opened).toBe(1);
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    expect(FIVE_MINUTES).toBe(5 * 60_000);
    setXsrfCookie();
    expect(await meAsksAfter('/sign-in', 11)).toBeGreaterThan(1);
  });
});
