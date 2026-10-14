// Adversarial edge cases for /alerts (P5 T04): the signing secret on every exit path, nothing of one
// account under the next, bounded retries, one status line per row, hostile row text.
import { QueryClientProvider, type QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { StrictMode } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import { createQueryClient } from '../query';
import { navigate } from '../router';
import AlertsPage from './AlertsPage';
import {
  FAKE_SIGNING_SECRET,
  clearXsrfCookie,
  demoMe,
  domHolds,
  emailNotVerifiedProblem,
  jsonResponse,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  unauthenticatedProblem,
  webhookCreatedBody,
  webhookOf,
  webhooksBody,
  type AccountCall,
} from '../test/fixtures';

const SECRET_B = `whsec_${btoa('1'.repeat(32))}`;
const HOOK_URL = 'https://hooks.example.com/pokeprices';
const ALPHA = 'https://alpha.example.com/…';
const BETA = 'https://beta.example.net/…';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

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

function requestText(calls: readonly AccountCall[]): string {
  return calls
    .map((call) => `${call.url} ${typeof call.init?.body === 'string' ? call.init.body : ''}`)
    .join('\n');
}

async function addSigned(url = HOOK_URL): Promise<void> {
  fireEvent.change(await screen.findByLabelText('URL'), { target: { value: url } });
  fireEvent.click(screen.getByRole('button', { name: 'Add webhook' }));
}

describe('the signing secret on every exit path (P5 T04 criterion 2, D10 "shown once")', () => {
  it('shows one secret and sends one POST under React strict mode, then removes it', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': [jsonResponse(webhooksBody()), jsonResponse(webhooksBody([webhookOf()]))],
      'POST /v1/webhooks': jsonResponse(webhookCreatedBody(webhookOf(), FAKE_SIGNING_SECRET), {
        status: 201,
      }),
    });
    const client = createQueryClient();
    render(
      <StrictMode>
        <QueryClientProvider client={client}>
          <AlertsPage />
        </QueryClientProvider>
      </StrictMode>,
    );
    await addSigned();
    await screen.findByRole('heading', { level: 3, name: 'Signing secret' });
    expect(document.querySelectorAll('code')).toHaveLength(1);
    expect(calls.filter((call) => call.key === 'POST /v1/webhooks')).toHaveLength(1);
    expect(cacheText(client)).not.toContain(FAKE_SIGNING_SECRET);
    fireEvent.click(screen.getByRole('button', { name: 'I have stored the secret' }));
    expect(domHolds(FAKE_SIGNING_SECRET)).toBe(false);
    expect(document.querySelectorAll('code')).toHaveLength(0);
  });

  it('keeps only the newest secret on screen after a second add, and no secret anywhere else', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([webhookOf()])),
      'POST /v1/webhooks': [
        jsonResponse(webhookCreatedBody(webhookOf(), FAKE_SIGNING_SECRET), { status: 201 }),
        jsonResponse(webhookCreatedBody(webhookOf({ id: 43 }), SECRET_B), { status: 201 }),
        problemResponse(serverErrorProblem('/v1/webhooks')),
      ],
    });
    const client = createQueryClient();
    render(
      <QueryClientProvider client={client}>
        <AlertsPage />
      </QueryClientProvider>,
    );
    await addSigned();
    await screen.findByText(FAKE_SIGNING_SECRET);
    await waitFor(() => {
      expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Add webhook' }).disabled).toBe(
        false,
      );
    });
    await addSigned('https://hooks.example.com/second');
    await screen.findByText(SECRET_B);
    expect(domHolds(FAKE_SIGNING_SECRET)).toBe(false);
    await waitFor(() => {
      expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Add webhook' }).disabled).toBe(
        false,
      );
    });
    // A failed third add must not bring the first secret back or leak the second one elsewhere.
    await addSigned('https://hooks.example.com/third');
    await screen.findByText('Could not add the webhook: Internal Server Error');
    expect(domHolds(FAKE_SIGNING_SECRET)).toBe(false);
    for (const secret of [FAKE_SIGNING_SECRET, SECRET_B]) {
      expect(cacheText(client)).not.toContain(secret);
      expect(requestText(calls)).not.toContain(secret);
    }
    expect(requestText(calls)).not.toContain('whsec_');
  });

  it('bounds a 419 on add to one retry, and shows no secret block for a second 419', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'POST /v1/webhooks': problemResponse({
        type: 'https://pokeprices.andrestheperez.com/problems/http-419',
        title: 'Page Expired',
        status: 419,
        instance: '/v1/webhooks',
        request_id: 'req-p5-419-0001',
      }),
    });
    render(
      <QueryClientProvider client={createQueryClient()}>
        <AlertsPage />
      </QueryClientProvider>,
    );
    await addSigned();
    expect((await screen.findByRole('alert')).textContent).toContain(
      'Could not add the webhook: Page Expired',
    );
    expect(calls.filter((call) => call.key === 'POST /v1/webhooks')).toHaveLength(2);
    expect(screen.queryByRole('heading', { name: 'Signing secret' })).toBeNull();
    expect(screen.getByLabelText<HTMLInputElement>('URL').value).toBe('');
  });
});

