// D2-i2-T07 review: a choice that could not be stored must survive the footer's hand-over to the
// Display panel (R297; preferences.ts: "the choice holds for this visit only").
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import { clearXsrfCookie, jsonResponse, signedInMe, stubAccountFetch } from '../test/fixtures';

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.restoreAllMocks();
  document.documentElement.removeAttribute('data-theme');
  document.documentElement.removeAttribute('data-palette');
  window.history.replaceState(null, '', '/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

describe('the footer hand-over with blocked storage (D2-i2-T07, R297)', () => {
  it('keeps a choice that could not be stored (blocked storage) when the visitor opens /account', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });
    stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    window.history.replaceState(null, '', '/nowhere');
    render(<App />);
    fireEvent.change(screen.getByRole('combobox', { name: 'Theme' }), {
      target: { value: 'dark' },
    });
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    act(() => {
      navigate('/account');
    });
    await screen.findByText('Signed in as collector@example.com');
    // "The choice holds for this visit only": the Display panel shows it and the page keeps it.
    expect(within(main()).getByRole<HTMLSelectElement>('combobox', { name: 'Theme' }).value).toBe(
      'dark',
    );
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    act(() => {
      navigate('/nowhere');
    });
    expect(screen.getByRole<HTMLSelectElement>('combobox', { name: 'Theme' }).value).toBe('dark');
  });
});
