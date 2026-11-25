// /alerts (P5 T04, D10): every state of the page and its Webhooks section, the request lists, the
// /account link, and the accessibility and honesty rules over every state.
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  FAKE_SIGNING_SECRET,
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  holdSessionCookie,
  demoAlertsUnavailableRoutes,
  demoMe,
  demoSamplesBody,
  discordWebhook,
  jsonResponse,
  lightOnlyClasses,
  notificationsBody,
  problemResponse,
  serverErrorProblem,
  shortControls,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  unverifiedMe,
  webhookCreatedBody,
  webhookOf,
  webhooksBody,
  type AccountAnswer,
} from '../test/fixtures';

const LOADING = 'Loading your alerts and webhooks…';
const INTRO =
  'PokéPrices sends a signed message to each signed webhook when a card on your watchlist or ' +
  'portfolio moves by at least 5% and $1.00 between two daily closes. A Discord webhook receives ' +
  'your alerts and digest when you choose Discord under “Where alerts go” above.';
const DEMO_LINE =
  'The read-only demo cannot set alerts or add webhooks: it has no email address or Discord of ' +
  'its own to send them to.';
const UNVERIFIED_LINE = 'Verify your email address to set alerts or add webhooks.';
const TURNED_OFF =
  'Turned off: the receiver said this webhook no longer exists. Delete it and add it again.';
const SECRET_LINE =
  'Copy this secret before you leave this page. PokéPrices shows it once; to change it, delete ' +
  'the webhook and add it again.';

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

function webhookList(): HTMLElement {
  return screen.getByRole('list', { name: 'Webhooks' });
}

function open(routes: Parameters<typeof stubAccountFetch>[0]) {
  const calls = stubAccountFetch(routes);
  visit('/alerts');
  const view = render(<App />);
  return { calls, container: view.container };
}

