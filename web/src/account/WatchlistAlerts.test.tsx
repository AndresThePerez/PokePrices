// /watchlist, the per-card alert control (P5 T07 criteria 3, 4, 5, 6; D10): the row line, the
// threshold select and Save alert, the encoded alert URLs, the refetch, the row problem, the
// explainer, the unverified line, and the accessibility and honesty rules over the new states.
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  accountValidationProblem,
  alertBody,
  clearXsrfCookie,
  demoReadOnlyProblem,
  emailNotVerifiedProblem,
  jsonResponse,
  lightOnlyClasses,
  problemResponse,
  rawResponse,
  sentBody,
  shortControls,
  signedInMe,
  stubAccountFetch,
  unverifiedMe,
  watchItemOf,
  watchlistBody,
  watchlistItems,
  type AccountAnswer,
} from '../test/fixtures';

const CHARIZARD = 'Charizard, holo, unlimited';
const CHARIZARD_ALERT = '/v1/alerts/base1-4%3Aholo%3Aunlimited%3A-';
const EXPLAINER =
  "An alert is sent when a card's daily close moves by at least its threshold and at least $1.00 " +
  'against the previous close. Choose where alerts go on Alerts and webhooks.';
const UNVERIFIED = 'Verify your email address to set alerts.';
const FOOTNOTE = 'TCGplayer market prices via TCGCSV, collected once a day.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.useRealTimers();
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function rows(): HTMLElement[] {
  return within(screen.getByRole('list', { name: 'Watchlist' })).getAllByRole('listitem');
}

function open(routes: Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>>) {
  const calls = stubAccountFetch(routes);
  visit('/watchlist');
  const view = render(<App />);
  return { calls, container: view.container };
}

function select(name: string): HTMLSelectElement {
  return screen.getByRole<HTMLSelectElement>('combobox', { name: `Alert threshold for ${name}` });
}

function saveButton(name: string): HTMLElement {
  return screen.getByRole('button', { name: `Save the alert for ${name}` });
}

function optionTexts(element: HTMLSelectElement): (string | null)[] {
  return Array.from(element.options).map((option) => option.textContent);
}

