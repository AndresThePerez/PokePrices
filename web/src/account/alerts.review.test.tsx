// Adversarial edge cases for P5 T07 (the alert settings and the per-card alert control): another
// account's settings after a sign-out, a save that lands after the sign-out, keys that stress the
// one-encode rule, rows that fail on their own, the stored-Discord state, double submits.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { deleteAlert, putAlert } from '../api/client';
import { navigate } from '../router';
import {
  FORBIDDEN_WORDS,
  accountValidationProblem,
  alertBody,
  clearXsrfCookie,
  emailNotVerifiedProblem,
  jsonResponse,
  lightOnlyClasses,
  notificationsBody,
  problemResponse,
  rawResponse,
  sentBody,
  serverErrorProblem,
  shortControls,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
  webhooksBody,
  type AccountAnswer,
} from '../test/fixtures';

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

function group(): HTMLElement {
  return screen.getByRole('group', { name: 'Send alerts and the digest by' });
}

type Routes = Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>>;

const A_SETTINGS = notificationsBody({ channel: 'discord', digest: true, discord_available: true });
const B_ME = { data: { ...signedInMe.data, email: 'second@example.com' } };

/** Sign account A out through the account page (the real SignOut), landing on the home page. */
async function signOut(): Promise<void> {
  fireEvent.click(screen.getByRole('link', { name: 'Account' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
  await screen.findByRole('heading', { level: 1, name: 'PokéPrices' });
}

describe('review: one account settings never show under the next (D8, privacy)', () => {
  it('does not render the first account settings when the next account settings cannot load', async () => {
    let me: Response = jsonResponse(signedInMe);
    let settings: Response = jsonResponse(A_SETTINGS);
    stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': () => settings,
      'POST /logout': rawResponse(204, null),
    });
    visit('/alerts');
    render(<App />);
    await waitFor(() => {
      expect(
        within(group()).getByRole<HTMLInputElement>('radio', { name: 'Discord' }).checked,
      ).toBe(true);
    });
    me = problemResponse(unauthenticatedProblem);
    await signOut();

    // Account B is signed in (as a fresh session) and opens /alerts; its settings request fails.
    me = jsonResponse(B_ME);
    settings = problemResponse(serverErrorProblem('/v1/me/notifications'));
    act(() => {
      navigate('/alerts');
    });
    expect(await screen.findByText(/Could not load your alert settings/)).toBeTruthy();
    expect(screen.queryByRole('group', { name: 'Send alerts and the digest by' })).toBeNull();
    expect(
      screen.queryByRole<HTMLInputElement>('checkbox', { name: 'Send me a daily digest' }),
    ).toBeNull();
    expect(main().textContent).not.toContain('collector@example.com');
  });

  it('does not let a save that lands after the sign-out put the first account settings in the cache', async () => {
    let me: Response = jsonResponse(signedInMe);
    let settings: Response = jsonResponse(A_SETTINGS);
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': () => settings,
      'POST /logout': rawResponse(204, null),
    });
    // Hold the PUT: the answer arrives only after the sign-out.
    const inner = globalThis.fetch;
    let release: (() => void) | undefined;
    vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
      if ((init?.method ?? 'GET') === 'PUT' && String(input) === '/v1/me/notifications') {
        calls.push({ url: String(input), init, key: 'PUT /v1/me/notifications' });
        return new Promise<Response>((resolve) => {
          release = () => {
            resolve(jsonResponse(notificationsBody({ channel: 'discord', digest: true })));
          };
        });
      }
      return inner(input, init);
    });
    visit('/alerts');
    render(<App />);
    await waitFor(() => {
      expect(group()).toBeTruthy();
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => {
      expect(release).toBeDefined();
    });
    me = problemResponse(unauthenticatedProblem);
    await signOut();
    // The slow answer for account A now arrives.
    await act(async () => {
      release?.();
      await Promise.resolve();
    });
    // Account B signs in and opens /alerts while its own settings request fails.
    me = jsonResponse(B_ME);
    settings = problemResponse(serverErrorProblem('/v1/me/notifications'));
    act(() => {
      navigate('/alerts');
    });
    await screen.findByRole('heading', { level: 2, name: 'Where alerts go' });
    await waitFor(() => {
      expect(calls.filter((call) => call.key === 'GET /v1/me/notifications')).toHaveLength(2);
    });
    expect(await screen.findByText(/Could not load your alert settings/)).toBeTruthy();
    expect(screen.queryByRole('group', { name: 'Send alerts and the digest by' })).toBeNull();
  });
});

