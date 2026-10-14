// The Webhooks section of /alerts (P5 T04 criteria 2, 3, 5, 6): adding a signed or Discord
// webhook, the shown-once signing secret (never in the DOM after dismissal or a navigation, never in
// a query or mutation cache entry, never in a later request), field errors, the problem blocks,
// the test message and Delete.
import { QueryClientProvider, type QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import { createQueryClient } from '../query';
import { navigate } from '../router';
import AlertsPage from './AlertsPage';
import { forgetLists } from './lists';
import {
  FAKE_SIGNING_SECRET,
  accountValidationProblem,
  clearXsrfCookie,
  discordWebhook,
  domHolds,
  emailNotVerifiedProblem,
  jsonResponse,
  notificationsBody,
  problemResponse,
  rawResponse,
  sentBody,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  webhookCreatedBody,
  webhookDisabledProblem,
  webhookOf,
  webhooksBody,
  type AccountCall,
} from '../test/fixtures';

const SECRET_LINE =
  'Copy this secret before you leave this page. PokéPrices shows it once; to change it, delete ' +
  'the webhook and add it again.';
const QUEUED = 'Test message queued. It is sent within a few minutes.';
const HOOK_URL = 'https://hooks.example.com/pokeprices';
const SIGNED_ROW = 'https://hooks.example.com/…';

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

function renderPage(client: QueryClient): void {
  render(
    <QueryClientProvider client={client}>
      <AlertsPage />
    </QueryClientProvider>,
  );
}

/** Everything both caches hold, as text: query data and errors, mutation variables and data. */
function cacheText(client: QueryClient): string {
  const queries = client
    .getQueryCache()
    .getAll()
    .map((query) => [query.queryKey, query.state.data, query.state.error]);
  const mutations = client
    .getMutationCache()
    .getAll()
    .map((mutation) => [mutation.state.variables, mutation.state.data]);
  return JSON.stringify([queries, mutations]);
}

function requestText(calls: readonly AccountCall[]): string[] {
  return calls.map(
    (call) => `${call.url} ${typeof call.init?.body === 'string' ? call.init.body : ''}`,
  );
}

async function addSigned(url = HOOK_URL, description = ''): Promise<void> {
  fireEvent.change(await screen.findByLabelText('URL'), { target: { value: url } });
  fireEvent.change(screen.getByLabelText('Description (optional)'), {
    target: { value: description },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Add webhook' }));
}

describe('adding a webhook (P5 T04 criterion 2)', () => {
  it('has the D10 form: Type with two options, URL and Description fields', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
    });
    renderPage(createQueryClient());
    const type = await screen.findByLabelText<HTMLSelectElement>('Type');
    expect(type.tagName).toBe('SELECT');
    expect(Array.from(type.options).map((option) => [option.value, option.textContent])).toEqual([
      ['standard', 'Signed webhook (Standard Webhooks)'],
      ['discord', 'Discord'],
    ]);
    const url = screen.getByLabelText<HTMLInputElement>('URL');
    expect(url.type).toBe('url');
    expect(url.getAttribute('inputmode')).toBe('url');
    expect(url.getAttribute('autocomplete')).toBe('off');
    expect(url.getAttribute('placeholder')).toBe('https://');
    expect(screen.getByLabelText<HTMLInputElement>('Description (optional)').type).toBe('text');
  });

  it('POSTs exactly kind, url and description, shows the secret once, then removes it', async () => {
    const created = webhookCreatedBody(webhookOf(), FAKE_SIGNING_SECRET);
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': [jsonResponse(webhooksBody()), jsonResponse(webhooksBody([webhookOf()]))],
      'POST /v1/webhooks': jsonResponse(created, { status: 201 }),
    });
    const client = createQueryClient();
    renderPage(client);
    await addSigned(HOOK_URL, 'Home bot');
    expect(await screen.findByRole('heading', { level: 3, name: 'Signing secret' })).toBeTruthy();
    expect(screen.getByText(SECRET_LINE)).toBeTruthy();
    const code = screen.getByText(FAKE_SIGNING_SECRET);
    expect(code.tagName).toBe('CODE');
    expect(code.className.split(/\s+/)).toContain('select-all');
    const posts = calls.filter((call) => call.key === 'POST /v1/webhooks');
    expect(posts).toHaveLength(1);
    expect(sentBody(posts[0])).toEqual({
      kind: 'standard',
      url: HOOK_URL,
      description: 'Home bot',
    });
    // The URL field is cleared after the submit; the new webhook is listed from a refetch.
    expect(screen.getByLabelText<HTMLInputElement>('URL').value).toBe('');
    expect(await within(main()).findByRole('list', { name: 'Webhooks' })).toBeTruthy();
    // Never in a cache entry, never in a later request.
    expect(cacheText(client)).not.toContain(FAKE_SIGNING_SECRET);
    expect(cacheText(client)).not.toContain('whsec_');
    fireEvent.click(screen.getByRole('button', { name: 'I have stored the secret' }));
    expect(screen.queryByRole('heading', { name: 'Signing secret' })).toBeNull();
    expect(domHolds(FAKE_SIGNING_SECRET)).toBe(false);
    expect(cacheText(client)).not.toContain(FAKE_SIGNING_SECRET);
    expect(requestText(calls).some((text) => text.includes('whsec_'))).toBe(false);
  });

  it('leaves out a blank description', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'POST /v1/webhooks': jsonResponse(
        webhookCreatedBody(webhookOf({ description: null }), FAKE_SIGNING_SECRET),
        { status: 201 },
      ),
    });
    renderPage(createQueryClient());
    await addSigned(HOOK_URL, '   ');
    await screen.findByText(SECRET_LINE);
    const post = calls.find((call) => call.key === 'POST /v1/webhooks');
    const body = sentBody(post) as Record<string, unknown>;
    expect(body).toEqual(
      'description' in body
        ? { kind: 'standard', url: HOOK_URL, description: null }
        : { kind: 'standard', url: HOOK_URL },
    );
  });

  it('never shows the secret again after a navigation away and back', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': [jsonResponse(webhooksBody()), jsonResponse(webhooksBody([webhookOf()]))],
      'POST /v1/webhooks': jsonResponse(webhookCreatedBody(webhookOf(), FAKE_SIGNING_SECRET), {
        status: 201,
      }),
    });
    visit('/alerts');
    render(<App />);
    await addSigned();
    expect(await screen.findByText(FAKE_SIGNING_SECRET)).toBeTruthy();
    act(() => {
      navigate('/account');
    });
    expect(await screen.findByText('Signed in as collector@example.com')).toBeTruthy();
    expect(domHolds(FAKE_SIGNING_SECRET)).toBe(false);
    act(() => {
      navigate('/alerts');
    });
    expect(await within(main()).findByRole('list', { name: 'Webhooks' })).toBeTruthy();
    expect(screen.queryByRole('heading', { name: 'Signing secret' })).toBeNull();
    expect(domHolds(FAKE_SIGNING_SECRET)).toBe(false);
  });

  it('adds a Discord webhook: its line, no secret block, the type sent as discord', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': [
        jsonResponse(webhooksBody()),
        jsonResponse(webhooksBody([discordWebhook])),
      ],
      'POST /v1/webhooks': jsonResponse(webhookCreatedBody(discordWebhook, null), { status: 201 }),
    });
    renderPage(createQueryClient());
    fireEvent.change(await screen.findByLabelText('Type'), { target: { value: 'discord' } });
    await addSigned('https://discord.com/api/webhooks/1/fake-token');
    expect(
      await screen.findByText('Discord webhook added. Use Send a test message to check it.'),
    ).toBeTruthy();
    expect(screen.queryByRole('heading', { name: 'Signing secret' })).toBeNull();
    const post = calls.find((call) => call.key === 'POST /v1/webhooks');
    expect((sentBody(post) as Record<string, unknown>).kind).toBe('discord');
    expect(screen.getByLabelText<HTMLInputElement>('URL').value).toBe('');
    // The Discord URL carries a token: it is never shown back.
    expect(domHolds('fake-token')).toBe(false);
  });

  it('shows each 422 message under its field with aria-invalid', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
      'POST /v1/webhooks': problemResponse(
        accountValidationProblem('/v1/webhooks', {
          kind: ['Choose a signed webhook or Discord.'],
          url: ['PokéPrices does not send to private, local or reserved network addresses.'],
          description: ['The description field must not be greater than 100 characters.'],
        }),
      ),
    });
    renderPage(createQueryClient());
    await addSigned('https://hooks.example.com/x', 'x'.repeat(101));
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not add the webhook: check the fields below.');
    for (const [label, message] of [
      ['Type', 'Choose a signed webhook or Discord.'],
      ['URL', 'PokéPrices does not send to private, local or reserved network addresses.'],
      ['Description (optional)', 'The description field must not be greater than 100 characters.'],
    ] as const) {
      const field = screen.getByLabelText(label);
      expect(field.getAttribute('aria-invalid')).toBe('true');
      const described = (field.getAttribute('aria-describedby') ?? '').split(' ');
      const texts = described.map((id) => document.getElementById(id)?.textContent);
      expect(texts).toContain(message);
    }
    // The messages sit under the fields, not repeated in the problem block.
    expect(alert.textContent).not.toContain('Choose a signed webhook or Discord.');
    // The URL field is cleared after any submit.
    expect(screen.getByLabelText<HTMLInputElement>('URL').value).toBe('');
  });

  it('shows a 403 email-not-verified as the problem block', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
      'POST /v1/webhooks': problemResponse(emailNotVerifiedProblem('/v1/webhooks')),
    });
    renderPage(createQueryClient());
    await addSigned();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not add the webhook: Verify Your Email First');
  });

  it('shows the P4 429 line', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
      'POST /v1/webhooks': problemResponse(tooManyRequestsProblem('/v1/webhooks')),
    });
    renderPage(createQueryClient());
    await addSigned();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not add the webhook: Too Many Requests. Wait a minute and try again.',
    );
  });

  it('sends one POST for a double click on Add webhook', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'POST /v1/webhooks': jsonResponse(webhookCreatedBody(webhookOf(), FAKE_SIGNING_SECRET), {
        status: 201,
      }),
    });
    renderPage(createQueryClient());
    fireEvent.change(await screen.findByLabelText('URL'), { target: { value: HOOK_URL } });
    const button = screen.getByRole('button', { name: 'Add webhook' });
    fireEvent.click(button);
    fireEvent.click(button);
    await screen.findByText(SECRET_LINE);
    expect(calls.filter((call) => call.key === 'POST /v1/webhooks')).toHaveLength(1);
  });
});

