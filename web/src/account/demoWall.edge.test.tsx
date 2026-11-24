// Adversarial edge cases for the read-only demo on the web: useReadOnly through a whole page life
// (loading, failed, ordinary to demo to signed out to ordinary on a poll), the card page that never
// asks for the session, the calm sentence against every other refusal, the samples decoder fed
// wrong kinds, the sign-up page's demo button under failure and double clicks, and the honesty
// words over everything outside the signed payload.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import {
  DEMO_SAMPLE_BODY,
  FORBIDDEN_WORDS,
  accountValidationProblem,
  clearXsrfCookie,
  demoMe,
  demoReadOnlyProblem,
  demoSampleWebhook,
  demoSamplesBody,
  emailNotVerifiedProblem,
  historyFor,
  holdingOf,
  jsonResponse,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
  webhooksBody,
} from '../test/fixtures';
import { ME_KEY } from './session';

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

const CALM = 'The demo account is read-only.';
const SIGN_UP_SENTENCE = 'Accounts are invite-only for the moment. Try the read-only demo.';
const DEMO = 'Try the read-only demo';
const TURN_ON = 'Turn on two-factor authentication';
const CARD = 'GET /v1/cards/ex10-%3F';
const HISTORY = 'GET /v1/cards/ex10-%3F/prices?range=1M';
const WATCH_PUT = 'PUT /v1/watchlist/ex10-%3F%3Aholo%3Aunlimited%3A-';
const ADD_WATCH = 'Add holo, unlimited to your watchlist';

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  created.clients.length = 0;
  window.localStorage.clear();
  window.history.replaceState(null, '', '/');
});

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

function client(): QueryClient {
  const first = created.clients[0];
  if (first === undefined) throw new Error('App created no QueryClient');
  return first;
}

async function settle(): Promise<void> {
  for (let round = 0; round < 6; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

/** The session poll: ['me'] asked again, as the five-minute interval or a focus would. */
async function poll(): Promise<void> {
  await act(async () => {
    await client().refetchQueries({ queryKey: ME_KEY });
  });
  await settle();
}

function buttonNames(): string[] {
  return within(main())
    .queryAllByRole('button')
    .map((button) => button.getAttribute('aria-label') ?? button.textContent);
}

function expectHonest(text: string): void {
  for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
  expect(text).not.toContain('undefined');
  expect(text).not.toContain('NaN');
}

/** The text of main with the sample payload's exact bytes left out. */
function textOutsidePayload(): string {
  const copy = main().cloneNode(true) as HTMLElement;
  for (const block of Array.from(copy.querySelectorAll('[data-sample-payload]'))) block.remove();
  return copy.textContent;
}

describe('useReadOnly through a page life', () => {
  it('shows no write control while the session loads, on any account page', async () => {
    for (const path of ['/account', '/watchlist', '/portfolio', '/alerts']) {
      vi.stubGlobal('fetch', () => new Promise<Response>(() => undefined));
      visit(path);
      render(<App />);
      await settle();
      const names = buttonNames();
      expect(
        names.filter((name) => /Turn on|Remove|Save|Add |Send a test|Delete/.test(name)),
      ).toEqual([]);
      expect(main().querySelector('form')).toBeNull();
      cleanup();
      vi.unstubAllGlobals();
    }
  });

  it('shows a failed /v1/me as a problem and no two-factor control', async () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(serverErrorProblem('/v1/me')) });
    visit('/account');
    render(<App />);
    await within(main()).findByRole('alert');
    expect(within(main()).queryByRole('button', { name: TURN_ON })).toBeNull();
    expectHonest(main().textContent);
  });

  it('follows ordinary, then demo, then signed out, then ordinary again on /account, by the poll alone', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [
        jsonResponse(signedInMe),
        jsonResponse(demoMe),
        problemResponse(unauthenticatedProblem),
        jsonResponse(signedInMe),
      ],
    });
    visit('/account');
    render(<App />);
    expect(await within(main()).findByRole('button', { name: TURN_ON })).toBeTruthy();

    await poll();
    await within(main()).findByText(`Signed in as ${demoMe.data.email}`);
    expect(within(main()).queryByRole('button', { name: TURN_ON })).toBeNull();
    expect(within(main()).getByText('Two-factor authentication is off.')).toBeTruthy();

    await poll();
    expect(within(main()).queryByText(`Signed in as ${demoMe.data.email}`)).toBeNull();
    expect(within(main()).queryByRole('button', { name: TURN_ON })).toBeNull();

    await poll();
    expect(await within(main()).findByRole('button', { name: TURN_ON })).toBeTruthy();
    expect(calls.map((call) => call.key).every((key) => key === 'GET /v1/me')).toBe(true);
  });

  it('takes the Remove and alert controls from /watchlist when a second tab turns it into the demo, and gives them back to an ordinary account', async () => {
    const rows = watchlistBody([
      watchItemOf(),
      watchItemOf({
        variant_key: 'ex5-17:reverse:-:-',
        card_id: 'ex5-17',
        finish: 'reverse',
        edition: '-',
        card: { name: 'Chimecho', set_name: 'Hidden Legends', number: '17' },
        alert_pct: 10,
      }),
    ]);
    stubAccountFetch({
      'GET /v1/me': [jsonResponse(signedInMe), jsonResponse(demoMe), jsonResponse(signedInMe)],
      'GET /v1/watchlist': jsonResponse(rows),
    });
    visit('/watchlist');
    render(<App />);
    const list = await within(main()).findByRole('list', { name: 'Watchlist' });
    expect(within(list).getAllByRole('button', { name: /^Remove / })).toHaveLength(2);
    expect(within(list).queryByText('Sample alert: at 10%')).toBeNull();

    await poll();
    const demoList = await within(main()).findByRole('list', { name: 'Watchlist' });
    await waitFor(() => {
      expect(within(demoList).queryAllByRole('button')).toHaveLength(0);
    });
    expect(within(demoList).queryAllByRole('combobox')).toHaveLength(0);
    expect(within(demoList).getAllByText('Sample alert: at 10%')).toHaveLength(1);

    await poll();
    const back = await within(main()).findByRole('list', { name: 'Watchlist' });
    await waitFor(() => {
      expect(within(back).getAllByRole('button', { name: /^Remove / })).toHaveLength(2);
    });
    expect(within(back).queryByText('Sample alert: at 10%')).toBeNull();
  });

  it('shows the demo no Remove on /portfolio and an ordinary account its Remove', async () => {
    for (const [me, count] of [
      [demoMe, 0],
      [signedInMe, 1],
    ] as const) {
      stubAccountFetch({
        'GET /v1/me': jsonResponse(me),
        'GET /v1/portfolio': jsonResponse(portfolioBody([holdingOf()])),
      });
      visit('/portfolio');
      render(<App />);
      const list = await within(main()).findByRole('list', { name: 'Portfolio' });
      expect(within(list).queryAllByRole('button', { name: /^Remove / })).toHaveLength(count);
      expectHonest(main().textContent);
      cleanup();
      vi.unstubAllGlobals();
    }
  });
});

