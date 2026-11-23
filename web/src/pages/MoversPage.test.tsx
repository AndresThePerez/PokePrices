import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  fallingMover,
  jsonResponse,
  moverOf,
  moversBody,
  problemResponse,
  risingMover,
  stubFetch,
  upstreamUnavailableProblem,
  type MoversBody,
} from '../test/fixtures';

const UP_URL = '/v1/movers?direction=up&limit=50';
const DOWN_URL = '/v1/movers?direction=down&limit=50';
const SCOPE =
  'Ranked by change vs previous close among variants whose previous close was at least $2.00, that moved at least 5% and at least $1.00, had four different closes among their last five, and whose two closes each sat between half the lowest listing and 1.5 times the mid listing of their own day.';
const STAMP_1012 = 'as of 2026-10-12 from TCGCSV (daily close)';
const FOOTNOTE = 'TCGplayer market prices via TCGCSV, collected once a day.';
const DEGRADED = 'Catalog data may be out of date: PokéSearch is unavailable';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

/** Opens the board at `path`; every request is answered by `reply`. Returns the request URLs. */
function openBoard(path: string, reply: (url: string) => Response | Error): string[] {
  const urls: string[] = [];
  stubFetch((url) => {
    urls.push(url);
    return reply(url);
  });
  visit(path);
  render(<App />);
  return urls;
}

function answer(body: MoversBody): () => Response {
  return () => jsonResponse(body);
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

function rows(): HTMLElement[] {
  return within(screen.getByRole('list', { name: 'Rises' })).getAllByRole('listitem');
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  visit('/');
});

