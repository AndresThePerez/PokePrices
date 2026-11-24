// D2-i2-T07 review: edge cases of the Account panels, the Display panel's hand-over with the
// footer, the Alerts empty state and the designed not-found page.
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  clearXsrfCookie,
  demoMe,
  demoSamplesBody,
  jsonResponse,
  notificationsBody,
  problemResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  stubFetch,
  unverifiedMe,
  webhooksBody,
} from '../test/fixtures';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function resetPreferences(): void {
  window.localStorage.clear();
  document.documentElement.removeAttribute('data-theme');
  document.documentElement.removeAttribute('data-palette');
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.restoreAllMocks();
  resetPreferences();
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

describe('not-found with a hostile or huge path (D2-i2-T07)', () => {
  it('shows a markup-looking path as text inside the one chip, nowhere else, with no request', () => {
    const calls = stubFetch(() => new Error('no request expected'));
    for (const path of [
      '/<script>alert(1)</script>',
      '/&lt;b&gt;x&lt;/b&gt;',
      '/a%20b?x=<i>#<u>',
    ]) {
      visit(path);
      const { unmount } = render(<App />);
      const chips = main().querySelectorAll('code');
      expect(chips).toHaveLength(1);
      const chip = chips[0] as HTMLElement;
      expect(chip.textContent).toBe(window.location.pathname);
      expect(chip.children).toHaveLength(0);
      expect(main().querySelector('script, b, i, u')).toBeNull();
      expect(chip.parentElement?.textContent).toBe(
        `PokéPrices has no page at ${window.location.pathname}.`,
      );
      // The path appears once in main: not in the search box, not in a link.
      expect(main().textContent.split(window.location.pathname)).toHaveLength(2);
      expect(
        within(main()).getByRole<HTMLInputElement>('searchbox', { name: 'Card name' }).value,
      ).toBe('');
      unmount();
    }
    expect(calls).toHaveLength(0);
  });

  it('keeps a 300-character path whole in the chip and the three links usable', () => {
    stubFetch(() => new Error('no request expected'));
    const path = `/${'x'.repeat(299)}`;
    visit(path);
    render(<App />);
    expect(main().querySelector('code')?.textContent).toBe(path);
    expect(
      within(main())
        .getAllByRole('link')
        .map((link) => link.textContent),
    ).toEqual(['Search', 'Movers', 'Go to the home page']);
    fireEvent.click(within(main()).getByRole('link', { name: 'Go to the home page' }));
    expect(window.location.pathname).toBe('/');
    expect(screen.queryByText('Page not found')).toBeNull();
  });
});

describe('the footer hand-over to the Display panel (D2-i2-T07, R297)', () => {
  it('keeps the two places in step through client navigation, in both directions', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    visit('/nowhere');
    render(<App />);
    fireEvent.change(screen.getByRole('combobox', { name: 'Theme' }), {
      target: { value: 'dark' },
    });
    act(() => {
      navigate('/account');
    });
    await screen.findByText('Signed in as collector@example.com');
    const theme = within(main()).getByRole<HTMLSelectElement>('combobox', { name: 'Theme' });
    expect(theme.value).toBe('dark');
    expect(screen.getAllByRole('combobox', { name: 'Theme' })).toHaveLength(1);
    fireEvent.change(within(main()).getByRole('combobox', { name: 'Up/down colours' }), {
      target: { value: 'blue-orange' },
    });
    act(() => {
      navigate('/nowhere');
    });
    expect(screen.getByRole<HTMLSelectElement>('combobox', { name: 'Theme' }).value).toBe('dark');
    expect(screen.getByRole<HTMLSelectElement>('combobox', { name: 'Up/down colours' }).value).toBe(
      'blue-orange',
    );
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    expect(document.documentElement.getAttribute('data-palette')).toBe('blue-orange');
  });

  it('gives /account/verify-email and a trailing-slash /account/ the full footer strip, not the empty one', () => {
    stubFetch(() => problemResponse(serverErrorProblem('/v1/me')));
    for (const path of ['/account/', '/account/verify-email']) {
      visit(path);
      const { unmount } = render(<App />);
      const footer = screen.getByRole('contentinfo');
      expect(
        within(footer)
          .getAllByRole('combobox')
          .map((box) => box.getAttribute('id') !== null),
      ).toEqual([true, true]);
      expect(screen.getAllByRole('combobox', { name: 'Theme' })).toHaveLength(1);
      expect(within(main()).queryAllByRole('combobox')).toEqual([]);
      unmount();
    }
  });
});