describe('test message and delete (P5 T04 criterion 3)', () => {
  it('POSTs one test per click (double click guarded) and shows the queued line', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([webhookOf(), discordWebhook])),
      'POST /v1/webhooks/41/test': jsonResponse(
        { data: { message_id: 'msg_01J0000000000000000000000A' } },
        { status: 202 },
      ),
    });
    renderPage(createQueryClient());
    const button = await screen.findByRole('button', {
      name: `Send a test message to ${SIGNED_ROW}`,
    });
    fireEvent.click(button);
    fireEvent.click(button);
    expect(await screen.findByText(QUEUED)).toBeTruthy();
    expect(screen.getByText(QUEUED).getAttribute('role')).toBe('status');
    expect(calls.filter((call) => call.key === 'POST /v1/webhooks/41/test')).toHaveLength(1);
    // Only the row that was tested says so.
    const rows = within(screen.getByRole('list', { name: 'Webhooks' })).getAllByRole('listitem');
    expect(rows[0]?.textContent).toContain(QUEUED);
    expect(rows[1]?.textContent).not.toContain(QUEUED);
  });

  it.each([
    [
      webhookDisabledProblem('/v1/webhooks/41/test'),
      'Could not send the test message: Webhook Turned Off',
    ],
    [
      tooManyRequestsProblem('/v1/webhooks/41/test'),
      'Could not send the test message: Too Many Requests. Wait a minute and try again.',
    ],
  ])('shows a refused test as the problem block (%#)', async (problem, line) => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([webhookOf()])),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
      'POST /v1/webhooks/41/test': problemResponse(problem),
    });
    renderPage(createQueryClient());
    fireEvent.click(
      await screen.findByRole('button', { name: `Send a test message to ${SIGNED_ROW}` }),
    );
    expect((await screen.findByRole('alert')).textContent).toContain(line);
    expect(screen.queryByText(QUEUED)).toBeNull();
  });

  it('DELETEs once by id and refetches the list', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': [
        jsonResponse(webhooksBody([webhookOf(), discordWebhook])),
        jsonResponse(webhooksBody([discordWebhook])),
      ],
      'DELETE /v1/webhooks/41': rawResponse(204, null),
    });
    renderPage(createQueryClient());
    const button = await screen.findByRole('button', { name: `Delete the webhook ${SIGNED_ROW}` });
    fireEvent.click(button);
    fireEvent.click(button);
    await waitFor(() => {
      expect(screen.queryByText(SIGNED_ROW)).toBeNull();
    });
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/me',
      'GET /v1/webhooks',
      'GET /v1/me/notifications',
      'GET /sanctum/csrf-cookie',
      'DELETE /v1/webhooks/41',
      'GET /v1/webhooks',
    ]);
  });

  it('shows a failed delete as the problem block and keeps the row', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([webhookOf()])),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
      'DELETE /v1/webhooks/41': problemResponse(serverErrorProblem('/v1/webhooks/41')),
    });
    renderPage(createQueryClient());
    fireEvent.click(
      await screen.findByRole('button', { name: `Delete the webhook ${SIGNED_ROW}` }),
    );
    expect((await screen.findByRole('alert')).textContent).toContain(
      'Could not delete the webhook: Internal Server Error',
    );
    expect(screen.getByText(SIGNED_ROW)).toBeTruthy();
  });
});

describe('another account never sees these webhooks (P5 T04)', () => {
  it('forgetLists drops the webhook list with the watchlist and portfolio', () => {
    const client = createQueryClient();
    client.setQueryData(['webhooks'], webhooksBody([webhookOf()]));
    forgetLists(client);
    expect(client.getQueryData(['webhooks'])).toBeUndefined();
  });
});
