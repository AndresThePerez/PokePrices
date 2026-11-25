// Adversarial tests for a watchlist Undo whose alert write fails: every status the alert write can
// answer (401, 404 as well), the list read after it failing, slow or wrongly shaped, a triple press,
// a second row removed while the owner's line shows on the first, the line never on a row that has
// an alert and never twice, a status and not a problem block, gone on saving that row's alert (Off
// too), on removing the row and on leaving the page, and focus never on the body.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  holdSessionCookieFor,
  jsonResponse,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  signedInMe,
  tooManyRequestsProblem,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
  watchlistItems,
} from '../test/fixtures';

const OWNER_LINE = 'Back on your watchlist. Its alert could not be put back; set it again.';
const PATH = 'base1-4%3Aholo%3Aunlimited%3A-';
const CHIMECHO = 'ex5-17%3Areverse%3A-%3A-';
const WATCH_PUT = `PUT /v1/watchlist/${PATH}`;
const ALERT_PUT = `PUT /v1/alerts/${PATH}`;
const ALERT_DELETE = `DELETE /v1/alerts/${PATH}`;
const DELETE = `DELETE /v1/watchlist/${PATH}`;
const LIST = 'GET /v1/watchlist';
const BASE = 'https://pokeprices.andrestheperez.com/problems/';

const [first, , chimecho] = watchlistItems;
if (first === undefined || chimecho === undefined) throw new Error('fixture');
const alerted = watchItemOf({ alert_pct: 10 });
const restored = watchItemOf({ alert_pct: null });
const before = watchlistBody([first, alerted, chimecho]);
const without = watchlistBody([first, chimecho]);
const back = watchlistBody([first, restored, chimecho]);

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
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

type Answer = Response | Promise<Response> | Error;

function deferred() {
  let release: (response: Response) => void = () => undefined;
  const promise = new Promise<Response>((resolve) => {
    release = resolve;
  });
  return { promise, release };
}

/** `METHOD url` keys answer their entries in order (the last repeats); an Error is a network failure. */
function stub(routes: Readonly<Record<string, readonly Answer[]>>): string[] {
  const names: string[] = [];
  const used = new Map<string, number>();
  holdSessionCookieFor(routes['GET /v1/me']?.[0]);
  vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
    const name = `${init?.method ?? 'GET'} ${String(input)}`;
    names.push(name);
    if (name === 'GET /sanctum/csrf-cookie') return Promise.resolve(rawResponse(204, null));
    const answers = routes[name];
    if (answers === undefined) return Promise.reject(new TypeError(`no request expected: ${name}`));
    const index = used.get(name) ?? 0;
    used.set(name, index + 1);
    const answer = answers[Math.min(index, answers.length - 1)];
    if (answer === undefined) return Promise.reject(new TypeError(`empty route: ${name}`));
    if (answer instanceof Error) return Promise.reject(answer);
    return answer instanceof Response ? Promise.resolve(answer.clone()) : answer;
  });
  return names;
}

function problemOf(status: number, slug: string, title: string, instance: string): Response {
  return problemResponse({
    type: `${BASE}${slug}`,
    title,
    status,
    instance,
    request_id: `req-edge-${String(status)}`,
  });
}

function alertFailure(status: number | 'network'): Answer {
  const instance = `/v1/alerts/${PATH}`;
  if (status === 'network') return new TypeError('network down');
  if (status === 401) return problemResponse({ ...unauthenticatedProblem, instance });
  if (status === 404) return problemOf(404, 'not-found', 'Not Found', instance);
  if (status === 429) return problemResponse(tooManyRequestsProblem(instance));
  return problemResponse(serverErrorProblem(instance));
}

function list(): HTMLElement {
  return screen.getByRole('list', { name: 'Watchlist' });
}

function names(): string[] {
  return Array.from(list().children).map((item) =>
    item.textContent.startsWith('Removed.')
      ? 'Removed.'
      : (item.querySelector('a')?.textContent ?? ''),
  );
}

async function open(routes: Readonly<Record<string, readonly Answer[]>>) {
  const calls = stub({ 'GET /v1/me': [jsonResponse(signedInMe)], ...routes });
  window.history.replaceState(null, '', '/watchlist');
  render(<App />);
  await screen.findByRole('list', { name: 'Watchlist' });
  await settle();
  return calls;
}

function removeCharizard(): void {
  fireEvent.click(
    within(list()).getByRole('button', { name: /^Remove Charizard, holo, unlimited/ }),
  );
}

