// T10 review: the watchlist and portfolio moved onto the design tokens without changing markup.
// These guard what the visual migration touched and no other suite pins: the alert select after it
// took the shared SELECT box, the pinned strings as single text nodes, the quantity error staying
// in its own row, and no image or extra heading entering the lists.
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  accountValidationProblem,
  clearXsrfCookie,
  demoMe,
  jsonResponse,
  portfolioBody,
  problemResponse,
  signedInMe,
  stubAccountFetch,
  watchItemOf,
  watchlistBody,
  holdingOf,
} from '../test/fixtures';

const FOOTNOTE = 'TCGplayer market prices via TCGCSV, collected once a day.';
const CHARIZARD_ALERT = '/v1/alerts/base1-4%3Aholo%3Aunlimited%3A-';
const CHARIZARD_HOLDING = '/v1/portfolio/base1-4%3Aholo%3Aunlimited%3A-';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function ownText(element: Element): string {
  return Array.from(element.childNodes)
    .filter((node) => node.nodeType === Node.TEXT_NODE)
    .map((node) => node.textContent)
    .join('');
}

describe('the watchlist alert select after the shared SELECT swap (T10)', () => {
  it('is still a named select with the same values, labelled, in the row order link, select, Save alert, Remove, and clean until a field error', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody([watchItemOf({ alert_pct: 7 })])),
      [`PUT ${CHARIZARD_ALERT}`]: problemResponse(
        accountValidationProblem(CHARIZARD_ALERT, { pct: ['The pct must be between 1 and 99.'] }),
      ),
    });
    visit('/watchlist');
    render(<App />);
    const list = await screen.findByRole('list', { name: 'Watchlist' });
    const row = within(list).getAllByRole('listitem')[0] as HTMLElement;
    const select = within(row).getByRole<HTMLSelectElement>('combobox');

    expect(select.tagName).toBe('SELECT');
    expect(select.name).toBe('alert_pct');
    expect(Array.from(select.options).map((option) => option.value)).toEqual([
      'off',
      '5',
      '7',
      '10',
      '20',
      '50',
    ]);
    expect(select.value).toBe('7');
    expect(row.querySelector(`label[for="${select.id}"]`)?.textContent).toBe('Alert threshold');
    expect(select.hasAttribute('aria-invalid')).toBe(false);
    expect(select.hasAttribute('aria-describedby')).toBe(false);

    const order = Array.from(row.querySelectorAll('a, select, button')).map(
      (element) => element.getAttribute('aria-label') ?? element.textContent,
    );
    expect(order).toEqual([
      'Charizard',
      'Alert threshold for Charizard, holo, unlimited',
      'Save the alert for Charizard, holo, unlimited',
      'Remove Charizard, holo, unlimited from your watchlist',
    ]);

    fireEvent.change(select, { target: { value: '5' } });
    fireEvent.click(
      within(row).getByRole('button', { name: 'Save the alert for Charizard, holo, unlimited' }),
    );
    const message = await within(row).findByText('The pct must be between 1 and 99.');
    expect(message.tagName).toBe('P');
    expect(select.getAttribute('aria-invalid')).toBe('true');
    expect(select.getAttribute('aria-describedby')).toBe(message.id);
    expect(calls.filter((call) => call.key === `PUT ${CHARIZARD_ALERT}`)).toHaveLength(1);
  });
});

