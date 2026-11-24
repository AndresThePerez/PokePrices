// Q1-i5-T05 review: the shared problem block, degraded line and page shell must render the DOM the
// pages printed at f93f7e1. The expected markup below is copied from that commit's sources (the
// hand-written Notice blocks, the old ProblemBlock and the old main shells) and compared as HTML;
// the test never asserts a class on its own (R255), it only demands equality with the old copy.
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { ApiFailure } from '../api/client';
import { AccountMain, ProblemBlock } from '../account/Form';
import {
  accountValidationProblem,
  alreadySignedInProblem,
  clearXsrfCookie,
  degradedCardBody,
  degradedSearchPage,
  isHistoryUrl,
  jsonResponse,
  moversBody,
  pricedCardBody,
  problemResponse,
  risingMover,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  stubFetch,
  tooManyRequestsProblem,
  upstreamUnavailableProblem,
  watchlistBody,
  watchlistItems,
} from '../test/fixtures';
import { detailLine, initialOf, variantLabel } from '../format';
import Notice from './Notice';
import Panel from './Panel';

// jsdom has no canvas: the chart library is replaced by its recording fake.
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const DEGRADED = 'Catalog data may be out of date: PokéSearch is unavailable';
const REQUEST_ID = 'req-t46-upstream-0001';
const UNREACHABLE = 'The PokéPrices API could not be reached';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.useRealTimers();
  visit('/');
});

/** The old public block (f93f7e1): the headline is two text nodes, the request id a code. */
function OldPublicProblem({
  context,
  title,
  requestId,
  className,
}: {
  readonly context: string;
  readonly title: string;
  readonly requestId: string | null;
  readonly className: string;
}) {
  return (
    <Notice tone="problem" role="alert" className={className}>
      <p className="font-semibold">
        {`${context}: `}
        {title}
      </p>
      {requestId !== null && (
        <p className="mt-1 text-sm">
          Request ID <code className="break-all">{requestId}</code>
        </p>
      )}
    </Notice>
  );
}

function htmlOf(node: ReactNodeLike): string {
  const view = render(node);
  const html = view.container.innerHTML;
  view.unmount();
  return html;
}
type ReactNodeLike = Parameters<typeof render>[0];

interface PublicPage {
  readonly name: string;
  readonly path: string;
  readonly context: string;
  readonly className: string;
  /** What the stub answers for a failing endpoint of this page. */
  readonly answer: (failing: () => Response | Error) => (url: string) => Response | Error;
}

const PAGES: readonly PublicPage[] = [
  {
    name: 'search',
    path: '/search?q=unown',
    context: 'Search failed',
    className: 'mt-6',
    answer: (failing) => () => failing(),
  },
  {
    name: 'card',
    path: '/card/ex10-%3F',
    context: 'Could not load this card',
    className: 'mt-6',
    answer: (failing) => () => failing(),
  },
  {
    name: 'price history',
    path: '/card/ex10-%3F',
    context: 'Could not load the price history',
    className: 'mt-3',
    answer: (failing) => (url) => (isHistoryUrl(url) ? failing() : jsonResponse(pricedCardBody)),
  },
  {
    name: 'movers',
    path: '/movers',
    context: 'Could not load the movers',
    className: 'mt-4',
    answer: (failing) => () => failing(),
  },
];

describe('public problem blocks print the DOM they printed at f93f7e1 (T05 review)', () => {
  it.each(PAGES)(
    '$name: a failure with a request id is the old block, one alert, headline in two text nodes',
    async (page) => {
      stubFetch(page.answer(() => problemResponse(upstreamUnavailableProblem)));
      visit(page.path);
      render(<App />);
      const alert = await screen.findByRole('alert');
      expect(screen.getAllByRole('alert')).toHaveLength(1);
      expect(alert.outerHTML).toBe(
        htmlOf(
          <OldPublicProblem
            context={page.context}
            title="Upstream Unavailable"
            requestId={REQUEST_ID}
            className={page.className}
          />,
        ),
      );
      const headline = alert.firstElementChild;
      expect(headline?.tagName).toBe('P');
      expect(headline?.childNodes).toHaveLength(2);
      expect(alert.childElementCount).toBe(2);
      expect(alert.querySelectorAll('p')).toHaveLength(2);
      expect(alert.querySelectorAll('[role], [aria-live], h1, h2, h3, h4')).toHaveLength(0);
    },
  );

  it.each(PAGES)(
    '$name: a network failure has no request id line and no code, otherwise the old block',
    async (page) => {
      stubFetch(page.answer(() => new TypeError('network down')));
      visit(page.path);
      render(<App />);
      const alert = await screen.findByRole('alert');
      expect(alert.outerHTML).toBe(
        htmlOf(
          <OldPublicProblem
            context={page.context}
            title={UNREACHABLE}
            requestId={null}
            className={page.className}
          />,
        ),
      );
      expect(alert.textContent).toBe(`${page.context}: ${UNREACHABLE}`);
      expect(alert.textContent).not.toContain('Request ID');
      expect(alert.querySelector('code')).toBeNull();
    },
  );

  it.each(PAGES)(
    '$name: a 429 gets no "wait a minute" line (HG-45 #3): the title only, no request id below 500',
    async (page) => {
      const throttled = tooManyRequestsProblem('/v1/x');
      stubFetch(page.answer(() => problemResponse(throttled)));
      visit(page.path);
      render(<App />);
      const alert = await screen.findByRole('alert');
      expect(alert.textContent).toBe(`${page.context}: Too Many Requests`);
      expect(alert.textContent).not.toMatch(/wait a minute/i);
      expect(alert.textContent).not.toMatch(/try again/i);
      expect(alert.querySelectorAll('p')).toHaveLength(1);
    },
  );
});

