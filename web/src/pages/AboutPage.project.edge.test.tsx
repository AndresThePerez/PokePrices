// Edge cases of the "personal portfolio project, not a business" statement: it sits in one
// element, under one h2 in the page's outline order, in every session state, passes the forbidden
// words, and the README holds it once, in one line, near the top, with no licence file beside it.
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  demoMe,
  jsonResponse,
  problemResponse,
  signedInMe,
  stubAccountFetch,
} from '../test/fixtures';

// tsconfig.app.json carries no Node types, hence the untyped specifier and the narrow cast.
const NODE_FS = 'node:fs';
const { readFileSync, existsSync } = (await import(/* @vite-ignore */ NODE_FS)) as {
  readonly readFileSync: (path: string, encoding: 'utf8') => string;
  readonly existsSync: (path: string) => boolean;
};
const testFile = import.meta.url;
const repoPath = (name: string): string => new URL(`../../../${name}`, testFile).pathname;

const STATEMENT =
  'PokéPrices is a personal portfolio project, not a business. It has no ads, no affiliate links and no paid features, and it sells no data. It exists to show how I build and run software.';
const RIGHTS = 'The code is published to be read, and all rights are reserved.';
const HEADING = 'A personal portfolio project, not a business';

const UNAUTHENTICATED = {
  type: 'https://pokeprices.andrestheperez.com/problems/unauthenticated',
  title: 'Unauthenticated',
  status: 401,
  instance: '/v1/me',
  request_id: 'req-edge-t01',
};

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

beforeEach(() => {
  window.localStorage.clear();
});

afterEach(() => {
  cleanup();
  visit('/');
});

describe('the About page project statement, in the page and its outline', () => {
  it('has one h2 for it, between the price source and who built it, and the whole page keeps its h2 order', () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(UNAUTHENTICATED) });
    visit('/about');
    render(<App />);
    const h2s = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent);
    expect(h2s.filter((text) => text === HEADING)).toHaveLength(1);
    expect(h2s).toEqual([
      'Where the prices come from',
      HEADING,
      'Who built it',
      'Not affiliated',
      'Privacy',
      'Contact',
    ]);
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.queryAllByRole('heading', { level: 3 })).toHaveLength(0);
  });

  it('holds the statement and the rights sentence each once, whole inside one paragraph', () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(UNAUTHENTICATED) });
    visit('/about');
    render(<App />);
    const main = screen.getByRole('main');
    const region = within(main).getByRole('region', { name: HEADING });
    const paragraphs = Array.from(region.querySelectorAll('p')).map((p) =>
      p.textContent.replace(/\s+/g, ' ').trim(),
    );
    expect(paragraphs).toEqual([STATEMENT, RIGHTS]);
    expect(region.querySelectorAll('a')).toHaveLength(0);
    const whole = main.textContent.replace(/\s+/g, ' ');
    expect(whole.split(STATEMENT)).toHaveLength(2);
    expect(whole.split(RIGHTS)).toHaveLength(2);
  });

  it.each([
    { state: 'signed out', answer: problemResponse(UNAUTHENTICATED) },
    { state: 'signed in', answer: jsonResponse(signedInMe) },
    { state: 'the demo', answer: jsonResponse(demoMe) },
  ])('shows the statement when $state, once the session answer is in', async (row) => {
    stubAccountFetch({ 'GET /v1/me': row.answer });
    visit('/about');
    render(<App />);
    await waitFor(() => {
      expect(screen.getByRole('region', { name: HEADING })).toBeTruthy();
    });
    await new Promise((resolveWait) => setTimeout(resolveWait, 20));
    const region = screen.getByRole('region', { name: HEADING });
    expect(within(region).getByText(STATEMENT)).toBeTruthy();
    expect(within(region).getByText(RIGHTS)).toBeTruthy();
    expect(screen.getAllByRole('region', { name: HEADING })).toHaveLength(1);
  });

  it('passes the forbidden words in the new section, its heading included', () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(UNAUTHENTICATED) });
    visit('/about');
    render(<App />);
    const region = screen.getByRole('region', { name: HEADING });
    const text = `${HEADING} ${region.textContent}`;
    for (const word of FORBIDDEN_WORDS) expect(text).not.toMatch(word);
    expect(text).not.toMatch(/PokePrices/);
    expect(text).not.toMatch(/\b(?:PD-i1|T\d{2}|ruling)\b/i);
  });
});

describe('the README statement', () => {
  const readme = readFileSync(repoPath('README.md'), 'utf8');
  const lines = readme.split('\n');

  it('is exactly one whole line, once in the file, inside the first 40 lines', () => {
    expect(readme.split(STATEMENT)).toHaveLength(2);
    const at = lines.indexOf(STATEMENT);
    expect(at).toBeGreaterThanOrEqual(0);
    expect(at).toBeLessThan(40);
  });

  it('keeps the rights sentence once, right after the statement, and adds no licence file', () => {
    expect(readme.split(RIGHTS)).toHaveLength(2);
    const at = lines.indexOf(STATEMENT);
    expect(lines.slice(at, at + 3)).toEqual([STATEMENT, '', RIGHTS]);
    for (const name of ['LICENSE', 'LICENSE.md', 'LICENSE.txt', 'COPYING', 'UNLICENSE']) {
      expect(existsSync(repoPath(name))).toBe(false);
    }
    expect(readme).not.toMatch(/\b(?:MIT|Apache|GPL|BSD)\b[- ]?(?:licen[sc]e|2\.0|3)?/);
  });
});
