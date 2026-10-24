import { useState, type ReactNode } from 'react';
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
import { useRoute, type Route } from './router';
import { pageTitle, useDocumentTitle, useRouteFocus, WORDMARK } from './title';
import { MUTED, TEXT_LINK } from './ui/classes';

const HOME_H1 = 'Pokémon card prices at the daily close';

/** The tab title of each route: its h1 and the wordmark. The card page titles itself. */
function routeTitle(route: Route): string | null {
  switch (route.name) {
    case 'home':
      return `${WORDMARK} · ${HOME_H1}`;
    case 'search':
      return pageTitle('Search cards');
    case 'card':
      return null;
    case 'movers':
      return pageTitle('Biggest moves');
    case 'sign-in':
      return pageTitle('Sign in');
    case 'sign-up':
      return pageTitle('Create an account');
    case 'forgot-password':
      return pageTitle('Reset your password');
    case 'reset-password':
      return pageTitle('Choose a new password');
    case 'account':
      return pageTitle('Account');
    case 'verify-email':
      return pageTitle('Verify your email');
    case 'watchlist':
      return pageTitle('Watchlist');
    case 'portfolio':
      return pageTitle('Portfolio');
    case 'alerts':
      return pageTitle('Alerts and webhooks');
    case 'not-found':
      return pageTitle('Page not found');
  }
}

function Page({ title, children }: { readonly title: string; readonly children: ReactNode }) {
  return (
    <main className="mx-auto max-w-3xl p-6">
      <h1 className="text-2xl font-semibold">{title}</h1>
      {children}
    </main>
  );
}

function Routes() {
  const { pathname, route } = useRoute();
  useDocumentTitle(routeTitle(route));
  useRouteFocus(pathname);
  switch (route.name) {
    case 'home':
      // The landing (D2): the job in the h1, the source sentence, then the one task (search) and
      // the demo entry; the movers follow as supporting content.
      return (
        <main className="mx-auto max-w-3xl p-6">
          <h1 className="text-2xl font-semibold sm:text-4xl">{HOME_H1}</h1>
          <p className={`mt-2 text-base ${MUTED}`}>
            Pokémon card prices at the daily close. Every price shows its source and the date it is
            as of.
          </p>
          <SearchForm initial="" size="hero" />
          <DemoButton placement="hero" />
          <BiggestMoves />
        </main>
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
      return (
        <Page title="Page not found">
          <p className="mt-2 text-base">PokéPrices has no page at {pathname}.</p>
          <p className="mt-4 text-base">
            <Link to="/" className={TEXT_LINK}>
              Go to the home page
            </Link>
          </p>
        </Page>
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
        <Routes />
      </div>
      <Footer />
    </QueryClientProvider>
  );
}