/**
 * The old account block (f93f7e1), whole: its headline is ONE text node, fields before the id.
 * One line differs from f93f7e1: the request id is printed for a server error (5xx) only.
 */
function OldProblemBlock({
  context,
  failure,
  fields = [],
}: {
  readonly context: string;
  readonly failure: ApiFailure;
  readonly fields?: readonly string[];
}) {
  const first =
    failure.status === 422
      ? `${context}: check the fields below.`
      : failure.status === 429
        ? `${context}: Too Many Requests. Wait a minute and try again.`
        : `${context}: ${failure.title}`;
  const errors = failure.status === 422 ? (failure.problem?.errors ?? {}) : {};
  const unshown = Object.entries(errors)
    .filter(([field]) => !fields.includes(field))
    .map(([field, messages]) => [field, messages[0]] as const)
    .filter((entry): entry is readonly [string, string] => entry[1] !== undefined);
  return (
    <Notice tone="problem" role="alert" className="mt-6">
      <p className="font-semibold">{first}</p>
      {unshown.length > 0 && (
        <ul className="mt-1 list-disc pl-5 text-sm">
          {unshown.map(([field, message]) => (
            <li key={field}>{message}</li>
          ))}
        </ul>
      )}
      {failure.requestId !== null && failure.status >= 500 && (
        <p className="mt-1 text-sm">
          Request ID <code className="break-all">{failure.requestId}</code>
        </p>
      )}
    </Notice>
  );
}

function failureOf(
  problem: NonNullable<ApiFailure['problem']>,
  over: Partial<ApiFailure> = {},
): ApiFailure {
  return {
    ok: false,
    status: problem.status,
    type: problem.type,
    title: problem.title,
    requestId: problem.request_id,
    problem,
    ...over,
  };
}

describe('the account ProblemBlock keeps its 409, 422 and 429 branches (T05 review)', () => {
  const validation = accountValidationProblem('/register', {
    email: ['The email field must be a valid email address.'],
    password: ['The password field must be at least 12 characters.'],
    nickname: ['The nickname is taken.'],
  });
  const cases: ReadonlyArray<readonly [string, ApiFailure, readonly string[]]> = [
    ['422 with fields shown by the form', failureOf(validation), ['email', 'password', 'nickname']],
    ['422 listing the fields the form does not show', failureOf(validation), ['email']],
    ['422 with no problem body', failureOf(validation, { problem: null }), []],
    ['429 with a request id', failureOf(tooManyRequestsProblem('/login')), []],
    [
      '429 without a request id',
      failureOf(tooManyRequestsProblem('/login'), { requestId: null }),
      [],
    ],
    ['500', failureOf(serverErrorProblem('/user')), []],
    ['500 without a request id', failureOf(serverErrorProblem('/user'), { requestId: null }), []],
    [
      '409 from another route is a plain problem, not "already signed in"',
      failureOf(alreadySignedInProblem, { type: 'https://pokeprices.example/problems/http-409' }),
      [],
    ],
  ];

  it('prints the old block for every branch, keeps its headline one text node, and keeps the 409 block', () => {
    for (const [name, failure, fields] of cases) {
      const view = render(
        <ProblemBlock context="Could not do it" failure={failure} fields={fields} />,
      );
      const html = view.container.innerHTML;
      view.unmount();
      expect(html, name).toBe(
        htmlOf(<OldProblemBlock context="Could not do it" failure={failure} fields={fields} />),
      );
    }
    const throttled = render(
      <ProblemBlock
        context="Could not sign in"
        failure={failureOf(tooManyRequestsProblem('/login'))}
      />,
    );
    const alert = screen.getByRole('alert');
    expect(alert.firstElementChild?.childNodes).toHaveLength(1);
    expect(alert.textContent).toBe(
      'Could not sign in: Too Many Requests. Wait a minute and try again.',
    );
    throttled.unmount();
    const invalid = render(
      <ProblemBlock
        context="Could not sign in"
        failure={failureOf(accountValidationProblem('/login', { email: ['Bad.'] }))}
      />,
    );
    expect(screen.getByRole('alert').firstElementChild?.childNodes).toHaveLength(1);
    expect(screen.getByRole('alert').textContent).toBe(
      'Could not sign in: check the fields below.Bad.',
    );
    invalid.unmount();
    render(
      <ProblemBlock context="Could not sign in" failure={failureOf(alreadySignedInProblem)} />,
    );
    const signedIn = screen.getByRole('alert');
    expect(screen.getAllByRole('alert')).toHaveLength(1);
    expect(signedIn.textContent).toBe('You are already signed in.Go to your account');
    expect(
      within(signedIn).getByRole('link', { name: 'Go to your account' }).getAttribute('href'),
    ).toBe('/account');
    expect(signedIn.querySelector('code')).toBeNull();
  });
});

