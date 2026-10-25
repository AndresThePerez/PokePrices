// D2-i3-T02 review: the loading-only reservation (one viewport on the card, the movers board and
// the home strip; a sized price-history status line) belongs to the loading frames only. Every
// other state keeps the markup it had: these tests compare states with each other and never name
// a class (the loaded `main` must carry the same class attribute as the failed and not-found
// ones, and the reservation is only ever added to it).
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import App from '../App';
import {
  answerMovers,
  cardBody,
  cardNotFoundProblem,
  cardWithPrices,
  fallingMover,
  fallingPrice,
  historyFor,
  isHistoryUrl,
  isMoversUrl,
  jsonResponse,
  moversBody,
  problemResponse,
  risingMover,
  risingPrice,
  stubFetch,
  upstreamUnavailableProblem,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const UP_URL = '/v1/movers?direction=up&limit=50';
const DOWN_URL = '/v1/movers?direction=down&limit=50';
const CARD_URL = '/v1/cards/ex10-%3F';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  visit('/');
});

/** A fetch whose answers wait for the test: `release(url, response)` settles every call to `url`. */
function held(): {
  readonly urls: string[];
  readonly release: (url: string, response: Response) => void;
  readonly auto: (url: string, response: () => Response) => void;
} {
  const urls: string[] = [];
  const waiting = new Map<string, ((response: Response) => void)[]>();
  const immediate = new Map<string, () => Response>();
  vi.stubGlobal('fetch', (input: string | URL): Promise<Response> => {
    const url = String(input);
    urls.push(url);
    const now = immediate.get(url);
    if (now !== undefined) return Promise.resolve(now());
    return new Promise<Response>((resolve) => {
      waiting.set(url, [...(waiting.get(url) ?? []), resolve]);
    });
  });
  onTestFinished(() => {
    vi.unstubAllGlobals();
  });
  return {
    urls,
    release: (url, response) => {
      for (const resolve of waiting.get(url) ?? []) resolve(response.clone());
      waiting.delete(url);
    },
    auto: (url, response) => {
      immediate.set(url, response);
    },
  };
}

function mainClass(): string {
  return screen.getByRole('main').className;
}

function tokens(value: string): string[] {
  return value.split(/\s+/).filter((token) => token !== '');
}

describe('card page: the reservation is only in the loading frame (D2-i3 T02)', () => {
  it('releases the reservation on load and gives the loaded, not-found and failed states one and the same main', async () => {
    const wire = held();
    visit('/card/ex10-%3F');
    render(<App />);
    const loading = mainClass();
    wire.release(CARD_URL, jsonResponse(cardBody));
    await screen.findByRole('region', { name: 'Sources' });
    const loaded = mainClass();
    cleanup();

    const missing = held();
    missing.auto(CARD_URL, () => problemResponse(cardNotFoundProblem));
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: /not found/i });
    const notFound = mainClass();
    cleanup();

    const broken = held();
    broken.auto(CARD_URL, () => problemResponse(upstreamUnavailableProblem));
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('alert');
    const failed = mainClass();

    expect(notFound).toBe(loaded);
    expect(failed).toBe(loaded);
    // The loading frame only adds to the loaded page: every loaded token is still there.
    expect(tokens(loading)).toEqual(expect.arrayContaining(tokens(loaded)));
    expect(loading).not.toBe(loaded);
  });
});

describe('movers board: the reservation returns on Rises to Falls and never touches the bar (D2-i3 T02)', () => {
  it('re-enters the loading main on Falls with the same bar nodes and focus, then settles to the loaded and failed main', async () => {
    const wire = held();
    visit('/movers');
    render(<App />);
    const loading = mainClass();
    const group = screen.getByRole('group', { name: 'Direction' });
    wire.release(UP_URL, jsonResponse(moversBody('up', [risingMover])));
    await screen.findByText('Market price $26.34');
    const loaded = mainClass();
    expect(loading).not.toBe(loaded);

    const falls = within(group).getByRole('button', { name: 'Falls' });
    falls.focus();
    fireEvent.click(falls);
    // The down answer is held, so the board is loading again: the page cannot shrink.
    await waitFor(() => {
      expect(wire.urls).toContain(DOWN_URL);
    });
    expect(mainClass()).toBe(loading);
    expect(screen.getByRole('group', { name: 'Direction' })).toBe(group);
    expect(within(group).getByRole('button', { name: 'Falls' })).toBe(falls);
    expect(document.activeElement).toBe(falls);
    expect(screen.getAllByRole('status')).toHaveLength(1);

    wire.release(DOWN_URL, problemResponse(upstreamUnavailableProblem));
    await screen.findByRole('alert');
    expect(mainClass()).toBe(loaded);
    expect(document.activeElement).toBe(falls);
  });
});