/** The routes of "remove the alerted Charizard, Undo it": the list read after Undo is `afterUndo`. */
function undoRoutes(alert: Answer, afterUndo: readonly Answer[] = [jsonResponse(back)]) {
  return {
    [LIST]: [jsonResponse(before), jsonResponse(without), ...afterUndo],
    [DELETE]: [rawResponse(204, null)],
    [WATCH_PUT]: [jsonResponse({ data: { variant_key: alerted.variant_key } })],
    [ALERT_PUT]: [alert],
  };
}

function notBody(): void {
  expect(document.activeElement).not.toBe(document.body);
  expect(document.activeElement).not.toBeNull();
}

describe('the alert write fails with a status the report did not pin', () => {
  it.each([401, 404] as const)(
    'status %i: the row is back, the owner line once, a status, no problem',
    async (status) => {
      const calls = await open({
        ...undoRoutes(alertFailure(status)),
        'GET /v1/me': [jsonResponse(signedInMe)],
      });
      removeCharizard();
      const undo = await screen.findByRole('button', { name: /^Undo removing Charizard/ });
      const start = calls.length;
      fireEvent.click(undo);
      await screen.findByText(OWNER_LINE);
      await settle();
      expect(screen.getAllByText(OWNER_LINE)).toHaveLength(1);
      expect(screen.getByText(OWNER_LINE).closest('[role="status"]')).not.toBeNull();
      expect(screen.queryByText(/Could not put it back/)).toBeNull();
      expect(screen.queryByText(/Undo did not go through/)).toBeNull();
      expect(screen.queryByRole('alert')).toBeNull();
      expect(names()).toEqual(['Charizard', 'Charizard', 'Chimecho']);
      expect(
        calls.slice(start).filter((name) => name !== 'GET /v1/me' && !name.includes('csrf')),
      ).toEqual([WATCH_PUT, ALERT_PUT, LIST]);
      notBody();
      for (const word of FORBIDDEN_WORDS) expect(list().textContent).not.toMatch(word);
    },
  );
});

describe('the list read after a half Undo', () => {
  it('failing: no crash, no sentence that says Undo failed, no focus on the body, Undo not lost', async () => {
    await open(
      undoRoutes(alertFailure(500), [problemResponse(serverErrorProblem('/v1/watchlist'))]),
    );
    removeCharizard();
    fireEvent.click(await screen.findByRole('button', { name: /^Undo removing Charizard/ }));
    await settle();
    expect(screen.queryByText(/Undo did not go through/)).toBeNull();
    expect(document.body.textContent).not.toMatch(/\b(NaN|undefined)\b/);
    // The last good list is on screen and the removed line is still the way back: never a blank page.
    expect(screen.getByRole('list', { name: 'Watchlist' })).toBeTruthy();
    notBody();
  });

  it('wrong shape: the page shows its problem calmly or the last list, never a crash', async () => {
    await open(undoRoutes(alertFailure(500), [jsonResponse({ data: 'not a list' })]));
    removeCharizard();
    fireEvent.click(await screen.findByRole('button', { name: /^Undo removing Charizard/ }));
    await settle();
    expect(document.body.textContent).not.toMatch(/\b(NaN|undefined)\b/);
    expect(screen.queryByText(/Undo did not go through/)).toBeNull();
    notBody();
  });

  it('slow: Undo stays pressed (aria-disabled) and the line is not yet claimed; then row, one line, focus on the row', async () => {
    const slow = deferred();
    const calls = await open(undoRoutes(alertFailure(500), [slow.promise]));
    removeCharizard();
    const undo = await screen.findByRole('button', { name: /^Undo removing Charizard/ });
    fireEvent.click(undo);
    await settle();
    expect(undo.getAttribute('aria-disabled')).toBe('true');
    expect(screen.queryByText(/Undo did not go through/)).toBeNull();
    notBody();
    await act(async () => {
      slow.release(jsonResponse(back));
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
    await screen.findByText(OWNER_LINE);
    await settle();
    expect(screen.getAllByText(OWNER_LINE)).toHaveLength(1);
    expect(names()).toEqual(['Charizard', 'Charizard', 'Chimecho']);
    expect(calls.filter((name) => name === LIST)).toHaveLength(3);
    await waitFor(() => {
      expect(list().contains(document.activeElement)).toBe(true);
    });
  });
});

describe('presses and neighbours', () => {
  it('three presses on Undo send one row write, one alert write and one list read', async () => {
    const calls = await open(undoRoutes(alertFailure(422)));
    removeCharizard();
    const undo = await screen.findByRole('button', { name: /^Undo removing Charizard/ });
    const start = calls.length;
    fireEvent.click(undo);
    fireEvent.click(undo);
    fireEvent.click(undo);
    await screen.findByText(OWNER_LINE);
    await settle();
    expect(calls.slice(start).filter((name) => !name.includes('csrf'))).toEqual([
      WATCH_PUT,
      ALERT_PUT,
      LIST,
    ]);
    expect(screen.getAllByText(OWNER_LINE)).toHaveLength(1);
  });

  it('a second row removed while the owner line shows on the first keeps the line once, on the first', async () => {
    await open({
      ...undoRoutes(alertFailure(500), [jsonResponse(back), watchlistBodyWithout(CHIMECHO_KEY)]),
      [`DELETE /v1/watchlist/${CHIMECHO}`]: [rawResponse(204, null)],
    });
    removeCharizard();
    fireEvent.click(await screen.findByRole('button', { name: /^Undo removing Charizard/ }));
    await screen.findByText(OWNER_LINE);
    await settle();
    fireEvent.click(within(list()).getByRole('button', { name: /^Remove Chimecho/ }));
    await screen.findByRole('button', { name: /^Undo removing Chimecho/ });
    await settle();
    expect(screen.getAllByText(OWNER_LINE)).toHaveLength(1);
    const owner = screen.getByText(OWNER_LINE).closest('li');
    expect(owner?.textContent).toContain('Charizard');
    expect(owner?.textContent).not.toContain('Chimecho');
    expect(names().filter((name) => name === 'Removed.')).toHaveLength(1);
    notBody();
  });

  it('is never on a row that has an alert: another tab set one before the list was read again', async () => {
    await open(undoRoutes(alertFailure(500), [jsonResponse(before)]));
    removeCharizard();
    fireEvent.click(await screen.findByRole('button', { name: /^Undo removing Charizard/ }));
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /^Undo removing/ })).toBeNull();
    });
    await settle();
    expect(screen.queryByText(OWNER_LINE)).toBeNull();
    expect(screen.getByText('Alert: at 10%')).toBeTruthy();
    notBody();
  });
});

