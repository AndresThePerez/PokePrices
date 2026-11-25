// The signed-out /alerts shows the demo's alert, its sample delivered message and its sample signed
// payload as labelled, read-only samples under the shared strip (SampleStrip), read from the public
// demo route with no cookie and no session; a failed read gives the signed-out view from before.
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  DEMO_SAMPLE_BODY,
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  demoAlertsRoutes,
  demoAlertsUnavailableRoutes,
  demoMe,
  demoSamplesBody,
  jsonResponse,
  problemResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  webhooksBody,
  type AccountAnswer,
} from '../test/fixtures';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

function open(routes: Readonly<Record<string, AccountAnswer>>) {
  const calls = stubAccountFetch(routes);
  visit('/alerts');
  render(<App />);
  return calls;
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

function keys(calls: readonly { readonly key: string }[]): string[] {
  return calls.map((call) => call.key);
}

const SIGNED_OUT_SENTENCE = 'Sign in to set up alerts and webhooks.';

describe('the signed-out alerts page (sample)', () => {
  it('asks only for the public alert sample and shows the three samples under the strip', async () => {
    const calls = open(demoAlertsRoutes());
    const strip = await screen.findByRole('region', { name: 'Sample data' });
    expect(strip.textContent).toContain(
      'This is sample data from the read-only demo collection, the same for every visitor. Signing in gives you your own alerts.',
    );
    expect(within(main()).getByRole('heading', { level: 2, name: 'Sample alert' })).toBeTruthy();
    expect(within(main()).getByText('Chimecho · reverse')).toBeTruthy();
    expect(within(main()).getByText(/An alert at 10%/)).toBeTruthy();
    expect(
      within(main()).getByRole('heading', { level: 2, name: 'Sample alert email' }),
    ).toBeTruthy();
    expect(
      within(main()).getByRole('heading', { level: 2, name: 'Sample signed webhook delivery' }),
    ).toBeTruthy();
    const position = (name: string) =>
      within(main()).getByRole('heading', { level: 2, name }).compareDocumentPosition(strip);
    expect(position('Sample alert') & Node.DOCUMENT_POSITION_PRECEDING).toBeTruthy();
    expect(keys(calls)).toContain('GET /v1/demo/alerts');
    for (const key of keys(calls)) {
      expect(key).not.toMatch(/\/v1\/(me|alerts|webhooks|demo\/samples|demo\/session)/);
      expect(key).not.toMatch(/csrf|token/);
    }
    expect(document.cookie).toBe('');
  });

  it('says in words that nothing was sent, and uses no forbidden word', async () => {
    open(demoAlertsRoutes());
    await screen.findByRole('region', { name: 'Sample data' });
    expect(main().textContent).toContain('nothing is ever sent from the demo');
    // The exact payload and message are data the API sent (their times are in them): check the words
    // the page itself wrote.
    const own = main().cloneNode(true) as HTMLElement;
    own.querySelectorAll('pre, code').forEach((block) => {
      block.remove();
    });
    for (const word of FORBIDDEN_WORDS) expect(own.textContent).not.toMatch(word);
  });

  it('shows the signed payload byte for byte in a selectable block', async () => {
    const body = demoSamplesBody();
    open(demoAlertsRoutes(body));
    await screen.findByRole('region', { name: 'Sample data' });
    const payload = main().querySelector('[data-sample-payload]');
    expect(payload?.tagName).toBe('PRE');
    expect(payload?.textContent).toBe(DEMO_SAMPLE_BODY);
    expect(payload?.textContent).toBe(body.data.webhook?.body);
    const headers = within(main()).getByRole('heading', { level: 3, name: 'Headers' });
    expect(headers.nextElementSibling?.textContent).toContain(
      `webhook-signature: ${body.data.webhook?.headers['webhook-signature'] ?? ''}`,
    );
    expect(within(main()).getByText(body.data.sample_secret)).toBeTruthy();
  });

  it('shows no button, input, select or form that writes', async () => {
    open(demoAlertsRoutes());
    await screen.findByRole('region', { name: 'Sample data' });
    const region = main();
    expect(within(region).queryAllByRole('combobox')).toHaveLength(0);
    expect(within(region).queryAllByRole('textbox')).toHaveLength(0);
    expect(within(region).queryAllByRole('checkbox')).toHaveLength(0);
    expect(within(region).queryAllByRole('radio')).toHaveLength(0);
    expect(region.querySelector('form, input, select, textarea')).toBeNull();
    expect(
      within(region)
        .getAllByRole('button')
        .map((button) => button.textContent),
    ).toEqual(['Try the read-only demo']);
    expect(region.textContent).not.toMatch(/Add webhook|Send test|Delete|Save/i);
    expect(
      within(region)
        .getAllByRole('link')
        .map((link) => link.getAttribute('href')),
    ).toEqual(['/sign-in?next=/alerts']);
  });

  it('shows the earlier signed-out view, no strip and no error, when the read answers 404', async () => {
    const calls = open(demoAlertsUnavailableRoutes());
    expect(await screen.findByText(SIGNED_OUT_SENTENCE)).toBeTruthy();
    expect(keys(calls)).toEqual(['GET /v1/demo/alerts']);
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(
      within(main())
        .getAllByRole('link')
        .map((link) => link.getAttribute('href')),
    ).toEqual(['/sign-in?next=/alerts', '/sign-up']);
  });

  it('shows the earlier signed-out view when the read fails', async () => {
    open({ 'GET /v1/demo/alerts': problemResponse(serverErrorProblem('/v1/demo/alerts')) });
    expect(await screen.findByText(SIGNED_OUT_SENTENCE)).toBeTruthy();
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('keeps one reserved wrapper node through loading, the sample and the earlier view', async () => {
    open(demoAlertsRoutes());
    const reserved = () => {
      const found = main().querySelector<HTMLElement>('.min-h-svh');
      if (found === null) throw new Error('no reserved wrapper');
      return found;
    };
    const first = reserved();
    expect(within(first).getByRole('status')).toBeTruthy();
    await screen.findByRole('region', { name: 'Sample data' });
    expect(reserved()).toBe(first);
    expect(within(first).getByRole('region', { name: 'Sample data' })).toBeTruthy();
    cleanup();
    open(demoAlertsUnavailableRoutes());
    const second = reserved();
    await screen.findByText(SIGNED_OUT_SENTENCE);
    expect(reserved()).toBe(second);
    expect(within(second).getByText(SIGNED_OUT_SENTENCE)).toBeTruthy();
  });
});

describe('signed in, the alerts page is as before', () => {
  it('an account keeps its requests and no strip, no reserved wrapper', async () => {
    const calls = open({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse({
        data: { channel: 'email', digest: false, discord_available: false },
      }),
    });
    expect(await screen.findByText('No webhooks yet.')).toBeTruthy();
    expect(keys(calls)).not.toContain('GET /v1/demo/alerts');
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(main().querySelector('.min-h-svh')).toBeNull();
  });

  it('the demo keeps its own samples route and no strip, no reserved wrapper', async () => {
    const calls = open({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/demo/samples': jsonResponse(demoSamplesBody()),
    });
    expect(await screen.findByRole('heading', { level: 2, name: 'Sample alert' })).toBeTruthy();
    expect(keys(calls)).not.toContain('GET /v1/demo/alerts');
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(main().querySelector('.min-h-svh')).toBeNull();
  });

  it('a leftover cookie whose session answers 401 still gets the sample', async () => {
    const calls = open({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      ...demoAlertsRoutes(),
    });
    expect(await screen.findByRole('region', { name: 'Sample data' })).toBeTruthy();
    expect(keys(calls)).toContain('GET /v1/demo/alerts');
  });
});
