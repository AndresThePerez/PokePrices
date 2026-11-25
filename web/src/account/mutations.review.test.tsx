// Adversarial review of Q1-i3 T02 (round 1): a slow or failing refetch after a write, a typed
// quantity over errors, row changes and account changes, the single re-ask of GET /v1/me after a
// 401 write, a write on a row that cannot overlap another, and what the mutation cache keeps.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import {
  accountValidationProblem,
  clearXsrfCookie,
  historyFor,
  holdingOf,
  jsonResponse,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  workedHoldings,
  type AccountCall,
} from '../test/fixtures';
import { SESSION_SIGNAL_KEY } from './session';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const created = vi.hoisted(() => ({ clients: [] as QueryClient[] }));
vi.mock('../query', async (importOriginal) => {
  const actual = await importOriginal<typeof QueryModule>();
  return {
    ...actual,
    createQueryClient: (): QueryClient => {
      const client = actual.createQueryClient();
      created.clients.push(client);
      return client;
    },
  };
});

const ROW_VARIANT = 'base1-4%3Aholo%3Aunlimited%3A-';
const ROW_PUT = `PUT /v1/portfolio/${ROW_VARIANT}`;
const ROW_DELETE = `DELETE /v1/portfolio/${ROW_VARIANT}`;
const PORTFOLIO = 'GET /v1/portfolio';
const SENTINEL = 'sentinel-secret-0000';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.restoreAllMocks();
  window.localStorage.clear();
  created.clients.length = 0;
  visit('/');
});

function client(): QueryClient {
  const first = created.clients[0];
  if (first === undefined) throw new Error('App created no QueryClient');
  return first;
}

function count(calls: readonly AccountCall[], key: string): number {
  return calls.filter((call) => call.key === key).length;
}

async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

/** Holds the next request to `url` until release(). */
function holdNext(url: string): { readonly held: () => boolean; readonly release: () => void } {
  const inner = globalThis.fetch;
  let release: () => void = () => undefined;
  let held = false;
  vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
    if (String(input) === url && !held) {
      held = true;
      return new Promise((resolve) => {
        release = () => {
          resolve(inner(input, init));
        };
      });
    }
    return inner(input, init);
  });
  return {
    held: () => held,
    release: () => {
      release();
    },
  };
}

function rowQuantity(quantity: number): Response {
  return jsonResponse(portfolioBody([holdingOf({ quantity })]));
}

function field(): HTMLInputElement {
  return within(screen.getByRole('main')).getByLabelText<HTMLInputElement>('Quantity owned');
}

function save(): HTMLButtonElement {
  return within(screen.getByRole('main')).getByRole<HTMLButtonElement>('button', { name: 'Save' });
}

function remove(): HTMLButtonElement {
  return within(screen.getByRole('main')).getByRole<HTMLButtonElement>('button', {
    name: /^Remove /,
  });
}

function type(value: string): void {
  fireEvent.change(field(), { target: { value } });
}

async function openPortfolio(
  routes: Parameters<typeof stubAccountFetch>[0],
): Promise<AccountCall[]> {
  const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe), ...routes });
  visit('/portfolio');
  render(<App />);
  await screen.findAllByLabelText('Quantity owned');
  return calls;
}

describe('a write on a portfolio row waits for the list to be read again, and no longer', () => {
  it('a slow refetch keeps Save and Remove disabled, then frees them with the new quantity', async () => {
    const calls = await openPortfolio({
      [PORTFOLIO]: [rowQuantity(2), rowQuantity(7)],
      [ROW_PUT]: jsonResponse({ data: { variant_key: 'x', quantity: 7 } }),
    });
    const gate = holdNext('/v1/portfolio');
    type('7');
    fireEvent.click(save());
    await waitFor(() => {
      expect(gate.held()).toBe(true);
    });
    await settle();
    expect(save().disabled).toBe(true);
    expect(remove().disabled).toBe(true);
    fireEvent.click(remove());
    fireEvent.submit(field().closest('form') as HTMLFormElement);
    gate.release();
    await settle();
    expect(save().disabled).toBe(false);
    expect(remove().disabled).toBe(false);
    expect(field().value).toBe('7');
    expect(count(calls, ROW_PUT)).toBe(1);
    expect(count(calls, ROW_DELETE)).toBe(0);
  });

  it('a failing refetch frees the row and does not turn into a write failure', async () => {
    const calls = await openPortfolio({
      [PORTFOLIO]: [rowQuantity(2), problemResponse(serverErrorProblem('/v1/portfolio'))],
      [ROW_PUT]: jsonResponse({ data: { variant_key: 'x', quantity: 7 } }),
    });
    type('7');
    fireEvent.click(save());
    await settle();
    expect(count(calls, ROW_PUT)).toBe(1);
    expect(count(calls, PORTFOLIO)).toBeGreaterThanOrEqual(2);
    expect(save().disabled).toBe(false);
    expect(remove().disabled).toBe(false);
    expect(screen.queryByText(/Could not change your portfolio/)).toBeNull();
    expect(field().getAttribute('aria-invalid')).toBeNull();
  });
});

