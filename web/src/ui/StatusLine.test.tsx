// The shared loading line must print the DOM each of its ten call sites printed at 32e848a. The
// expected markup below is copied from that commit's sources and compared as HTML; the test never
// asserts a class on its own (R255), it only demands equality with the old copy. Each site is
// checked twice: the component with the site's props, and the page itself caught while loading.
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  cardWithPrices,
  clearXsrfCookie,
  fallingPrice,
  historyFor,
  jsonResponse,
  notificationsBody,
  pricedCardBody,
  risingPrice,
  signedInMe,
  webhooksBody,
} from '../test/fixtures';
import { MUTED } from './classes';
import StatusLine from './StatusLine';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.unstubAllGlobals();
  visit('/');
});

/**
 * Answers each `METHOD url` from `routes`; anything else stays in flight for the whole test, so
 * the page that asked for it keeps its loading line.
 */
function stubPending(routes: Readonly<Record<string, () => Response>>): string[] {
  const keys: string[] = [];
  vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
    const key = `${init?.method ?? 'GET'} ${String(input)}`;
    keys.push(key);
    const answer = routes[key];
    return answer === undefined
      ? new Promise<Response>(() => undefined)
      : Promise.resolve(answer());
  });
  return keys;
}

function htmlOf(node: ReactNode): string {
  const view = render(<>{node}</>);
  const html = view.container.innerHTML;
  view.unmount();
  return html;
}

const CARD = 'GET /v1/cards/ex10-%3F';
const HISTORY = 'GET /v1/cards/ex10-%3F/prices?range=1M';
const WEBHOOKS_LOADING = 'Loading your alerts and webhooks…';
const ID = 'ex10-?';
/** PriceHistory's reserve at 32e848a: one priced variant, or two and more. */
const ONE_PRICED = 'min-h-[374px] sm:min-h-[454px]';
const TWO_PRICED = 'min-h-[426px] sm:min-h-[506px]';

interface Site {
  readonly name: string;
  /** The line as the source printed it at 32e848a. */
  readonly old: ReactNode;
  /** The shared line with this site's props. */
  readonly shared: () => ReactNode;
  /** Opens the page and returns the loading line it shows. */
  readonly open: () => Promise<HTMLElement>;
}

/** The one status line on the page once `text` shows. */
async function onlyStatus(text: string): Promise<HTMLElement> {
  const line = await screen.findByText(text, { selector: '[role="status"]' });
  expect(screen.getAllByRole('status')).toEqual([line]);
  return line;
}