describe('nothing of one account shows under the next one (P4 pattern, P5 data rule 4)', () => {
  it('never shows account A webhooks or its secret to B signing in on the same tab', async () => {
    let me: Response = jsonResponse(signedInMe);
    let list: Response = jsonResponse(webhooksBody([webhookOf({ url_hint: ALPHA })]));
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/webhooks': () => list,
      'POST /v1/webhooks': jsonResponse(
        webhookCreatedBody(webhookOf({ id: 44, url_hint: ALPHA }), FAKE_SIGNING_SECRET),
        { status: 201 },
      ),
      'POST /logout': rawResponse(204, null),
      'POST /login': jsonResponse({ two_factor: false }),
    });
    visit('/alerts');
    render(<App />);
    await screen.findByText(ALPHA);
    await addSigned();
    await screen.findByText(FAKE_SIGNING_SECRET);

    act(() => {
      navigate('/account');
    });
    me = problemResponse(unauthenticatedProblem);
    fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
    await screen.findByRole('heading', { level: 1, name: 'PokéPrices' });
    expect(domHolds(FAKE_SIGNING_SECRET)).toBe(false);
    expect(domHolds('alpha.example.com')).toBe(false);

    act(() => {
      navigate('/sign-in?next=/alerts');
    });
    fireEvent.change(await screen.findByLabelText('Email'), {
      target: { value: 'second@example.com' },
    });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'fake-pass-0000' } });
    me = jsonResponse({ data: { ...signedInMe.data, email: 'second@example.com' } });
    list = jsonResponse(webhooksBody([webhookOf({ id: 51, url_hint: BETA })]));
    const before = calls.filter((call) => call.key === 'GET /v1/webhooks').length;
    fireEvent.click(within(screen.getByRole('main')).getByRole('button', { name: 'Sign in' }));
    await screen.findByText(BETA);
    expect(screen.queryByText(ALPHA)).toBeNull();
    expect(domHolds('alpha.example.com')).toBe(false);
    expect(domHolds(FAKE_SIGNING_SECRET)).toBe(false);
    expect(screen.queryByRole('heading', { name: 'Signing secret' })).toBeNull();
    // B's list was fetched, not served from A's cache.
    expect(calls.filter((call) => call.key === 'GET /v1/webhooks').length).toBeGreaterThan(before);
  });

  it('never shows account A webhooks to the demo that starts after A signs out', async () => {
    let me: Response = jsonResponse(signedInMe);
    let list: Response = jsonResponse(webhooksBody([webhookOf({ url_hint: ALPHA })]));
    stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/webhooks': () => list,
      'POST /logout': rawResponse(204, null),
      'POST /v1/demo/session': rawResponse(204, null),
    });
    visit('/alerts');
    render(<App />);
    await screen.findByText(ALPHA);
    act(() => {
      navigate('/account');
    });
    me = problemResponse(unauthenticatedProblem);
    fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
    await screen.findByRole('heading', { level: 1, name: 'PokéPrices' });
    act(() => {
      navigate('/sign-in');
    });
    me = jsonResponse(demoMe);
    list = jsonResponse(webhooksBody());
    fireEvent.click(await screen.findByRole('button', { name: 'Try the read-only demo' }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/watchlist');
    });
    act(() => {
      navigate('/alerts');
    });
    await screen.findByText('No webhooks yet.');
    expect(domHolds('alpha.example.com')).toBe(false);
    expect(within(screen.getByRole('main')).queryByRole('button')).toBeNull();
  });
});

