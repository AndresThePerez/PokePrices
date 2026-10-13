import { afterEach, describe, expect, it, vi } from 'vitest';
import { getCardPrices } from './client';

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('getCardPrices edge cases (T05 criterion 2)', () => {
  it('keeps a slash and a non-ASCII id inside one encoded path segment', async () => {
    const urls: string[] = [];
    vi.stubGlobal('fetch', (input: string | URL): Promise<Response> => {
      urls.push(String(input));
      return Promise.resolve(new Response('{}', { status: 200 }));
    });
    await getCardPrices('a/b', '1W');
    await getCardPrices('pokémon', 'YTD');
    expect(urls).toEqual([
      '/v1/cards/a%2Fb/prices?range=1W',
      '/v1/cards/pok%C3%A9mon/prices?range=YTD',
    ]);
  });

  it('never calls fetch for collapsing ids and resolves a status-0 failure', async () => {
    const fetchSpy = vi.fn();
    vi.stubGlobal('fetch', fetchSpy);
    for (const id of ['', '.', '..']) {
      const result = await getCardPrices(id, '1M');
      expect(result.ok).toBe(false);
      if (!result.ok) expect(result.status).toBe(0);
    }
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});
