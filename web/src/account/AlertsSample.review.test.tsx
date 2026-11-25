// Review tests for the signed-out sample on /alerts: the signed payload is shown byte for byte and
// a reader can verify its signature by hand from what the page shows; a leftover cookie, a throttled
// or malformed public read and a partial sample must give a true view and never a write control;
// the signed-in demo keeps its own route.
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  DEMO_SAMPLE_SECRET,
  clearXsrfCookie,
  demoAlertsRoutes,
  demoMe,
  demoSamplesBody,
  jsonResponse,
  problemResponse,
  rawResponse,
  setXsrfCookie,
  stubAccountFetch,
  tooManyRequestsProblem,
  unauthenticatedProblem,
  webhooksBody,
  type AccountAnswer,
} from '../test/fixtures';

const FOCUSABLE = 'a[href], button, input, select, textarea, [tabindex]';
const SIGNED_OUT_SENTENCE = 'Sign in to set up alerts and webhooks.';

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

async function hmac(secret: string, message: string): Promise<string> {
  const raw = Uint8Array.from(atob(secret.slice('whsec_'.length)), (c) => c.charCodeAt(0));
  const key = await crypto.subtle.importKey('raw', raw, { name: 'HMAC', hash: 'SHA-256' }, false, [
    'sign',
  ]);
  const mac = new Uint8Array(
    await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(message)),
  );
  return `v1,${btoa(String.fromCharCode(...mac))}`;
}

// What a renderer could alter: markup characters, escaped and astral unicode, a line separator,
// tabs, runs of spaces, CRLF and a leading and trailing newline.
const AWKWARD_BODY =
  '\n{"note":"a <b>&amp; é \u{1F0CF}   end","t":"x\ty",  "s":"two  spaces\r\nline"}\n';

describe('the signed-out alerts sample is byte-exact and checkable by hand', () => {
  it('shows a body with markup, unicode, tabs and newlines exactly, and its signature verifies from the page', async () => {
    const id = 'msg_01K7DEM0WEBH00KSAMP1E00000';
    const timestamp = '1791835544';
    const base = demoSamplesBody();
    const webhook = base.data.webhook;
    if (webhook === null) throw new Error('fixture has no webhook');
    const signature = await hmac(DEMO_SAMPLE_SECRET, `${id}.${timestamp}.${AWKWARD_BODY}`);
    const body = demoSamplesBody({
      webhook: {
        ...webhook,
        headers: {
          'webhook-id': id,
          'webhook-timestamp': timestamp,
          'webhook-signature': signature,
        },
        body: AWKWARD_BODY,
      },
    });
    open(demoAlertsRoutes(body));
    await screen.findByRole('region', { name: 'Sample data' });
    const payload = main().querySelector('[data-sample-payload]');
    expect(payload?.textContent).toBe(AWKWARD_BODY);
    // Recompute from the three headers, the body and the secret exactly as the page renders them.
    const headerText =
      within(main()).getByRole('heading', { level: 3, name: 'Headers' }).nextElementSibling
        ?.textContent ?? '';
    const shown = Object.fromEntries(
      headerText.split('\n').map((line) => {
        const at = line.indexOf(': ');
        return [line.slice(0, at), line.slice(at + 2)];
      }),
    ) as Record<string, string>;
    const secret = within(main()).getByText(DEMO_SAMPLE_SECRET).textContent;
    expect(secret).toBe(DEMO_SAMPLE_SECRET);
    const message = `${shown['webhook-id'] ?? ''}.${shown['webhook-timestamp'] ?? ''}.${payload?.textContent ?? ''}`;
    expect(await hmac(secret, message)).toBe(shown['webhook-signature']);
  });
});

describe('the signed-out alerts sample when the session or the read misbehaves', () => {
  it('a leftover cookie whose session answers 401 asks the session once, the sample once, nothing of the account', async () => {
    setXsrfCookie();
    const calls = open({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      ...demoAlertsRoutes(),
    });
    await screen.findByRole('region', { name: 'Sample data' });
    expect(calls.map((call) => call.key).sort()).toEqual(['GET /v1/demo/alerts', 'GET /v1/me']);
    expect(main().querySelector('form, input, select, textarea')).toBeNull();
  });

  it('a throttled public read (429) gives the earlier signed-out view with no strip and no error block', async () => {
    const calls = open({
      'GET /v1/demo/alerts': problemResponse(tooManyRequestsProblem('/v1/demo/alerts')),
    });
    expect(await screen.findByText(SIGNED_OUT_SENTENCE)).toBeTruthy();
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/demo/alerts']);
  });

  it('a malformed 200 body and an empty-object body give the earlier signed-out view, no crash', async () => {
    open({ 'GET /v1/demo/alerts': rawResponse(200, '{"data":{"alert":{"card_id":1}}') });
    expect(await screen.findByText(SIGNED_OUT_SENTENCE)).toBeTruthy();
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    cleanup();
    open({ 'GET /v1/demo/alerts': jsonResponse({ data: {} }) });
    expect(await screen.findByText(SIGNED_OUT_SENTENCE)).toBeTruthy();
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
  });

  it('a sample with no alert still shows the strip and the other two samples, and one with only the alert shows just that', async () => {
    open(demoAlertsRoutes(demoSamplesBody({ alert: null })));
    await screen.findByRole('region', { name: 'Sample data' });
    expect(within(main()).queryByRole('heading', { level: 2, name: 'Sample alert' })).toBeNull();
    expect(
      within(main()).getByRole('heading', { level: 2, name: 'Sample alert email' }),
    ).toBeTruthy();
    expect(
      within(main()).getByRole('heading', { level: 2, name: 'Sample signed webhook delivery' }),
    ).toBeTruthy();
    cleanup();
    open(demoAlertsRoutes(demoSamplesBody({ message: null, webhook: null })));
    await screen.findByRole('region', { name: 'Sample data' });
    expect(within(main()).getByRole('heading', { level: 2, name: 'Sample alert' })).toBeTruthy();
    expect(main().textContent).not.toContain('No sample messages');
    expect(within(main()).queryByRole('heading', { level: 3, name: 'Headers' })).toBeNull();
  });
});

describe('the signed-out alerts sample reading order and the demo', () => {
  it('the only focusable things are the strip then the way in; the payload block wraps instead of scrolling the page', async () => {
    open(demoAlertsRoutes());
    await screen.findByRole('region', { name: 'Sample data' });
    const order = Array.from(main().querySelectorAll<HTMLElement>(FOCUSABLE)).map(
      (node) => node.textContent,
    );
    expect(order).toEqual(['Sign in', 'Try the read-only demo']);
    const payload = main().querySelector('[data-sample-payload]');
    expect(payload?.className).toContain('whitespace-pre-wrap');
    expect(payload?.className).toContain('break-all');
    expect(payload?.className).not.toMatch(/overflow-|whitespace-pre(?!-)/);
  });

  it('the signed-in demo reads its own samples route, never the public one, and sees the same sections without the strip', async () => {
    const calls = open({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/demo/samples': jsonResponse(demoSamplesBody()),
    });
    await screen.findByRole('heading', { level: 2, name: 'Sample signed webhook delivery' });
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/me',
      'GET /v1/webhooks',
      'GET /v1/demo/samples',
    ]);
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(main().querySelector('.min-h-svh')).toBeNull();
  });
});
