// /alerts, the sections T07 adds (P5 T07 criteria 1, 2, 5, 6; D10): "Where alerts go" (the radio
// group, the digest checkbox, Save), "Per-card alerts", the request lists, the Discord refetches and
// the accessibility and honesty rules over every new state.
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  accountValidationProblem,
  clearXsrfCookie,
  demoMe,
  discordWebhook,
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
  unverifiedMe,
  webhookCreatedBody,
  webhooksBody,
  type AccountAnswer,
} from '../test/fixtures';

const DIGEST_HINT =
  'The digest lists the cards on your watchlist and portfolio whose daily close moved by at least ' +
  "5% and $1.00. It is sent once per collected day, after that day's prices are in.";
const DISCORD_HINT = 'Add a Discord webhook below first.';
const PER_CARD = 'Set a threshold on any card in your watchlist.';

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

function open(routes: Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>>) {
  const calls = stubAccountFetch(routes);
  visit('/alerts');
  const view = render(<App />);
  return { calls, container: view.container };
}

function group(): HTMLElement {
  return screen.getByRole('group', { name: 'Send alerts and the digest by' });
}

function discordRadio(): HTMLInputElement {
  return within(group()).getByRole<HTMLInputElement>('radio', { name: 'Discord' });
}

const verified = (
  settings: AccountAnswer | readonly AccountAnswer[] = jsonResponse(notificationsBody()),
  extra: Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>> = {},
) => ({
  'GET /v1/me': jsonResponse(signedInMe),
  'GET /v1/webhooks': jsonResponse(webhooksBody()),
  'GET /v1/me/notifications': settings,
  ...extra,
});