describe('the alert control on each watchlist row (P5 T07 criterion 3)', () => {
  it('shows the line, the select with the D10 options and Save alert on every row', async () => {
    const { calls } = open({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(
        watchlistBody([
          watchItemOf({ variant_key: 'base1-4:holo:1st:-', edition: '1st', price: null }),
          watchItemOf({ alert_pct: 10 }),
          watchItemOf({
            variant_key: 'base1-4:reverse:unlimited:-',
            finish: 'reverse',
            alert_pct: 7,
          }),
        ]),
      ),
    });
    await screen.findByRole('list', { name: 'Watchlist' });
    const [off, ten, seven] = rows();
    if (off === undefined || ten === undefined || seven === undefined) {
      throw new Error('three rows expected');
    }
    expect(within(off).getByText('Alert: off')).toBeTruthy();
    expect(within(ten).getByText('Alert: at 10%')).toBeTruthy();
    expect(within(seven).getByText('Alert: at 7%')).toBeTruthy();

    const offSelect = select('Charizard, holo, 1st');
    expect(optionTexts(offSelect)).toEqual(['Off', '5%', '10%', '20%', '50%']);
    expect(offSelect.selectedOptions[0]?.textContent).toBe('Off');
    const tenSelect = select(CHARIZARD);
    expect(optionTexts(tenSelect)).toEqual(['Off', '5%', '10%', '20%', '50%']);
    expect(tenSelect.selectedOptions[0]?.textContent).toBe('10%');
    const sevenSelect = select('Charizard, reverse, unlimited');
    expect(optionTexts(sevenSelect)).toEqual(['Off', '5%', '7%', '10%', '20%', '50%']);
    expect(sevenSelect.selectedOptions[0]?.textContent).toBe('7%');

    // A visible label as well as the row's name.
    expect(main().querySelector(`label[for="${tenSelect.id}"]`)?.textContent).toBe(
      'Alert threshold',
    );
    expect(saveButton(CHARIZARD).textContent).toBe('Save alert');
    // The explainer sits under the list, before the footnote, and links to /alerts.
    const explainer = within(main()).getByText((_, element) => element?.textContent === EXPLAINER);
    expect(
      within(explainer).getByRole('link', { name: 'Alerts and webhooks' }).getAttribute('href'),
    ).toBe('/alerts');
    expect(main().textContent.endsWith(FOOTNOTE)).toBe(true);
    // Criterion 5: no settings and no webhooks request on /watchlist.
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/prices/freshness',
      'GET /v1/me',
      'GET /v1/watchlist',
    ]);
  });

  it('PUTs {"pct": n} once per click for a number, then refetches the watchlist', async () => {
    const { calls } = open({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': [
        jsonResponse(watchlistBody()),
        jsonResponse(
          watchlistBody(
            watchlistItems.map((item, index) => (index === 1 ? { ...item, alert_pct: 20 } : item)),
          ),
        ),
      ],
      [`PUT ${CHARIZARD_ALERT}`]: jsonResponse(alertBody('base1-4:holo:unlimited:-', 20)),
    });
    await screen.findByRole('list', { name: 'Watchlist' });
    fireEvent.change(select(CHARIZARD), { target: { value: '20' } });
    fireEvent.click(saveButton(CHARIZARD));
    fireEvent.click(saveButton(CHARIZARD));
    await waitFor(() => {
      expect(within(rows()[1] as HTMLElement).getByText('Alert: at 20%')).toBeTruthy();
    });
    const keys = calls.map((call) => call.key);
    const puts = calls.filter((call) => call.key === `PUT ${CHARIZARD_ALERT}`);
    expect(puts).toHaveLength(1);
    expect(sentBody(puts[0])).toEqual({ pct: 20 });
    expect(keys.filter((key) => key === 'GET /v1/watchlist')).toHaveLength(2);
    expect(keys.indexOf(`PUT ${CHARIZARD_ALERT}`)).toBeLessThan(
      keys.lastIndexOf('GET /v1/watchlist'),
    );
    expect(select(CHARIZARD).value).toBe('20');
  });

  it('sends DELETE for Off, then refetches the watchlist', async () => {
    const { calls } = open({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': [
        jsonResponse(watchlistBody([watchItemOf({ alert_pct: 10 })])),
        jsonResponse(watchlistBody([watchItemOf()])),
      ],
      [`DELETE ${CHARIZARD_ALERT}`]: rawResponse(204, null),
    });
    await screen.findByText('Alert: at 10%');
    fireEvent.change(select(CHARIZARD), { target: { value: 'off' } });
    fireEvent.click(saveButton(CHARIZARD));
    expect(await screen.findByText('Alert: off')).toBeTruthy();
    const deletes = calls.filter((call) => call.key === `DELETE ${CHARIZARD_ALERT}`);
    expect(deletes).toHaveLength(1);
    expect(deletes[0]?.init?.body).toBeUndefined();
    expect(calls.some((call) => call.key.startsWith('PUT '))).toBe(false);
  });

  it.each([
    ['ex10-?:holo:-:-', 'ex10-?', '/v1/alerts/ex10-%3F%3Aholo%3A-%3A-'],
    ['ex10-!:holo:-:-', 'ex10-!', '/v1/alerts/ex10-!%3Aholo%3A-%3A-'],
  ])('percent-encodes the key %s once in the alert URL', async (key, cardId, path) => {
    const item = watchItemOf({ variant_key: key, card_id: cardId, edition: '-', card: null });
    const { calls } = open({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody([item])),
      [`PUT ${path}`]: jsonResponse(alertBody(key, 5)),
      [`DELETE ${path}`]: rawResponse(204, null),
    });
    const name = `Card ${cardId}, holo`;
    await screen.findByRole('list', { name: 'Watchlist' });
    fireEvent.change(select(name), { target: { value: '5' } });
    fireEvent.click(saveButton(name));
    await waitFor(() => {
      expect(calls.some((call) => call.key === `PUT ${path}`)).toBe(true);
    });
    fireEvent.change(select(name), { target: { value: 'off' } });
    await waitFor(() => {
      expect(saveButton(name).hasAttribute('disabled')).toBe(false);
    });
    fireEvent.click(saveButton(name));
    await waitFor(() => {
      expect(calls.some((call) => call.key === `DELETE ${path}`)).toBe(true);
    });
    const alertCalls = calls.filter((call) => call.url.startsWith('/v1/alerts/'));
    expect(alertCalls.map((call) => call.url)).toEqual([path, path]);
    expect(alertCalls.some((call) => call.url.includes('%25'))).toBe(false);
  });

  it.each([
    [
      '422',
      accountValidationProblem(CHARIZARD_ALERT, {
        variant_key: ['Add this variant to your watchlist first.'],
      }),
      'Could not save the alert: check the fields below.',
      'Add this variant to your watchlist first.',
    ],
    [
      '403',
      emailNotVerifiedProblem(CHARIZARD_ALERT),
      'Could not save the alert: Verify Your Email First',
      null,
    ],
    ['403 demo', demoReadOnlyProblem(CHARIZARD_ALERT), 'The demo account is read-only.', null],
  ] as const)(
    'shows a %s beside the row and keeps the list',
    async (_name, problem, line, field) => {
      const { calls } = open({
        'GET /v1/me': jsonResponse(signedInMe),
        'GET /v1/watchlist': jsonResponse(watchlistBody()),
        [`PUT ${CHARIZARD_ALERT}`]: problemResponse(problem),
      });
      await screen.findByRole('list', { name: 'Watchlist' });
      fireEvent.change(select(CHARIZARD), { target: { value: '10' } });
      fireEvent.click(saveButton(CHARIZARD));
      const alert = await screen.findByRole('alert');
      expect(alert.textContent).toContain(line);
      // Beside the row it belongs to.
      const row = rows()[1] as HTMLElement;
      expect(row.contains(alert)).toBe(true);
      if (field !== null) {
        const message = within(row).getByText(field);
        expect(select(CHARIZARD).getAttribute('aria-invalid')).toBe('true');
        expect(select(CHARIZARD).getAttribute('aria-describedby')).toBe(message.id);
      }
      expect(rows()).toHaveLength(3);
      expect(within(row).getByText('Alert: off')).toBeTruthy();
      expect(calls.filter((call) => call.key === 'GET /v1/watchlist')).toHaveLength(1);
    },
  );
});