describe('a refused add or delete never wedges the page (D10 problem blocks, double-submit guards)', () => {
  it('lets a second add go out after a 403 email-not-verified, one POST per submit, no secret block', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'POST /v1/webhooks': problemResponse(emailNotVerifiedProblem('/v1/webhooks')),
    });
    render(
      <QueryClientProvider client={createQueryClient()}>
        <AlertsPage />
      </QueryClientProvider>,
    );
    await addSigned();
    await screen.findByText('Could not add the webhook: Verify Your Email First');
    await waitFor(() => {
      expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Add webhook' }).disabled).toBe(
        false,
      );
    });
    await addSigned();
    await waitFor(() => {
      expect(calls.filter((call) => call.key === 'POST /v1/webhooks')).toHaveLength(2);
    });
    expect(screen.queryByRole('heading', { name: 'Signing secret' })).toBeNull();
    expect(document.querySelectorAll('[role="alert"]')).toHaveLength(1);
  });

  it('releases the Delete guard after a refusal so the next click sends a second DELETE', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': [jsonResponse(webhooksBody([webhookOf()])), jsonResponse(webhooksBody())],
      'DELETE /v1/webhooks/41': [
        problemResponse(tooManyRequestsProblem('/v1/webhooks/41')),
        rawResponse(204, null),
      ],
    });
    render(
      <QueryClientProvider client={createQueryClient()}>
        <AlertsPage />
      </QueryClientProvider>,
    );
    const label = 'Delete the webhook https://hooks.example.com/…';
    fireEvent.click(await screen.findByRole('button', { name: label }));
    expect((await screen.findByRole('alert')).textContent).toContain(
      'Could not delete the webhook: Too Many Requests. Wait a minute and try again.',
    );
    await waitFor(() => {
      expect(screen.getByRole<HTMLButtonElement>('button', { name: label }).disabled).toBe(false);
    });
    fireEvent.click(screen.getByRole('button', { name: label }));
    await screen.findByText('No webhooks yet.');
    expect(calls.filter((call) => call.key === 'DELETE /v1/webhooks/41')).toHaveLength(2);
  });
});

describe('rows: exactly one status line, hostile text stays text (D10, D11)', () => {
  const STATUS =
    /^(No message sent yet\.|Last message delivered \(\d{4}-\d{2}-\d{2}\)\.|Last message failed \(\d{4}-\d{2}-\d{2}\)\.|Last message waiting to be sent\.|Turned off: .*)$/;

  it('gives each of five mixed rows exactly one status line and the write buttons its state allows', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(
        webhooksBody([
          webhookOf({ id: 1, url_hint: 'https://a.example.com/…' }),
          webhookOf({
            id: 2,
            url_hint: 'https://b.example.com/…',
            last_delivery: { status: 'failed', at: '2026-10-11T20:05:48Z' },
          }),
          webhookOf({
            id: 3,
            url_hint: 'https://c.example.com/…',
            last_delivery: { status: 'pending', at: '2026-10-10T20:05:49Z' },
          }),
          webhookOf({
            id: 4,
            url_hint: 'https://d.example.com/…',
            disabled: true,
            last_delivery: { status: 'failed', at: '2026-10-09T20:05:49Z' },
          }),
          webhookOf({
            id: 5,
            url_hint: 'https://e.example.com/…',
            last_delivery: { status: 'delivered', at: '2026-10-14T20:05:47Z' },
          }),
        ]),
      ),
    });
    render(
      <QueryClientProvider client={createQueryClient()}>
        <AlertsPage />
      </QueryClientProvider>,
    );
    const rows = within(await screen.findByRole('list', { name: 'Webhooks' })).getAllByRole(
      'listitem',
    );
    expect(rows).toHaveLength(5);
    for (const row of rows) {
      const lines = Array.from(row.querySelectorAll('p')).filter((p) => STATUS.test(p.textContent));
      expect(lines).toHaveLength(1);
      // The time of day of a delivery is never shown, only its date.
      expect(row.textContent).not.toMatch(/\d{2}:\d{2}/);
    }
    const tests = within(rows[3] as HTMLElement).queryAllByRole('button', { name: /^Send a test/ });
    expect(tests).toHaveLength(0);
    expect(within(rows[3] as HTMLElement).getAllByRole('button')).toHaveLength(1);
    for (const index of [0, 1, 2, 4]) {
      expect(within(rows[index] as HTMLElement).getAllByRole('button')).toHaveLength(2);
    }
  });

  it('renders a hostile url_hint and description as text, never as markup', async () => {
    const hostile = '<img src=x onerror=alert(1)><script>window.pwned=1</script>';
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(
        webhooksBody([webhookOf({ url_hint: hostile, description: hostile })]),
      ),
    });
    render(
      <QueryClientProvider client={createQueryClient()}>
        <AlertsPage />
      </QueryClientProvider>,
    );
    await screen.findByRole('list', { name: 'Webhooks' });
    expect(document.querySelectorAll('img, script')).toHaveLength(0);
    expect(within(screen.getByRole('main')).getAllByText(hostile)).toHaveLength(2);
    // The hint is also inside the buttons' accessible names, as plain text.
    expect(screen.getByRole('button', { name: `Delete the webhook ${hostile}` })).toBeTruthy();
  });
});
