import { useState } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import AccountPage from './account/AccountPage';
import AlertsPage from './account/AlertsPage';
import DemoButton from './account/DemoButton';
import ForgotPasswordPage from './account/ForgotPasswordPage';
import PortfolioPage from './account/PortfolioPage';
import ResetPasswordPage from './account/ResetPasswordPage';
import SignInPage from './account/SignInPage';
import SignUpPage from './account/SignUpPage';
import VerifyEmailPage from './account/VerifyEmailPage';
import WatchlistPage from './account/WatchlistPage';
import Footer from './Footer';
import Header from './Header';
import Link from './Link';
import CardPage from './pages/CardPage';
import { BiggestMoves } from './pages/MoverList';
import MoversPage from './pages/MoversPage';
import SearchForm from './pages/SearchForm';
import SearchPage from './pages/SearchPage';
import { createQueryClient } from './query';
import { useRoute } from './router';
import { HOME_H1, ROUTES } from './routes';
import { useDocumentTitle, useRouteFocus } from './title';
import { MUTED, TEXT_LINK } from './ui/classes';
import Page from './ui/Page';
import RootBoundary from './ui/RootBoundary';

/** The unknown path as a mono chip: only the pathname, breaking anywhere on a narrow screen. */
const PATH_CHIP = 'rounded-sm bg-surface-sunken px-1.5 py-0.5 font-mono text-sm break-all';

function Routes() {
  const { pathname, route } = useRoute();
  useDocumentTitle(ROUTES[route.name].title);
  useRouteFocus(pathname);
  switch (route.name) {
    case 'home':
      // The landing (D2): the job in the h1, the source sentence, then the one task (search) and
      // the demo entry; the movers follow as supporting content.
      return (
        <Page>
          <h1 className="text-2xl font-semibold sm:text-4xl">{HOME_H1}</h1>
          <p className={`mt-2 text-base ${MUTED}`}>
            Pokémon card prices at the daily close. Every price shows its source and the date it is
            as of.
          </p>
          <SearchForm initial="" size="hero" />
          <DemoButton placement="hero" />
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

export default function App() {
  // One client per mount (D9): every render(<App />) starts with an empty cache.
  const [queryClient] = useState(createQueryClient);
  return (
    <QueryClientProvider client={queryClient}>
      <Header />
      <div id="content" tabIndex={-1}>
        <RootBoundary>
          <Routes />
        </RootBoundary>
      </div>
      <Footer />
    </QueryClientProvider>
  );
}
