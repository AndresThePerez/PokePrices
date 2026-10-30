import { describe, expect, it } from 'vitest';
import { ROUTES } from './routes';
import type { Route } from './router';

// Every route the router can answer with; the Record type behind ROUTES makes a missing name a
// type error, and this list makes an extra or a missing one a test failure.
const NAMES: readonly Route['name'][] = [
  'home',
  'search',
  'card',
  'movers',
  'sign-in',
  'sign-up',
  'forgot-password',
  'reset-password',
  'account',
  'verify-email',
  'watchlist',
  'portfolio',
  'alerts',
  'not-found',
];

describe('the route table', () => {
  it('has one entry per route name, 14 in all', () => {
    expect(Object.keys(ROUTES).sort()).toEqual([...NAMES].sort());
    expect(Object.keys(ROUTES)).toHaveLength(14);
  });

  it('holds the 13 tab titles and leaves the card page to title itself', () => {
    expect(Object.fromEntries(NAMES.map((name) => [name, ROUTES[name].title]))).toEqual({
      home: 'PokéPrices · Pokémon card prices at the daily close',
      search: 'Search cards · PokéPrices',
      card: null,
      movers: 'Biggest moves · PokéPrices',
      'sign-in': 'Sign in · PokéPrices',
      'sign-up': 'Create an account · PokéPrices',
      'forgot-password': 'Reset your password · PokéPrices',
      'reset-password': 'Choose a new password · PokéPrices',
      account: 'Account · PokéPrices',
      'verify-email': 'Verify your email · PokéPrices',
      watchlist: 'Watchlist · PokéPrices',
      portfolio: 'Portfolio · PokéPrices',
      alerts: 'Alerts and webhooks · PokéPrices',
      'not-found': 'Page not found · PokéPrices',
    });
  });

  it('names the header link each route marks current, and hides the footer settings on the account page only', () => {
    expect(Object.fromEntries(NAMES.map((name) => [name, ROUTES[name].nav]))).toEqual({
      home: '/',
      search: '/search',
      card: null,
      movers: '/movers',
      'sign-in': '/sign-in',
      'sign-up': null,
      'forgot-password': null,
      'reset-password': null,
      account: '/account',
      'verify-email': null,
      watchlist: '/watchlist',
      portfolio: '/portfolio',
      alerts: '/account',
      'not-found': null,
    });
    expect(NAMES.filter((name) => !ROUTES[name].footerSettings)).toEqual(['account']);
  });
});
