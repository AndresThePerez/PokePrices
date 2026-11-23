// A watchlist Undo of a row that had an alert is two writes: the row, then its alert (T09 fix round
// 1, point A). When the row's write succeeds and only the alert's fails, the row is back on the
// server without its alert, so the page reads the list again and shows the row as the server holds
// it, with no "Removed." line and no problem block, and the row carries one calm status line, in the
// owner's words. The line goes when the visitor saves the row's alert, removes the row or leaves
// the page. When the row's own write fails, nothing changes: "Removed. Undo did not go through."
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  FORBIDDEN_WORDS,
  accountValidationProblem,
  clearXsrfCookie,
  jsonResponse,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  watchItemOf,
  watchlistBody,
  watchlistItems,
  type AccountAnswer,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const OWNER_LINE = 'Back on your watchlist. Its alert could not be put back; set it again.';
const KEY = 'base1-4:holo:unlimited:-';
const PATH = 'base1-4%3Aholo%3Aunlimited%3A-';
const WATCH_PUT = `PUT /v1/watchlist/${PATH}`;
const ALERT_PUT = `PUT /v1/alerts/${PATH}`;
const DELETE = `DELETE /v1/watchlist/${PATH}`;
const LIST = 'GET /v1/watchlist';
const NAME = 'Charizard, holo, unlimited';

const [first, , chimecho] = watchlistItems;
if (first === undefined || chimecho === undefined) throw new Error('fixture');
const alerted = watchItemOf({ alert_pct: 10 });
const restored = watchItemOf({ alert_pct: null });
const before = watchlistBody([first, alerted, chimecho]);
const without = watchlistBody([first, chimecho]);
const back = watchlistBody([first, restored, chimecho]);

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  window.localStorage.clear();
  window.history.replaceState(null, '', '/');
});

async function settle(): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

type Routes = Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>>;

async function open(routes: Routes) {
  const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe), ...routes });
  window.history.replaceState(null, '', '/watchlist');
  render(<App />);
  await screen.findByRole('list', { name: 'Watchlist' });
  await settle();
  return calls;
}

function list(): HTMLElement {
  return screen.getByRole('list', { name: 'Watchlist' });
}

/** Each item of the list: the row's variant line, or "Removed." for the line. */
function items(): string[] {
  return Array.from(list().children).map((item) =>
    item.textContent.startsWith('Removed.')
      ? 'Removed.'
      : (item.querySelector('a')?.textContent ?? '') +
        (item.textContent.includes('1st') ? ' 1st' : ''),
  );
}

function row(index: number): HTMLElement {
  const item = list().children[index];
  if (!(item instanceof HTMLElement)) throw new Error(`no row ${String(index)}`);
  return item;
}

/** Remove Charizard holo unlimited, then press its Undo; returns the calls' count before Undo. */
async function removeThenUndo(calls: readonly { key: string }[]): Promise<number> {
  fireEvent.click(
    within(list()).getByRole('button', { name: `Remove ${NAME} from your watchlist` }),
  );
  const undo = await screen.findByRole('button', {
    name: `Undo removing ${NAME} from your watchlist`,
  });
  await settle();
  const start = calls.length;
  fireEvent.click(undo);
  return start;
}