describe('the alerts page states (P5 T04 criterion 1, D10)', () => {
  it('without the cookie asks no session: the sample loading line first, then the signed-out view, with sign-in coming back here', async () => {
    const { calls } = open({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      ...demoAlertsUnavailableRoutes(),
    });
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Alerts and webhooks');
    expect(within(main()).getByRole('status').textContent).toBe('Loading the sample alerts…');
    expect(main().textContent).not.toContain(LOADING);
    expect(await screen.findByText('Sign in to set up alerts and webhooks.')).toBeTruthy();
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
    expect(within(main()).queryByRole('status')).toBeNull();
    const links = within(main())
      .getAllByRole('link')
      .map((link) => [link.textContent, link.getAttribute('href')]);
    expect(links).toEqual([
      ['Sign in', '/sign-in?next=/alerts'],
      ['Create an account', '/sign-up'],
    ]);
    expect(within(main()).queryByRole('button')).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
    // Criterion 5: signed out with no cookie, the page asks for no session, only the public sample.
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/demo/alerts']);
  });

  it('signed in with no webhooks: the intro, the empty line and the add form', async () => {
    const { calls } = open({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
    });
    expect(await screen.findByText('No webhooks yet.')).toBeTruthy();
    expect(screen.getByRole('heading', { level: 2, name: 'Webhooks' })).toBeTruthy();
    expect(within(main()).getByText(INTRO)).toBeTruthy();
    expect(screen.getByRole('heading', { level: 3, name: 'Add a webhook' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Add webhook' })).toBeTruthy();
    // Criterion 5 (P5 T07 supersession, R240): GET /v1/me, GET /v1/webhooks, then the settings.
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/me',
      'GET /v1/webhooks',
      'GET /v1/me/notifications',
    ]);
  });

  it('lists one webhook of each type with its hint, description, type and added date', async () => {
    open({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([webhookOf(), discordWebhook])),
    });
    await screen.findByRole('list', { name: 'Webhooks' });
    const [signed, discord] = within(webhookList()).getAllByRole('listitem');
    if (signed === undefined || discord === undefined) throw new Error('two rows expected');
    const signedText = within(signed);
    expect(signedText.getByText('https://hooks.example.com/…')).toBeTruthy();
    expect(signedText.getByText('Home bot')).toBeTruthy();
    expect(signedText.getByText('Signed webhook')).toBeTruthy();
    expect(signedText.getByText('Added 2026-10-12')).toBeTruthy();
    expect(signedText.getByText('No message sent yet.')).toBeTruthy();
    const discordText = within(discord);
    expect(discordText.getByText('https://discord.com/…')).toBeTruthy();
    expect(discordText.getByText('Discord')).toBeTruthy();
    expect(discordText.getByText('Added 2026-10-13')).toBeTruthy();
    expect(discordText.getByText('Last message delivered (2026-10-14).')).toBeTruthy();
    // No description: nothing in its place.
    expect(discord.textContent).not.toContain('Home bot');
    expect(
      signedText.getByRole('button', {
        name: 'Send a test message to https://hooks.example.com/…',
      }),
    ).toBeTruthy();
    expect(
      signedText.getByRole('button', { name: 'Delete the webhook https://hooks.example.com/…' }),
    ).toBeTruthy();
    expect(signedText.getByRole('button', { name: /^Send a test message/ }).textContent).toBe(
      'Send a test message',
    );
    expect(signedText.getByRole('button', { name: /^Delete/ }).textContent).toBe('Delete');
  });

  it.each([
    [null, false, 'No message sent yet.'],
    [
      { status: 'delivered', at: '2026-10-14T20:05:47Z' },
      false,
      'Last message delivered (2026-10-14).',
    ],
    [{ status: 'failed', at: '2026-10-11T20:05:48Z' }, false, 'Last message failed (2026-10-11).'],
    [{ status: 'pending', at: '2026-10-10T20:05:49Z' }, false, 'Last message waiting to be sent.'],
    [{ status: 'delivered', at: '2026-10-14T20:05:47Z' }, true, TURNED_OFF],
    [null, true, TURNED_OFF],
  ] as const)(
    'shows the status line for last_delivery %j, disabled %s',
    async (last, disabled, line) => {
      open({
        'GET /v1/me': jsonResponse(signedInMe),
        'GET /v1/webhooks': jsonResponse(
          webhooksBody([webhookOf({ last_delivery: last, disabled })]),
        ),
      });
      await screen.findByRole('list', { name: 'Webhooks' });
      const row = within(webhookList()).getByRole('listitem');
      expect(within(row).getByText(line)).toBeTruthy();
      // Exactly one status line per webhook.
      const statusLines = [
        /No message sent yet\./,
        /Last message delivered \(/,
        /Last message failed \(/,
        /Last message waiting to be sent\./,
        /Turned off: /,
      ];
      expect(statusLines.filter((pattern) => pattern.test(row.textContent))).toHaveLength(1);
      // The test button is hidden once the webhook is turned off; Delete stays.
      expect(within(row).queryByRole('button', { name: /^Send a test message/ }) === null).toBe(
        disabled,
      );
      expect(within(row).getByRole('button', { name: /^Delete/ })).toBeTruthy();
    },
  );

  it('demo: says why it cannot, with no form and no button', async () => {
    const { calls } = open({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/demo/samples': jsonResponse(demoSamplesBody()),
    });
    expect(await screen.findByText(DEMO_LINE)).toBeTruthy();
    expect(within(main()).queryByRole('button')).toBeNull();
    expect(main().querySelector('form')).toBeNull();
    expect(within(main()).queryByRole('textbox')).toBeNull();
    expect(screen.queryByRole('heading', { name: 'Add a webhook' })).toBeNull();
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/me',
      'GET /v1/webhooks',
      'GET /v1/demo/samples',
    ]);
  });

  it('unverified: says to verify, links to the account, lists webhooks without any button', async () => {
    open({
      'GET /v1/me': jsonResponse(unverifiedMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([webhookOf()])),
    });
    expect(await screen.findByText(UNVERIFIED_LINE)).toBeTruthy();
    expect(
      within(main()).getByRole('link', { name: 'Go to your account' }).getAttribute('href'),
    ).toBe('/account');
    await screen.findByRole('list', { name: 'Webhooks' });
    expect(within(webhookList()).getByText('https://hooks.example.com/…')).toBeTruthy();
    expect(within(main()).queryByRole('button')).toBeNull();
    expect(main().querySelector('form')).toBeNull();
  });

  it('shows a failed webhook list as the problem block', async () => {
    open({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': problemResponse(serverErrorProblem('/v1/webhooks')),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
    });
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not load your webhooks: Internal Server Error');
    expect(alert.textContent).toContain('Request ID req-p4-server-0007');
  });

  it('treats a 401 from the webhook list as signed out', async () => {
    open({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': problemResponse({ ...unauthenticatedProblem, instance: '/v1/webhooks' }),
    });
    expect(await screen.findByText('Sign in to set up alerts and webhooks.')).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('shows a failed session load as the problem block', async () => {
    holdSessionCookie();
    open({ 'GET /v1/me': problemResponse(serverErrorProblem('/v1/me')) });
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not load your account: Internal Server Error');
  });
});

describe('the alerts page is accessible and honest in every state (P5 T04 criterion 6)', () => {
  it('one h1, 44 px controls, dark-ready, no forbidden word and no clock in any state', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    const list = webhooksBody([
      webhookOf(),
      discordWebhook,
      webhookOf({ id: 43, last_delivery: { status: 'failed', at: '2026-10-11T20:05:48Z' } }),
      webhookOf({ id: 44, last_delivery: { status: 'pending', at: '2026-10-10T20:05:49Z' } }),
      webhookOf({ id: 45, disabled: true }),
    ]);
    const states: readonly (readonly [AccountAnswer, AccountAnswer, string | RegExp])[] = [
      [
        problemResponse(unauthenticatedProblem),
        jsonResponse(list),
        'Sign in to set up alerts and webhooks.',
      ],
      [jsonResponse(signedInMe), jsonResponse(webhooksBody()), 'No webhooks yet.'],
      [jsonResponse(signedInMe), jsonResponse(list), 'Last message waiting to be sent.'],
      [jsonResponse(demoMe), jsonResponse(webhooksBody()), DEMO_LINE],
      [jsonResponse(unverifiedMe), jsonResponse(list), UNVERIFIED_LINE],
      [
        jsonResponse(signedInMe),
        problemResponse(serverErrorProblem('/v1/webhooks')),
        /Could not load your webhooks/,
      ],
    ];
    const check = (container: HTMLElement): void => {
      expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
      expect(lightOnlyClasses(container)).toEqual([]);
      expect(shortControls(main())).toEqual([]);
      // The demo's sample payload is shown as its exact signed bytes, the event's RFC 3339 times
      // included: the page's own words are checked without it, and the payload holds no
      // forbidden word once those times are taken out.
      const words = main().cloneNode(true) as HTMLElement;
      for (const payload of Array.from(words.querySelectorAll('[data-sample-payload]'))) {
        expect(
          payload.textContent.replace(/"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z"/g, '""'),
        ).not.toMatch(/\blive\b|real-?time|\btoday\b|\bnow\b|20:05/i);
        payload.remove();
      }
      for (const pattern of FORBIDDEN_WORDS) expect(words.textContent).not.toMatch(pattern);
      expect(main().textContent).not.toContain('2031');
      for (const input of Array.from(main().querySelectorAll('input, select'))) {
        expect(input.id).not.toBe('');
        expect(main().querySelector(`label[for="${input.id}"]`)?.textContent).toBeTruthy();
      }
    };
    for (const [me, webhooks, expected] of states) {
      const { container } = open({
        'GET /v1/me': me,
        'GET /v1/webhooks': webhooks,
        'GET /v1/demo/samples': jsonResponse(demoSamplesBody()),
      });
      check(container);
      await screen.findByText(expected);
      check(container);
      cleanup();
    }
  });

  it('keeps the rules with the secret block and the Discord line on screen', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    const { container } = open({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'POST /v1/webhooks': [
        jsonResponse(webhookCreatedBody(webhookOf(), FAKE_SIGNING_SECRET), { status: 201 }),
        jsonResponse(webhookCreatedBody(discordWebhook, null), { status: 201 }),
      ],
    });
    await screen.findByText('No webhooks yet.');
    fireEvent.change(screen.getByLabelText('URL'), {
      target: { value: 'https://hooks.example.com/pokeprices' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add webhook' }));
    expect(await screen.findByText(SECRET_LINE)).toBeTruthy();
    const block = screen.getByRole('heading', { name: 'Signing secret' }).parentElement;
    if (block === null) throw new Error('secret block expected');
    expect(lightOnlyClasses(container)).toEqual([]);
    expect(shortControls(main())).toEqual([]);
    for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
    expect(main().textContent).not.toContain('2031');
    fireEvent.click(screen.getByRole('button', { name: 'I have stored the secret' }));
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'discord' } });
    fireEvent.change(screen.getByLabelText('URL'), {
      target: { value: 'https://discord.com/api/webhooks/1/fake-token' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add webhook' }));
    expect(
      await screen.findByText('Discord webhook added. Use Send a test message to check it.'),
    ).toBeTruthy();
    for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
    expect(lightOnlyClasses(container)).toEqual([]);
  });
});

describe('the /account link to /alerts (P5 T04 criterion 4)', () => {
  it('follows the two-factor section, is static and adds no request', async () => {
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    visit('/account');
    render(<App />);
    const link = await within(await screen.findByRole('main')).findByRole('link', {
      name: 'Alerts and webhooks',
    });
    expect(link.getAttribute('href')).toBe('/alerts');
    expect(link.className.split(/\s+/)).toContain('min-h-11');
    const twoFactor = screen.getByRole('heading', { name: 'Two-factor authentication' });
    const yourData = screen.getByRole('heading', { name: 'Your data' });
    expect(twoFactor.compareDocumentPosition(link) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(link.compareDocumentPosition(yourData) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me']);
  });

  it('shows the link to the demo too, and it navigates in place to /alerts', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/demo/samples': jsonResponse(demoSamplesBody()),
    });
    visit('/account');
    render(<App />);
    const link = await screen.findByRole('link', { name: 'Alerts and webhooks' });
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me']);
    act(() => {
      fireEvent.click(link);
    });
    expect(window.location.pathname).toBe('/alerts');
    expect(await screen.findByText(DEMO_LINE)).toBeTruthy();
    act(() => {
      navigate('/account');
    });
  });
});