const SITES: readonly Site[] = [
  {
    name: 'pages/MoverList.tsx MoversLoading',
    old: (
      <p role="status" className="mt-4 text-sm text-ink-muted">
        Loading movers…
      </p>
    ),
    shared: () => <StatusLine gap="mt-4">Loading movers…</StatusLine>,
    open: async () => {
      stubPending({});
      visit('/movers');
      render(<App />);
      return onlyStatus('Loading movers…');
    },
  },
  {
    name: 'pages/CardPage.tsx loading card',
    old: (
      <p role="status" className="mt-6 text-sm text-ink-muted">
        {`Loading card ${ID}…`}
      </p>
    ),
    shared: () => <StatusLine gap="mt-6">{`Loading card ${ID}…`}</StatusLine>,
    open: async () => {
      stubPending({});
      visit('/card/ex10-%3F');
      render(<App />);
      return onlyStatus('Loading card ex10-?…');
    },
  },
  {
    name: 'pages/SearchPage.tsx searching',
    old: (
      <p role="status" className={`mt-6 text-sm ${MUTED}`}>
        Searching for “{'unown'}”…
      </p>
    ),
    shared: () => <StatusLine gap="mt-6">Searching for “{'unown'}”…</StatusLine>,
    open: async () => {
      stubPending({});
      visit('/search?q=unown');
      render(<App />);
      const line = await screen.findByRole('status');
      expect(line.textContent).toBe('Searching for “unown”…');
      expect(screen.getAllByRole('status')).toEqual([line]);
      return line;
    },
  },
  {
    name: 'pages/PriceHistory.tsx loading history, one priced variant',
    old: (
      <p role="status" className={`mt-3 text-sm ${MUTED} ${ONE_PRICED}`}>
        Loading price history…
      </p>
    ),
    shared: () => (
      <StatusLine gap="mt-3" reserve={ONE_PRICED}>
        Loading price history…
      </StatusLine>
    ),
    open: async () => {
      stubPending({ [CARD]: () => jsonResponse(pricedCardBody) });
      visit('/card/ex10-%3F');
      render(<App />);
      return onlyStatus('Loading price history…');
    },
  },
  {
    name: 'pages/PriceHistory.tsx loading history, two priced variants',
    old: (
      <p role="status" className={`mt-3 text-sm ${MUTED} ${TWO_PRICED}`}>
        Loading price history…
      </p>
    ),
    shared: () => (
      <StatusLine gap="mt-3" reserve={TWO_PRICED}>
        Loading price history…
      </StatusLine>
    ),
    open: async () => {
      stubPending({ [CARD]: () => jsonResponse(cardWithPrices(risingPrice, fallingPrice)) });
      visit('/card/ex10-%3F');
      render(<App />);
      return onlyStatus('Loading price history…');
    },
  },
  {
    name: 'account/ListParts.tsx ListLoading',
    old: (
      <p role="status" className={`mt-6 text-sm ${MUTED}`}>
        {'Loading your watchlist…'}
      </p>
    ),
    shared: () => <StatusLine gap="mt-6">{'Loading your watchlist…'}</StatusLine>,
    open: async () => {
      stubPending({});
      visit('/watchlist');
      render(<App />);
      return onlyStatus('Loading your watchlist…');
    },
  },
  {
    name: 'account/Form.tsx LoadingAccount',
    old: (
      <p role="status" className={`mt-6 text-sm ${MUTED}`}>
        Loading your account…
      </p>
    ),
    shared: () => <StatusLine gap="mt-6">Loading your account…</StatusLine>,
    open: async () => {
      stubPending({});
      visit('/account');
      render(<App />);
      return onlyStatus('Loading your account…');
    },
  },
  {
    name: 'account/AlertsPage.tsx Loading',
    old: (
      <p role="status" className={`mt-6 text-sm ${MUTED}`}>
        {WEBHOOKS_LOADING}
      </p>
    ),
    shared: () => <StatusLine gap="mt-6">{WEBHOOKS_LOADING}</StatusLine>,
    open: async () => {
      stubPending({});
      visit('/alerts');
      render(<App />);
      return onlyStatus(WEBHOOKS_LOADING);
    },
  },
  {
    name: 'account/Webhooks.tsx loading webhooks',
    old: (
      <p role="status" className={`mt-4 text-sm ${MUTED}`}>
        {WEBHOOKS_LOADING}
      </p>
    ),
    shared: () => <StatusLine gap="mt-4">{WEBHOOKS_LOADING}</StatusLine>,
    open: async () => {
      stubPending({
        'GET /v1/me': () => jsonResponse(signedInMe),
        'GET /v1/me/notifications': () => jsonResponse(notificationsBody()),
      });
      visit('/alerts');
      render(<App />);
      await screen.findByText('Send alerts and the digest by');
      return onlyStatus(WEBHOOKS_LOADING);
    },
  },
  {
    name: 'account/NotificationSettings.tsx loading settings',
    old: (
      <p role="status" className={`mt-4 text-sm ${MUTED}`}>
        {WEBHOOKS_LOADING}
      </p>
    ),
    shared: () => <StatusLine gap="mt-4">{WEBHOOKS_LOADING}</StatusLine>,
    open: async () => {
      stubPending({
        'GET /v1/me': () => jsonResponse(signedInMe),
        'GET /v1/webhooks': () => jsonResponse(webhooksBody([])),
      });
      visit('/alerts');
      render(<App />);
      await screen.findByText('No webhooks yet.');
      return onlyStatus(WEBHOOKS_LOADING);
    },
  },
  {
    name: 'account/CardActions.tsx loading the portfolio',
    old: (
      <p role="status" className="mt-2 text-sm text-ink-muted">
        Loading your portfolio…
      </p>
    ),
    shared: () => <StatusLine gap="mt-2">Loading your portfolio…</StatusLine>,
    open: async () => {
      stubPending({
        [CARD]: () => jsonResponse(pricedCardBody),
        [HISTORY]: () => jsonResponse(historyFor(pricedCardBody)),
      });
      visit('/card/ex10-%3F');
      render(<App />);
      const prices = await screen.findByRole('region', { name: 'Prices' });
      fireEvent.click(
        within(prices).getByRole('button', { name: 'Add holo, unlimited to your portfolio' }),
      );
      return onlyStatus('Loading your portfolio…');
    },
  },
];

describe('the shared loading line prints the 32e848a markup of every site', () => {
  it.each(SITES)('$name: the component with the site props is the old line', (site) => {
    expect(htmlOf(site.shared())).toBe(htmlOf(site.old));
  });

  it.each(SITES)('$name: the page prints the old line, once', async (site) => {
    const line = await site.open();
    expect(line.outerHTML).toBe(htmlOf(site.old));
  });
});
