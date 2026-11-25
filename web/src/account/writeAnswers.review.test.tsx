// Review tests for T03: a wrong-shaped answer to three more writes leaves their pages unchanged
// apart from the problem block, and the verify-email page refreshes the session only for a
// verification this visit fetched and the page was still there to receive.
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  clearXsrfCookie,
  holdSessionCookie,
  emptyPortfolioBody,
  historyFor,
  invalidSignatureProblem,
  jsonResponse,
  notificationsBody,
  pricedCardBody,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  unverifiedMe,
  webhookOf,
  webhooksBody,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const LINK = '/email/verify/7/0a1b2c?expires=1700000000&signature=fake0signature0000beef';
const PAGE = `/account/verify-email?link=${encodeURIComponent(LINK)}`;
const verifiedMe = { data: { ...unverifiedMe.data, email_verified: true } };

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

const ALERTS_ROUTES = {
  'GET /v1/me': jsonResponse(signedInMe),
  'GET /v1/webhooks': jsonResponse(webhooksBody([webhookOf()])),
  'GET /v1/me/notifications': jsonResponse(notificationsBody()),
};

describe('a wrong-shaped 2xx to a write whose page shows an outcome', () => {
  it('shows no queued line, and the problem block, when the test message answer has no data', async () => {
    const calls = stubAccountFetch({
      ...ALERTS_ROUTES,
      'POST /v1/webhooks/41/test': jsonResponse({ message_id: 'msg_01' }, { status: 202 }),
    });
    visit('/alerts');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: /^Send a test message to / }));
    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain('unexpected answer');
    expect(screen.queryByText('Test message queued. It is sent within a few minutes.')).toBeNull();
    expect(calls.filter((call) => call.key === 'POST /v1/webhooks/41/test')).toHaveLength(1);
  });

  it('says Saved. nowhere, and keeps the unsaved digest choice, when the settings answer lacks its fields', async () => {
    stubAccountFetch({
      ...ALERTS_ROUTES,
      'PUT /v1/me/notifications': jsonResponse({ data: { channel: 'email' } }),
    });
    visit('/alerts');
    render(<App />);
    const digest = await screen.findByRole<HTMLInputElement>('checkbox', {
      name: 'Send me a daily digest',
    });
    fireEvent.click(digest);
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain('Could not save your alert settings');
    expect(screen.queryByText('Saved.')).toBeNull();
    expect(
      screen.getByRole<HTMLInputElement>('checkbox', { name: 'Send me a daily digest' }).checked,
    ).toBe(true);
  });

  it('keeps the quantity form open and prints no held line when the holding answer has a string quantity', async () => {
    const holdingPut = 'PUT /v1/portfolio/ex10-%3F%3Aholo%3Aunlimited%3A-';
    stubAccountFetch({
      'GET /v1/cards/ex10-%3F': jsonResponse(pricedCardBody),
      'GET /v1/cards/ex10-%3F/prices?range=1M': jsonResponse(historyFor(pricedCardBody)),
      'GET /v1/portfolio': jsonResponse(emptyPortfolioBody),
      [holdingPut]: jsonResponse({
        data: { variant_key: 'ex10-?:holo:unlimited:-', quantity: '4' },
      }),
    });
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    const prices = screen.getByRole('region', { name: 'Prices' });
    fireEvent.click(
      within(prices).getByRole('button', { name: 'Add holo, unlimited to your portfolio' }),
    );
    const row = within(prices).getByText('holo, unlimited').closest('li');
    if (row === null) throw new Error('no holo row');
    fireEvent.change(await within(row).findByDisplayValue('1'), { target: { value: '4' } });
    fireEvent.click(within(row).getByRole('button', { name: 'Save' }));
    const alert = await within(row).findByRole('alert');
    expect(alert.textContent).toContain('Could not change your portfolio');
    expect(within(row).getByLabelText<HTMLInputElement>('Quantity owned').value).toBe('4');
    expect(within(row).queryByRole('link', { name: 'See your portfolio' })).toBeNull();
  });
});

describe('the verify-email page and the one session refresh', () => {
  it.each([
    ['a 403 link', problemResponse(invalidSignatureProblem)],
    ['a 500', problemResponse(serverErrorProblem('/email/verify/7/0a1b2c'))],
  ] as const)('asks the session only once when the link answers %s', async (_name, answer) => {
    const calls = stubAccountFetch({
      'GET /v1/me': [jsonResponse(unverifiedMe), jsonResponse(verifiedMe)],
      [`GET ${LINK}`]: answer,
    });
    visit(PAGE);
    render(<App />);
    await waitFor(() => {
      expect(screen.queryByText('Loading your account…')).toBeNull();
      expect(calls.map((call) => call.key)).toContain(`GET ${LINK}`);
    });
    await new Promise((resolve) => setTimeout(resolve, 40));
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me', `GET ${LINK}`]);
    expect(screen.queryByText('Your email address is verified.')).toBeNull();
  });

  it('asks nothing more when the refreshed session says signed out', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [jsonResponse(unverifiedMe), problemResponse(unauthenticatedProblem)],
      [`GET ${LINK}`]: rawResponse(204, null),
    });
    visit(PAGE);
    render(<App />);
    await screen.findByText('Sign in to verify your email address.');
    await new Promise((resolve) => setTimeout(resolve, 40));
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me', `GET ${LINK}`, 'GET /v1/me']);
  });

  it('refreshes nothing when the page is left before the link answers', async () => {
    holdSessionCookie();
    const calls: string[] = [];
    let release: (response: Response) => void = () => undefined;
    const linkAnswer = new Promise<Response>((resolve) => {
      release = resolve;
    });
    vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
      const key = `${init?.method ?? 'GET'} ${String(input)}`;
      calls.push(key);
      if (key === 'GET /v1/me') return Promise.resolve(jsonResponse(unverifiedMe));
      if (key === `GET ${LINK}`) return linkAnswer;
      if (key.startsWith('GET /v1/movers'))
        return Promise.resolve(jsonResponse({ data: [], meta: {} }));
      return Promise.reject(new TypeError(`no request expected: ${key}`));
    });
    visit(PAGE);
    render(<App />);
    await waitFor(() => {
      expect(calls).toContain(`GET ${LINK}`);
    });
    navigate('/sign-up');
    await screen.findByRole('heading', { level: 1 });
    release(rawResponse(204, null));
    await new Promise((resolve) => setTimeout(resolve, 40));
    vi.unstubAllGlobals();
    expect(calls.filter((key) => key === 'GET /v1/me')).toHaveLength(1);
  });
});
