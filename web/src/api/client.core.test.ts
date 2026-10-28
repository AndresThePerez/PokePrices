// Q1-i4 T06: the client has one request pipeline (core) for the public reads and the account
// requests, and the reads decode the fields their pages read. A 200 whose fields do not match the
// contract is the unexpected-answer failure, so the page shows its problem block instead of throwing
// in render; the public and account differences of the old two pipelines stay as they were.
import { cleanup, render, screen } from '@testing-library/react';
import { createElement } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  cardBody,
  clearXsrfCookie,
  jsonResponse,
  moverOf,
  moversBody,
  rawResponse,
  setXsrfCookie,
  stubAccountFetch,
  stubFetch,
} from '../test/fixtures';
import { getCard, getMe, getMovers, searchCards, signOut } from './client';

const UNEXPECTED_TITLE = 'The PokéPrices API sent an unexpected answer (HTTP 200)';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

describe('a 200 whose fields miss the contract shows the problem block (RED 1)', () => {
  it('/movers with a numeric change shows the movers problem, not a crash', async () => {
    const mover = { ...moverOf(), change: 5 };
    stubFetch(() => jsonResponse({ ...moversBody('up', []), data: [mover] }));
    visit('/movers');
    render(createElement(App));
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(`Could not load the movers: ${UNEXPECTED_TITLE}`);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Biggest moves');
  });

  it('/card/:id with variants that are not a list shows the card problem, not a crash', async () => {
    stubFetch(() => jsonResponse({ ...cardBody, data: { ...cardBody.data, variants: 'none' } }));
    visit('/card/ex10-28');
    render(createElement(App));
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(`Could not load this card: ${UNEXPECTED_TITLE}`);
  });

  it('GET /v1/me without data.email is the unexpected answer, not a cast account', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse({
        data: { email_verified: true, two_factor: false, read_only: false },
      }),
    });
    expect(await getMe()).toEqual({
      ok: false,
      status: 200,
      type: 'about:blank',
      title: UNEXPECTED_TITLE,
      requestId: null,
      problem: null,
    });
  });
});

describe('one pipeline keeps the public and account differences (characterisation)', () => {
  it('a public read sends Accept only, with no credentials, method or redirect flag', async () => {
    const calls = stubFetch(() => jsonResponse(moversBody('up', [])));
    await getMovers('up', 5);
    expect(calls).toHaveLength(1);
    expect(calls[0]?.url).toBe('/v1/movers?direction=up&limit=5');
    expect(calls[0]?.init).toEqual({ headers: { Accept: 'application/json' } });
  });

  it('an empty 200 is a failure on a public read and ok with no data on an account write', async () => {
    stubFetch(() => rawResponse(200, ''));
    expect(await searchCards({ q: 'unown' })).toEqual({
      ok: false,
      status: 200,
      type: 'about:blank',
      title: 'The PokéPrices API answered HTTP 200',
      requestId: null,
      problem: null,
    });
    setXsrfCookie();
    stubAccountFetch({ 'POST /logout': rawResponse(204, null) });
    expect(await signOut()).toEqual({ ok: true, data: undefined });
  });

  it('a 200 that is not JSON keeps its title per side: answered on public, unexpected on account', async () => {
    stubFetch(() => rawResponse(200, '<!doctype html>', 'text/html'));
    const publicResult = await getCard('ex10-28');
    expect(publicResult.ok ? '' : publicResult.title).toBe('The PokéPrices API answered HTTP 200');
    setXsrfCookie();
    stubAccountFetch({ 'POST /logout': rawResponse(200, '<!doctype html>', 'text/html') });
    const accountResult = await signOut();
    expect(accountResult.ok ? '' : accountResult.title).toBe(UNEXPECTED_TITLE);
  });

  it('a network failure has the same unreachable title on both sides', async () => {
    const unreachable = {
      ok: false,
      status: 0,
      type: 'about:blank',
      title: 'The PokéPrices API could not be reached',
      requestId: null,
      problem: null,
    };
    stubFetch(() => new TypeError('offline'));
    expect(await getMovers('up', 5)).toEqual(unreachable);
    stubAccountFetch({ 'GET /v1/me': new TypeError('offline') });
    expect(await getMe()).toEqual(unreachable);
  });
});
