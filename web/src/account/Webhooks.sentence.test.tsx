// The Webhooks intro's Discord sentence on /alerts: an account that may change its settings is
// pointed up at "Where alerts go", which is on the page above the sentence; the demo and an
// unverified account, whose page shows no settings, are pointed at nothing.
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  demoMe,
  demoSamplesBody,
  jsonResponse,
  notificationsBody,
  signedInMe,
  stubAccountFetch,
  unverifiedMe,
  webhooksBody,
} from '../test/fixtures';

const RULE =
  'PokéPrices sends a signed message to each signed webhook when a card on your watchlist or ' +
  'portfolio moves by at least 5% and $1.00 between two daily closes. ';
const POINTED = `${RULE}A Discord webhook receives your alerts and digest when you choose Discord under “Where alerts go” above.`;
const UNPOINTED = `${RULE}A Discord webhook receives your alerts and digest when Discord is where your alerts go.`;

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

function webhooksSection(): HTMLElement {
  return screen.getByRole('heading', { level: 2, name: 'Webhooks' }).parentElement as HTMLElement;
}

describe('the Discord sentence of the Webhooks intro', () => {
  it('points up at Where alerts go for an account that may change its settings', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
    });
    visit('/alerts');
    render(<App />);
    const settings = await screen.findByRole('heading', { level: 2, name: 'Where alerts go' });
    const intro = within(webhooksSection()).getByText(POINTED);
    expect(intro.tagName).toBe('P');
    // "above": the section it names comes before the sentence in the page.
    expect(settings.compareDocumentPosition(intro) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.getByRole('main').textContent).not.toContain('Discord below.');
    for (const word of FORBIDDEN_WORDS) expect(intro.textContent).not.toMatch(word);
  });

  it.each([
    ['the demo', demoMe],
    ['an unverified account', unverifiedMe],
  ])('points at no control for %s, whose page shows no settings', async (_who, me) => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(me),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/demo/samples': jsonResponse(demoSamplesBody()),
    });
    visit('/alerts');
    render(<App />);
    await screen.findByText('No webhooks yet.');
    const intro = within(webhooksSection()).getByText(UNPOINTED);
    expect(intro.tagName).toBe('P');
    expect(screen.queryByRole('heading', { name: 'Where alerts go' })).toBeNull();
    expect(webhooksSection().textContent).not.toMatch(/\b(above|below)\b/);
    for (const word of FORBIDDEN_WORDS) expect(intro.textContent).not.toMatch(word);
  });
});