describe('the card page never asks for the session', () => {
  it('sends only the card and its history signed out, and useReadOnly adds no request', async () => {
    const calls = stubAccountFetch({
      [CARD]: jsonResponse(pricedCardBody),
      [HISTORY]: jsonResponse(historyFor(pricedCardBody)),
    });
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    await settle();
    // The header's freshness line, the card and its history: no session or CSRF request.
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/prices/freshness', CARD, HISTORY]);
  });

  it('answers a demo that opened the card page directly (session not known) with the calm sentence, no Request ID, and usable buttons', async () => {
    const calls = stubAccountFetch({
      [CARD]: jsonResponse(pricedCardBody),
      [HISTORY]: jsonResponse(historyFor(pricedCardBody)),
      [WATCH_PUT]: problemResponse(
        demoReadOnlyProblem('/v1/watchlist/ex10-%3F%3Aholo%3Aunlimited%3A-'),
      ),
    });
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    const add = await screen.findByRole<HTMLInputElement>('button', { name: ADD_WATCH });
    fireEvent.click(add);
    const line = await screen.findByText(CALM);
    const prices = screen.getByRole('region', { name: 'Prices' });
    expect(line.textContent).toBe(CALM);
    expect(prices.textContent).not.toContain('Request ID');
    expect(prices.textContent).not.toContain('Demo Account Is Read-Only');
    expect(prices.textContent).not.toContain('req-p4-demo-0008');
    await waitFor(() => {
      expect(screen.getByRole<HTMLInputElement>('button', { name: ADD_WATCH }).disabled).toBe(
        false,
      );
    });
    expect(calls.filter((call) => call.key === WATCH_PUT)).toHaveLength(1);
    expect(calls.filter((call) => call.key === 'GET /v1/me')).toHaveLength(0);
  });
});