describe('the list strings stay single text nodes in their own elements (T10)', () => {
  it('prints Total value, its as-of line, Quantity, Value and the footnote each as one text node, the as-of line right under the total', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/portfolio': jsonResponse(
        portfolioBody([holdingOf({ quantity: 3, value: '2906.73' })], {
          total_value: '2906.73',
          priced: 1,
          unpriced: 0,
          first_day: '2026-10-12',
          last_day: '2026-10-12',
        }),
      ),
    });
    visit('/portfolio');
    render(<App />);
    const total = await screen.findByText('Total value $2,906.73');
    const asOf = total.nextElementSibling;
    expect(total.tagName).toBe('P');
    expect(total.childNodes).toHaveLength(1);
    if (asOf === null) throw new Error('the as-of line sits right under the total');
    expect(asOf.tagName).toBe('P');
    expect(asOf.childNodes).toHaveLength(1);
    expect(asOf.textContent).toBe('as of 2026-10-12 from TCGCSV (daily close)');
    // One day, one line: no "Closes from" variant beside it.
    expect(screen.queryByText(/^Closes from/)).toBeNull();

    const row = within(screen.getByRole('list', { name: 'Portfolio' })).getAllByRole('listitem')[0];
    if (row === undefined) throw new Error('one row expected');
    for (const text of ['Quantity 3', 'Value $2,906.73 (3 × $968.91)', 'Market price $968.91']) {
      const element = within(row).getByText(text);
      expect(element.childNodes, text).toHaveLength(1);
      expect(ownText(element)).toBe(text);
    }
    const footnote = screen.getByText(FOOTNOTE);
    expect(footnote.tagName).toBe('P');
    expect(footnote.childNodes).toHaveLength(1);
    expect(main().lastElementChild).toBe(footnote);
    expect(screen.getAllByText(FOOTNOTE)).toHaveLength(1);
  });

  it('adds no image, svg or extra heading to the demo watchlist and portfolio, and no request beyond the session and the list', async () => {
    for (const [path, listName, key, body] of [
      ['/watchlist', 'Watchlist', 'GET /v1/watchlist', watchlistBody()],
      ['/portfolio', 'Portfolio', 'GET /v1/portfolio', portfolioBody()],
    ] as const) {
      const calls = stubAccountFetch({
        'GET /v1/me': jsonResponse(demoMe),
        [key]: jsonResponse(body),
      });
      visit(path);
      const { unmount } = render(<App />);
      await screen.findByRole('list', { name: listName });
      expect(main().querySelectorAll('img, svg[role="img"]'), path).toHaveLength(0);
      expect(
        within(main())
          .getAllByRole('heading')
          .map((heading) => `${heading.tagName} ${heading.textContent}`),
        path,
      ).toEqual([`H1 ${listName}`]);
      expect(
        calls.map((call) => call.key),
        path,
      ).toEqual(['GET /v1/me', key]);
      expect(screen.getAllByText(FOOTNOTE), path).toHaveLength(1);
      unmount();
      cleanup();
    }
  });
});

describe('a quantity error stays in the row that failed (T10)', () => {
  it('marks only the saved holding invalid, wires its describedby to the error text, and leaves the other rows clean', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/portfolio': jsonResponse(portfolioBody()),
      [`PUT ${CHARIZARD_HOLDING}`]: problemResponse(
        accountValidationProblem(CHARIZARD_HOLDING, {
          quantity: ['The quantity must be between 1 and 9999.'],
        }),
      ),
    });
    visit('/portfolio');
    render(<App />);
    const list = await screen.findByRole('list', { name: 'Portfolio' });
    const rows = within(list).getAllByRole('listitem');
    expect(rows).toHaveLength(3);
    const charizard = rows[1] as HTMLElement;
    fireEvent.change(within(charizard).getByLabelText('Quantity owned'), {
      target: { value: '0' },
    });
    fireEvent.click(within(charizard).getByRole('button', { name: 'Save' }));
    const message = await within(charizard).findByText('The quantity must be between 1 and 9999.');
    expect(message.tagName).toBe('P');
    const field = within(charizard).getByLabelText('Quantity owned');
    expect(field.getAttribute('aria-invalid')).toBe('true');
    expect(field.getAttribute('aria-describedby')).toBe(message.id);

    const invalid = list.querySelectorAll('[aria-invalid]');
    expect(Array.from(invalid)).toEqual([field]);
    const ids = Array.from(list.querySelectorAll('input[name="quantity"]')).map(
      (input) => input.id,
    );
    expect(new Set(ids).size).toBe(3);
    // The failed save keeps the rows and their pinned values.
    expect(within(charizard).getByText('Quantity 1')).toBeTruthy();
    expect(screen.getByText('Total value $1,047.93')).toBeTruthy();
  });
});
