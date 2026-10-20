// The PokéPrices header on every route (D1): one 56 px bar with a skip link, the wordmark and the
// main links, the current page marked by aria-current, semibold and a 2 px accent underline. Under
// 640 px the links fold into a Menu sheet, hidden by CSS only. The display settings live in the
// footer. The header reads no session and sends no request.
import { useEffect, useId, useState } from 'react';
import Link from './Link';
import { useRoute, type Route } from './router';
import { Button } from './ui/Button';
import { FOCUS } from './ui/classes';

const LINKS = [
  { to: '/search', label: 'Search' },
  { to: '/movers', label: 'Movers' },
  { to: '/watchlist', label: 'Watchlist' },
  { to: '/portfolio', label: 'Portfolio' },
  { to: '/account', label: 'Account' },
] as const;

/** The path of the header link that names the current page, or null when none does. */
function currentPath(route: Route): string | null {
  switch (route.name) {
    case 'home':
      return '/';
    case 'search':
      return '/search';
    case 'movers':
      return '/movers';
    case 'watchlist':
      return '/watchlist';
    case 'portfolio':
      return '/portfolio';
    case 'account':
    case 'alerts':
      return '/account';
    default:
      return null;
  }
}

const SKIP =
  `absolute -top-24 left-4 z-20 inline-flex min-h-11 items-center rounded-md border border-line ` +
  `bg-surface-raised px-4 text-sm font-semibold text-ink focus:top-1.5 ${FOCUS}`;

const WORDMARK = `inline-flex min-h-11 items-center text-lg font-semibold text-ink underline-offset-8 ${FOCUS}`;

const NAV_LINK = `flex min-h-11 items-center text-base text-ink underline-offset-8 max-sm:min-h-12 ${FOCUS}`;

/** Marks the current page by weight and a 2 px accent underline, never by colour alone. */
const CURRENT = 'font-semibold underline decoration-accent decoration-2';

const LIST_OPEN =
  'absolute inset-x-0 top-14 z-10 flex flex-col border border-line bg-surface-raised px-4 py-1 shadow-panel';

const LIST_WIDE =
  'sm:static sm:z-auto sm:ml-6 sm:flex sm:flex-1 sm:flex-row sm:items-center sm:gap-6 ' +
  'sm:border-0 sm:bg-transparent sm:p-0 sm:shadow-none';

export default function Header() {
  const { route } = useRoute();
  const current = currentPath(route);
  const [open, setOpen] = useState(false);
  const menuId = useId();
  const listId = useId();

  useEffect(() => {
    if (!open) return undefined;
    function onKeyDown(event: KeyboardEvent): void {
      if (event.key !== 'Escape') return;
      setOpen(false);
      document.getElementById(menuId)?.focus();
    }
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open, menuId]);

  function close(): void {
    setOpen(false);
  }

  return (
    <header className="border-b border-line-subtle bg-surface">
      <div className="relative mx-auto flex h-14 max-w-3xl items-center px-4 sm:px-6">
        <a href="#content" className={SKIP}>
          Skip to content
        </a>
        <nav aria-label="Main" className="flex flex-1 items-center">
          <Link
            to="/"
            onClick={close}
            aria-current={current === '/' ? 'page' : undefined}
            className={current === '/' ? `${WORDMARK} ${CURRENT}` : WORDMARK}
          >
            PokéPrices
          </Link>
          <Button
            variant="quiet"
            id={menuId}
            aria-expanded={open}
            aria-controls={listId}
            onClick={() => {
              setOpen(!open);
            }}
            className="ml-auto sm:hidden"
          >
            Menu
          </Button>
          <ul id={listId} className={`${open ? LIST_OPEN : 'hidden'} ${LIST_WIDE}`}>
            {LINKS.map(({ to, label }) => (
              <li key={to} className={to === '/account' ? 'sm:ml-auto' : undefined}>
                <Link
                  to={to}
                  onClick={close}
                  aria-current={current === to ? 'page' : undefined}
                  className={
                    current === to ? `${NAV_LINK} ${CURRENT}` : `${NAV_LINK} hover:underline`
                  }
                >
                  {label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      </div>
    </header>
  );
}
