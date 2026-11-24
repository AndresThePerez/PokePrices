// The Webhooks intro's Discord sentence against the page it sits on, when the settings it points
// at failed to load: the heading it names is still on the page above it, and once "Try again"
// loads the settings the Discord choice itself is under that heading, above the sentence.
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  clearXsrfCookie,
  discordWebhook,
  jsonResponse,
  notificationsBody,
  problemResponse,
  serviceUnavailableProblem,
  signedInMe,
  stubAccountFetch,
  webhooksBody,
} from '../test/fixtures';

const POINTED =
  'A Discord webhook receives your alerts and digest when you choose Discord under “Where alerts ' +
  'go” above.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  window.localStorage.clear();
  visit('/');
});

function follows(first: Node, second: Node): boolean {
  return (first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
}

describe('the Discord sentence when the settings read failed (review)', () => {
  it('names a heading the page shows above it, and Try again puts the Discord choice under that heading', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([discordWebhook])),
      'GET /v1/me/notifications': [
        problemResponse(serviceUnavailableProblem('/v1/me/notifications')),
        jsonResponse(notificationsBody({ discord_available: true })),
      ],
    });
    visit('/alerts');
    render(<App />);
    await screen.findByText(/Could not load your alert settings/);
    const heading = screen.getByRole('heading', { level: 2, name: 'Where alerts go' });
    const section = heading.parentElement as HTMLElement;
    const webhooks = screen.getByRole('heading', { level: 2, name: 'Webhooks' })
      .parentElement as HTMLElement;
    const intro = within(webhooks).getByText((text) => text.endsWith(POINTED));
    expect(follows(heading, intro)).toBe(true);
    expect(within(section).queryByRole('radio', { name: 'Discord' })).toBeNull();

    fireEvent.click(within(section).getByRole('button', { name: 'Try again' }));
    const choice = await within(section).findByRole('radio', { name: 'Discord' });
    expect((choice as HTMLInputElement).disabled).toBe(false);
    expect(follows(choice, intro)).toBe(true);
    // The one Discord choice is the one the sentence points at: none sits in the Webhooks section.
    expect(screen.getAllByRole('radio', { name: 'Discord' })).toHaveLength(1);
    expect(within(webhooks).queryByRole('radio')).toBeNull();
    expect(within(webhooks).getByText((text) => text.endsWith(POINTED))).toBe(intro);
    expect(calls.filter((call) => call.key === 'GET /v1/me/notifications')).toHaveLength(2);
    expect(within(section).queryByRole('alert')).toBeNull();
  });
});