describe('the degraded line is the same role-less paragraph in all four places (T05 review)', () => {
  const OLD_DEGRADED = (className: string) => (
    <Notice tone="warn" as="p" className={className}>
      {DEGRADED}
    </Notice>
  );

  it('search and card: the old paragraph in its old place, no role, no alert', async () => {
    stubFetch(() => jsonResponse(degradedSearchPage));
    visit('/search?q=unown');
    const search = render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const line = screen.getByText(DEGRADED);
    expect(line.outerHTML).toBe(htmlOf(OLD_DEGRADED('mt-4')));
    expect(line.nextElementSibling).toBe(screen.getByRole('status'));
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.getAllByText(DEGRADED)).toHaveLength(1);
    search.unmount();

    // card: the old paragraph is the first thing in main, before the article
    stubFetch((url) =>
      isHistoryUrl(url) ? new Error('no history') : jsonResponse(degradedCardBody),
    );
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    const cardLine = screen.getByText(DEGRADED);
    expect(cardLine.outerHTML).toBe(htmlOf(OLD_DEGRADED('mb-6')));
    expect(screen.getByRole('main').firstElementChild).toBe(cardLine);
    expect(cardLine.nextElementSibling?.tagName).toBe('ARTICLE');
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('movers and watchlist: the old paragraph in its old place, the home strip prints it too', async () => {
    stubFetch(() => jsonResponse(moversBody('up', [risingMover], { degraded: true })));
    visit('/movers');
    const board = render(<App />);
    const line = await screen.findByText(DEGRADED);
    expect(line.outerHTML).toBe(htmlOf(OLD_DEGRADED('mt-4')));
    expect(line.previousElementSibling?.tagName).toBe('H1');
    board.unmount();
    stubFetch(() => jsonResponse(moversBody('up', [risingMover], { degraded: true })));
    visit('/');
    render(<App />);
    const lines = await screen.findAllByText(DEGRADED);
    expect(lines.length).toBeGreaterThan(0);
    for (const each of lines) {
      expect(each.tagName).toBe('P');
      expect(each.hasAttribute('role')).toBe(false);
      expect(each.outerHTML).toBe(htmlOf(OLD_DEGRADED('mt-4')));
    }
    cleanup();
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody(watchlistItems, { degraded: true })),
    });
    visit('/watchlist');
    render(<App />);
    const listLine = await screen.findByText(DEGRADED);
    expect(listLine.outerHTML).toBe(htmlOf(OLD_DEGRADED('mt-6')));
    expect(listLine.parentElement).toBe(screen.getByRole('main'));
    expect(screen.queryByRole('alert')).toBeNull();
  });
});

