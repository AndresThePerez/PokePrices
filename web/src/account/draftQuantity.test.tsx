// A poll never wipes a quantity being typed (Q1-i3 T02, review c8): the quantity form keeps the
// typed text as a draft beside the server's value and shows the draft over it. Only a successful
// save or another account drops the draft; an untouched form follows the server's value.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import {
  clearXsrfCookie,
  historyFor,
  holdingOf,
  jsonResponse,
  portfolioBody,
  pricedCardBody,
  signedInMe,
  stubAccountFetch,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

// App builds its own QueryClient; capture it so a test can refetch the portfolio like the poll does.
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

const CARD = 'GET /v1/cards/ex10-%3F';
const HISTORY = 'GET /v1/cards/ex10-%3F/prices?range=1M';
const CARD_VARIANT = 'ex10-?:holo:unlimited:-';
const CARD_PUT = 'PUT /v1/portfolio/ex10-%3F%3Aholo%3Aunlimited%3A-';
const ROW_PUT = 'PUT /v1/portfolio/base1-4%3Aholo%3Aunlimited%3A-';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  created.clients.length = 0;
  visit('/');
});

function client(): QueryClient {
  const first = created.clients[0];
  if (first === undefined) throw new Error('App created no QueryClient');
  return first;
}

/** Refetch ['account', 'portfolio'] the way the five-minute poll or a window focus does. */
async function pollPortfolio(): Promise<void> {
  await act(async () => {
    await client().refetchQueries({ queryKey: ['account', 'portfolio'] });
  });
  // The query client tells its observers on a 0 ms timer: let those renders run too.
  for (let round = 0; round < 3; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

function field(): HTMLInputElement {
  return within(screen.getByRole('main')).getByLabelText<HTMLInputElement>('Quantity owned');
}

function type(value: string): void {
  fireEvent.change(field(), { target: { value } });
}

function cardHolding(quantity: number): Response {
  return jsonResponse(portfolioBody([holdingOf({ variant_key: CARD_VARIANT, quantity })]));
}

function rowHolding(quantity: number): Response {
  return jsonResponse(portfolioBody([holdingOf({ quantity })]));
}

describe('the card page Add to portfolio form keeps a typed quantity over a refetch', () => {
  async function openForm(portfolio: () => Response): Promise<ReturnType<typeof stubAccountFetch>> {
    const calls = stubAccountFetch({
      [CARD]: jsonResponse(pricedCardBody),
      [HISTORY]: jsonResponse(historyFor(pricedCardBody)),
      'GET /v1/portfolio': portfolio,
      [CARD_PUT]: jsonResponse({ data: { variant_key: CARD_VARIANT, quantity: 7 } }),
    });
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    fireEvent.click(screen.getByRole('button', { name: 'Add holo, unlimited to your portfolio' }));
    await screen.findByLabelText('Quantity owned');
    return calls;
  }

  it('shows the typed 7 after the held quantity changes from 2 to 3 under the open form', async () => {
    let held = cardHolding(2);
    await openForm(() => held);
    expect(field().value).toBe('2');
    type('7');
    held = cardHolding(3);
    await pollPortfolio();
    expect(field().value).toBe('7');
  });

  it('follows the held quantity while nothing was typed', async () => {
    let held = cardHolding(2);
    await openForm(() => held);
    expect(field().value).toBe('2');
    held = cardHolding(3);
    await pollPortfolio();
    expect(field().value).toBe('3');
  });

  it('saves the typed quantity after the refetch, not the server value', async () => {
    let held = cardHolding(2);
    const calls = await openForm(() => held);
    type('7');
    held = cardHolding(3);
    await pollPortfolio();
    fireEvent.submit(field().closest('form') as HTMLFormElement);
    await screen.findByText('In your portfolio: 7.');
    const put = calls.find((call) => call.key === CARD_PUT);
    expect(put?.init?.body).toBe('{"quantity":7}');
  });
});

describe('a portfolio row keeps a typed quantity over a refetch', () => {
  async function openPortfolio(
    portfolio: () => Response,
    put: () => Response = () => jsonResponse({ data: { variant_key: 'x', quantity: 7 } }),
  ): Promise<ReturnType<typeof stubAccountFetch>> {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/portfolio': portfolio,
      [ROW_PUT]: put,
    });
    visit('/portfolio');
    render(<App />);
    await screen.findByLabelText('Quantity owned');
    return calls;
  }

  it('shows the typed 7 after the row quantity changes from 2 to 3', async () => {
    let list = rowHolding(2);
    await openPortfolio(() => list);
    expect(field().value).toBe('2');
    type('7');
    list = rowHolding(3);
    await pollPortfolio();
    expect(screen.getByText('Quantity 3')).toBeTruthy();
    expect(field().value).toBe('7');
  });

  it('follows the row quantity while nothing was typed', async () => {
    let list = rowHolding(2);
    await openPortfolio(() => list);
    list = rowHolding(3);
    await pollPortfolio();
    expect(field().value).toBe('3');
  });

  it('drops the draft after a successful save: the field shows what the list says next', async () => {
    let list = rowHolding(2);
    const calls = await openPortfolio(() => list);
    type('7');
    // The saved list answers 9 (another tab saved in between): the field follows it, not the 7.
    list = rowHolding(9);
    fireEvent.submit(field().closest('form') as HTMLFormElement);
    await screen.findByText('Quantity 9');
    expect(calls.filter((call) => call.key === ROW_PUT)).toHaveLength(1);
    expect(field().value).toBe('9');
  });
});
