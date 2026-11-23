import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import type { Card, CardDetail } from '../api/client';
import {
  cardBody,
  cardNotFoundPlainProblem,
  jsonResponse,
  problemResponse,
  stubFetch,
  unownCard,
} from '../test/fixtures';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

type Variant = Card['variants'][number];
type Source = Variant['sources'][number];

const holoSource: Source = {
  source: 'tcgplayer',
  product_id: 88123,
  subtype: 'Holofoil',
  group_id: 1399,
  series_epoch: 1,
  map_snapshot_day: '2026-10-11',
};
const holoVariant: Variant = {
  variant_key: 'ex10-?:holo:unlimited:-',
  finish: 'holo',
  edition: 'unlimited',
  treatment: '-',
  sources: [holoSource],
  price: null,
};

function bodyWith(patch: Partial<Card>, degraded = false): CardDetail {
  const card: Card = { ...unownCard, ...patch };
  return { data: card, meta: { degraded } };
}

async function show(body: unknown, heading = 'Unown'): Promise<string> {
  stubFetch(() => jsonResponse(body));
  visit('/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: heading });
  return screen.getByRole('main').textContent;
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('CardPage edge cases', () => {
  it('names a seed_ref shorter than 7 characters whole, without padding or undefined', async () => {
    const text = await show(
      bodyWith({ catalog: { seed_ref: 'abc', seeded_at: '2026-10-11T20:05:45Z', status: 'ok' } }),
    );
    expect(text).toContain('Catalog: PokéSearch dataset abc, indexed 2026-10-11 (UTC)');
    expect(text).not.toMatch(/undefined|NaN|null/);
  });

  it('invents no date when seeded_at is not an ISO timestamp', async () => {
    const text = await show(
      bodyWith({ catalog: { seed_ref: '39a26a144c8b', seeded_at: 'last tuesday', status: 'ok' } }),
    );
    expect(text).toContain('Catalog: PokéSearch dataset 39a26a1');
    expect(text).not.toMatch(/indexed|\(UTC\)|Invalid|NaN|undefined/);
  });

  it('invents no date from a bare number in seeded_at (a lenient Date.parse reads "12" as a date)', async () => {
    const text = await show(
      bodyWith({ catalog: { seed_ref: '39a26a144c8b', seeded_at: '12', status: 'ok' } }),
    );
    expect(text).toContain('Catalog: PokéSearch dataset 39a26a1');
    expect(text).not.toContain('indexed');
  });

  it('keeps the degraded, unmapped and unseeded lines together, each verbatim', async () => {
    const text = await show(
      bodyWith(
        {
          variants: [],
          catalog: { seed_ref: null, seeded_at: null, status: 'unverified' },
        },
        true,
      ),
    );
    expect(text).toContain('Catalog data may be out of date: PokéSearch is unavailable');
    expect(text).toContain('Not yet mapped');
    expect(text).toContain('No prices yet');
    expect(text).toContain('catalog version unknown');
    expect(text).not.toContain('Catalog: PokéSearch dataset');
  });

  it('renders two sources on one variant as two verbatim lines and an empty variant as unmapped', async () => {
    await show(
      bodyWith({
        variants: [
          {
            ...holoVariant,
            sources: [holoSource, { ...holoSource, product_id: 99001, subtype: 'Normal' }],
          },
          {
            ...holoVariant,
            variant_key: 'ex10-?:reverse:unlimited:-',
            finish: 'reverse',
            sources: [],
          },
        ],
      }),
    );
    const lines = screen.getAllByText(/^TCGplayer product /).map((node) => node.textContent);
    expect(lines).toEqual([
      'TCGplayer product 88123 (Holofoil) via TCGCSV, mapped from the collected snapshot of 2026-10-11',
      'TCGplayer product 99001 (Normal) via TCGCSV, mapped from the collected snapshot of 2026-10-11',
    ]);
    expect(screen.getAllByText('Not yet mapped')).toHaveLength(1);
  });

  it('renders hostile card fields as text, never as elements', async () => {
    const hostile = '<img src=x onerror="window.__pwned=1">';
    const text = await show(
      bodyWith({
        name: hostile,
        rarity: hostile,
        number: hostile,
        set: { ...unownCard.set, name: hostile },
        variants: [{ ...holoVariant, sources: [{ ...holoSource, subtype: hostile }] }],
      }),
      hostile,
    );
    expect(text).toContain(hostile);
    expect(screen.getByRole('main').querySelectorAll('img')).toHaveLength(1);
    expect(screen.getByRole('main').querySelector('img[src="x"]')).toBeNull();
  });

  it('never shows the previous card while the next card id is loading, and ignores a late answer for it', async () => {
    const pending = new Map<string, (response: Response) => void>();
    vi.stubGlobal('fetch', (input: string | URL): Promise<Response> => {
      return new Promise((resolve) => {
        pending.set(String(input), resolve);
      });
    });
    onTestFinished(() => {
      vi.unstubAllGlobals();
    });
    visit('/card/base1-4');
    render(<App />);
    expect(pending.has('/v1/cards/base1-4')).toBe(true);
    act(() => {
      navigate('/card/ex10-%3F');
    });
    expect(pending.has('/v1/cards/ex10-%3F')).toBe(true);
    // B settles first, then A answers late: A must not render over B.
    await act(async () => {
      pending.get('/v1/cards/ex10-%3F')?.(jsonResponse(cardBody));
      await Promise.resolve();
    });
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    await act(async () => {
      pending.get('/v1/cards/base1-4')?.(
        jsonResponse(bodyWith({ id: 'base1-4', name: 'Alakazam', number: '1' })),
      );
      await Promise.resolve();
    });
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
    expect(screen.queryByText('Alakazam')).toBeNull();
  });

  it('shows a loading state, not the old card, after navigating to a new id', async () => {
    const calls = stubFetch((url) =>
      url.endsWith('ex10-%3F') ? jsonResponse(cardBody) : new Error('offline'),
    );
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    act(() => {
      navigate('/card/svp-1');
    });
    expect(screen.queryByText('Unown')).toBeNull();
    expect(screen.getByRole('heading', { level: 1 }).textContent).toMatch(/^Card svp-1$/);
    expect(calls.map((call) => call.url)).toEqual([
      '/v1/prices/freshness',
      '/v1/cards/ex10-%3F',
      '/v1/cards/svp-1',
    ]);
  });

  it('survives empty and unparseable image URLs without crashing or inventing an attribution host', async () => {
    const text = await show(bodyWith({ images: { small: '', large: 'not a url' } }));
    expect(text).toContain('Unown');
    expect(screen.getByRole('main').querySelector('img')?.getAttribute('src')).toBe('not a url');
    expect(screen.queryByText(/^Card image from/)).toBeNull();
    cleanup();
    const bare = await show(bodyWith({ images: { small: '', large: '' } }));
    expect(bare).toContain('No prices yet');
    expect(screen.getByRole('main').querySelectorAll('img')).toHaveLength(0);
  });

  it('shows the Card not found heading only for a card-not-found 404, not for any other 404', async () => {
    stubFetch(
      () =>
        new Response('<html>nginx 404</html>', {
          status: 404,
          headers: { 'Content-Type': 'text/html' },
        }),
    );
    visit('/card/ex10-%3F');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not load this card');
    expect(screen.queryByText('Card not found')).toBeNull();
    cleanup();
    stubFetch(() => problemResponse(cardNotFoundPlainProblem));
    render(<App />);
    expect(await screen.findByRole('heading', { name: 'Card not found' })).toBeTruthy();
    expect(screen.queryByText('This card is no longer in the PokéSearch catalog')).toBeNull();
  });

  it.each([
    '2026-10-11',
    '1760212000',
    '2026-10-11T20:05:45',
    '2025-02-29T10:00:00Z',
    '2026-10-11T24:00:00Z',
    '2026-10-11T20:05:45+99:00',
    ' 2026-10-11T20:05:45Z',
    '2026-10-11T20:05:45Z\n',
  ])('prints no indexed day for the non-RFC 3339 seeded_at %j', async (seededAt) => {
    const text = await show(
      bodyWith({ catalog: { seed_ref: '39a26a144c8b', seeded_at: seededAt, status: 'ok' } }),
    );
    expect(text).toContain('Catalog: PokéSearch dataset 39a26a1');
    expect(text).not.toContain('indexed');
  });

  it.each([
    ['2026-10-11T20:05:45.123456Z', '2026-10-11'],
    ['2028-02-29T00:00:00Z', '2028-02-29'],
    ['2026-10-12T01:30:00+05:30', '2026-10-11'],
  ])('still prints the UTC day for the valid timestamp %s', async (seededAt, day) => {
    const text = await show(
      bodyWith({ catalog: { seed_ref: '39a26a144c8b', seeded_at: seededAt, status: 'ok' } }),
    );
    expect(text).toContain(`Catalog: PokéSearch dataset 39a26a1, indexed ${day} (UTC)`);
  });
});
