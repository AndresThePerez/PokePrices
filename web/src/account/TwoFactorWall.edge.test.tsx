// Reviewer edge case: the two-factor screen against the 423 the API really sends now, the problem
// document password-confirmation-required. The screen must still ask for the password, show the
// problem nowhere as an error, and run the waiting step again once the password is confirmed, also
// when the wall answers a later read of the same step.
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  FAKE_SETUP_KEY,
  clearXsrfCookie,
  domHolds,
  emptyJsonResponse,
  jsonResponse,
  problemResponse,
  sentBody,
  signedInMe,
  stubAccountFetch,
  twoFactorQrAnswer,
  twoFactorSecretAnswer,
} from '../test/fixtures';

const PASSWORD = 'fake-password-0000-ok';
const QR_ALT = 'QR code for your authenticator app';
const ENABLE = 'POST /user/two-factor-authentication';
const CONFIRM_PASSWORD = 'POST /user/confirm-password';
const QR = 'GET /user/two-factor-qr-code';
const SECRET = 'GET /user/two-factor-secret-key';

function wall(instance: string, requestId: string): Response {
  return problemResponse({
    type: 'https://pokeprices.andrestheperez.com/problems/password-confirmation-required',
    title: 'Password Confirmation Required',
    status: 423,
    instance,
    request_id: requestId,
  });
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

function click(name: string): void {
  fireEvent.click(within(main()).getByRole('button', { name }));
}

/** Waits for the prompt the wall opened (once `met` says the 423 has arrived), then confirms. */
async function confirmPassword(met: () => boolean): Promise<void> {
  await waitFor(() => {
    expect(met()).toBe(true);
    const button = within(main()).getByRole('button', { name: 'Confirm password' });
    expect(button.hasAttribute('disabled')).toBe(false);
  });
  expect(within(main()).queryByRole('alert')).toBeNull();
  expect(domHolds('Password Confirmation Required')).toBe(false);
  expect(domHolds('req-wall-')).toBe(false);
  fireEvent.change(within(main()).getByLabelText('Password'), { target: { value: PASSWORD } });
  click('Confirm password');
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  window.localStorage.clear();
  window.sessionStorage.clear();
  window.history.replaceState(null, '', '/');
});

describe('the two-factor screen and the 423 problem document', () => {
  it('asks for the password on the problem 423 of the write and of a later read, then finishes the step', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      [ENABLE]: [
        wall('/user/two-factor-authentication', 'req-wall-0001'),
        emptyJsonResponse(200),
        emptyJsonResponse(200),
      ],
      [CONFIRM_PASSWORD]: [emptyJsonResponse(201), emptyJsonResponse(201)],
      [QR]: [wall('/user/two-factor-qr-code', 'req-wall-0002'), jsonResponse(twoFactorQrAnswer)],
      [SECRET]: jsonResponse(twoFactorSecretAnswer),
    });
    window.history.replaceState(null, '', '/account');
    render(<App />);
    await screen.findByText('Signed in as collector@example.com');

    click('Turn on two-factor authentication');
    await confirmPassword(() => calls.some((call) => call.key === ENABLE));
    // The first confirmation let the write through; the QR code read met the wall again.
    await confirmPassword(() => calls.some((call) => call.key === QR));
    await within(main()).findByRole('img', { name: QR_ALT });

    expect(domHolds(FAKE_SETUP_KEY)).toBe(true);
    expect(within(main()).queryByRole('alert')).toBeNull();
    expect(within(main()).queryByRole('button', { name: 'Confirm password' })).toBeNull();
    expect(
      calls.map((call) => call.key).filter((key) => key !== 'GET /v1/me' && !key.includes('csrf')),
    ).toEqual([ENABLE, CONFIRM_PASSWORD, ENABLE, QR, CONFIRM_PASSWORD, ENABLE, QR, SECRET]);
    expect(
      calls.filter((call) => call.key === CONFIRM_PASSWORD).map((call) => sentBody(call)),
    ).toEqual([{ password: PASSWORD }, { password: PASSWORD }]);
  });
});
