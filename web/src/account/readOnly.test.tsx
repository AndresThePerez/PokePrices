// The read-only demo across the account pages: one shared way to ask whether the session is
// read-only (useReadOnly), the controls it hides, the seeded samples on /alerts and /watchlist,
// the calm refusal of a write that can still be attempted, the sign-up sentence, the home page's
// demo button, and the honesty words over every touched screen, in the demo and out of it.
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  DEMO_SAMPLE_BODY,
  DEMO_SAMPLE_SECRET,
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  demoMe,
  demoReadOnlyProblem,
  demoSampleWebhook,
  demoSamplesBody,
  emptyPortfolioBody,
  historyFor,
  jsonResponse,
  notificationsBody,
  pricedCardBody,
  problemResponse,
  rawResponse,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
  webhooksBody,
} from '../test/fixtures';
import { ME_KEY, useReadOnly } from './session';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const CALM = 'The demo account is read-only.';
const SIGN_UP_SENTENCE = 'Accounts are invite-only for the moment. Try the read-only demo.';
const DEMO = 'Try the read-only demo';
const TURN_ON = 'Turn on two-factor authentication';
const CARD = 'GET /v1/cards/ex10-%3F';
const HISTORY = 'GET /v1/cards/ex10-%3F/prices?range=1M';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

/** The page text with the sample payload's exact bytes left out (they carry the event's as_of). */
function textBesidesPayload(): string {
  const copy = main().cloneNode(true) as HTMLElement;
  for (const block of Array.from(copy.querySelectorAll('[data-sample-payload]'))) block.remove();
  return copy.textContent;
}

function expectHonestWords(text: string): void {
  for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
}

describe('useReadOnly: one passive answer to "is this session read-only"', () => {
  function Probe() {
    return <p>{useReadOnly() ? 'read-only' : 'can write'}</p>;
  }

  it('reads the cached session only, sends no request, and is true for the demo alone', () => {
    const calls = stubAccountFetch({});
    const client = new QueryClient();
    render(
      <QueryClientProvider client={client}>
        <Probe />
      </QueryClientProvider>,
    );
    expect(screen.getByText('can write')).toBeTruthy();
    act(() => {
      client.setQueryData(ME_KEY, demoMe.data);
    });
    expect(screen.getByText('read-only')).toBeTruthy();
    act(() => {
      client.setQueryData(ME_KEY, signedInMe.data);
    });
    expect(screen.getByText('can write')).toBeTruthy();
    act(() => {
      client.setQueryData(ME_KEY, null);
    });
    expect(screen.getByText('can write')).toBeTruthy();
    act(() => {
      client.setQueryData(ME_KEY, demoMe.data);
      void client.invalidateQueries({ queryKey: ME_KEY, refetchType: 'none' });
    });
    // A stale demo answer still hides the controls until a new answer says otherwise.
    expect(screen.getByText('read-only')).toBeTruthy();
    expect(calls).toHaveLength(0);
  });
});

describe('controls a read-only session cannot use', () => {
  it('shows the demo no two-factor control on /account, and an ordinary account still has it', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(demoMe) });
    visit('/account');
    render(<App />);
    await within(main()).findByText('Two-factor authentication is off.');
    expect(within(main()).queryByRole('button', { name: TURN_ON })).toBeNull();
    expectHonestWords(main().textContent);
    cleanup();

    stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    visit('/account');
    render(<App />);
    expect(await within(main()).findByRole('button', { name: TURN_ON })).toBeTruthy();
    expectHonestWords(main().textContent);
  });

  it.each([
    ['the demo', demoMe, 0],
    ['an ordinary account', signedInMe, 2],
  ] as const)(
    'shows %s the card page with %s Add buttons on the priced row',
    async (_who, me, count) => {
      const calls = stubAccountFetch({
        'GET /v1/me': jsonResponse(me),
        [CARD]: jsonResponse(pricedCardBody),
        [HISTORY]: jsonResponse(historyFor(pricedCardBody)),
        // The card page of a known session reads both lists (answered empty: no line shows).
        'GET /v1/watchlist': jsonResponse(watchlistBody([])),
        'GET /v1/portfolio': jsonResponse(emptyPortfolioBody),
      });
      visit('/account');
      render(<App />);
      await within(main()).findByText(`Signed in as ${me.data.email}`);
      act(() => {
        navigate('/card/ex10-%3F');
      });
      await screen.findByRole('heading', { level: 1, name: 'Unown' });
      const prices = screen.getByRole('region', { name: 'Prices' });
      const row = within(prices).getByText('holo, unlimited').closest('li');
      if (row === null) throw new Error('no holo row');
      expect(
        within(row).queryAllByRole('button', { name: /^Add holo, unlimited to your/ }),
      ).toHaveLength(count);
      // The card page asks nothing of the account to know it.
      expect(calls.filter((call) => call.key === 'GET /v1/me')).toHaveLength(1);
    },
  );
});