describe('movers board (T09 criteria 2 and 3)', () => {
  it('asks for the 50 biggest rises and marks Rises pressed', async () => {
    const urls = openBoard('/movers', answer(moversBody('up', [risingMover])));
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Biggest moves');
    const group = screen.getByRole('group', { name: 'Direction' });
    const rises = within(group).getByRole('button', { name: 'Rises' });
    const falls = within(group).getByRole('button', { name: 'Falls' });
    expect(rises.getAttribute('aria-pressed')).toBe('true');
    expect(falls.getAttribute('aria-pressed')).toBe('false');
    for (const button of [rises, falls]) {
      expect(button.className.split(/\s+/)).toEqual(
        expect.arrayContaining(['min-h-11', 'min-w-11']),
      );
    }
    await screen.findByText('Market price $26.34');
    expect(urls).toEqual(['/v1/prices/freshness', UP_URL]);
  });

  it('switches to falls in place: /movers?direction=down, Falls pressed, the falls request', async () => {
    const urls = openBoard('/movers', (url) =>
      url === DOWN_URL
        ? jsonResponse(moversBody('down', [fallingMover]))
        : jsonResponse(moversBody('up', [risingMover])),
    );
    await screen.findByText('Market price $26.34');
    fireEvent.click(screen.getByRole('button', { name: 'Falls' }));
    expect(window.location.pathname + window.location.search).toBe('/movers?direction=down');
    expect(screen.getByRole('button', { name: 'Falls' }).getAttribute('aria-pressed')).toBe('true');
    expect(screen.getByRole('button', { name: 'Rises' }).getAttribute('aria-pressed')).toBe(
      'false',
    );
    await screen.findByText('Market price $65.11');
    expect(urls).toEqual(['/v1/prices/freshness', UP_URL, DOWN_URL]);
    fireEvent.click(screen.getByRole('button', { name: 'Rises' }));
    expect(window.location.pathname + window.location.search).toBe('/movers');
  });

  it('opens on falls from /movers?direction=down', async () => {
    const urls = openBoard('/movers?direction=down', answer(moversBody('down', [fallingMover])));
    await screen.findByText('Market price $65.11');
    expect(urls).toEqual(['/v1/prices/freshness', DOWN_URL]);
  });

  it('reaches the board from the header Movers link', async () => {
    openBoard('/nowhere', answerMovers);
    const link = within(screen.getByRole('banner')).getByRole('link', { name: 'Movers' });
    expect(link.getAttribute('href')).toBe('/movers');
    expect(link.className.split(/\s+/)).toContain('min-h-11');
    expect(fireEvent.click(link)).toBe(false);
    expect(window.location.pathname).toBe('/movers');
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Biggest moves');
    await screen.findByText('Market price $26.34');
  });

  it('prints the scope line, the stamp, then the ranked rows and the footnote, in that order', async () => {
    const second = moverOf({
      rank: 2,
      card_id: 'pl4-82',
      card: { name: 'Beginning Door', set_name: 'Arceus', number: '82' },
      variant_key: 'pl4-82:holo:1st-edition:-',
      finish: 'holo',
      edition: '1st-edition',
      close: '9.81',
      previous_close: '2.40',
      change: '7.41',
      change_pct: '308.75',
    });
    openBoard('/movers', answer(moversBody('up', [risingMover, second])));
    await screen.findByText('Market price $26.34');
    const text = main().textContent;
    const order = [SCOPE, STAMP_1012, '#1', 'Chimecho', '#2', 'Beginning Door', FOOTNOTE].map(
      (part) => text.indexOf(part),
    );
    expect(order.every((at) => at >= 0)).toBe(true);
    expect([...order].sort((a, b) => a - b)).toEqual(order);
    expect(screen.getByRole('list', { name: 'Rises' }).tagName).toBe('OL');
    const [first, other] = rows();
    if (first === undefined || other === undefined) throw new Error('two rows expected');
    const link = within(first).getByRole('link', { name: 'Chimecho' });
    expect(link.getAttribute('href')).toBe('/card/ex5-17?variant=ex5-17%3Areverse%3A-%3A-');
    expect(link.className.split(/\s+/)).toContain('min-h-11');
    expect(within(first).getByText('#1')).toBeTruthy();
    expect(within(first).getByText('Hidden Legends No. 17 · Reverse')).toBeTruthy();
    expect(within(first).getByText('Market price $26.34')).toBeTruthy();
    const change = within(first).getByText('▲ +$20.63 (+361.30%) vs previous close (2026-10-11)');
    // The full line is read by assistive technology; the short chip shows the tone.
    expect(change.className.split(/\s+/)).toContain('sr-only');
    const chip = within(first).getByText('▲ +361.30%');
    expect(chip.className.split(/\s+/)).toContain('text-up');
    expect(within(other).getByText('Arceus No. 82 · Holo, 1st-edition')).toBeTruthy();
    expect(
      within(other).getByText('▲ +$7.41 (+308.75%) vs previous close (2026-10-11)'),
    ).toBeTruthy();
    expect(screen.getAllByText(SCOPE)).toHaveLength(1);
    expect(screen.getAllByText(STAMP_1012)).toHaveLength(1);
  });

  it('prints a fall with the down arrow, the minus sign and the down tone', async () => {
    openBoard('/movers?direction=down', answer(moversBody('down', [fallingMover])));
    expect(
      await screen.findByText('▼ -$99.89 (-60.54%) vs previous close (2026-10-11)'),
    ).toBeTruthy();
    const change = screen.getByText('▼ -60.54%');
    expect(change.className.split(/\s+/)).toContain('text-down');
    expect(change.className).not.toMatch(/emerald|red-/);
    expect(screen.getByText('Emerald No. 91 · Holo')).toBeTruthy();
  });
});

