import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import { cardVariantPath } from '../routes';
import { pageTitle } from '../title';
import {
  deoxysCard,
  deoxysCardBody,
  deoxysReverseMover,
  fallingMover,
  historyFor,
  isHistoryUrl,
  isMoversUrl,
  jsonResponse,
  moversBody,
  stubFetch,
} from '../test/fixtures';
import { recorder, resetRecorder } from '../test/lightweightChartsFake';

// jsdom has no canvas: the chart library is replaced by its recording fake.
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const HOLO = 'ex13-3:holo:-:-';
const REVERSE = 'ex13-3:reverse:-:-';
const REVERSE_LINK = '/card/ex13-3?variant=ex13-3%3Areverse%3A-%3A-';
const HOLO_LINK = '/card/ex13-3?variant=ex13-3%3Aholo%3A-%3A-';
const REVERSE_CHANGE = '▲ +$747.00 (+14940.00%) vs previous close (2026-10-12)';
const HOLO_CHANGE = 'No change vs previous close (2026-10-12)';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

/** Serves the home page's movers (Deoxys δ's reverse on top), the card and its history. */
function serve(card: CardDetail = deoxysCardBody): string[] {
  const urls: string[] = [];
  stubFetch((url) => {
    urls.push(url);
    if (isMoversUrl(url)) {
      return url.includes('direction=down')
        ? jsonResponse(moversBody('down', [fallingMover], { day: '2026-10-13' }))
        : jsonResponse(moversBody('up', [deoxysReverseMover], { day: '2026-10-13' }));
    }
    if (isHistoryUrl(url)) return jsonResponse(historyFor(card));
    if (url === '/v1/cards/ex13-3') return jsonResponse(card);
    return new Error(`no request expected: ${url}`);
  });
  return urls;
}

/** The hero: the block led by its "Market price · <variant>" label line. */
async function hero(): Promise<HTMLElement> {
  const label = await screen.findByText(/^Market price · /);
  const block = label.parentElement;
  if (block === null) throw new Error('the hero label has no block');
  return block;
}

/** The Price history section once its stamp line has settled. */
async function history(): Promise<HTMLElement> {
  const section = await screen.findByRole('region', { name: 'Price history' });
  await within(section).findByText(/^as of /);
  return section;
}

function pressed(section: HTMLElement): string[] {
  const group = within(section).getByRole('group', { name: 'Variant' });
  return within(group)
    .getAllByRole('button')
    .filter((button) => button.getAttribute('aria-pressed') === 'true')
    .map((button) => button.textContent);
}

function summary(section: HTMLElement): string {
  return section.querySelector('p.sr-only')?.textContent ?? '';
}

/** The closes the chart was last given, as numbers. */
function drawnValues(): number[] {
  const chart = recorder.charts.at(-1);
  const data = chart?.series.at(-1)?.data.at(-1);
  if (!Array.isArray(data)) return [];
  return data.map((point: unknown) => (point as { value: number }).value);
}

async function openTopRiser(): Promise<void> {
  visit('/');
  render(<App />);
  const rises = await screen.findByRole('list', { name: 'Rises' });
  fireEvent.click(within(rises).getByRole('link', { name: 'Deoxys δ' }));
}

let consoleError: MockInstance<typeof console.error>;

beforeEach(() => {
  resetRecorder();
  consoleError = vi.spyOn(console, 'error');
});

afterEach(() => {
  cleanup();
  consoleError.mockRestore();
  visit('/');
});

