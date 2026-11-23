import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FAKE_SIGNING_SECRET,
  clearXsrfCookie,
  degradedCardBody,
  degradedSearchPage,
  demoMe,
  demoSamplesBody,
  isHistoryUrl,
  jsonResponse,
  lightOnlyClasses,
  moversBody,
  notificationsBody,
  pricedCardBody,
  problemResponse,
  risingMover,
  signedInMe,
  stubAccountFetch,
  stubFetch,
  unverifiedMe,
  upstreamUnavailableProblem,
  watchlistBody,
  watchlistItems,
  webhookCreatedBody,
  webhookOf,
  webhooksBody,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake.
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const DEGRADED = 'Catalog data may be out of date: PokéSearch is unavailable';
const DEMO_NOTICE =
  'You are signed in to the read-only demo account. Its watchlist and portfolio cannot be changed.';
const DEMO_LINE =
  'The read-only demo cannot set alerts or add webhooks: it has no email address or Discord of ' +
  'its own to send them to.';
const UNVERIFIED_LINE = 'Verify your email address to set alerts or add webhooks.';
const SECRET_LINE =
  'Copy this secret before you leave this page. PokéPrices shows it once; to change it, delete ' +
  'the webhook and add it again.';
const REQUEST_ID = 'req-t46-upstream-0001';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.useRealTimers();
  visit('/');
});

/** Every skeleton bar under root: the only aria-hidden div the diff may add. */
function bars(root: ParentNode): Element[] {
  return Array.from(root.querySelectorAll('div[aria-hidden]'));
}

/** The element whose own text is exactly `text`, with the structure facts the migration keeps. */
function noticeOf(text: string): HTMLElement {
  const element = screen.getByText(text);
  expect(element.textContent).toBe(text);
  return element;
}

