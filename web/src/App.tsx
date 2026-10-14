import { useState, type ReactNode } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import AccountPage from './account/AccountPage';
import AlertsPage from './account/AlertsPage';
import ForgotPasswordPage from './account/ForgotPasswordPage';
import PortfolioPage from './account/PortfolioPage';
import ResetPasswordPage from './account/ResetPasswordPage';
import SignInPage from './account/SignInPage';
import SignUpPage from './account/SignUpPage';
import VerifyEmailPage from './account/VerifyEmailPage';
import WatchlistPage from './account/WatchlistPage';
import Header from './Header';
import Link from './Link';
import CardPage from './pages/CardPage';
import { BiggestMoves } from './pages/MoverList';
import MoversPage from './pages/MoversPage';
import SearchPage from './pages/SearchPage';
import { createQueryClient } from './query';
import { useRoute } from './router';

function Page({ title, children }: { readonly title: string; readonly children: ReactNode }) {
  return (
    <main className="mx-auto max-w-3xl p-6">
      <h1 className="text-3xl font-semibold">{title}</h1>
      {children}
    </main>
  );
}

function Routes() {
  const { pathname, route } = useRoute();
  switch (route.name) {
    case 'home':
      return (
        <Page title="PokéPrices">
          <p className="mt-2 text-base">
            Pokémon card prices at the daily close. Every price shows its source and the date it is
            as of.
          </p>
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
      return (
        <Page title="Page not found">
          <p className="mt-2 text-base">PokéPrices has no page at {pathname}.</p>
          <p className="mt-4 text-base">
            <Link
              to="/"
              className="inline-flex min-h-11 items-center underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 dark:focus-visible:outline-sky-400"
            >
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
      <Routes />
    </QueryClientProvider>
  );
}