describe('a list link opens the card page on the variant it names', () => {
  it('builds the card link with the variant key as one encoded query parameter', () => {
    expect(cardVariantPath('ex13-3', REVERSE)).toBe(REVERSE_LINK);
    expect(cardVariantPath('ex10-?', 'ex10-?:holo:unlimited:-')).toBe(
      '/card/ex10-%3F?variant=ex10-%3F%3Aholo%3Aunlimited%3A-',
    );
    expect(cardVariantPath('a b', 'x&y=z+w #')).toBe('/card/a%20b?variant=x%26y%3Dz%2Bw%20%23');
  });

  it('links the top riser to its own variant, not to the bare card page', async () => {
    serve();
    visit('/');
    render(<App />);
    const rises = await screen.findByRole('list', { name: 'Rises' });
    const link = within(rises).getByRole('link', { name: 'Deoxys δ' });
    expect(link.getAttribute('href')).toBe(REVERSE_LINK);
  });

  it('opens the reverse holo in the hero and the chart, not the holo the card lists first', async () => {
    serve();
    await openTopRiser();
    expect(window.location.pathname + window.location.search).toBe(REVERSE_LINK);
    const block = await hero();
    expect(within(block).getByText('Market price · reverse')).toBeTruthy();
    expect(within(block).getByText('$752.00')).toBeTruthy();
    expect(within(block).getByText(REVERSE_CHANGE)).toBeTruthy();
    expect(within(block).queryByText('$81.78')).toBeNull();
    expect(within(block).queryByText(HOLO_CHANGE)).toBeNull();
    const section = await history();
    expect(pressed(section)).toEqual(['reverse']);
    expect(summary(section)).toMatch(/^Reverse, 1M: /);
    await waitFor(() => {
      expect(drawnValues()).toEqual([5, 752]);
    });
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('names the preselected variant in the title, the accessible names and the price sentence', async () => {
    // The holo's close is a day older here, so its sentence and the reverse's differ.
    const holoOlder = {
      ...deoxysCardBody,
      data: {
        ...deoxysCard,
        variants: deoxysCard.variants.map((variant) =>
          variant.variant_key === HOLO
            ? {
                ...variant,
                price: {
                  ...variant.price,
                  day: '2026-10-12',
                  as_of: '2026-10-12T20:06:44Z',
                  previous_day: '2026-10-11',
                },
              }
            : variant,
        ),
      },
    } satisfies CardDetail;
    serve(holoOlder);
    visit(REVERSE_LINK);
    render(<App />);
    const block = await hero();
    expect(document.title).toBe(pageTitle('Deoxys δ'));
    expect(document.title).not.toMatch(/holo/i);
    expect(within(block).getByText('as of 2026-10-13 from TCGCSV (daily close)')).toBeTruthy();
    expect(within(block).queryByText('as of 2026-10-12 from TCGCSV (daily close)')).toBeNull();
    expect(within(block).getByText(REVERSE_CHANGE)).toBeTruthy();
    const section = await history();
    const group = within(section).getByRole('group', { name: 'Variant' });
    expect(
      within(group).getByRole('button', { name: 'reverse' }).getAttribute('aria-pressed'),
    ).toBe('true');
    expect(within(group).getByRole('button', { name: 'holo' }).getAttribute('aria-pressed')).toBe(
      'false',
    );
    expect(summary(section)).toMatch(/^Reverse, 1M: /);
    expect(consoleError).not.toHaveBeenCalled();
  });

  it.each([
    ['a variant the card does not have', '?variant=ex13-3%3Afirst%3A-%3A-'],
    ['another card’s variant', '?variant=ex5-17%3Areverse%3A-%3A-'],
    ['an empty variant', '?variant='],
    ['a bare variant parameter', '?variant'],
    ['a malformed escape', '?variant=%E0%A4%A'],
    ['a half key', '?variant=reverse'],
    ['a key with trailing space', '?variant=ex13-3%3Areverse%3A-%3A-%20'],
  ])('opens the first variant, with no error, for %s', async (_case, search) => {
    serve();
    visit(`/card/ex13-3${search}`);
    render(<App />);
    const block = await hero();
    expect(within(block).getByText('Market price · holo')).toBeTruthy();
    expect(within(block).getByText('$81.78')).toBeTruthy();
    expect(within(block).getByText(HOLO_CHANGE)).toBeTruthy();
    const section = await history();
    expect(pressed(section)).toEqual(['holo']);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Deoxys δ');
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByText(/^Could not load/)).toBeNull();
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('opens the first variant when the card page has no variant parameter, as before', async () => {
    serve();
    visit('/card/ex13-3');
    render(<App />);
    const block = await hero();
    expect(within(block).getByText('Market price · holo')).toBeTruthy();
    expect(pressed(await history())).toEqual(['holo']);
  });
});

describe('the address follows the variant chosen on the card page', () => {
  it('replaces the address on another choice, so Back leaves the card and Forward reopens the last choice', async () => {
    serve();
    await openTopRiser();
    const section = await history();
    const length = window.history.length;
    const group = within(section).getByRole('group', { name: 'Variant' });
    const holo = within(group).getByRole('button', { name: 'holo' });
    holo.focus();
    fireEvent.click(holo);
    expect(window.location.pathname + window.location.search).toBe(HOLO_LINK);
    expect(window.history.length).toBe(length);
    expect(within(await hero()).getByText('Market price · holo')).toBeTruthy();
    expect(pressed(section)).toEqual(['holo']);
    // The same page stays: focus stays on the pressed segment, not on the page's heading.
    expect(document.activeElement).toBe(within(group).getByRole('button', { name: 'holo' }));

    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    await screen.findByRole('list', { name: 'Rises' });
    act(() => {
      window.history.forward();
    });
    await waitFor(() => {
      expect(window.location.pathname + window.location.search).toBe(HOLO_LINK);
    });
    const block = await hero();
    expect(within(block).getByText('Market price · holo')).toBeTruthy();
    expect(within(block).queryByText('Market price · reverse')).toBeNull();
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('follows a history step between two entries of the same card', async () => {
    serve();
    visit(REVERSE_LINK);
    render(<App />);
    expect(within(await hero()).getByText('Market price · reverse')).toBeTruthy();
    act(() => {
      window.history.pushState(null, '', HOLO_LINK);
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(within(await hero()).getByText('Market price · holo')).toBeTruthy();
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(window.location.search).toBe('?variant=ex13-3%3Areverse%3A-%3A-');
    });
    await waitFor(() => {
      expect(screen.getByText('Market price · reverse')).toBeTruthy();
    });
  });

  it('keeps an existing history state when it replaces the address', async () => {
    serve();
    window.history.replaceState({ status: 'kept' }, '', REVERSE_LINK);
    render(<App />);
    const section = await history();
    const group = within(section).getByRole('group', { name: 'Variant' });
    fireEvent.click(within(group).getByRole('button', { name: 'holo' }));
    expect(window.location.search).toBe('?variant=ex13-3%3Aholo%3A-%3A-');
    expect(window.history.state).toEqual({ status: 'kept' });
  });
});