describe('the calm sentence is for the demo refusal alone', () => {
  it.each([
    {
      name: 'the demo refusal',
      problem: demoReadOnlyProblem('/register'),
      shows: [CALM],
      hides: ['Request ID', 'Demo Account Is Read-Only', 'req-p4-demo-0008'],
    },
    {
      name: 'another 403',
      problem: emailNotVerifiedProblem('/register'),
      shows: ['Could not create the account: Verify Your Email First'],
      hides: [CALM, 'Request ID', 'req-p5-unverified-0011'],
    },
    {
      name: 'a 422',
      problem: accountValidationProblem('/register', {
        invite_code: ['The invite code is not valid.'],
      }),
      shows: ['The invite code is not valid.'],
      hides: [CALM],
    },
    {
      name: 'a 500',
      problem: serverErrorProblem('/register'),
      shows: ['Could not create the account: Internal Server Error', 'req-p4-server-0007'],
      hides: [CALM],
    },
  ])('sign-up shows $name in its own words', async (row) => {
    const { problem, shows, hides } = row;
    stubAccountFetch({ 'POST /register': problemResponse(problem) });
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
    await within(main()).findByRole('alert');
    const text = main().textContent;
    for (const words of shows) expect(text).toContain(words);
    for (const words of hides) expect(text).not.toContain(words);
    expect(
      within(main()).getByRole<HTMLButtonElement>('button', { name: 'Create account' }).disabled,
    ).toBe(false);
    expectHonest(text);
  });

  it('keeps the Request ID on a server error the demo meets on /alerts', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([demoSampleWebhook])),
      'GET /v1/demo/samples': problemResponse(serverErrorProblem('/v1/demo/samples')),
    });
    visit('/alerts');
    render(<App />);
    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain("Could not load the demo's samples: Internal Server Error");
    expect(alert.textContent).toContain('req-p4-server-0007');
    expect(alert.textContent).not.toContain(CALM);
  });
});

describe('the samples decoder', () => {
  const good = demoSamplesBody();
  const webhook = good.data.webhook;
  if (webhook === null) throw new Error('fixture has no webhook');
  const withoutMessage = Object.fromEntries(
    Object.entries(good.data).filter(([key]) => key !== 'message'),
  );
  it.each([
    {
      name: 'a null payload',
      body: { data: { ...good.data, webhook: { ...webhook, body: null } } },
    },
    {
      name: 'a numeric signature',
      body: {
        data: {
          ...good.data,
          webhook: { ...webhook, headers: { ...webhook.headers, 'webhook-signature': 7 } },
        },
      },
    },
    { name: 'a missing sample', body: { data: withoutMessage } },
    {
      name: 'a numeric alert threshold as text',
      body: { data: { ...good.data, alert: { ...good.data.alert, alert_pct: '10' } } },
    },
    { name: 'no data at all', body: { samples: good.data } },
  ])('shows the problem block for $name, never a crash', async (row) => {
    const { body } = row;
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([demoSampleWebhook])),
      'GET /v1/demo/samples': jsonResponse(body),
    });
    visit('/alerts');
    render(<App />);
    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain("Could not load the demo's samples");
    expect(
      within(main()).queryByRole('region', { name: 'Sample signed webhook delivery' }),
    ).toBeNull();
    expectHonest(main().textContent);
  });

  it('ignores an extra unknown sample kind and shows the three it knows', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([demoSampleWebhook])),
      'GET /v1/demo/samples': jsonResponse({
        data: { ...demoSamplesBody().data, discord: { text: 'unknown kind' } },
      }),
    });
    visit('/alerts');
    render(<App />);
    await within(main()).findByRole('region', { name: 'Sample signed webhook delivery' });
    expect(within(main()).getByRole('region', { name: 'Sample alert' })).toBeTruthy();
    expect(within(main()).getByRole('region', { name: 'Sample alert email' })).toBeTruthy();
    expect(main().textContent).not.toContain('unknown kind');
    expect(within(main()).queryByRole('alert')).toBeNull();
    expectHonest(textOutsidePayload());
  });
});