describe('migrated notices keep the role and element they had before T05 (review)', () => {
  it('search: the problem is one div role=alert with its request id in a code, the degraded line a role-less p beside the one status', async () => {
    stubFetch(() => problemResponse(upstreamUnavailableProblem));
    visit('/search?q=unown');
    const failed = render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.tagName).toBe('DIV');
    expect(screen.getAllByRole('alert')).toHaveLength(1);
    expect(alert.textContent).toBe(`Search failed: Upstream UnavailableRequest ID ${REQUEST_ID}`);
    expect(within(alert).getByText(REQUEST_ID).tagName).toBe('CODE');
    expect(screen.queryByRole('status')).toBeNull();
    failed.unmount();

    stubFetch(() => jsonResponse(degradedSearchPage));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const line = noticeOf(DEGRADED);
    expect(line.tagName).toBe('P');
    expect(line.hasAttribute('role')).toBe(false);
    expect(line.closest('[role]')).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(screen.getByRole('status')).not.toBe(line);
  });

  it('card failed: hostile title and RTL request id stay text inside one div role=alert', async () => {
    const hostile = '<img src=x onerror=alert(1)> & <b>bold</b>';
    const rtl = '‮req&amp;<id>‬';
    stubFetch(
      () =>
        new Response(
          JSON.stringify({ ...upstreamUnavailableProblem, title: hostile, request_id: rtl }),
          { status: 503, headers: { 'Content-Type': 'application/problem+json' } },
        ),
    );
    visit('/card/ex10-%3F');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.tagName).toBe('DIV');
    expect(alert.textContent).toBe(`Could not load this card: ${hostile}Request ID ${rtl}`);
    expect(alert.querySelector('img, b, script')).toBeNull();
    expect(alert.querySelectorAll('code')).toHaveLength(1);
    expect(alert.querySelector('code')?.textContent).toBe(rtl);
    expect(screen.getAllByRole('alert')).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Card ex10-?');
    expect(screen.queryByRole('status')).toBeNull();
    expect(bars(document.body)).toHaveLength(0);
  });

  it('card: skeletons exist only while loading, the degraded line is a role-less p, and no region doubles', async () => {
    stubFetch((url) =>
      isHistoryUrl(url) ? new Error('no history') : jsonResponse(degradedCardBody),
    );
    visit('/card/ex10-%3F');
    const { container } = render(<App />);
    expect(bars(container)).toHaveLength(2);
    expect(screen.getAllByRole('status')).toHaveLength(1);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    expect(bars(container)).toHaveLength(0);
    const line = noticeOf(DEGRADED);
    expect(line.tagName).toBe('P');
    expect(line.hasAttribute('role')).toBe(false);
    expect(line.closest('p, [role]')).toBe(line);
    expect(lightOnlyClasses(container)).toEqual([]);
  });

  it('card: a failing price history is one div role=alert with the request id in a code, and no status lingers', async () => {
    stubFetch((url) =>
      isHistoryUrl(url)
        ? problemResponse(upstreamUnavailableProblem)
        : jsonResponse(pricedCardBody),
    );
    visit('/card/ex10-%3F');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.tagName).toBe('DIV');
    expect(alert.textContent).toBe(
      `Could not load the price history: Upstream UnavailableRequest ID ${REQUEST_ID}`,
    );
    expect(within(alert).getByText(REQUEST_ID).tagName).toBe('CODE');
    expect(screen.getAllByRole('alert')).toHaveLength(1);
    expect(bars(document.body)).toHaveLength(0);
  });

  it('movers: loading is one status followed by one bar, a failure replaces both with one alert, never a second status', async () => {
    stubFetch(() => problemResponse(upstreamUnavailableProblem));
    visit('/movers');
    const { container } = render(<App />);
    const status = screen.getByRole('status');
    expect(status.textContent).toBe('Loading movers…');
    expect(bars(container)).toHaveLength(1);
    expect(status.nextElementSibling).toBe(bars(container)[0]);
    expect(status.contains(bars(container)[0] ?? null)).toBe(false);
    const alert = await screen.findByRole('alert');
    expect(alert.tagName).toBe('DIV');
    expect(alert.textContent).toBe(
      `Could not load the movers: Upstream UnavailableRequest ID ${REQUEST_ID}`,
    );
    expect(screen.getAllByRole('alert')).toHaveLength(1);
    expect(screen.queryByRole('status')).toBeNull();
    expect(bars(container)).toHaveLength(0);
  });

  it('home strip: every loading line has exactly one bar after it and no svg, img or button appears', () => {
    stubFetch(() => new Error('not answered before the assertions'));
    visit('/');
    const { container } = render(<App />);
    const statuses = screen.getAllByRole('status');
    expect(bars(container)).toHaveLength(statuses.length);
    for (const status of statuses) {
      expect(status.textContent).toBe('Loading movers…');
      expect(status.nextElementSibling?.getAttribute('aria-hidden')).toBe('true');
      expect(status.querySelector('[aria-hidden]')).toBeNull();
    }
    for (const bar of bars(container)) {
      expect(bar.textContent).toBe('');
      expect(bar.childElementCount).toBe(0);
    }
    expect(container.querySelector('svg')).toBeNull();
    const tiles = screen.getByRole('list', { name: 'Well-known cards' });
    expect(container.querySelectorAll('img')).toHaveLength(tiles.querySelectorAll('img').length);
  });

  it('movers: the degraded line is a role-less p above the board while the board has no alert', async () => {
    stubFetch(() => jsonResponse(moversBody('up', [risingMover], { degraded: true })));
    visit('/movers');
    render(<App />);
    const line = await screen.findByText(DEGRADED);
    expect(line.tagName).toBe('P');
    expect(line.hasAttribute('role')).toBe(false);
    expect(line.textContent).toBe(DEGRADED);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByRole('status')).toBeNull();
    expect(bars(document.body)).toHaveLength(0);
  });
});