describe('Where alerts go (P5 T07 criterion 1)', () => {
  it('loads the settings: the radio group, the email, the disabled Discord radio, the digest', async () => {
    const { calls } = open(verified());
    expect(await screen.findByRole('heading', { level: 2, name: 'Where alerts go' })).toBeTruthy();
    const fieldset = await waitFor(() => group());
    expect(fieldset.tagName).toBe('FIELDSET');
    expect(fieldset.querySelector('legend')?.textContent).toBe('Send alerts and the digest by');
    const email = within(fieldset).getByRole<HTMLInputElement>('radio', {
      name: 'Email (collector@example.com)',
    });
    expect(email.checked).toBe(true);
    const discord = discordRadio();
    expect(discord.disabled).toBe(true);
    expect(discord.checked).toBe(false);
    const hint = within(fieldset).getByText(DISCORD_HINT);
    expect((discord.getAttribute('aria-describedby') ?? '').split(' ')).toContain(hint.id);
    const digest = screen.getByRole<HTMLInputElement>('checkbox', {
      name: 'Send me a daily digest',
    });
    expect(digest.checked).toBe(false);
    expect(within(main()).getByText(DIGEST_HINT)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Save' })).toBeTruthy();
    // Criterion 5: the session, the webhooks, the settings; nothing else.
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/me',
      'GET /v1/webhooks',
      'GET /v1/me/notifications',
    ]);
  });

  it('enables the Discord radio, without its hint, when a Discord webhook is available', async () => {
    open(
      verified(jsonResponse(notificationsBody({ channel: 'discord', discord_available: true }))),
    );
    await waitFor(() => {
      expect(discordRadio().disabled).toBe(false);
    });
    expect(discordRadio().checked).toBe(true);
    expect(screen.queryByText(DISCORD_HINT)).toBeNull();
  });

  it('shows a stored Discord channel whose webhook is turned off as checked, disabled, with the hint', async () => {
    open(
      verified(jsonResponse(notificationsBody({ channel: 'discord', discord_available: false }))),
    );
    await waitFor(() => {
      expect(discordRadio().checked).toBe(true);
    });
    expect(discordRadio().disabled).toBe(true);
    expect(within(group()).getByText(DISCORD_HINT)).toBeTruthy();
  });

  it('Save PUTs exactly channel and digest once, then says Saved.', async () => {
    const saved = notificationsBody({ digest: true });
    const { calls } = open(
      verified(jsonResponse(notificationsBody()), {
        'PUT /v1/me/notifications': jsonResponse(saved),
      }),
    );
    const digest = await screen.findByRole<HTMLInputElement>('checkbox', {
      name: 'Send me a daily digest',
    });
    fireEvent.click(digest);
    expect(digest.checked).toBe(true);
    const save = screen.getByRole('button', { name: 'Save' });
    fireEvent.click(save);
    fireEvent.click(save);
    const status = await screen.findByText('Saved.');
    expect(status.getAttribute('role')).toBe('status');
    const puts = calls.filter((call) => call.key === 'PUT /v1/me/notifications');
    expect(puts).toHaveLength(1);
    expect(sentBody(puts[0])).toEqual({ channel: 'email', digest: true });
    expect(
      screen.getByRole<HTMLInputElement>('checkbox', { name: 'Send me a daily digest' }).checked,
    ).toBe(true);
    // The answer is the new state: no extra GET.
    expect(calls.filter((call) => call.key === 'GET /v1/me/notifications')).toHaveLength(1);
  });

  it('sends the chosen Discord channel', async () => {
    const { calls } = open(
      verified(jsonResponse(notificationsBody({ discord_available: true })), {
        'PUT /v1/me/notifications': jsonResponse(
          notificationsBody({ channel: 'discord', discord_available: true }),
        ),
      }),
    );
    await waitFor(() => {
      expect(discordRadio().disabled).toBe(false);
    });
    fireEvent.click(discordRadio());
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await screen.findByText('Saved.');
    const put = calls.find((call) => call.key === 'PUT /v1/me/notifications');
    expect(sentBody(put)).toEqual({ channel: 'discord', digest: false });
  });

  it('shows a 422 errors.channel[0] under the group', async () => {
    open(
      verified(jsonResponse(notificationsBody({ channel: 'discord' })), {
        'PUT /v1/me/notifications': problemResponse(
          accountValidationProblem('/v1/me/notifications', {
            channel: ['Add a Discord webhook first.'],
          }),
        ),
      }),
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Save' }));
    const message = await within(group()).findByText('Add a Discord webhook first.');
    for (const radio of within(group()).getAllByRole('radio')) {
      expect((radio.getAttribute('aria-describedby') ?? '').split(' ')).toContain(message.id);
    }
    const alert = screen.getByRole('alert');
    expect(alert.textContent).toContain(
      'Could not save your alert settings: check the fields below.',
    );
    expect(alert.textContent).not.toContain('Add a Discord webhook first.');
    expect(screen.queryByText('Saved.')).toBeNull();
  });

  it('shows a failed save and a failed load as their problem blocks', async () => {
    open(
      verified(jsonResponse(notificationsBody()), {
        'PUT /v1/me/notifications': problemResponse(serverErrorProblem('/v1/me/notifications')),
      }),
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Save' }));
    expect((await screen.findByRole('alert')).textContent).toContain(
      'Could not save your alert settings: Internal Server Error',
    );
    cleanup();
    open(verified(problemResponse(serverErrorProblem('/v1/me/notifications'))));
    expect((await screen.findByRole('alert')).textContent).toContain(
      'Could not load your alert settings: Internal Server Error',
    );
    expect(screen.queryByRole('group', { name: 'Send alerts and the digest by' })).toBeNull();
  });

  it('refetches the settings after a Discord webhook is added, then after it is deleted', async () => {
    const { calls } = open({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': [
        jsonResponse(webhooksBody()),
        jsonResponse(webhooksBody([discordWebhook])),
        jsonResponse(webhooksBody()),
      ],
      'GET /v1/me/notifications': [
        jsonResponse(notificationsBody()),
        jsonResponse(notificationsBody({ discord_available: true })),
        jsonResponse(notificationsBody()),
      ],
      'POST /v1/webhooks': jsonResponse(webhookCreatedBody(discordWebhook, null), { status: 201 }),
      'DELETE /v1/webhooks/42': rawResponse(204, null),
    });
    await waitFor(() => {
      expect(discordRadio().disabled).toBe(true);
    });
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'discord' } });
    fireEvent.change(screen.getByLabelText('URL'), {
      target: { value: 'https://discord.com/api/webhooks/1/fake-token' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add webhook' }));
    await waitFor(() => {
      expect(discordRadio().disabled).toBe(false);
    });
    fireEvent.click(
      await screen.findByRole('button', { name: 'Delete the webhook https://discord.com/…' }),
    );
    await waitFor(() => {
      expect(discordRadio().disabled).toBe(true);
    });
    expect(calls.filter((call) => call.key === 'GET /v1/me/notifications')).toHaveLength(3);
  });
});