describe('honesty on the demo /alerts in every state', () => {
  it.each([
    { name: 'loaded', answer: () => jsonResponse(demoSamplesBody()) },
    {
      name: 'no messages',
      answer: () => jsonResponse(demoSamplesBody({ message: null, webhook: null })),
    },
    { name: 'failed', answer: () => problemResponse(serverErrorProblem('/v1/demo/samples')) },
  ])('has no forbidden word outside the payload when $name', async (row) => {
    const { answer } = row;
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([demoSampleWebhook])),
      'GET /v1/demo/samples': answer(),
    });
    visit('/alerts');
    render(<App />);
    await within(main()).findByText(
      'The alert, the email and the signed webhook delivery below are samples: nothing is ever sent from the demo.',
    );
    await settle();
    expect(main().textContent).not.toContain("Loading the demo's samples");
    expectHonest(textOutsidePayload());
  });

  it('keeps the payload inside the section labelled as a sample, byte for byte, and every price in the email with its source and day', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([demoSampleWebhook])),
      'GET /v1/demo/samples': jsonResponse(demoSamplesBody()),
    });
    visit('/alerts');
    render(<App />);
    const signed = await within(main()).findByRole('region', {
      name: 'Sample signed webhook delivery',
    });
    const payloads = main().querySelectorAll('[data-sample-payload]');
    expect(payloads).toHaveLength(1);
    const payload = payloads[0];
    if (payload === undefined) throw new Error('no payload');
    expect(signed.contains(payload)).toBe(true);
    expect(payload.closest('section')).toBe(signed);
    // The exact string, not an object rendered again with other spacing or key order.
    expect(payload.textContent).toBe(DEMO_SAMPLE_BODY);
    expect(payload.textContent).not.toBe(JSON.stringify(JSON.parse(DEMO_SAMPLE_BODY), null, 2));
    expect(payload.textContent).toContain('"source":"tcgplayer"');
    expect(payload.textContent).toContain('"day":"2026-10-12"');

    const email = within(main()).getByRole('region', { name: 'Sample alert email' });
    const lines = email.textContent;
    expect(lines).toContain('$26.34');
    expect(lines).toContain('as of 2026-10-12 from TCGCSV (daily close)');
    expect(lines).toContain('Delivered by email on 2026-10-12.');
    // Each of the three is a section whose own heading says it is a sample.
    const samples = within(main()).getAllByRole('region', { name: /^Sample / });
    expect(samples.map((region) => region.querySelector('h2')?.textContent)).toEqual([
      'Sample alert',
      'Sample alert email',
      'Sample signed webhook delivery',
    ]);
  });
});

describe('the sign-up page and its demo button', () => {
  it('shows the sentence once in the whole document, one demo button, and asks for nothing until pressed', async () => {
    const calls = stubAccountFetch({});
    visit('/sign-up');
    render(<App />);
    await settle();
    const all = document.body.textContent;
    expect(all.split(SIGN_UP_SENTENCE)).toHaveLength(2);
    expect(screen.getAllByRole('button', { name: DEMO })).toHaveLength(1);
    expect(calls).toHaveLength(0);
    expectHonest(main().textContent);
  });

  it('asks for the CSRF cookie, then the demo session, once on a double click, and lands on the watchlist', async () => {
    const calls = stubAccountFetch({
      'POST /v1/demo/session': rawResponse(204, null),
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    visit('/sign-up');
    render(<App />);
    const demo = within(main()).getByRole<HTMLButtonElement>('button', { name: DEMO });
    fireEvent.click(demo);
    fireEvent.click(demo);
    await waitFor(() => {
      expect(window.location.pathname).toBe('/watchlist');
    });
    const keys = calls.map((call) => call.key);
    expect(keys.filter((key) => key === 'POST /v1/demo/session')).toHaveLength(1);
    expect(keys.indexOf('GET /sanctum/csrf-cookie')).toBeGreaterThanOrEqual(0);
    expect(keys.indexOf('GET /sanctum/csrf-cookie')).toBeLessThan(
      keys.indexOf('POST /v1/demo/session'),
    );
    expect(keys.slice(0, 2)).toEqual(['GET /sanctum/csrf-cookie', 'POST /v1/demo/session']);
  });

  it.each([
    {
      name: 'a 429',
      answer: () => problemResponse(tooManyRequestsProblem('/v1/demo/session')),
      words: 'Could not start the demo',
    },
    {
      name: 'a 503',
      answer: () =>
        problemResponse({
          type: 'https://pokeprices.andrestheperez.com/problems/http-503',
          title: 'Service Unavailable',
          status: 503,
          instance: '/v1/demo/session',
          request_id: 'req-t08-edge-0503',
        }),
      words: 'Could not start the demo: Service Unavailable',
    },
    {
      name: 'a 500',
      answer: () => problemResponse(serverErrorProblem('/v1/demo/session')),
      words: 'Could not start the demo: Internal Server Error',
    },
    {
      name: 'a network failure',
      answer: () => new TypeError('Failed to fetch'),
      words: 'Could not start the demo',
    },
  ])('shows $name calmly and leaves the button usable', async (row) => {
    const { answer, words } = row;
    stubAccountFetch({ 'POST /v1/demo/session': () => answer() });
    visit('/sign-up');
    render(<App />);
    fireEvent.click(within(main()).getByRole('button', { name: DEMO }));
    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain(words);
    expectHonest(main().textContent);
    expect(window.location.pathname).toBe('/sign-up');
    await waitFor(() => {
      expect(within(main()).getByRole<HTMLButtonElement>('button', { name: DEMO }).disabled).toBe(
        false,
      );
    });
    expect(within(main()).getAllByText(SIGN_UP_SENTENCE)).toHaveLength(1);
  });
});