describe('review: variant keys are encoded exactly once, never collapsed, never double-encoded', () => {
  const KEYS: readonly (readonly [string, string])[] = [
    ['!', '!'],
    ['!:!:!:!', '!%3A!%3A!%3A!'],
    ['ex10-?:holo:-:-', 'ex10-%3F%3Aholo%3A-%3A-'],
    ['50%:holo:-:-', '50%25%3Aholo%3A-%3A-'],
    ['%2F', '%252F'],
    ['a/b', 'a%2Fb'],
    ['é:holo:-:-', '%C3%A9%3Aholo%3A-%3A-'],
    ['ポケ:ホロ', '%E3%83%9D%E3%82%B1%3A%E3%83%9B%E3%83%AD'],
    ['a..', 'a..'],
  ];

  it.each(KEYS)(
    'sends %s as one segment that decodes back to the stored key',
    async (key, segment) => {
      const calls = stubAccountFetch({
        [`PUT /v1/alerts/${segment}`]: jsonResponse(alertBody(key, 5)),
        [`DELETE /v1/alerts/${segment}`]: rawResponse(204, null),
      });
      expect((await putAlert(key, 5)).ok).toBe(true);
      expect((await deleteAlert(key)).ok).toBe(true);
      expect(calls.map((call) => call.url).filter((url) => url.startsWith('/v1/alerts/'))).toEqual([
        `/v1/alerts/${segment}`,
        `/v1/alerts/${segment}`,
      ]);
      // What the API's `.+` route sees after one percent-decode is the stored key, byte for byte.
      expect(decodeURIComponent(segment)).toBe(key);
      // The path stays one segment under URL resolution.
      expect(new URL(`/v1/alerts/${segment}`, 'http://example.test').pathname).toBe(
        `/v1/alerts/${segment}`,
      );
    },
  );

  it.each(['', '.', '..'])('never reaches the network for the collapsing key %j', async (key) => {
    const calls = stubAccountFetch({});
    const put = await putAlert(key, 5);
    const del = await deleteAlert(key);
    expect(put.ok).toBe(false);
    expect(del.ok).toBe(false);
    expect(calls).toEqual([]);
  });
});

describe('review: each watchlist row fails on its own', () => {
  const ONE = 'base1-4:holo:1st:-';
  const TWO = 'ex5-17:reverse:-:-';
  const ONE_PATH = '/v1/alerts/base1-4%3Aholo%3A1st%3A-';
  const TWO_PATH = '/v1/alerts/ex5-17%3Areverse%3A-%3A-';

  it('keeps an error inside its row, leaves the other row clean, and lets Save run again after the error', async () => {
    let first: Response = problemResponse(
      accountValidationProblem(ONE_PATH, { pct: ['Choose a whole percentage from 5 to 100.'] }),
    );
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(
        watchlistBody([
          watchItemOf({ variant_key: ONE, edition: '1st', price: null }),
          watchItemOf({
            variant_key: TWO,
            card_id: 'ex5-17',
            finish: 'reverse',
            edition: '-',
          }),
        ]),
      ),
      [`PUT ${ONE_PATH}`]: () => first,
      [`PUT ${TWO_PATH}`]: problemResponse(emailNotVerifiedProblem(TWO_PATH)),
    });
    visit('/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    const items = within(screen.getByRole('list', { name: 'Watchlist' })).getAllByRole('listitem');
    const [one, two] = items;
    if (one === undefined || two === undefined) throw new Error('two rows expected');

    fireEvent.change(within(one).getByRole('combobox'), { target: { value: '10' } });
    fireEvent.click(within(one).getByRole('button', { name: /^Save the alert/ }));
    await within(one).findByText('Choose a whole percentage from 5 to 100.');
    expect(within(two).queryByRole('alert')).toBeNull();

    fireEvent.change(within(two).getByRole('combobox'), { target: { value: '20' } });
    fireEvent.click(within(two).getByRole('button', { name: /^Save the alert/ }));
    await within(two).findByText(/Could not save the alert: Verify Your Email First/);
    // Row one's error is still its own and row two's is not in row one.
    expect(within(one).getByText('Choose a whole percentage from 5 to 100.')).toBeTruthy();
    expect(within(one).queryByText(/Verify Your Email First/)).toBeNull();
    expect(within(two).queryByText('Choose a whole percentage from 5 to 100.')).toBeNull();

    // The button is usable again: a second Save on row one sends a second PUT.
    const saveOne = within(one).getByRole('button', { name: /^Save the alert/ });
    await waitFor(() => {
      expect(saveOne.hasAttribute('disabled')).toBe(false);
    });
    first = jsonResponse(alertBody(ONE, 10));
    fireEvent.click(saveOne);
    await waitFor(() => {
      expect(calls.filter((call) => call.key === `PUT ${ONE_PATH}`)).toHaveLength(2);
    });
    // The watchlist is refetched only by the success, and nothing but the session and the list is read.
    const reads = calls.map((call) => call.key).filter((key) => key.startsWith('GET /v1/'));
    expect(reads.filter((key) => key === 'GET /v1/watchlist')).toHaveLength(2);
    expect(reads.filter((key) => key !== 'GET /v1/watchlist' && key !== 'GET /v1/me')).toEqual([]);
  });
});