describe('only the alert fails to be put back', () => {
  it.each([
    [
      'a 422',
      problemResponse(
        accountValidationProblem(`/v1/alerts/${PATH}`, { pct: ['The pct field is invalid.'] }),
      ),
    ],
    ['a 429', problemResponse(tooManyRequestsProblem(`/v1/alerts/${PATH}`))],
    ['a 500', problemResponse(serverErrorProblem(`/v1/alerts/${PATH}`))],
    ['a network error', new Error('network down')],
  ] as const)(
    'after %s the row is back without its alert, with the owner line once and focus on it',
    async (...cells) => {
      const [, failure] = cells;
      const calls = await open({
        [LIST]: [jsonResponse(before), jsonResponse(without), jsonResponse(back)],
        [DELETE]: rawResponse(204, null),
        [WATCH_PUT]: jsonResponse({ data: { variant_key: KEY } }),
        [ALERT_PUT]: failure,
      });
      const start = await removeThenUndo(calls);

      await within(list()).findByText(OWNER_LINE);
      await settle();
      expect(items()).toEqual(['Charizard 1st', 'Charizard', 'Chimecho']);
      expect(screen.getAllByText(OWNER_LINE)).toHaveLength(1);
      const status = within(row(1)).getByText(OWNER_LINE);
      expect(status.closest('[role="status"]')).not.toBeNull();
      expect(within(row(1)).getByText('Alert: off')).toBeTruthy();
      expect(screen.queryByText(/Removed\./)).toBeNull();
      expect(screen.queryByText(/Could not put it back/)).toBeNull();
      expect(screen.queryByRole('alert')).toBeNull();
      // The alert is set again on the row itself: its threshold select and Save alert are there.
      expect(
        within(row(1)).getByRole('combobox', { name: `Alert threshold for ${NAME}` }),
      ).toBeTruthy();
      expect(
        within(row(1)).getByRole('button', { name: `Save the alert for ${NAME}` }),
      ).toBeTruthy();
      // Focus: the restored row's first control, never the body.
      expect(document.activeElement).toBe(row(1).querySelector('a, button, input'));
      expect(calls.slice(start).map((call) => call.key)).toEqual([WATCH_PUT, ALERT_PUT, LIST]);
      for (const word of FORBIDDEN_WORDS) expect(row(1).textContent).not.toMatch(word);
    },
  );

  it('the line goes once the visitor saves an alert on the row', async () => {
    const calls = await open({
      [LIST]: [
        jsonResponse(before),
        jsonResponse(without),
        jsonResponse(back),
        jsonResponse(watchlistBody([first, watchItemOf({ alert_pct: 20 }), chimecho])),
      ],
      [DELETE]: rawResponse(204, null),
      [WATCH_PUT]: jsonResponse({ data: { variant_key: KEY } }),
      [ALERT_PUT]: [
        problemResponse(serverErrorProblem(`/v1/alerts/${PATH}`)),
        jsonResponse({ data: { variant_key: KEY, alert_pct: 20 } }),
      ],
    });
    await removeThenUndo(calls);
    await within(list()).findByText(OWNER_LINE);
    fireEvent.change(
      within(row(1)).getByRole('combobox', { name: `Alert threshold for ${NAME}` }),
      {
        target: { value: '20' },
      },
    );
    fireEvent.click(within(row(1)).getByRole('button', { name: `Save the alert for ${NAME}` }));
    await within(row(1)).findByText('Alert: at 20%');
    await settle();
    expect(screen.queryByText(OWNER_LINE)).toBeNull();
  });

  it('the line goes when the visitor removes the row', async () => {
    const calls = await open({
      [LIST]: [
        jsonResponse(before),
        jsonResponse(without),
        jsonResponse(back),
        jsonResponse(without),
      ],
      [DELETE]: rawResponse(204, null),
      [WATCH_PUT]: jsonResponse({ data: { variant_key: KEY } }),
      [ALERT_PUT]: problemResponse(serverErrorProblem(`/v1/alerts/${PATH}`)),
    });
    await removeThenUndo(calls);
    await within(list()).findByText(OWNER_LINE);
    fireEvent.click(
      within(list()).getByRole('button', { name: `Remove ${NAME} from your watchlist` }),
    );
    await screen.findByRole('button', { name: `Undo removing ${NAME} from your watchlist` });
    await settle();
    expect(screen.queryByText(OWNER_LINE)).toBeNull();
    expect(items()).toEqual(['Charizard 1st', 'Removed.', 'Chimecho']);
  });

  it('the line goes when the visitor leaves the page and comes back', async () => {
    const calls = await open({
      [LIST]: [jsonResponse(before), jsonResponse(without), jsonResponse(back)],
      [DELETE]: rawResponse(204, null),
      [WATCH_PUT]: jsonResponse({ data: { variant_key: KEY } }),
      [ALERT_PUT]: problemResponse(serverErrorProblem(`/v1/alerts/${PATH}`)),
    });
    await removeThenUndo(calls);
    await within(list()).findByText(OWNER_LINE);
    act(() => {
      navigate('/about');
    });
    await settle();
    act(() => {
      navigate('/watchlist');
    });
    await screen.findByRole('list', { name: 'Watchlist' });
    await settle();
    expect(items()).toEqual(['Charizard 1st', 'Charizard', 'Chimecho']);
    expect(screen.queryByText(OWNER_LINE)).toBeNull();
  });
});

describe("the row's own write fails", () => {
  it('stays as before: "Removed. Undo did not go through.", the problem, Undo offered again, no alert write', async () => {
    const calls = await open({
      [LIST]: [jsonResponse(before), jsonResponse(without)],
      [DELETE]: rawResponse(204, null),
      [WATCH_PUT]: problemResponse(serverErrorProblem(`/v1/watchlist/${PATH}`)),
    });
    const start = await removeThenUndo(calls);
    await screen.findByText(/Could not put it back: Internal Server Error/);
    expect(items()).toEqual(['Charizard 1st', 'Removed.', 'Chimecho']);
    expect(row(1).textContent).toContain('Removed. Undo did not go through.');
    const again = within(row(1)).getByRole('button', {
      name: `Undo removing ${NAME} from your watchlist`,
    });
    expect(again.getAttribute('aria-disabled')).toBeNull();
    await waitFor(() => {
      expect(document.activeElement).toBe(again);
    });
    expect(screen.queryByText(OWNER_LINE)).toBeNull();
    expect(calls.slice(start).map((call) => call.key)).toEqual([WATCH_PUT]);
  });
});
