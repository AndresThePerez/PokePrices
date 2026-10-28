import { describe, expect, it } from 'vitest';
import { ME_KEY, endSession } from './account/session';
import { createQueryClient } from './query';
import { cardKey, cardPricesKey, moversKey, searchKey } from './queryKeys';

const A = { email: 'collector@example.com', email_verified: true, two_factor: false };
const B = { ...A, email: 'second@example.com' };

describe('public query keys against the account cache (Q1-i5-T04 review)', () => {
  it('keeps every public answer through an account change, a sign-out and a session end, and hands out a fresh key per call', () => {
    const client = createQueryClient();
    const entries = [
      [searchKey('a', 1), { data: 'search' }],
      [cardKey('ex10-?'), { data: 'card' }],
      [cardPricesKey('ex10-?', '1M'), { data: 'prices' }],
      [moversKey('up', 5), { data: 'movers' }],
    ] as const;
    for (const [key, value] of entries) client.setQueryData(key, value);
    client.setQueryData(['account', 'watchlist'], { data: 'mine' });
    client.setQueryData(ME_KEY, A);
    client.setQueryData(ME_KEY, B);
    client.setQueryData(ME_KEY, null);
    endSession(client);
    for (const [key, value] of entries) expect(client.getQueryData(key)).toEqual(value);
    // A fresh key each call: a caller changing one cannot change the next.
    const first = searchKey('a', 1) as unknown as unknown[];
    first[1] = 'b';
    expect(searchKey('a', 1)).toEqual(['search', 'a', 1]);
  });
});
