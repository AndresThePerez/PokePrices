import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  clearXsrfCookie,
  demoMe,
  jsonResponse,
  lightOnlyClasses,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  shortControls,
  signedInMe,
  stubAccountFetch,
  stubFetch,
  tooManyRequestsProblem,
  unauthenticatedProblem,
  unverifiedMe,
} from '../test/fixtures';

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

describe('the account page (T03 criterion 3, D10)', () => {
  it('shows the loading line, then the signed-out view with its two links', async () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    visit('/account');
    render(<App />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Account');
    expect(screen.getByRole('status').textContent).toBe('Loading your account…');
    expect(await screen.findByText('You are not signed in.')).toBeTruthy();
    const links = within(main())
      .getAllByRole('link')
      .map((link) => [link.textContent, link.getAttribute('href')]);
    expect(links).toEqual([
      ['Sign in', '/sign-in'],
      ['Create an account', '/sign-up'],
    ]);
    // A 401 is the signed-out state, not a problem.
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('shows a verified account with its Sign out button', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    visit('/account');
    render(<App />);
    expect(await screen.findByText('Signed in as collector@example.com')).toBeTruthy();
    expect(within(main()).getByText('Email verified')).toBeTruthy();
    expect(within(main()).queryByText('Email not verified')).toBeNull();
    expect(
      within(main()).queryByRole('button', { name: 'Send the verification email again' }),
    ).toBeNull();
    expect(within(main()).getByRole('button', { name: 'Sign out' })).toBeTruthy();
  });

  it('resends the verification email for an unverified account', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(unverifiedMe),
      'POST /email/verification-notification': rawResponse(202, '""', 'application/json'),
    });
    visit('/account');
    render(<App />);
    expect(await screen.findByText('Email not verified')).toBeTruthy();
    fireEvent.click(
      within(main()).getByRole('button', { name: 'Send the verification email again' }),
    );
    expect(await screen.findByText('Verification email sent.')).toBeTruthy();
    expect(screen.getByText('Verification email sent.').getAttribute('role')).toBe('status');
    expect(
      calls.filter((call) => call.key === 'POST /email/verification-notification'),
    ).toHaveLength(1);
  });

  it('shows the 429 line when the resend is throttled', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(unverifiedMe),
      'POST /email/verification-notification': problemResponse(
        tooManyRequestsProblem('/email/verification-notification'),
      ),
    });
    visit('/account');
    render(<App />);
    fireEvent.click(
      await screen.findByRole('button', { name: 'Send the verification email again' }),
    );
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not send the verification email: Too Many Requests. Wait a minute and try again.',
    );
    expect(screen.queryByText('Verification email sent.')).toBeNull();
  });

  it('signs out to / and then shows the signed-out view', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [jsonResponse(signedInMe), problemResponse(unauthenticatedProblem)],
      'POST /logout': rawResponse(204, null),
    });
    visit('/account');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    expect(calls.some((call) => call.key === 'POST /logout')).toBe(true);
    act(() => {
      navigate('/account');
    });
    expect(await screen.findByText('You are not signed in.')).toBeTruthy();
    expect(screen.queryByText(/Signed in as/)).toBeNull();
  });

  it('shows a failed sign-out as the problem block', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'POST /logout': problemResponse(serverErrorProblem('/logout')),
    });
    visit('/account');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not sign out: Internal Server Error');
    expect(window.location.pathname).toBe('/account');
  });

  it('treats a 401 from /logout as already signed out: leaves for / with one GET /v1/me', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [jsonResponse(signedInMe), problemResponse(unauthenticatedProblem)],
      'POST /logout': problemResponse({ ...unauthenticatedProblem, instance: '/logout' }),
    });
    visit('/account');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    await new Promise((resolve) => setTimeout(resolve, 30));
    expect(screen.queryByText(/Could not sign out/)).toBeNull();
    // The home page's own movers reads are not routed here; every account request is listed.
    expect(
      calls.map((call) => call.key).filter((key) => !key.startsWith('GET /v1/movers?')),
    ).toEqual(['GET /v1/me', 'GET /sanctum/csrf-cookie', 'POST /logout', 'GET /v1/me']);
    act(() => {
      navigate('/account');
    });
    expect(await screen.findByText('You are not signed in.')).toBeTruthy();
    expect(screen.queryByText(/Signed in as/)).toBeNull();
  });

  it('shows a failed first load as the problem block', async () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(serverErrorProblem('/v1/me')) });
    visit('/account');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not load your account: Internal Server Error');
    expect(alert.textContent).toContain('Request ID req-p4-server-0007');
  });

  it('is dark-ready, 44 px and free of forbidden words in each state', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    const states = [
      [problemResponse(unauthenticatedProblem), 'You are not signed in.'],
      [jsonResponse(signedInMe), 'Email verified'],
      [jsonResponse(unverifiedMe), 'Email not verified'],
      [problemResponse(serverErrorProblem('/v1/me')), /Could not load your account/],
    ] as const;
    for (const [answer, expected] of states) {
      stubAccountFetch({
        'GET /v1/me': answer,
        'POST /email/verification-notification': problemResponse(
          tooManyRequestsProblem('/email/verification-notification'),
        ),
      });
      visit('/account');
      const { container } = render(<App />);
      const check = (): void => {
        expect(lightOnlyClasses(container)).toEqual([]);
        expect(shortControls(main())).toEqual([]);
        for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
        expect(main().textContent).not.toContain('2031');
      };
      check();
      await screen.findByText(expected);
      check();
      const resend = within(main()).queryByRole('button', {
        name: 'Send the verification email again',
      });
      if (resend !== null) {
        fireEvent.click(resend);
        await screen.findByRole('alert');
        check();
      }
      cleanup();
    }
  });
});

