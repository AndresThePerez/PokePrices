import { describe, expect, it } from 'vitest';
import { ACCOUNT_KEY, ME_KEY } from './account/session';
import { cardKey, cardPricesKey, moversKey, searchKey } from './queryKeys';

const PUBLIC_KEYS = [
  cardKey('ex10-?'),
  cardPricesKey('ex10-?', '1M'),
  moversKey('up', 5),
  searchKey('pikachu', 2),
] as const;

describe('public query keys (Q1-i5-T04)', () => {
  it('keeps the card, prices and movers keys byte-identical to the literals they replace', () => {
    expect(cardKey('ex10-?')).toEqual(['card', 'ex10-?']);
    expect(cardPricesKey('ex10-?', '1M')).toEqual(['card-prices', 'ex10-?', '1M']);
    expect(cardPricesKey('ex10-?', 'YTD')).toEqual(['card-prices', 'ex10-?', 'YTD']);
    expect(moversKey('up', 5)).toEqual(['movers', 'up', 5]);
    expect(moversKey('down', 50)).toEqual(['movers', 'down', 50]);
  });

  it('keys a search by its query and page', () => {
    expect(searchKey('pikachu', 1)).toEqual(['search', 'pikachu', 1]);
    expect(searchKey('pikachu', 2)).toEqual(['search', 'pikachu', 2]);
    expect(searchKey('', 1)).toEqual(['search', '', 1]);
  });

  it('never makes a public key per-user: none starts with the account root or equals the me key', () => {
    for (const key of PUBLIC_KEYS) {
      expect(key[0]).not.toBe(ACCOUNT_KEY[0]);
      expect(key).not.toEqual(ME_KEY);
      expect(key[0]).not.toBe(ME_KEY[0]);
    }
  });
});
