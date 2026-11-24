import { useEffect, useState } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import AccountPage from './account/AccountPage';
import AlertsPage from './account/AlertsPage';
import DemoButton from './account/DemoButton';
import ForgotPasswordPage from './account/ForgotPasswordPage';
import PortfolioPage from './account/PortfolioPage';
import PublicSession from './account/PublicSession';
import ResetPasswordPage from './account/ResetPasswordPage';
import SignInPage from './account/SignInPage';
import { SignedOutLine } from './account/SignOut';
import SignUpPage from './account/SignUpPage';
import VerifyEmailPage from './account/VerifyEmailPage';
import WatchlistPage from './account/WatchlistPage';
import Footer from './Footer';
import Header from './Header';
import Link from './Link';
import AboutPage from './pages/AboutPage';
import CardPage from './pages/CardPage';
import { CardTiles, ExampleSearches } from './pages/HomeStart';
import { BiggestMoves } from './pages/MoverList';
import MoversPage from './pages/MoversPage';
import SearchForm from './pages/SearchForm';
import SearchPage from './pages/SearchPage';
import { createQueryClient } from './query';
import { useRoute } from './router';
import { HOME_H1, ROUTES } from './routes';
import { useDocumentTitle, useRouteFocus } from './title';
import { MUTED, TEXT_LINK } from './ui/classes';
import FreshnessBanner from './ui/FreshnessBanner';
import Page from './ui/Page';
import RootBoundary from './ui/RootBoundary';

/** The unknown path as a mono chip: only the pathname, breaking anywhere on a narrow screen. */
const PATH_CHIP = 'rounded-sm bg-surface-sunken px-1.5 py-0.5 font-mono text-sm break-all';

/**
 * Points index.html's canonical link at the page on screen: the site address the link is written
 * on, then the pathname without the query, so no page is declared a copy of the home page. Without
 * that link, or with one that is not an absolute address, it does nothing.
 */
function useCanonical(pathname: string): void {
  useEffect(() => {
    const link = document.head.querySelector('link[rel="canonical"]');
    if (link === null) return;
    let origin: string;
    try {
      origin = new URL(link.getAttribute('href') ?? '').origin;
    } catch {
      return;
    }
    link.setAttribute('href', `${origin}${pathname}`);
  }, [pathname]);
}

function Routes() {
  const { pathname, route } = useRoute();
  useDocumentTitle(ROUTES[route.name].title);
  useRouteFocus(pathname);
  switch (route.name) {
    case 'home':
      // The landing: the job in the h1, the source sentence, then the one task (search) and
      // the demo entry, example searches and well-known cards; the movers follow.
      return (
        <Page>
          <h1 className="text-2xl font-semibold sm:text-4xl">{HOME_H1}</h1>
          <p className={`mt-2 text-base ${MUTED}`}>
            Each price is a TCGplayer market price collected once a day through TCGCSV, shown with
            its source and the day it is as of.
          </p>
          <SignedOutLine />
          <SearchForm initial="" size="hero" />
          <DemoButton placement="hero" />
          <ExampleSearches />
          <CardTiles />
          <BiggestMoves />
        </Page>
      );
    case 'search':
      return <SearchPage query={route.query} />;
    case 'card':
      return <CardPage key={route.id} id={route.id} />;
    case 'movers':
      return <MoversPage direction={route.direction} />;
    case 'sign-in':
      return <SignInPage next={route.next} />;
    case 'sign-up':
      return <SignUpPage />;
    case 'forgot-password':
      return <ForgotPasswordPage />;
    case 'reset-password':
      return <ResetPasswordPage token={route.token} email={route.email} />;
    case 'account':
      return <AccountPage />;
    case 'verify-email':
      return <VerifyEmailPage key={route.link} link={route.link} />;
    case 'watchlist':
      return <WatchlistPage />;
    case 'portfolio':
      return <PortfolioPage />;
    case 'alerts':
      return <AlertsPage />;
    case 'about':
      return <AboutPage />;
    case 'not-found':
      // Designed not-found: the path in a chip, the search form as the primary
      // action, then the three ways on. It sends no request.
      return (
        <main className="mx-auto max-w-3xl px-6 pt-12 pb-6">
          <h1 className="text-2xl font-semibold">Page not found</h1>
          <p className="mt-2 text-base">
            PokéPrices has no page at <code className={PATH_CHIP}>{pathname}</code>.
          </p>
          <SearchForm initial="" size="hero" />
          <p className="mt-6 flex flex-wrap gap-x-6">
            <Link to="/search" className={TEXT_LINK}>
              Search
            </Link>
            <Link to="/movers" className={TEXT_LINK}>
              Movers
            </Link>
            <Link to="/" className={TEXT_LINK}>
              Go to the home page
            </Link>
          </p>
        </main>
      );
  }
}

/** The routes whose pages show a price: the stale-prices banner shows on these only. */
const PRICED_ROUTES: ReadonlySet<string> = new Set([
  'home',
  'search',
  'card',
  'movers',
  'watchlist',
  'portfolio',
]);

/** The one stale-prices banner, under the header, on a page that shows a price. */
/**
 * The canonical link's keeper, outside the root boundary, so a page that throws while it renders
 * still leaves the canonical on the address on screen. It renders nothing.
 */
function Canonical() {
  const { pathname } = useRoute();
  useCanonical(pathname);
  return null;
}

function StaleBanner() {
  const { route } = useRoute();
  return PRICED_ROUTES.has(route.name) ? <FreshnessBanner /> : null;
}

export default function App() {
  // One client per mount: every render(<App />) starts with an empty cache.
  const [queryClient] = useState(createQueryClient);
  return (
    <QueryClientProvider client={queryClient}>
      <Header />
      <Canonical />
      <StaleBanner />
      <div id="content" tabIndex={-1}>
        <RootBoundary>
          <Routes />
        </RootBoundary>
      </div>
      {/* After the page, so its own requests keep their order; with the cookie only. */}
      <PublicSession />
      <Footer />
    </QueryClientProvider>
  );
}