describe('login persists across a reload (T03 criterion 4, D13 Vitest half)', () => {
  it('a fresh mount at /account reads GET /v1/me and sends no sign-in request', async () => {
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    visit('/account');
    render(<App />);
    expect(await screen.findByText('Signed in as collector@example.com')).toBeTruthy();
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me']);
  });

  it('a fresh mount at /sign-in says who is signed in', async () => {
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    visit('/sign-in');
    render(<App />);
    expect(await screen.findByText('You are signed in as collector@example.com.')).toBeTruthy();
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me']);
  });
});

describe('the header Account link (T03 criterion 5)', () => {
  it('is a static link: the home page requests only its movers', () => {
    const calls = stubFetch(answerMovers);
    render(<App />);
    const link = within(screen.getByRole('banner')).getByRole('link', { name: 'Account' });
    expect(link.getAttribute('href')).toBe('/account');
    expect(link.className.split(/\s+/)).toContain('min-h-11');
    expect(calls.every((call) => call.url.startsWith('/v1/movers?'))).toBe(true);
    expect(calls.some((call) => call.url.startsWith('/v1/me'))).toBe(false);
  });

  it('fetches nothing on a page that makes no request, and navigates in place', async () => {
    const calls = stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    visit('/nowhere');
    render(<App />);
    expect(calls).toHaveLength(0);
    const link = within(screen.getByRole('banner')).getByRole('link', { name: 'Account' });
    expect(fireEvent.click(link)).toBe(false);
    expect(window.location.pathname).toBe('/account');
    expect(await screen.findByText('You are not signed in.')).toBeTruthy();
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me']);
  });
});

describe('the Display panel on /account (D2-i2-T07, R297)', () => {
  function options(select: HTMLElement): [string, string | null][] {
    return Array.from(select.querySelectorAll('option')).map((option) => [
      option.value,
      option.textContent,
    ]);
  }

  function resetPreferences(): void {
    window.localStorage.clear();
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.removeAttribute('data-palette');
  }

  // The footer landmark stays, empty; main holds the two settings once, labels and options exact.
  function checkDisplay(): void {
    const footer = screen.getByRole('contentinfo');
    expect(within(footer).queryAllByRole('combobox')).toEqual([]);
    expect(footer.textContent).toBe('');
    const display = within(main()).getByRole('region', { name: 'Display' });
    const theme = within(display).getByRole('combobox', { name: 'Theme' });
    expect(options(theme)).toEqual([
      ['system', 'System'],
      ['light', 'Light'],
      ['dark', 'Dark'],
    ]);
    const palette = within(display).getByRole('combobox', { name: 'Up/down colours' });
    expect(options(palette)).toEqual([
      ['green-red', 'Green and red'],
      ['blue-orange', 'Blue and orange'],
    ]);
    expect(within(display).getByText('Theme').tagName).toBe('LABEL');
    expect(within(display).getByText('Up/down colours').tagName).toBe('LABEL');
    expect(screen.getAllByRole('combobox', { name: 'Theme' })).toHaveLength(1);
    expect(screen.getAllByRole('combobox', { name: 'Up/down colours' })).toHaveLength(1);
  }

  it('holds Theme and Up/down colours in main, and none in the footer, in every session state', async () => {
    const states = [
      [problemResponse(unauthenticatedProblem), 'You are not signed in.'],
      [jsonResponse(signedInMe), 'Email verified'],
      [jsonResponse(unverifiedMe), 'Email not verified'],
      [jsonResponse(demoMe), 'Signed in as demo@example.com'],
      [problemResponse(serverErrorProblem('/v1/me')), /Could not load your account/],
    ] as const;
    for (const [answer, expected] of states) {
      const calls = stubAccountFetch({ 'GET /v1/me': answer });
      visit('/account');
      render(<App />);
      // Loading first: the settings are already there.
      expect(screen.getByRole('status').textContent).toBe('Loading your account…');
      checkDisplay();
      await screen.findByText(expected);
      checkDisplay();
      expect(calls.map((call) => call.key)).toEqual(['GET /v1/me']);
      cleanup();
    }
  });

  it('applies and stores a choice made in the Display panel', async () => {
    resetPreferences();
    stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as collector@example.com');
    const display = within(main()).getByRole('region', { name: 'Display' });
    const theme = within(display).getByRole<HTMLSelectElement>('combobox', { name: 'Theme' });
    const palette = within(display).getByRole<HTMLSelectElement>('combobox', {
      name: 'Up/down colours',
    });
    expect(theme.value).toBe('system');
    expect(palette.value).toBe('green-red');
    fireEvent.change(theme, { target: { value: 'dark' } });
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    expect(window.localStorage.getItem('pokeprices:theme')).toBe('dark');
    expect(theme.value).toBe('dark');
    fireEvent.change(palette, { target: { value: 'blue-orange' } });
    expect(document.documentElement.getAttribute('data-palette')).toBe('blue-orange');
    expect(window.localStorage.getItem('pokeprices:palette')).toBe('blue-orange');
    expect(palette.value).toBe('blue-orange');
    fireEvent.change(theme, { target: { value: 'system' } });
    expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    expect(window.localStorage.getItem('pokeprices:theme')).toBe('system');
    cleanup();
    resetPreferences();
  });
});