const CHIMECHO_KEY = 'ex5-17:reverse:-:-';
const watchlistBodyWithout = (gone: string) =>
  jsonResponse(
    watchlistBody([first, restored, chimecho].filter((item) => item.variant_key !== gone)),
  );

describe('the line goes away', () => {
  async function toTheLine(extra: Readonly<Record<string, readonly Answer[]>> = {}) {
    const calls = await open({
      ...undoRoutes(alertFailure(500), [jsonResponse(back)]),
      ...extra,
    });
    removeCharizard();
    fireEvent.click(await screen.findByRole('button', { name: /^Undo removing Charizard/ }));
    await screen.findByText(OWNER_LINE);
    await settle();
    return calls;
  }

  it('on saving the alert Off, and the line does not come back on the next poll', async () => {
    await toTheLine({
      [ALERT_DELETE]: [rawResponse(204, null)],
      [LIST]: [jsonResponse(before), jsonResponse(without), jsonResponse(back)],
    });
    const row = list().children[1] as HTMLElement;
    fireEvent.change(
      within(row).getByRole('combobox', { name: /^Alert threshold for Charizard/ }),
      {
        target: { value: 'off' },
      },
    );
    fireEvent.click(within(row).getByRole('button', { name: /^Save the alert for Charizard/ }));
    await waitFor(() => {
      expect(screen.queryByText(OWNER_LINE)).toBeNull();
    });
    await settle();
    expect(screen.queryByText(OWNER_LINE)).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('on removing the row, and Undo of that removal brings back no stale line', async () => {
    await toTheLine({
      [LIST]: [
        jsonResponse(before),
        jsonResponse(without),
        jsonResponse(back),
        jsonResponse(without),
        jsonResponse(back),
      ],
      [WATCH_PUT]: [jsonResponse({ data: { variant_key: restored.variant_key } })],
      [ALERT_PUT]: [alertFailure(500)],
    });
    fireEvent.click(
      within(list()).getByRole('button', { name: /^Remove Charizard, holo, unlimited/ }),
    );
    await screen.findByRole('button', { name: /^Undo removing Charizard/ });
    expect(screen.queryByText(OWNER_LINE)).toBeNull();
    expect(names().filter((name) => name === 'Removed.')).toHaveLength(1);
    notBody();
  });

  it('on leaving the page and coming back', async () => {
    await toTheLine({
      [LIST]: [jsonResponse(before), jsonResponse(without), jsonResponse(back)],
    });
    act(() => {
      navigate('/about');
    });
    await screen.findByRole('heading', { level: 1 });
    act(() => {
      navigate('/watchlist');
    });
    await screen.findByRole('list', { name: 'Watchlist' });
    await settle();
    expect(screen.queryByText(OWNER_LINE)).toBeNull();
    expect(names()).toEqual(['Charizard', 'Charizard', 'Chimecho']);
  });
});