describe('a typed quantity over a failed save and a changing list', () => {
  it('a 422 keeps the typed 7 through a poll, shows the field message, and the next save is sent', async () => {
    const calls = await openPortfolio({
      [PORTFOLIO]: [rowQuantity(2), rowQuantity(3)],
      [ROW_PUT]: [
        problemResponse(
          accountValidationProblem(`/v1/portfolio/${ROW_VARIANT}`, {
            quantity: ['The quantity field must not be greater than 9999.'],
          }),
        ),
        jsonResponse({ data: { variant_key: 'x', quantity: 7 } }),
      ],
    });
    type('70000');
    fireEvent.click(save());
    await screen.findByText('The quantity field must not be greater than 9999.');
    expect(field().value).toBe('70000');
    await act(async () => {
      await client().refetchQueries({ queryKey: ['account', 'portfolio'] });
    });
    await settle();
    expect(field().value).toBe('70000');
    type('7');
    fireEvent.click(save());
    await waitFor(() => {
      expect(count(calls, ROW_PUT)).toBe(2);
    });
    await settle();
    expect(screen.queryByText('The quantity field must not be greater than 9999.')).toBeNull();
    expect(save().disabled).toBe(false);
  });

  it('a draft typed in a row stays with that row when another row above it disappears', async () => {
    let list = [...workedHoldings];
    await openPortfolio({ [PORTFOLIO]: () => jsonResponse(portfolioBody(list)) });
    const fields = (): HTMLInputElement[] =>
      within(screen.getByRole('list', { name: 'Portfolio' })).getAllByLabelText<HTMLInputElement>(
        'Quantity owned',
      );
    expect(fields().map((input) => input.value)).toEqual(['2', '1', '3']);
    fireEvent.change(fields()[2] as HTMLInputElement, { target: { value: '99' } });
    list = workedHoldings.slice(1);
    await act(async () => {
      await client().refetchQueries({ queryKey: ['account', 'portfolio'] });
    });
    await settle();
    expect(fields().map((input) => input.value)).toEqual(['1', '99']);
  });

  it('another tab switching accounts drops the typed draft: the field shows the new account', async () => {
    const other = { data: { ...signedInMe.data, email: 'other@example.com' } };
    await openPortfolio({
      'GET /v1/me': [jsonResponse(signedInMe), jsonResponse(other)],
      [PORTFOLIO]: [rowQuantity(2), rowQuantity(5)],
    });
    type('7');
    expect(field().value).toBe('7');
    // The network is slower than the query client's 0 ms notice: the loading line shows first.
    const gate = holdNext('/v1/portfolio');
    await act(async () => {
      window.dispatchEvent(
        new StorageEvent('storage', { key: SESSION_SIGNAL_KEY, newValue: '9.9' }),
      );
      await Promise.resolve();
    });
    await waitFor(() => {
      expect(gate.held()).toBe(true);
    });
    await settle();
    expect(screen.queryByLabelText('Quantity owned')).toBeNull();
    gate.release();
    await settle();
    expect(field().value).toBe('5');
  });
});