describe('the demo shows its samples, labelled as samples', () => {
  it('shows the alert, the delivered email and the signed payload on /alerts, and asks for them once', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([demoSampleWebhook])),
      'GET /v1/demo/samples': jsonResponse(demoSamplesBody()),
    });
    visit('/alerts');
    render(<App />);
    const alert = await within(main()).findByRole('region', { name: 'Sample alert' });
    const email = within(main()).getByRole('region', { name: 'Sample alert email' });
    const signed = within(main()).getByRole('region', { name: 'Sample signed webhook delivery' });

    expect(alert.textContent).toContain('Chimecho');
    expect(alert.textContent).toContain('at 10%');
    expect(email.textContent).toContain('PokéPrices alert: Chimecho moved +361.30% (2026-10-12)');
    expect(email.textContent).toContain('Market price $26.34');
    expect(email.textContent).toContain('Delivered by email on 2026-10-12.');
    const payload = signed.querySelector('[data-sample-payload]');
    expect(payload?.textContent).toBe(DEMO_SAMPLE_BODY);
    expect(signed.textContent).toContain('webhook-id: msg_01K7DEM0WEBH00KSAMP1E00000');
    expect(signed.textContent).toContain('webhook-timestamp: 1791835544');
    expect(signed.textContent).toContain(
      'webhook-signature: v1,c2FtcGxlLXNpZ25hdHVyZS1mb3ItdGhlLWRlbW8tMDA=',
    );
    expect(signed.textContent).toContain(DEMO_SAMPLE_SECRET);
    expect(signed.textContent).toContain(
      'Sample signing secret, published for the demo only; it belongs to no account.',
    );
    expect(main().textContent).toContain(
      'The alert, the email and the signed webhook delivery below are samples: nothing is ever sent from the demo.',
    );
    // Read-only: no control at all.
    expect(within(main()).queryByRole('button')).toBeNull();
    expect(main().querySelector('form')).toBeNull();
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/me',
      'GET /v1/webhooks',
      'GET /v1/demo/samples',
    ]);
    expectHonestWords(textBesidesPayload());
  });

  it('says so when the demo has no sample message, and shows a failed read as the problem block', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/demo/samples': jsonResponse(demoSamplesBody({ message: null, webhook: null })),
    });
    visit('/alerts');
    render(<App />);
    expect(
      await within(main()).findByText(
        'No sample messages: no card on the demo watchlist moved by its alert threshold in the collected closes.',
      ),
    ).toBeTruthy();
    expect(within(main()).queryByRole('region', { name: 'Sample alert email' })).toBeNull();
    cleanup();

    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/demo/samples': problemResponse({
        type: 'https://pokeprices.andrestheperez.com/problems/server-error',
        title: 'Server Error',
        status: 500,
        instance: '/v1/demo/samples',
        request_id: 'req-t08-0001',
      }),
    });
    visit('/alerts');
    render(<App />);
    expect((await within(main()).findByRole('alert')).textContent).toContain(
      "Could not load the demo's samples: Server Error",
    );
  });

  it('never asks an ordinary account for the samples and shows it no sample', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
    });
    visit('/alerts');
    render(<App />);
    await within(main()).findByText('No webhooks yet.');
    expect(within(main()).queryByRole('region', { name: 'Sample alert' })).toBeNull();
    expect(calls.map((call) => call.key)).not.toContain('GET /v1/demo/samples');
    expectHonestWords(main().textContent);
  });

  it('shows the demo its alert on the watchlist row, labelled as a sample, with no control', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/watchlist': jsonResponse(
        watchlistBody([
          watchItemOf(),
          watchItemOf({
            variant_key: 'ex5-17:reverse:-:-',
            card_id: 'ex5-17',
            finish: 'reverse',
            edition: '-',
            card: { name: 'Chimecho', set_name: 'Hidden Legends', number: '17' },
            alert_pct: 10,
          }),
        ]),
      ),
    });
    visit('/watchlist');
    render(<App />);
    const list = await within(main()).findByRole('list', { name: 'Watchlist' });
    expect(within(list).getAllByText('Sample alert: at 10%')).toHaveLength(1);
    expect(within(list).queryByRole('button')).toBeNull();
    expect(within(list).queryByRole('combobox')).toBeNull();
    expectHonestWords(main().textContent);
  });
});