describe('the Profile panel (D2-i2-T07)', () => {
  it('names exactly seven regions in order, with Sign out and a failed sign-out inside Profile', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'POST /logout': problemResponse(serverErrorProblem('/logout')),
    });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as collector@example.com');
    const regions = within(main()).getAllByRole('region');
    expect(regions.map((region) => region.getAttribute('aria-labelledby') !== null)).toEqual(
      regions.map(() => true),
    );
    expect(
      regions.map(
        (region) =>
          (document.getElementById(region.getAttribute('aria-labelledby') ?? '') as HTMLElement)
            .textContent,
      ),
    ).toEqual([
      'Profile',
      'Change password',
      'Two-factor authentication',
      'Notifications',
      'Display',
      'Your data',
      'Delete account',
    ]);
    const profile = within(main()).getByRole('region', { name: 'Profile' });
    expect(within(profile).getAllByRole('heading')).toHaveLength(1);
    const twoFactor = within(main()).getByRole('region', { name: 'Two-factor authentication' });
    expect(within(twoFactor).getAllByRole('heading')).toHaveLength(1);
    expect(within(profile).getByText('Email verified').childNodes).toHaveLength(1);
    expect(within(main()).getAllByRole('button', { name: 'Sign out' })).toHaveLength(1);
    fireEvent.click(within(profile).getByRole('button', { name: 'Sign out' }));
    const alert = await within(profile).findByRole('alert');
    expect(alert.textContent).toContain('Could not sign out: Internal Server Error');
    expect(window.location.pathname).toBe('/account');
    // One alert on the page; the Display panel sent nothing; the only API request was the session.
    expect(screen.getAllByRole('alert')).toHaveLength(1);
    expect(calls.map((call) => call.key).filter((key) => key.includes('/v1/'))).toEqual([
      'GET /v1/me',
    ]);
  });

  it('keeps the resend flow and its status inside Profile for an unverified account', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(unverifiedMe),
      'POST /email/verification-notification': problemResponse(
        serverErrorProblem('/email/verification-notification'),
      ),
    });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as newcomer@example.com');
    const profile = within(main()).getByRole('region', { name: 'Profile' });
    expect(within(profile).getByText('Email not verified').childNodes).toHaveLength(1);
    expect(within(profile).queryByText('Email verified')).toBeNull();
    fireEvent.click(
      within(profile).getByRole('button', { name: 'Send the verification email again' }),
    );
    expect(await within(profile).findByRole('alert')).toBeTruthy();
    expect(screen.getAllByRole('alert')).toHaveLength(1);
  });
});

describe('the Alerts empty state (D2-i2-T07)', () => {
  const EMPTY = 'No webhooks yet.';

  async function openAlerts(me: typeof signedInMe | typeof demoMe | typeof unverifiedMe) {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(me),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
      'GET /v1/demo/samples': jsonResponse(demoSamplesBody()),
    });
    visit('/alerts');
    render(<App />);
    return screen.findByText(EMPTY);
  }

  it('is one text node in a paragraph, with the add form after it, for an account that can write', async () => {
    const empty = await openAlerts(signedInMe);
    expect(empty.tagName).toBe('P');
    expect(empty.childNodes).toHaveLength(1);
    const form = screen.getByRole('heading', { level: 3, name: 'Add a webhook' });
    expect(empty.compareDocumentPosition(form) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    // The empty line is not inside a list or the form.
    expect(empty.closest('ul, form')).toBeNull();
  });

  it.each([
    ['demo', demoMe],
    ['unverified', unverifiedMe],
  ] as const)(
    'shows the %s account the empty line and no form, button or field',
    async (_name, me) => {
      const empty = await openAlerts(me);
      expect(empty.textContent).toBe(EMPTY);
      expect(screen.queryByRole('heading', { name: 'Add a webhook' })).toBeNull();
      expect(screen.queryByRole('button', { name: 'Add webhook' })).toBeNull();
      expect(within(main()).queryByRole('textbox', { name: /URL/ })).toBeNull();
    },
  );
});
