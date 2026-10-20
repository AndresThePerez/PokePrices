import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { deleteAccount } from '../api/client';
import type * as QueryModule from '../query';
import { navigate } from '../router';
import {
  FORBIDDEN_WORDS,
  XSRF_HEADER,
  accountValidationProblem,
  clearXsrfCookie,
  demoMe,
  domHolds,
  jsonResponse,
  lightOnlyClasses,
  portfolioBody,
  problemResponse,
  rawResponse,
  sentBody,
  serverErrorProblem,
  setXsrfCookie,
  shortControls,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  unauthenticatedProblem,
  watchlistBody,
} from '../test/fixtures';

// Plan P4-i1 T08 criterion 3 (D10 "Your data", D11, D14): the export is a plain download link
// (no fetch), the delete form needs the checkbox and the password, 204 signs the browser out with
// a status line, and the password never outlives an answer.

// App builds its own QueryClient; capture each one so the cache can be inspected after a deletion.
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

const PASSWORD = 'fake-password-0000-ok';
const DELETION = 'POST /v1/me/deletion';
const WRONG_PASSWORD = 'The password is incorrect.';
const CHECKBOX = 'I understand that this cannot be undone';
const DELETE = 'Delete my account';
const DELETE_TEXT =
  'Deleting your account removes your email address, watchlist and portfolio from PokéPrices. It cannot be undone.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.useRealTimers();
  created.clients.length = 0;
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function region(name: string): HTMLElement {
  return within(main()).getByRole('region', { name });
}

function deleteButton(): HTMLButtonElement {
  return within(region('Delete account')).getByRole<HTMLButtonElement>('button', { name: DELETE });
}

function tick(): void {
  fireEvent.click(within(region('Delete account')).getByRole('checkbox', { name: CHECKBOX }));
}

function typePassword(value: string): void {
  fireEvent.change(within(region('Delete account')).getByLabelText('Password'), {
    target: { value },
  });
}

function passwordField(): HTMLInputElement {
  return within(region('Delete account')).getByLabelText<HTMLInputElement>('Password');
}

async function openAccount(email = 'collector@example.com'): Promise<void> {
  visit('/account');
  render(<App />);
  await screen.findByText(`Signed in as ${email}`);
}

describe('the deleteAccount client (T08 criterion 3, D11)', () => {
  it('posts the password in a JSON body to /v1/me/deletion with the account flags', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({ [DELETION]: rawResponse(204, null) });

    const result = await deleteAccount({ password: PASSWORD });

    expect(result).toEqual({ ok: true, data: undefined });
    expect(calls.map((call) => call.key)).toEqual([DELETION]);
    const init = calls[0]?.init;
    expect(calls[0]?.url).toBe('/v1/me/deletion');
    expect(sentBody(calls[0])).toEqual({ password: PASSWORD });
    expect(init?.credentials).toBe('same-origin');
    expect(init?.referrerPolicy).toBe('same-origin');
    expect(init?.redirect).toBe('error');
    expect(init?.headers).toMatchObject({
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': XSRF_HEADER,
    });
  });
});

