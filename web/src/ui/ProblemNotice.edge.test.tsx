// "Try again" and the request id line on the pages that print the problem block: two presses in
// one tick repeat the read once, a failed write beside a failed read gets no button and is never
// sent again, an answer that carries no id prints no label, and a repeat that fails again shows
// the new answer.
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  answerMovers,
  clearXsrfCookie,
  isMoversUrl,
  jsonResponse,
  notificationsBody,
  problemResponse,
  serverErrorProblem,
  serviceUnavailableProblem,
  signedInMe,
  stubAccountFetch,
  stubFetch,
  tooManyRequestsProblem,
  watchlistBody,
  webhooksBody,
} from '../test/fixtures';

const SERVER_ERROR_ID = 'Request ID req-p4-server-0007';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function alerts(): (string | null)[] {
  return screen.getAllByRole('alert').map((alert) => alert.textContent);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  window.localStorage.clear();
  visit('/');
});

describe('Try again on the pages (review)', () => {
  it('repeats the watchlist read once when it is pressed twice in one tick', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': [
        problemResponse(serverErrorProblem('/v1/watchlist')),
        jsonResponse(watchlistBody()),
      ],
    });
    visit('/watchlist');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toBe(
      `Could not load your watchlist: Internal Server Error${SERVER_ERROR_ID}`,
    );
    const button = screen.getByRole('button', { name: 'Try again' });
    act(() => {
      button.click();
      button.click();
    });
    expect(await screen.findByRole('list', { name: 'Watchlist' })).toBeTruthy();
    expect(calls.filter((call) => call.key === 'GET /v1/watchlist')).toHaveLength(2);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull();
  });

  it('offers it for the failed read only when a write on the same page fails with the same answer, and sends the write once', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': [
        problemResponse(serverErrorProblem('/v1/me/notifications')),
        jsonResponse(notificationsBody()),
      ],
      'POST /v1/webhooks': problemResponse(serverErrorProblem('/v1/webhooks')),
    });
    visit('/alerts');
    render(<App />);
    await screen.findByText('No webhooks yet.');
    await screen.findByText(/Could not load your alert settings/);
    fireEvent.change(screen.getByLabelText('URL'), {
      target: { value: 'https://hooks.example.com/pokeprices' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add webhook' }));
    await screen.findByText(/Could not add the webhook/);
    // The two failures are the same status, title and id: only the read's block has the button.
    expect(alerts()).toEqual([
      `Could not load your alert settings: Internal Server Error${SERVER_ERROR_ID}`,
      `Could not add the webhook: Internal Server Error${SERVER_ERROR_ID}`,
    ]);
    const buttons = screen.getAllByRole('button', { name: 'Try again' });
    expect(buttons).toHaveLength(1);
    const settings = screen.getByRole('heading', { level: 2, name: 'Where alerts go' });
    expect(settings.parentElement?.contains(buttons[0] ?? null)).toBe(true);
    fireEvent.click(buttons[0] as HTMLElement);
    expect(await screen.findByLabelText('Send me a daily digest')).toBeTruthy();
    expect(calls.filter((call) => call.key === 'GET /v1/me/notifications')).toHaveLength(2);
    expect(calls.filter((call) => call.key === 'POST /v1/webhooks')).toHaveLength(1);
    expect(alerts()).toEqual([
      `Could not add the webhook: Internal Server Error${SERVER_ERROR_ID}`,
    ]);
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull();
  });

  it('prints no Request ID label for a 502 that carries no id, and its Try again loads the movers', async () => {
    let asked = 0;
    stubFetch((url) => {
      if (!isMoversUrl(url)) return new Error(`no request expected: ${url}`);
      asked += 1;
      return asked === 1
        ? new Response('<html><body>Bad Gateway</body></html>', {
            status: 502,
            headers: { 'Content-Type': 'text/html' },
          })
        : answerMovers(url);
    });
    visit('/movers');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toBe(
      'Could not load the movers: The PokéPrices API answered HTTP 502',
    );
    expect(screen.getByRole('main').textContent).not.toContain('Request ID');
    expect(alert.querySelector('code')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('list', { name: 'Rises' })).toBeTruthy();
    expect(asked).toBe(2);
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('shows the id of a 503 session read, then the 429 its repeat got without one, then the account', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [
        problemResponse(serviceUnavailableProblem('/v1/me')),
        problemResponse(tooManyRequestsProblem('/v1/me')),
        jsonResponse(signedInMe),
      ],
    });
    visit('/account');
    render(<App />);
    await screen.findByRole('alert');
    expect(alerts()).toEqual([
      'Could not load your account: Service UnavailableRequest ID req-p4-unavailable-0010',
    ]);
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    await screen.findByText(/Too Many Requests/);
    expect(alerts()).toEqual([
      'Could not load your account: Too Many Requests. Wait a minute and try again.',
    ]);
    expect(screen.getByRole('main').textContent).not.toContain('Request ID');
    expect(screen.getAllByRole('button', { name: 'Try again' })).toHaveLength(1);
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('Signed in as collector@example.com')).toBeTruthy();
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me', 'GET /v1/me', 'GET /v1/me']);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull();
  });
});
