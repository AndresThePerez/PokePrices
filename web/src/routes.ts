// The PokéPrices route table: what each route shows around its page. Matching a path to a route
// stays in router.ts; the page each route renders stays in App.tsx.
import type { Route } from './router';
import { pageTitle, WORDMARK } from './title';

/** The landing's h1, which its tab title repeats after the wordmark. */
export const HOME_H1 = 'Pokémon card prices at the daily close';

interface RouteEntry {
  /** The tab title: the page's h1, then the wordmark; null where the page titles itself (card). */
  readonly title: string | null;
  /** The path of the header link marked current on this route, or null when none is. */
  readonly nav: string | null;
  /**
   * False on the account page, which carries its own Display settings and Sign out: the footer
   * strip and the demo strip's Sign out stay off there.
   */
  readonly footerSettings: boolean;
}

export const ROUTES: Readonly<Record<Route['name'], RouteEntry>> = {
  home: { title: `${WORDMARK} · ${HOME_H1}`, nav: '/', footerSettings: true },
  search: { title: pageTitle('Search cards'), nav: '/search', footerSettings: true },
  card: { title: null, nav: null, footerSettings: true },
  movers: { title: pageTitle('Biggest moves'), nav: '/movers', footerSettings: true },
  'sign-in': { title: pageTitle('Sign in'), nav: '/sign-in', footerSettings: true },
  'sign-up': { title: pageTitle('Create an account'), nav: null, footerSettings: true },
  'forgot-password': { title: pageTitle('Reset your password'), nav: null, footerSettings: true },
  'reset-password': { title: pageTitle('Choose a new password'), nav: null, footerSettings: true },
  account: { title: pageTitle('Account'), nav: '/account', footerSettings: false },
  'verify-email': { title: pageTitle('Verify your email'), nav: null, footerSettings: true },
  watchlist: { title: pageTitle('Watchlist'), nav: '/watchlist', footerSettings: true },
  portfolio: { title: pageTitle('Portfolio'), nav: '/portfolio', footerSettings: true },
  alerts: { title: pageTitle('Alerts and webhooks'), nav: '/account', footerSettings: true },
  'not-found': { title: pageTitle('Page not found'), nav: null, footerSettings: true },
};
