// Q1-i6 T03: a write answer of the wrong shape shows the standard problem block on its page (c1
// rest), the email and webhook-description inputs carry the server's length limits while the
// password fields and the webhook URL carry none (D10, HG-46 #1), and the verify-email page asks
// the link once and then refreshes the session exactly once (the c1 note).
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  clearXsrfCookie,
  historyFor,
  jsonResponse,
  notificationsBody,
  pricedCardBody,
  problemResponse,
  rawResponse,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  unverifiedMe,
  webhookOf,
  webhooksBody,
} from '../test/fixtures';
import { navigate } from '../router';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const UNEXPECTED_TITLE = 'The PokéPrices API sent an unexpected answer (HTTP 200)';

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

describe('a wrong-shaped write answer on its page', () => {
  it('shows the problem block, and no secret, for a webhook create answered without its fields', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
      'POST /v1/webhooks': jsonResponse({ data: { ...webhookOf() } }, { status: 201 }),
    });
    visit('/alerts');
    render(<App />);
    fireEvent.change(await screen.findByLabelText('URL'), {
      target: { value: 'https://hooks.example.com/pokeprices' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add webhook' }));
    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not add the webhook: The PokéPrices API sent an unexpected answer (HTTP 201)',
    );
    expect(screen.queryByRole('heading', { name: 'Signing secret' })).toBeNull();
    expect(calls.filter((call) => call.key === 'POST /v1/webhooks')).toHaveLength(1);
  });

  it('shows the problem block, and no watched line, for a watchlist add answered with a list', async () => {
    const watchPut = 'PUT /v1/watchlist/ex10-%3F%3Aholo%3Aunlimited%3A-';
    stubAccountFetch({
      'GET /v1/cards/ex10-%3F': jsonResponse(pricedCardBody),
      'GET /v1/cards/ex10-%3F/prices?range=1M': jsonResponse(historyFor(pricedCardBody)),
      [watchPut]: jsonResponse({ data: [] }),
    });
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    const prices = screen.getByRole('region', { name: 'Prices' });
    fireEvent.click(
      within(prices).getByRole('button', { name: 'Add holo, unlimited to your watchlist' }),
    );
    const row = within(prices).getByText('holo, unlimited').closest('li');
    if (row === null) throw new Error('no holo row');
    const alert = await within(row).findByRole('alert');
    expect(alert.textContent).toContain(`Could not change your watchlist: ${UNEXPECTED_TITLE}`);
    expect(within(row).queryByText('On your watchlist.')).toBeNull();
  });
});

function input(label: string): HTMLInputElement {
  return within(main()).getByLabelText<HTMLInputElement>(label);
}

describe('the server-matching length limits (D10, HG-46 #1)', () => {
  it('limits the sign-up email to 255 and no password field', () => {
    stubAccountFetch({});
    visit('/sign-up');
    render(<App />);
    expect(input('Email').getAttribute('maxlength')).toBe('255');
    expect(input('Password').hasAttribute('maxlength')).toBe(false);
    expect(input('Confirm password').hasAttribute('maxlength')).toBe(false);
  });

  it('limits the sign-in email to 255 and not the password', async () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    visit('/sign-in');
    render(<App />);
    const email = await within(main()).findByLabelText<HTMLInputElement>('Email');
    expect(email.getAttribute('maxlength')).toBe('255');
    expect(input('Password').hasAttribute('maxlength')).toBe(false);
  });

  it('limits the forgot-password email to 255', () => {
    stubAccountFetch({});
    visit('/password/forgot');
    render(<App />);
    expect(input('Email').getAttribute('maxlength')).toBe('255');
  });

  it('limits the reset-password email to 255 and neither new password field', () => {
    stubAccountFetch({});
    visit(
      `/password/reset?token=fake0reset0value0000abcd&email=${encodeURIComponent('a@example.com')}`,
    );
    render(<App />);
    expect(input('Email').getAttribute('maxlength')).toBe('255');
    expect(input('New password').hasAttribute('maxlength')).toBe(false);
    expect(input('Confirm new password').hasAttribute('maxlength')).toBe(false);
  });

  it('limits the webhook description to 100 and not the URL', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
    });
    visit('/alerts');
    render(<App />);
    const url = await within(main()).findByLabelText<HTMLInputElement>('URL');
    expect(url.hasAttribute('maxlength')).toBe(false);
    expect(input('Description (optional)').getAttribute('maxlength')).toBe('100');
  });
});

describe('the verify-email request sequence (c1 note)', () => {
  const link = '/email/verify/7/0a1b2c?expires=1700000000&signature=fake0signature0000beef';
  const page = `/account/verify-email?link=${encodeURIComponent(link)}`;
  const verifiedMe = { data: { ...unverifiedMe.data, email_verified: true } };

  it('asks the session, the link once, then the session exactly once more', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [jsonResponse(unverifiedMe), jsonResponse(verifiedMe)],
      [`GET ${link}`]: rawResponse(204, null),
    });
    visit(page);
    render(<App />);
    expect(await screen.findByText('Your email address is verified.')).toBeTruthy();
    await waitFor(() => {
      expect(calls.map((call) => call.key)).toEqual(['GET /v1/me', `GET ${link}`, 'GET /v1/me']);
    });
    await new Promise((resolve) => setTimeout(resolve, 30));
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me', `GET ${link}`, 'GET /v1/me']);
  });

  it('neither asks the link again nor refreshes the session again on a return to the page', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [jsonResponse(unverifiedMe), jsonResponse(verifiedMe)],
      [`GET ${link}`]: rawResponse(204, null),
    });
    visit(page);
    render(<App />);
    expect(await screen.findByText('Your email address is verified.')).toBeTruthy();
    await waitFor(() => {
      expect(calls.filter((call) => call.key === 'GET /v1/me')).toHaveLength(2);
    });
    navigate('/');
    await screen.findByRole('heading', { level: 1 });
    navigate(page);
    expect(await screen.findByText('Your email address is verified.')).toBeTruthy();
    await new Promise((resolve) => setTimeout(resolve, 30));
    // The home page in between asks only for its movers.
    const account = calls.filter((call) => !call.key.startsWith('GET /v1/movers?'));
    expect(account.map((call) => call.key)).toEqual(['GET /v1/me', `GET ${link}`, 'GET /v1/me']);
  });
});