describe('Page: one main per route and the shell it replaces (T05 review)', () => {
  const OLD_MAIN = 'mx-auto max-w-3xl p-6';

  it('AccountMain keeps its three shapes: form and wide are h1 + sized div, auth is a panel plus after', () => {
    const AUTH_PANEL = 'mx-auto w-full max-w-[420px] max-sm:px-4 sm:py-6';
    for (const width of ['form', 'wide'] as const) {
      const view = render(
        <AccountMain title="T" width={width}>
          <p>child</p>
        </AccountMain>,
      );
      const expected = htmlOf(
        <main className={OLD_MAIN}>
          <h1 className="text-2xl font-semibold">T</h1>
          <div className={width === 'wide' ? 'max-w-xl' : 'max-w-md'}>
            <p>child</p>
          </div>
        </main>,
      );
      expect(view.container.innerHTML).toBe(expected);
      view.unmount();
    }
    const auth = render(
      <AccountMain title="T" width="auth" after={<p>after</p>}>
        <p>child</p>
      </AccountMain>,
    );
    expect(auth.container.innerHTML).toBe(
      htmlOf(
        <main className={OLD_MAIN}>
          <Panel className={AUTH_PANEL}>
            <h1 className="text-2xl font-semibold">T</h1>
            <p>child</p>
          </Panel>
          <p>after</p>
        </main>,
      ),
    );
  });

  const ROUTES: ReadonlyArray<readonly [string, string]> = [
    ['home', '/'],
    ['search', '/search?q=unown'],
    ['search without a query', '/search'],
    ['card', '/card/ex10-%3F'],
    ['movers', '/movers'],
    ['watchlist', '/watchlist'],
    ['portfolio', '/portfolio'],
    ['alerts', '/alerts'],
    ['account', '/account'],
    ['sign-in', '/sign-in'],
    ['sign-up', '/sign-up'],
    ['not-found', '/no-such-page'],
  ];

  it.each(ROUTES)(
    '%s: exactly one main, a direct child of #content, loading and again once it failed',
    async (_name, path) => {
      stubAccountFetch({});
      visit(path);
      render(<App />);
      const content = document.getElementById('content');
      expect(document.querySelectorAll('main')).toHaveLength(1);
      expect(content?.querySelectorAll(':scope > main')).toHaveLength(1);
      expect(screen.getAllByRole('main')).toHaveLength(1);
      await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 30));
      });
      expect(document.querySelectorAll('main')).toHaveLength(1);
      expect(content?.querySelectorAll(':scope > main')).toHaveLength(1);
      expect(screen.getByRole('main').querySelectorAll('main')).toHaveLength(0);
    },
  );

  it('the one main node survives loading to loaded on the card and loading to failed on movers (no remount)', async () => {
    stubFetch((url) =>
      isHistoryUrl(url) ? new Error('no history') : jsonResponse(pricedCardBody),
    );
    visit('/card/ex10-%3F');
    render(<App />);
    const loadingMain = screen.getByRole('main');
    expect(screen.getByRole('status')).toBeTruthy();
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    expect(screen.getByRole('main')).toBe(loadingMain);
    expect(document.querySelectorAll('main')).toHaveLength(1);
    cleanup();

    // movers: the main node survives loading to failed
    stubFetch(() => problemResponse(upstreamUnavailableProblem));
    visit('/movers');
    render(<App />);
    const moversMain = screen.getByRole('main');
    await screen.findByRole('alert');
    expect(screen.getByRole('main')).toBe(moversMain);
    expect(document.querySelectorAll('main')).toHaveLength(1);
    await waitFor(() => {
      expect(screen.queryByRole('status')).toBeNull();
    });
  });
});

describe('detailLine and initialOf equal the copies they replaced (T05 review)', () => {
  // The two removed detailLine bodies (MoverList.tsx and account/lists.ts at f93f7e1) and the two
  // removed initialOf bodies (CardPage.tsx and account/ListParts.tsx at f93f7e1).
  function oldMoverDetail(row: Parameters<typeof detailLine>[0]): string {
    const label = variantLabel(row);
    const variant = label.charAt(0).toUpperCase() + label.slice(1);
    if (row.card === null) return variant;
    return `${row.card.set_name} No. ${row.card.number} · ${variant}`;
  }
  function oldInitial(name: string): string {
    const code = name.trim().codePointAt(0);
    return code === undefined ? '' : String.fromCodePoint(code).toUpperCase();
  }

  it('matches for every naming shape crossed with a null, empty, unicode and ordinary card, and initialOf matches too', () => {
    const namings = [
      { variant_key: 'k:1', finish: '', edition: '', treatment: '' },
      { variant_key: 'k:2', finish: '-', edition: '-', treatment: '-' },
      { variant_key: 'k:3', finish: 'holo', edition: '', treatment: '' },
      { variant_key: 'k:4', finish: '', edition: '1st', treatment: 'error' },
      { variant_key: 'k:5', finish: 'ß', edition: '-', treatment: 'ǆ' },
      { variant_key: 'k:6', finish: '😀 holo', edition: '', treatment: '-' },
      { variant_key: '', finish: '', edition: '', treatment: '' },
      { variant_key: 'ñ', finish: '-', edition: '', treatment: '' },
    ];
    const cards = [
      null,
      { set_name: 'Base', number: '4/102' },
      { set_name: '', number: '' },
      { set_name: 'Hidden Legends', number: '017' },
      { set_name: '‮Set', number: '<b>1</b>' },
    ];
    for (const naming of namings) {
      for (const card of cards) {
        const row = { ...naming, card };
        expect(detailLine(row)).toBe(oldMoverDetail(row));
      }
    }

    // initialOf: blank, padded, astral, combining and non-letter names
    for (const name of [
      '',
      '   ',
      '\t\n',
      ' charizard',
      'ñandu',
      'ǆ',
      '😀 x',
      'é',
      '1st',
      '-',
      'ß',
    ]) {
      expect(initialOf(name)).toBe(oldInitial(name));
    }
  });
});