describe('Per-card alerts and the states without a settings form (P5 T07 criterion 2)', () => {
  it('signed in and verified: the line and the link to the watchlist', async () => {
    open(verified());
    const heading = await screen.findByRole('heading', { level: 2, name: 'Per-card alerts' });
    const section = heading.closest('section');
    if (section === null) throw new Error('section expected');
    expect(within(section).getByText(PER_CARD)).toBeTruthy();
    expect(
      within(section).getByRole('link', { name: 'Open your watchlist' }).getAttribute('href'),
    ).toBe('/watchlist');
    // The sections come in D10's order: Where alerts go, Per-card alerts, Webhooks.
    const headings = within(main())
      .getAllByRole('heading', { level: 2 })
      .map((h) => h.textContent);
    expect(headings).toEqual(['Where alerts go', 'Per-card alerts', 'Webhooks']);
  });

  it.each([
    ['demo', demoMe, 'The read-only demo cannot set alerts or add webhooks'],
    ['unverified', unverifiedMe, 'Verify your email address to set alerts or add webhooks.'],
  ] as const)('%s: no settings form and no settings request', async (_name, me, line) => {
    const { calls } = open({
      'GET /v1/me': jsonResponse(me),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
    });
    expect(await screen.findByText(new RegExp(line))).toBeTruthy();
    await screen.findByText('No webhooks yet.');
    expect(screen.queryByRole('heading', { name: 'Where alerts go' })).toBeNull();
    expect(screen.queryByRole('group')).toBeNull();
    expect(screen.queryByRole('checkbox')).toBeNull();
    expect(screen.queryByRole('radio')).toBeNull();
    expect(within(main()).queryByRole('button')).toBeNull();
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me', 'GET /v1/webhooks']);
  });
});

describe('the new sections are accessible and honest (P5 T07 criterion 6)', () => {
  const states = [
    ['email', jsonResponse(notificationsBody()), DISCORD_HINT],
    [
      'discord with a digest',
      jsonResponse(
        notificationsBody({ channel: 'discord', digest: true, discord_available: true }),
      ),
      DIGEST_HINT,
    ],
    [
      'failed',
      problemResponse(serverErrorProblem('/v1/me/notifications')),
      /Could not load your alert settings/,
    ],
  ] as const;

  it.each(states)(
    '%s: one h1, 44 px controls, labels, dark-ready, no forbidden word, no clock',
    async (_name, settings, ready) => {
      vi.useFakeTimers({ toFake: ['Date'] });
      vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
      const { container } = open(verified(settings));
      const check = (): void => {
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(lightOnlyClasses(container)).toEqual([]);
        expect(shortControls(main())).toEqual([]);
        for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
        expect(main().textContent).not.toContain('2031');
        for (const input of Array.from(main().querySelectorAll('input, select'))) {
          expect(input.id).not.toBe('');
          expect(main().querySelector(`label[for="${input.id}"]`)?.textContent).toBeTruthy();
        }
      };
      check();
      await screen.findAllByText(ready);
      check();
    },
  );

  it('keeps the rules with Saved. and a 422 on screen', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    const { container } = open(
      verified(jsonResponse(notificationsBody()), {
        'PUT /v1/me/notifications': [
          jsonResponse(notificationsBody()),
          problemResponse(
            accountValidationProblem('/v1/me/notifications', {
              channel: ['Add a Discord webhook first.'],
            }),
          ),
        ],
      }),
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Save' }));
    await screen.findByText('Saved.');
    for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
    expect(lightOnlyClasses(container)).toEqual([]);
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await screen.findByText('Add a Discord webhook first.');
    for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
    expect(lightOnlyClasses(container)).toEqual([]);
    expect(shortControls(main())).toEqual([]);
  });
});