describe('Your data on /account (T08 criterion 3, D10)', () => {
  it('offers the export as a plain same-origin download link, never a fetch and never noreferrer', async () => {
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    await openAccount();

    const section = region('Your data');
    expect(within(section).getByRole('heading', { level: 2 }).textContent).toBe('Your data');
    const link = within(section).getByRole('link', { name: 'Download your data (JSON)' });
    expect(link.getAttribute('href')).toBe('/v1/me/export');
    expect(link.getAttribute('download')).toBe('pokeprices-export.json');
    expect(link.getAttribute('referrerpolicy')).toBe('same-origin');
    expect(link.hasAttribute('rel')).toBe(false);
    expect(link.hasAttribute('target')).toBe(false);

    // The click is the browser's own navigation: the app does not intercept it or fetch anything.
    let prevented: boolean | null = null;
    const observe = (event: MouseEvent): void => {
      prevented = event.defaultPrevented;
      event.preventDefault();
    };
    window.addEventListener('click', observe);
    fireEvent.click(link);
    window.removeEventListener('click', observe);
    expect(prevented).toBe(false);
    expect(window.location.pathname).toBe('/account');
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me']);
  });

  it('shows the export link and "cannot be deleted" to the demo, without the delete form', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(demoMe) });
    await openAccount('demo@example.com');

    expect(
      within(region('Your data')).getByRole('link', { name: 'Download your data (JSON)' }),
    ).toBeTruthy();
    const deletion = region('Delete account');
    expect(within(deletion).getByText('The demo account cannot be deleted.')).toBeTruthy();
    expect(within(deletion).queryByText(DELETE_TEXT)).toBeNull();
    expect(within(deletion).queryByRole('checkbox')).toBeNull();
    expect(within(deletion).queryByLabelText('Password')).toBeNull();
    expect(within(deletion).queryByRole('button', { name: DELETE })).toBeNull();
  });

  it('needs the checkbox and then the password before Delete my account submits', async () => {
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    await openAccount();
    const deletion = region('Delete account');
    expect(within(deletion).getByRole('heading', { level: 2 }).textContent).toBe('Delete account');
    expect(within(deletion).getByText(DELETE_TEXT)).toBeTruthy();

    // Nothing ticked: no password field, and the button sends nothing.
    expect(within(deletion).queryByLabelText('Password')).toBeNull();
    expect(deleteButton().disabled).toBe(true);
    fireEvent.click(deleteButton());

    // Ticked without a password: still nothing.
    tick();
    expect(passwordField().type).toBe('password');
    expect(passwordField().getAttribute('autocomplete')).toBe('current-password');
    expect(deleteButton().disabled).toBe(true);
    fireEvent.submit(deleteButton().closest('form') as HTMLFormElement);

    // A password, then unticked: the password is dropped and the button is off again.
    typePassword(PASSWORD);
    expect(deleteButton().disabled).toBe(false);
    tick();
    expect(within(deletion).queryByLabelText('Password')).toBeNull();
    expect(deleteButton().disabled).toBe(true);
    tick();
    expect(passwordField().value).toBe('');

    await act(() => Promise.resolve());
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me']);
    expect(domHolds(PASSWORD)).toBe(false);
  });

  it('deletes on 204: the status line, the signed-out view, ["me"] refetched and the lists forgotten', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [jsonResponse(signedInMe), problemResponse(unauthenticatedProblem)],
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
      'GET /v1/portfolio': jsonResponse(portfolioBody()),
      [DELETION]: rawResponse(204, null),
    });
    // The lists were read earlier in this visit, so their caches hold this account's rows.
    visit('/watchlist');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Watchlist' });
    await waitFor(() => {
      expect(created.clients[0]?.getQueryData(['account', 'watchlist'])).toBeDefined();
    });
    act(() => {
      navigate('/portfolio');
    });
    await waitFor(() => {
      expect(created.clients[0]?.getQueryData(['account', 'portfolio'])).toBeDefined();
    });
    act(() => {
      navigate('/account');
    });
    await screen.findByText('Signed in as collector@example.com');

    tick();
    typePassword(PASSWORD);
    fireEvent.click(deleteButton());

    const status = await screen.findByText('Your account has been deleted.');
    expect(status.getAttribute('role')).toBe('status');
    expect(await screen.findByText('You are not signed in.')).toBeTruthy();
    expect(screen.queryByText(/Signed in as/)).toBeNull();
    expect(screen.queryByRole('region', { name: 'Delete account' })).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
    const deletions = calls.filter((call) => call.key === DELETION);
    expect(deletions).toHaveLength(1);
    expect(sentBody(deletions[0])).toEqual({ password: PASSWORD });
    expect(calls.filter((call) => call.key === 'GET /v1/me')).toHaveLength(2);
    const client = created.clients[0];
    expect(client?.getQueryCache().find({ queryKey: ['account', 'watchlist'] })).toBeUndefined();
    expect(client?.getQueryCache().find({ queryKey: ['account', 'portfolio'] })).toBeUndefined();
    expect(client?.getQueryData(['me'])).toBeNull();
    expect(domHolds(PASSWORD)).toBe(false);
  });

  it('shows a wrong password under Password, keeps the account and clears the field', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      [DELETION]: problemResponse(
        accountValidationProblem('/v1/me/deletion', { password: [WRONG_PASSWORD] }),
      ),
    });
    await openAccount();
    tick();
    typePassword(PASSWORD);
    fireEvent.click(deleteButton());

    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain('Could not delete the account: check the fields below.');
    // The message sits under its field, not repeated in the block.
    expect(alert.textContent).not.toContain(WRONG_PASSWORD);
    const field = passwordField();
    expect(field.getAttribute('aria-invalid')).toBe('true');
    const describedBy = field.getAttribute('aria-describedby') ?? '';
    expect(document.getElementById(describedBy)?.textContent).toBe(WRONG_PASSWORD);
    expect(field.value).toBe('');
    expect(domHolds(PASSWORD)).toBe(false);
    expect(screen.getByText('Signed in as collector@example.com')).toBeTruthy();
    expect(screen.queryByText('Your account has been deleted.')).toBeNull();
  });

  it('shows the 429 line and other failures as the problem block, clearing the password each time', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      [DELETION]: [
        problemResponse(tooManyRequestsProblem('/v1/me/deletion')),
        problemResponse(serverErrorProblem('/v1/me/deletion')),
        new TypeError('network down'),
      ],
    });
    await openAccount();
    tick();

    typePassword(PASSWORD);
    fireEvent.click(deleteButton());
    expect((await within(main()).findByRole('alert')).textContent).toContain(
      'Could not delete the account: Too Many Requests. Wait a minute and try again.',
    );
    expect(passwordField().value).toBe('');

    typePassword(PASSWORD);
    fireEvent.click(deleteButton());
    await within(main()).findByText(/Could not delete the account: Internal Server Error/);
    expect(within(main()).getByRole('alert').textContent).toContain(
      'Request ID req-p4-server-0007',
    );
    expect(passwordField().value).toBe('');

    typePassword(PASSWORD);
    fireEvent.click(deleteButton());
    await within(main()).findByText(
      /Could not delete the account: The PokéPrices API could not be reached/,
    );
    expect(passwordField().value).toBe('');
    expect(domHolds(PASSWORD)).toBe(false);
  });

  it('keeps every state dark-ready, 44 px, labelled and free of forbidden words', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    function honest(container: HTMLElement): void {
      expect(lightOnlyClasses(container)).toEqual([]);
      expect(shortControls(main())).toEqual([]);
      const text = main().textContent;
      for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
      expect(text).not.toContain('2031');
      for (const input of Array.from(main().querySelectorAll('input'))) {
        expect(main().querySelector(`label[for="${input.id}"]`)?.textContent).not.toBe('');
      }
    }

    stubAccountFetch({
      'GET /v1/me': [jsonResponse(signedInMe), problemResponse(unauthenticatedProblem)],
      [DELETION]: [
        problemResponse(
          accountValidationProblem('/v1/me/deletion', { password: [WRONG_PASSWORD] }),
        ),
        rawResponse(204, null),
      ],
    });
    visit('/account');
    const { container } = render(<App />);
    await screen.findByText('Signed in as collector@example.com');
    honest(container);
    tick();
    honest(container);
    typePassword(PASSWORD);
    fireEvent.click(deleteButton());
    await within(main()).findByRole('alert');
    honest(container);
    typePassword(PASSWORD);
    fireEvent.click(deleteButton());
    await screen.findByText('Your account has been deleted.');
    await screen.findByText('You are not signed in.');
    honest(container);
    cleanup();

    stubAccountFetch({ 'GET /v1/me': jsonResponse(demoMe) });
    visit('/account');
    const demo = render(<App />);
    await screen.findByText('The demo account cannot be deleted.');
    honest(demo.container);
  });
});