describe('a 401 on a write asks GET /v1/me exactly once', () => {
  it('a row save refused with 401 sends one more GET /v1/me, and nothing else asks for it', async () => {
    const calls = await openPortfolio({
      'GET /v1/me': [jsonResponse(signedInMe), problemResponse(unauthenticatedProblem)],
      [PORTFOLIO]: rowQuantity(2),
      [ROW_PUT]: problemResponse({
        ...unauthenticatedProblem,
        instance: `/v1/portfolio/${ROW_VARIANT}`,
      }),
    });
    expect(count(calls, 'GET /v1/me')).toBe(1);
    type('7');
    fireEvent.click(save());
    await settle();
    await settle();
    expect(count(calls, ROW_PUT)).toBe(1);
    expect(count(calls, 'GET /v1/me')).toBe(2);
  });
});

function stubCardPage(routes: Parameters<typeof stubAccountFetch>[0]): {
  readonly calls: AccountCall[];
  readonly variant: string;
  readonly path: string;
} {
  const card = pricedCardBody;
  const id = encodeURIComponent(card.data.id);
  const variant = card.data.variants[0]?.variant_key ?? '';
  const calls = stubAccountFetch({
    'GET /v1/me': jsonResponse(signedInMe),
    [`GET /v1/cards/${id}`]: jsonResponse(card),
    [`GET /v1/cards/${id}/prices?range=1M`]: jsonResponse(historyFor(card)),
    ...routes,
  });
  return { calls, variant, path: `/card/${id}` };
}

describe('the card page: one write at a time, even within one tick', () => {
  it('Add to watchlist pressed in the same tick as Save of the open portfolio form sends one write', async () => {
    const card = pricedCardBody;
    const id = card.data.id;
    const variant = card.data.variants[0]?.variant_key ?? '';
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      [`GET /v1/cards/${encodeURIComponent(id)}`]: jsonResponse(card),
      [`GET /v1/cards/${encodeURIComponent(id)}/prices?range=1M`]: jsonResponse(historyFor(card)),
      'GET /v1/portfolio': jsonResponse(portfolioBody([])),
      [`PUT /v1/watchlist/${encodeURIComponent(variant)}`]: jsonResponse({
        data: { variant_key: variant },
      }),
      [`PUT /v1/portfolio/${encodeURIComponent(variant)}`]: jsonResponse({
        data: { variant_key: variant, quantity: 1 },
      }),
      'GET /v1/watchlist': jsonResponse({
        data: [],
        meta: { count: 0, limit: 100, degraded: false },
      }),
    });
    visit(`/card/${encodeURIComponent(id)}`);
    render(<App />);
    const main = await screen.findByRole('main');
    const watch = await within(main).findAllByRole('button', { name: /to your watchlist$/ });
    const hold = within(main).getAllByRole('button', { name: /to your portfolio$/ });
    fireEvent.click(hold[0] as HTMLElement);
    const form = (await within(main).findByLabelText('Quantity owned')).closest('form');
    fireEvent.click(watch[0] as HTMLElement);
    fireEvent.submit(form as HTMLFormElement);
    await settle();
    const writes = calls.filter((call) => call.key.startsWith('PUT ')).map((call) => call.key);
    expect(writes).toHaveLength(1);
  });
});

describe('sign-in when the session cannot be read again', () => {
  it('opens the next page once even if GET /v1/me fails, with one POST /login', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [
        problemResponse(unauthenticatedProblem),
        problemResponse(serverErrorProblem('/v1/me')),
      ],
      'POST /login': jsonResponse({ two_factor: false }),
    });
    visit('/sign-in');
    render(<App />);
    const main = screen.getByRole('main');
    fireEvent.change(await within(main).findByLabelText('Email'), {
      target: { value: 'collector@example.com' },
    });
    fireEvent.change(within(main).getByLabelText('Password'), { target: { value: SENTINEL } });
    fireEvent.click(within(main).getByRole('button', { name: 'Sign in' }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    await settle();
    expect(count(calls, 'POST /login')).toBe(1);
  });
});