describe('account notices keep their role, element and text (review)', () => {
  it('watchlist: loading is one status then one bar; the demo notice and degraded line are role-less p with exact text', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody(watchlistItems, { degraded: true })),
    });
    visit('/watchlist');
    const { container } = render(<App />);
    const status = screen.getByRole('status');
    expect(status.textContent).toBe('Loading your watchlist…');
    expect(status.nextElementSibling?.getAttribute('aria-hidden')).toBe('true');
    expect(bars(container)).toHaveLength(1);
    const demo = await screen.findByText(DEMO_NOTICE);
    expect(demo.tagName).toBe('P');
    expect(demo.hasAttribute('role')).toBe(false);
    expect(demo.textContent).toBe(DEMO_NOTICE);
    const degraded = await screen.findByText(DEGRADED);
    expect(degraded.textContent).toBe(DEGRADED);
    expect(degraded.tagName).toBe('P');
    expect(degraded.hasAttribute('role')).toBe(false);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByRole('status')).toBeNull();
    expect(bars(container)).toHaveLength(0);
    expect(lightOnlyClasses(container)).toEqual([]);
  });

  it('alerts: the demo line is a role-less p and the unverified notice a role-less div of two paragraphs, no region around either', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/demo/samples': jsonResponse(demoSamplesBody()),
    });
    visit('/alerts');
    const demo = render(<App />);
    const demoLine = await screen.findByText(DEMO_LINE);
    expect(demoLine.tagName).toBe('P');
    expect(demoLine.hasAttribute('role')).toBe(false);
    expect(demoLine.textContent).toBe(DEMO_LINE);
    await waitFor(() => {
      expect(screen.queryByRole('status')).toBeNull();
    });
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByRole('region', { name: 'Signing secret' })).toBeNull();
    expect(lightOnlyClasses(demo.container)).toEqual([]);
    demo.unmount();

    stubAccountFetch({
      'GET /v1/me': jsonResponse(unverifiedMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
    });
    const unverified = render(<App />);
    const line = await screen.findByText(UNVERIFIED_LINE);
    const block = line.parentElement;
    if (block === null) throw new Error('notice expected');
    expect(block.tagName).toBe('DIV');
    expect(block.hasAttribute('role')).toBe(false);
    expect(Array.from(block.children).map((child) => child.tagName)).toEqual(['P', 'P']);
    expect(block.textContent).toBe(`${UNVERIFIED_LINE}Go to your account`);
    expect(within(block).getByRole('link', { name: 'Go to your account' })).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(lightOnlyClasses(unverified.container)).toEqual([]);
  });

  it('webhooks: the signing-secret block is one named region holding h3, a role-less warn p, the selectable code and the button, in that order', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody()),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
      'POST /v1/webhooks': jsonResponse(webhookCreatedBody(webhookOf(), FAKE_SIGNING_SECRET), {
        status: 201,
      }),
    });
    visit('/alerts');
    const { container } = render(<App />);
    await screen.findByText('No webhooks yet.');
    fireEvent.change(screen.getByLabelText('URL'), {
      target: { value: 'https://hooks.example.com/pokeprices' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add webhook' }));
    await screen.findByText(SECRET_LINE);
    const region = screen.getByRole('region', { name: 'Signing secret' });
    expect(screen.getAllByRole('region', { name: 'Signing secret' })).toHaveLength(1);
    expect(region.tagName).toBe('DIV');
    expect(region.getAttributeNames().filter((name) => name !== 'class')).toEqual([
      'role',
      'aria-labelledby',
    ]);
    expect(Array.from(region.children).map((child) => child.tagName)).toEqual([
      'H3',
      'P',
      'CODE',
      'P',
    ]);
    const [heading, warn, code, action] = Array.from(region.children);
    expect(heading?.textContent).toBe('Signing secret');
    expect(heading?.id).toBe(region.getAttribute('aria-labelledby'));
    expect(warn?.textContent).toBe(SECRET_LINE);
    expect(warn?.hasAttribute('role')).toBe(false);
    expect(warn?.querySelector('*')).toBeNull();
    expect(code?.textContent).toBe(FAKE_SIGNING_SECRET);
    expect(action?.textContent).toBe('I have stored the secret');
    expect(screen.queryByRole('alert')).toBeNull();
    expect(lightOnlyClasses(container)).toEqual([]);
    // The secret is shown in the block once, and goes when the block is dismissed.
    expect(document.body.textContent.split(FAKE_SIGNING_SECRET)).toHaveLength(2);
    fireEvent.click(screen.getByRole('button', { name: 'I have stored the secret' }));
    await waitFor(() => {
      expect(screen.queryByRole('region', { name: 'Signing secret' })).toBeNull();
    });
    expect(document.body.textContent).not.toContain(FAKE_SIGNING_SECRET);
  });
});
