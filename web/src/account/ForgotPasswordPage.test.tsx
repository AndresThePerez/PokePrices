import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  accountValidationProblem,
  clearXsrfCookie,
  jsonResponse,
  lightOnlyClasses,
  problemResponse,
  sentBody,
  serverErrorProblem,
  shortControls,
  stubAccountFetch,
  tooManyRequestsProblem,
} from '../test/fixtures';

const FIXED = 'If that address has a PokéPrices account, a reset link is on its way.';

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

function send(email: string): void {
  fireEvent.change(within(main()).getByLabelText('Email'), { target: { value: email } });
  fireEvent.click(within(main()).getByRole('button', { name: 'Send reset link' }));
}

describe('the forgot-password page (T03 criterion 3, D10)', () => {
  it('shows the exact heading, label and button', () => {
    const calls = stubAccountFetch({});
    visit('/password/forgot');
    render(<App />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Reset your password');
    const email = within(main()).getByLabelText('Email');
    expect(email.getAttribute('type')).toBe('email');
    expect(email.getAttribute('autocomplete')).toBe('email');
    expect(within(main()).getByText('Email').tagName).toBe('LABEL');
    expect(calls).toHaveLength(0);
  });

  it("shows the API's message verbatim", async () => {
    const calls = stubAccountFetch({ 'POST /forgot-password': jsonResponse({ message: FIXED }) });
    visit('/password/forgot');
    render(<App />);
    send('collector@example.com');
    const status = await screen.findByRole('status');
    expect(status.textContent).toBe(FIXED);
    expect(sentBody(calls.find((call) => call.key === 'POST /forgot-password'))).toEqual({
      email: 'collector@example.com',
    });
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('shows whatever message the API sends, not a copy of its own', async () => {
    stubAccountFetch({ 'POST /forgot-password': jsonResponse({ message: 'Server wording.' }) });
    visit('/password/forgot');
    render(<App />);
    send('collector@example.com');
    expect((await screen.findByRole('status')).textContent).toBe('Server wording.');
  });

  it('shows a malformed email under the field', async () => {
    stubAccountFetch({
      'POST /forgot-password': problemResponse(
        accountValidationProblem('/forgot-password', {
          email: ['The email field must be a valid email address.'],
        }),
      ),
    });
    visit('/password/forgot');
    render(<App />);
    send('not-an-address@');
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not send the reset link: check the fields below.');
    const email = within(main()).getByLabelText('Email');
    expect(email.getAttribute('aria-invalid')).toBe('true');
    expect(document.getElementById(email.getAttribute('aria-describedby') ?? '')?.textContent).toBe(
      'The email field must be a valid email address.',
    );
  });

  it('shows the 429 line and a server failure with its request id', async () => {
    stubAccountFetch({
      'POST /forgot-password': [
        problemResponse(tooManyRequestsProblem('/forgot-password')),
        problemResponse(serverErrorProblem('/forgot-password')),
      ],
    });
    visit('/password/forgot');
    render(<App />);
    send('collector@example.com');
    expect((await screen.findByRole('alert')).textContent).toContain(
      'Could not send the reset link: Too Many Requests. Wait a minute and try again.',
    );
    send('collector@example.com');
    expect(
      await screen.findByText('Could not send the reset link: Internal Server Error'),
    ).toBeTruthy();
    expect(screen.getByRole('alert').textContent).toContain('Request ID req-p4-server-0007');
  });

  it('is dark-ready, 44 px and free of forbidden words in each state', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    stubAccountFetch({
      'POST /forgot-password': [
        problemResponse(tooManyRequestsProblem('/forgot-password')),
        jsonResponse({ message: FIXED }),
      ],
    });
    visit('/password/forgot');
    const { container } = render(<App />);
    const check = (): void => {
      expect(lightOnlyClasses(container)).toEqual([]);
      expect(shortControls(main())).toEqual([]);
      for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
      expect(main().textContent).not.toContain('2031');
    };
    check();
    send('collector@example.com');
    await screen.findByRole('alert');
    check();
    send('collector@example.com');
    await screen.findByText(FIXED);
    check();
  });
});
