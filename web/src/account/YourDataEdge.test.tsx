import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  accountValidationProblem,
  clearXsrfCookie,
  domHolds,
  jsonResponse,
  passwordRequiredResponse,
  problemResponse,
  rawResponse,
  sentBody,
  signedInMe,
  stubAccountFetch,
} from '../test/fixtures';

// Adversarial edge tests for plan P4-i1 T08 criterion 3 (D10 "Delete account", D14 accessibility).

const PASSWORD = 'fake-password-0000-ok';
const DELETION = 'POST /v1/me/deletion';
const ENABLE = 'POST /user/two-factor-authentication';
const CHECKBOX = 'I understand that this cannot be undone';
const WRONG_PASSWORD = 'The password is incorrect.';

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  window.history.replaceState(null, '', '/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function region(name: string): HTMLElement {
  return within(main()).getByRole('region', { name });
}

async function openAccount(): Promise<void> {
  window.history.replaceState(null, '', '/account');
  render(<App />);
  await screen.findByText('Signed in as collector@example.com');
}

function tick(): void {
  fireEvent.click(within(region('Delete account')).getByRole('checkbox', { name: CHECKBOX }));
}

function typeDeletePassword(value: string): void {
  fireEvent.change(within(region('Delete account')).getByLabelText('Password'), {
    target: { value },
  });
}

function deleteForm(): HTMLFormElement {
  const form = within(region('Delete account'))
    .getByRole('button', { name: 'Delete my account' })
    .closest('form');
  if (form === null) throw new Error('the delete form is missing');
  return form;
}

describe('Delete account edge cases (T08)', () => {
  it('sends one deletion when the form is submitted twice in a row (double submit)', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      [DELETION]: rawResponse(204, null),
    });
    await openAccount();
    tick();
    typeDeletePassword(PASSWORD);

    fireEvent.submit(deleteForm());
    fireEvent.submit(deleteForm());
    await act(() => Promise.resolve());
    await screen.findByText('Your account has been deleted.');

    const deletions = calls.filter((call) => call.key === DELETION);
    expect(deletions).toHaveLength(1);
    expect(sentBody(deletions[0])).toEqual({ password: PASSWORD });
  });

  it('keeps the wrong-password message on screen when the checkbox is cleared afterwards', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      [DELETION]: problemResponse(
        accountValidationProblem('/v1/me/deletion', { password: [WRONG_PASSWORD] }),
      ),
    });
    await openAccount();
    tick();
    typeDeletePassword(PASSWORD);
    fireEvent.click(
      within(region('Delete account')).getByRole('button', { name: 'Delete my account' }),
    );
    await within(main()).findByRole('alert');

    tick();

    // Whatever the layout does with the hidden field, the person is not left with a silent failure.
    expect(
      within(main()).queryByRole('alert') ?? within(main()).queryByText(WRONG_PASSWORD),
    ).not.toBeNull();
    expect(domHolds(PASSWORD)).toBe(false);
  });

  it('keeps the delete password and the two-factor password prompt apart when both are open', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      [ENABLE]: passwordRequiredResponse(),
    });
    await openAccount();
    tick();
    typeDeletePassword('fake-delete-password-0000');

    fireEvent.click(
      within(region('Two-factor authentication')).getByRole('button', {
        name: 'Turn on two-factor authentication',
      }),
    );
    await within(region('Two-factor authentication')).findByRole('button', {
      name: 'Confirm password',
    });

    // Two fields named Password exist at once, but each is reachable only inside its own named region.
    expect(within(main()).getAllByLabelText('Password')).toHaveLength(2);
    expect(within(region('Delete account')).getAllByLabelText('Password')).toHaveLength(1);
    expect(within(region('Two-factor authentication')).getAllByLabelText('Password')).toHaveLength(
      1,
    );
    expect(
      within(region('Delete account')).getByLabelText<HTMLInputElement>('Password').value,
    ).toBe('fake-delete-password-0000');
    expect(
      within(region('Two-factor authentication')).getByLabelText<HTMLInputElement>('Password')
        .value,
    ).toBe('');
    expect(calls.filter((call) => call.key === DELETION)).toHaveLength(0);
  });
});