describe('who sees the alert control (P5 T07 criterion 4)', () => {
  it('unverified: no control, the one line, Remove still there', async () => {
    const { calls } = open({
      'GET /v1/me': jsonResponse(unverifiedMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    expect(await screen.findByText(UNVERIFIED)).toBeTruthy();
    expect(screen.getAllByText(UNVERIFIED)).toHaveLength(1);
    expect(within(main()).queryByRole('combobox')).toBeNull();
    expect(screen.queryByRole('button', { name: /^Save the alert/ })).toBeNull();
    expect(screen.queryByText(/^Alert: /)).toBeNull();
    expect(screen.queryByText(EXPLAINER)).toBeNull();
    expect(screen.getAllByRole('button', { name: /^Remove / })).toHaveLength(3);
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/prices/freshness',
      'GET /v1/me',
      'GET /v1/watchlist',
    ]);
  });

  it('an empty watchlist shows no explainer', async () => {
    open({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody([])),
    });
    await screen.findByText(/Your watchlist is empty/);
    expect(screen.queryByText(/An alert is sent/)).toBeNull();
  });
});

describe('the alert control is accessible and honest (P5 T07 criterion 6)', () => {
  it.each([
    ['verified', signedInMe, 'Alert: at 50%'],
    ['unverified', unverifiedMe, UNVERIFIED],
  ] as const)(
    '%s: 44 px controls, labels, dark-ready, no forbidden word, no clock',
    async (_n, me, ready) => {
      vi.useFakeTimers({ toFake: ['Date'] });
      vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
      const { container } = open({
        'GET /v1/me': jsonResponse(me),
        'GET /v1/watchlist': jsonResponse(
          watchlistBody([
            ...watchlistItems,
            watchItemOf({
              variant_key: 'base1-4:reverse:-:-',
              finish: 'reverse',
              edition: '-',
              alert_pct: 50,
            }),
          ]),
        ),
        [`PUT ${CHARIZARD_ALERT}`]: problemResponse(emailNotVerifiedProblem(CHARIZARD_ALERT)),
      });
      await screen.findByText(ready);
      const check = (): void => {
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(lightOnlyClasses(container)).toEqual([]);
        expect(shortControls(main())).toEqual([]);
        for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
        expect(main().textContent).not.toContain('2031');
        for (const control of Array.from(main().querySelectorAll('input, select'))) {
          expect(control.id).not.toBe('');
          expect(main().querySelector(`label[for="${control.id}"]`)?.textContent).toBeTruthy();
        }
      };
      check();
      if (me === signedInMe) {
        fireEvent.click(saveButton(CHARIZARD));
        await screen.findByRole('alert');
        check();
      }
    },
  );
});