describe('a refused write in the demo reads as the calm sentence', () => {
  it('shows the calm sentence and no Request ID when the demo submits the sign-up form', async () => {
    stubAccountFetch({ 'POST /register': problemResponse(demoReadOnlyProblem('/register')) });
    visit('/sign-up');
    render(<App />);
    fireEvent.change(within(main()).getByLabelText('Invite code'), { target: { value: 'x' } });
    // The form checks itself before it is sent: the other fields hold values that pass.
    for (const [label, value] of [
      ['Email', 'newcomer@example.com'],
      ['Password', 'fake-password-0000-ok'],
      ['Confirm password', 'fake-password-0000-ok'],
    ] as const) {
      fireEvent.change(within(main()).getByLabelText(label), { target: { value } });
    }
    fireEvent.click(within(main()).getByRole('button', { name: 'Create account' }));
    const refusal = await within(main()).findByRole('alert');
    expect(refusal.textContent).toBe(CALM);
    expect(main().textContent).not.toContain('Request ID');
    expect(main().textContent).not.toContain('Demo Account Is Read-Only');
  });
});

describe('the sign-up page says where things stand', () => {
  it('shows the ruled sentence word for word with the demo button beside it, and asks for nothing', () => {
    const calls = stubAccountFetch({});
    visit('/sign-up');
    render(<App />);
    const sentence = within(main()).getByText(SIGN_UP_SENTENCE);
    expect(sentence.textContent).toBe(SIGN_UP_SENTENCE);
    const notice = sentence.parentElement;
    if (notice === null) throw new Error('no notice');
    const demo = within(notice).getByRole<HTMLButtonElement>('button', { name: DEMO });
    expect(demo.type).toBe('button');
    expect(within(main()).getAllByRole('button', { name: DEMO })).toHaveLength(1);
    expectHonestWords(main().textContent);
    expect(calls).toHaveLength(0);
  });
});

describe('the home page demo entry is a real button', () => {
  it('is a button styled as one, and starts the demo session', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [problemResponse(unauthenticatedProblem), jsonResponse(demoMe)],
      'POST /v1/demo/session': rawResponse(204, null),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    visit('/');
    render(<App />);
    const demo = within(main()).getByRole<HTMLButtonElement>('button', { name: DEMO });
    const classes = demo.className.split(/\s+/);
    expect(demo.tagName).toBe('BUTTON');
    expect(demo.type).toBe('button');
    expect(classes).toEqual(expect.arrayContaining(['rounded-md', 'border', 'min-h-11']));
    expect(classes).not.toContain('underline');
    fireEvent.click(demo);
    await waitFor(() => {
      expect(window.location.pathname).toBe('/watchlist');
    });
    expect(calls.filter((call) => call.key === 'POST /v1/demo/session')).toHaveLength(1);
  });
});