describe('what the mutation cache keeps of a sign-in', () => {
  function mutationText(): string {
    return JSON.stringify(
      client()
        .getMutationCache()
        .getAll()
        .map((mutation) => mutation.state),
    );
  }

  it('holds no password after a refused sign-in or after a good one', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [problemResponse(unauthenticatedProblem), jsonResponse(signedInMe)],
      'POST /login': [
        problemResponse(
          accountValidationProblem('/login', { email: ['These credentials do not match.'] }),
        ),
        jsonResponse({ two_factor: false }),
      ],
    });
    visit('/sign-in');
    render(<App />);
    const main = screen.getByRole('main');
    const email = await within(main).findByLabelText('Email');
    fireEvent.change(email, { target: { value: 'collector@example.com' } });
    fireEvent.change(within(main).getByLabelText('Password'), { target: { value: SENTINEL } });
    fireEvent.click(within(main).getByRole('button', { name: 'Sign in' }));
    await within(main).findByText('These credentials do not match.');
    expect(mutationText()).not.toContain(SENTINEL);
    fireEvent.change(within(main).getByLabelText('Password'), { target: { value: SENTINEL } });
    fireEvent.click(within(main).getByRole('button', { name: 'Sign in' }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    await settle();
    expect(count(calls, 'POST /login')).toBe(2);
    expect(mutationText()).not.toContain(SENTINEL);
    expect(client().getMutationCache().getAll().length).toBeGreaterThan(0);
  });
});

describe('a typed quantity while its own save is in flight', () => {
  it('a poll that changes the server value mid-save leaves the typed 7 in the field and in the PUT', async () => {
    let held = 2;
    const calls = await openPortfolio({
      [PORTFOLIO]: () => rowQuantity(held),
      [ROW_PUT]: () => {
        held = 7;
        return jsonResponse({ data: { variant_key: 'x', quantity: 7 } });
      },
    });
    type('7');
    const gate = holdNext(`/v1/portfolio/${ROW_VARIANT}`);
    fireEvent.click(save());
    await settle();
    held = 3;
    await act(async () => {
      await client().refetchQueries({ queryKey: ['account', 'portfolio'] });
    });
    await settle();
    expect(field().value).toBe('7');
    gate.release();
    await settle();
    const put = calls.find((call) => call.key === ROW_PUT);
    expect(put?.init?.body).toBe('{"quantity":7}');
    expect(field().value).toBe('7');
    expect(save().disabled).toBe(false);
  });
});

describe('the card page form after a refused hold', () => {
  it('a 409 keeps the typed 7 and the message, and reopening the form starts from the server value', async () => {
    const variant = pricedCardBody.data.variants[0]?.variant_key ?? '';
    const { calls, path } = stubCardPage({
      // A function answer: this visitor holds no token cookie, so the page never asks for it.
      'GET /v1/me': () => jsonResponse(signedInMe),
      'GET /v1/portfolio': jsonResponse(
        portfolioBody([holdingOf({ variant_key: variant, quantity: 2 })]),
      ),
      [`PUT /v1/portfolio/${encodeURIComponent(variant)}`]: problemResponse({
        ...serverErrorProblem('/v1/portfolio'),
        status: 409,
        title: 'Conflict',
      }),
    });
    visit(path);
    render(<App />);
    const main = await screen.findByRole('main');
    const opener = await within(main).findAllByRole('button', { name: /to your portfolio$/ });
    fireEvent.click(opener[0] as HTMLElement);
    const input = await within(main).findByLabelText<HTMLInputElement>('Quantity owned');
    expect(input.value).toBe('2');
    fireEvent.change(input, { target: { value: '7' } });
    fireEvent.submit(input.closest('form') as HTMLFormElement);
    await settle();
    expect(calls.filter((call) => call.key.startsWith('PUT '))).toHaveLength(1);
    expect(within(main).getByLabelText<HTMLInputElement>('Quantity owned').value).toBe('7');
    // Close the form and open it again: a new form, nothing of the refused draft.
    const toggle = within(main).getAllByRole('button', { name: /to your portfolio$/ })[0];
    fireEvent.click(toggle as HTMLElement);
    await settle();
    if (within(main).queryByLabelText('Quantity owned') !== null) {
      fireEvent.click(
        within(main).getAllByRole('button', { name: /to your portfolio$/ })[0] as HTMLElement,
      );
      await settle();
    }
    expect(within(main).queryByLabelText('Quantity owned')).toBeNull();
    fireEvent.click(
      within(main).getAllByRole('button', { name: /to your portfolio$/ })[0] as HTMLElement,
    );
    expect((await within(main).findByLabelText<HTMLInputElement>('Quantity owned')).value).toBe(
      '2',
    );
  });
});