describe('review: the stored-Discord state with no Discord webhook', () => {
  const STORED = notificationsBody({ channel: 'discord', digest: true, discord_available: false });

  async function openSettings(extra: Routes = {}) {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(STORED),
      ...extra,
    });
    visit('/alerts');
    const view = render(<App />);
    await waitFor(() => {
      expect(group()).toBeTruthy();
    });
    return { calls, container: view.container };
  }

  it('shows Discord selected but disabled with its hint, and still saves a switch to email', async () => {
    const switched = notificationsBody({
      channel: 'email',
      digest: true,
      discord_available: false,
    });
    const { calls } = await openSettings({
      'GET /v1/me/notifications': [jsonResponse(STORED), jsonResponse(switched)],
      'PUT /v1/me/notifications': jsonResponse(switched),
    });
    const discord = within(group()).getByRole<HTMLInputElement>('radio', { name: 'Discord' });
    expect(discord.checked).toBe(true);
    expect(discord.disabled).toBe(true);
    const hint = within(group()).getByText('Add a Discord webhook below first.');
    expect((discord.getAttribute('aria-describedby') ?? '').split(' ')).toContain(hint.id);

    fireEvent.click(within(group()).getByRole('radio', { name: /^Email \(/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByText('Saved.')).toBeTruthy();
    const puts = calls.filter((call) => call.key === 'PUT /v1/me/notifications');
    expect(puts).toHaveLength(1);
    expect(sentBody(puts[0])).toEqual({ channel: 'email', digest: true });
    expect(
      within(group()).getByRole<HTMLInputElement>('radio', { name: /^Email \(/ }).checked,
    ).toBe(true);
    // The saved settings are read again, never written from the PUT answer: one more GET.
    expect(calls.filter((call) => call.key === 'GET /v1/me/notifications')).toHaveLength(2);
  });

  it('shows the channel 422 under the group when the stored Discord is saved unchanged, and Save works again', async () => {
    const refusal = problemResponse(
      accountValidationProblem('/v1/me/notifications', {
        channel: ['Add a Discord webhook first.'],
      }),
    );
    const { calls, container } = await openSettings({ 'PUT /v1/me/notifications': () => refusal });
    const save = screen.getByRole('button', { name: 'Save' });
    fireEvent.click(save);
    const message = await within(group()).findByText('Add a Discord webhook first.');
    expect(message.id).not.toBe('');
    await waitFor(() => {
      expect(save.hasAttribute('disabled')).toBe(false);
    });
    fireEvent.click(save);
    await waitFor(() => {
      expect(calls.filter((call) => call.key === 'PUT /v1/me/notifications')).toHaveLength(2);
    });
    // The error state is accessible and honest.
    expect(shortControls(main())).toEqual([]);
    expect(lightOnlyClasses(container)).toEqual([]);
    for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
  });

  it('sends one PUT for a double submit of Save', async () => {
    const { calls } = await openSettings({
      'PUT /v1/me/notifications': jsonResponse(STORED),
    });
    const form = screen.getByRole('button', { name: 'Save' }).closest('form');
    if (form === null) throw new Error('form expected');
    fireEvent.submit(form);
    fireEvent.submit(form);
    await screen.findByText('Saved.');
    expect(calls.filter((call) => call.key === 'PUT /v1/me/notifications')).toHaveLength(1);
  });
});
