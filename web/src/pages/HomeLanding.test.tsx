// D2-i1-T03: home as a landing (H1, the shared search form, the demo entry) and the movers board's
// sticky bar (the Direction group and the one stamp), its Back to top link and the stable segment.
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  answerMovers,
  fallingMover,
  isMoversUrl,
  jsonResponse,
  moversBody,
  risingMover,
  searchPage,
  stubFetch,
} from '../test/fixtures';

const H1 = 'Pokémon card prices at the daily close';
const INTRO =
  'Each price is a TCGplayer market price collected once a day through TCGCSV, shown with its source and the day it is as of.';
const SCOPE =
  'Ranked by change vs previous close among variants whose previous close was at least $2.00, that moved at least 5% and at least $1.00, had four different closes among their last five, and whose two closes each sat between half the lowest listing and 1.5 times the mid listing of their own day.';
const STAMP_1012 = 'as of 2026-10-12 from TCGCSV (daily close)';
const FOOTNOTE = 'TCGplayer market prices via TCGCSV, collected once a day.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

function follows(first: Node, second: Node): boolean {
  return (first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('home as a landing (D2-i1-T03)', () => {
  it('states the job in the one h1, with the unchanged sentence directly after it', () => {
    stubFetch(answerMovers);
    visit('/');
    render(<App />);
    const headings = screen.getAllByRole('heading', { level: 1 });
    expect(headings.map((h) => h.textContent)).toEqual([H1]);
    const intro = screen.getByText(INTRO);
    expect(headings[0]?.nextElementSibling).toBe(intro);
  });

  it('offers the search form before the movers, submitting the trimmed query to /search with no request on /', async () => {
    const calls = stubFetch((url) =>
      isMoversUrl(url) ? answerMovers(url) : jsonResponse(searchPage),
    );
    visit('/');
    render(<App />);
    await within(main()).findByRole('link', { name: 'See all movers' });
    const form = within(main()).getByRole('search');
    expect(follows(screen.getByText(INTRO), form)).toBe(true);
    const strip = screen.getByRole('region', { name: 'Biggest moves vs previous close' });
    expect(follows(form, strip)).toBe(true);
    const box = within(form).getByRole('searchbox', { name: 'Card name' });
    const submits = form.querySelectorAll('button[type=submit], input[type=submit]');
    expect(submits).toHaveLength(1);
    expect(submits[0]?.textContent).toBe('Search');
    expect(calls.map((call) => call.url).sort()).toEqual([
      '/v1/demo/alerts',
      '/v1/demo/portfolio',
      '/v1/demo/watchlist',
      '/v1/movers?direction=down&limit=5',
      '/v1/movers?direction=up&limit=5',
      '/v1/prices/freshness',
    ]);
    fireEvent.change(box, { target: { value: '  unown ?  ' } });
    fireEvent.submit(box);
    expect(window.location.pathname + window.location.search).toBe('/search?q=unown+%3F');
    await screen.findByRole('list', { name: 'Search results' });
    expect(calls.map((call) => call.url).filter((url) => !isMoversUrl(url))).toEqual([
      '/v1/prices/freshness',
      '/v1/demo/watchlist',
      '/v1/demo/portfolio',
      '/v1/demo/alerts',
      '/v1/cards?q=unown+%3F',
    ]);
  });

  it('puts the read-only demo button after the search form and before the movers', () => {
    stubFetch(answerMovers);
    visit('/');
    render(<App />);
    const buttons = within(main()).getAllByRole('button');
    expect(buttons.map((b) => b.textContent)).toEqual(['Search', 'Try the read-only demo']);
    const demo = buttons[1] as HTMLElement;
    expect(follows(within(main()).getByRole('search'), demo)).toBe(true);
    expect(
      follows(demo, screen.getByRole('region', { name: 'Biggest moves vs previous close' })),
    ).toBe(true);
  });
});

describe('movers board bar (D2-i1-T03)', () => {
  function board(): void {
    stubFetch((url) =>
      url.includes('direction=down')
        ? jsonResponse(moversBody('down', [fallingMover]))
        : jsonResponse(moversBody('up', [risingMover])),
    );
    visit('/movers');
    render(<App />);
  }

  it('holds the Direction group and the one stamp in one bar, after the scope line and before the rows', async () => {
    board();
    await screen.findByText('Market price $26.34');
    const group = screen.getByRole('group', { name: 'Direction' });
    const bar = group.parentElement as HTMLElement;
    expect(bar).not.toBe(main());
    const stamp = within(bar).getByText(STAMP_1012);
    expect(screen.getAllByText(STAMP_1012)).toEqual([stamp]);
    expect(follows(group, stamp)).toBe(true);
    expect(follows(screen.getByText(SCOPE), bar)).toBe(true);
    const list = screen.getByRole('list', { name: 'Rises' });
    expect(bar.contains(list)).toBe(false);
    expect(follows(bar, list)).toBe(true);
  });

  it('ends with a Back to top link to #content after the footnote', async () => {
    board();
    await screen.findByText('Market price $26.34');
    const back = within(main()).getByRole('link', { name: 'Back to top' });
    expect(back.getAttribute('href')).toBe('#content');
    expect(document.getElementById('content')?.contains(main())).toBe(true);
    expect(follows(screen.getByText(FOOTNOTE), back)).toBe(true);
  });

  it('shows the Direction bar while loading, before the status line', () => {
    board();
    const group = screen.getByRole('group', { name: 'Direction' });
    const status = screen.getByRole('status');
    expect(status.textContent).toBe('Loading movers…');
    expect(follows(group, status)).toBe(true);
    expect(group.parentElement?.contains(status)).toBe(false);
  });

  it('keeps focus on the pressed segment across Rises to Falls, through loading into the falls rows', async () => {
    board();
    await screen.findByText('Market price $26.34');
    const falls = within(screen.getByRole('group', { name: 'Direction' })).getByRole('button', {
      name: 'Falls',
    });
    falls.focus();
    fireEvent.click(falls);
    expect(falls.isConnected).toBe(true);
    expect(document.activeElement).toBe(falls);
    expect(falls.getAttribute('aria-pressed')).toBe('true');
    await screen.findByText('Market price $65.11');
    await waitFor(() => {
      expect(screen.queryByRole('status')).toBeNull();
    });
    expect(falls.isConnected).toBe(true);
    expect(document.activeElement).toBe(falls);
  });
});