describe('movers board carry-forward cases (T09 criterion 5)', () => {
  it('ranks a +0.01 move with change_pct 0.00 as a rise, never as No change', async () => {
    openBoard(
      '/movers',
      answer(
        moversBody('up', [
          moverOf({
            close: '214398.51',
            previous_close: '214398.50',
            change: '0.01',
            change_pct: '0.00',
          }),
        ]),
      ),
    );
    expect(
      await screen.findByText('▲ +$0.01 (+0.00%) vs previous close (2026-10-11)'),
    ).toBeTruthy();
    expect(screen.getByText('▲ +0.00%').className.split(/\s+/)).toContain('text-up');
    expect(screen.getByText('Market price $214,398.51')).toBeTruthy();
    expect(main().textContent).not.toContain('No change');
  });

  it('prints a previous_day two days back as the API gave it', async () => {
    openBoard('/movers', answer(moversBody('up', [moverOf({ previous_day: '2026-10-10' })])));
    expect(
      await screen.findByText('▲ +$20.63 (+361.30%) vs previous close (2026-10-10)'),
    ).toBeTruthy();
    expect(main().textContent).not.toContain('2026-10-11');
  });

  it('names a card the catalog does not have as Card <card_id>, linking to its card page', async () => {
    openBoard('/movers', answer(moversBody('up', [moverOf({ card_id: 'ex10-?', card: null })])));
    const link = await screen.findByRole('link', { name: 'Card ex10-?' });
    expect(link.getAttribute('href')).toBe('/card/ex10-%3F?variant=ex5-17%3Areverse%3A-%3A-');
    const [row] = rows();
    if (row === undefined) throw new Error('one row expected');
    expect(within(row).getByText('Reverse')).toBeTruthy();
    expect(row.textContent).not.toContain('No.');
  });

  it('says No falls ranked for the day when the day has no falls', async () => {
    openBoard('/movers?direction=down', answer(moversBody('down', [])));
    expect(await screen.findByText('No falls ranked for 2026-10-12')).toBeTruthy();
    expect(screen.getByText(SCOPE)).toBeTruthy();
    expect(screen.getByText(STAMP_1012)).toBeTruthy();
    expect(screen.queryByRole('list', { name: 'Falls' })).toBeNull();
    expect(screen.queryByText(FOOTNOTE)).toBeNull();
  });

  it('says No rises ranked for the day when the day has no rises', async () => {
    openBoard('/movers', answer(moversBody('up', [])));
    expect(await screen.findByText('No rises ranked for 2026-10-12')).toBeTruthy();
  });

  it('says No movers yet, with no stamp, before any movers day', async () => {
    openBoard('/movers', answer(moversBody('up', [], { day: null, as_of: null })));
    expect(await screen.findByText('No movers yet')).toBeTruthy();
    expect(main().textContent).not.toContain('as of');
    expect(screen.queryByRole('list', { name: 'Rises' })).toBeNull();
  });
});

describe('movers board states (T09 criterion 4)', () => {
  it('shows Loading movers… until the answer arrives', async () => {
    openBoard('/movers', answer(moversBody('up', [risingMover])));
    expect(screen.getByRole('status').textContent).toBe('Loading movers…');
    await screen.findByText('Market price $26.34');
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('shows the failure with its request id when there is no answer to show', async () => {
    openBoard('/movers', () => problemResponse(upstreamUnavailableProblem));
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not load the movers: Upstream Unavailable');
    expect(within(alert).getByText('req-t46-upstream-0001').tagName).toBe('CODE');
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('shows a failure without a request id when the API could not be reached', async () => {
    openBoard('/movers', () => new TypeError('network down'));
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toBe(
      'Could not load the movers: The PokéPrices API could not be reached',
    );
  });

  it('puts the degraded catalog line above the list when meta.degraded is true', async () => {
    openBoard('/movers', answer(moversBody('up', [moverOf({ card: null })], { degraded: true })));
    const line = await screen.findByText(DEGRADED);
    const list = screen.getByRole('list', { name: 'Rises' });
    expect(line.compareDocumentPosition(list) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Card ex5-17' })).toBeTruthy();
  });

  it('has no degraded line when meta.degraded is false', async () => {
    openBoard('/movers', answer(moversBody('up', [risingMover])));
    await screen.findByText('Market price $26.34');
    expect(screen.queryByText(DEGRADED)).toBeNull();
  });
});

describe('movers board honesty (D1, D14)', () => {
  it.each([
    ['rows', () => jsonResponse(moversBody('up', [risingMover, moverOf({ card: null })]))],
    ['degraded', () => jsonResponse(moversBody('up', [risingMover], { degraded: true }))],
    ['no rises', () => jsonResponse(moversBody('up', []))],
    ['no movers', () => jsonResponse(moversBody('up', [], { day: null, as_of: null }))],
    ['failure', () => problemResponse(upstreamUnavailableProblem)],
  ] as const)('never shows a forbidden word or the faked year: %s', async (_, reply) => {
    openBoard('/movers', reply);
    await waitFor(() => {
      expect(screen.queryByRole('status')).toBeNull();
    });
    const text = document.body.textContent;
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
    expect(text).not.toContain('2031');
  });

  it('never shows a forbidden word while loading', () => {
    openBoard('/movers', answer(moversBody('up', [risingMover])));
    const text = document.body.textContent;
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
    expect(text).not.toContain('2031');
  });
});