describe('home strip: the reservation lasts while either direction is still loading (D2-i3 T02)', () => {
  it('keeps the reservation with one direction answered, then drops it to the loaded section for a loaded or a failed strip', async () => {
    const wire = held();
    visit('/');
    render(<App />);
    const heading = await screen.findByRole('heading', { name: /biggest moves/i });
    const section = (): HTMLElement => {
      const found = heading.closest('section');
      if (found === null) throw new Error('no strip');
      return found;
    };
    const loading = section().className;
    wire.release(
      '/v1/movers?direction=up&limit=5',
      jsonResponse(moversBody('up', [risingMover], { limit: 5 })),
    );
    await new Promise((resolve) => {
      setTimeout(resolve, 20);
    });
    // The falls are still pending: the strip is still loading, with no list drawn yet.
    expect(screen.queryByRole('list', { name: 'Rises' })).toBeNull();
    expect(section().className).toBe(loading);
    wire.release(
      '/v1/movers?direction=down&limit=5',
      jsonResponse(moversBody('down', [fallingMover], { limit: 5 })),
    );
    await screen.findByRole('list', { name: 'Falls' });
    const loaded = section().className;
    expect(loaded).not.toBe(loading);
    expect(tokens(loading)).toEqual(expect.arrayContaining(tokens(loaded)));
    cleanup();

    // One direction answers, the other fails: the strip shows its problem and takes no reservation.
    stubFetch((url) =>
      url.includes('direction=down')
        ? problemResponse(upstreamUnavailableProblem)
        : isMoversUrl(url)
          ? answerMovers(url)
          : new Error('no request expected'),
    );
    visit('/');
    render(<App />);
    const failedHeading = await screen.findByRole('heading', { name: /biggest moves/i });
    await within(screen.getByRole('main')).findByRole('alert');
    expect(failedHeading.closest('section')?.className).toBe(loaded);
  });
});

describe('price history: the sized status line stays one plain status (D2-i3 T02)', () => {
  it('shows one status with its exact text, the five range buttons and no aria-hidden node for two priced variants, then none of it once loaded', async () => {
    const card = cardWithPrices(risingPrice, { ...fallingPrice, close: '68.06' });
    const wire = held();
    wire.auto(CARD_URL, () => jsonResponse(card));
    visit('/card/ex10-%3F');
    render(<App />);
    const section = await screen.findByRole('region', { name: 'Price history' });
    await waitFor(() => {
      expect(wire.urls.filter(isHistoryUrl)).toHaveLength(1);
    });
    const status = within(section).getAllByRole('status');
    expect(status).toHaveLength(1);
    expect(status[0]?.textContent).toBe('Loading price history…');
    expect(within(section).getAllByRole('button')).toHaveLength(5);
    expect(within(section).queryByRole('group', { name: 'Variant' })).toBeNull();
    expect(
      section.querySelectorAll('[aria-hidden], [role="presentation"], [role="none"]'),
    ).toHaveLength(0);

    wire.release(wire.urls.filter(isHistoryUrl)[0] ?? '', jsonResponse(historyFor(card)));
    await within(section).findByRole('group', { name: 'Variant' });
    expect(within(section).queryAllByRole('status')).toHaveLength(0);
    expect(document.querySelectorAll('div[aria-hidden]')).toHaveLength(0);
    // One card request and one history request: the reservation adds no fetch.
    expect(wire.urls.filter((url) => url === CARD_URL)).toHaveLength(1);
    expect(wire.urls.filter(isHistoryUrl)).toHaveLength(1);
  });
});
