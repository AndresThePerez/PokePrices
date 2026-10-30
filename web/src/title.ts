// The PokéPrices shell's route hooks: the tab title of each page and the focus move to the new
// page's h1 after a pathname change. Nothing is announced: no live region, status or alert.
import { useEffect, useRef } from 'react';

export const WORDMARK = 'PokéPrices';

/** A page title: the page's h1, then the wordmark. */
export function pageTitle(h1: string): string {
  return `${h1} · ${WORDMARK}`;
}

/**
 * Sets document.title while the calling page shows (index.html keeps the bare wordmark); null
 * leaves it to a page that titles itself.
 */
export function useDocumentTitle(title: string | null): void {
  useEffect(() => {
    if (title !== null) document.title = title;
  }, [title]);
}

function routeHeading(): HTMLElement | null {
  return document.querySelector<HTMLElement>('#content main h1');
}

function focusHeading(h1: HTMLElement): void {
  h1.setAttribute('tabindex', '-1');
  h1.focus();
}

/**
 * After the pathname changes (navigate or Back/Forward), focus the new page's h1 (tabindex -1, so
 * it never joins the tab order). Not on first mount, and not on a query-only change. A page that
 * replaces its h1 once its data lands (the card: loading, then the name) loses focus to the body:
 * the hook then focuses the replacing h1 once, and only while focus is still on the body.
 */
export function useRouteFocus(pathname: string): void {
  const seen = useRef(pathname);
  useEffect(() => {
    if (seen.current === pathname) return undefined;
    seen.current = pathname;
    const first = routeHeading();
    if (first === null) return undefined;
    focusHeading(first);
    const content = document.getElementById('content');
    if (content === null) return undefined;
    const observer = new MutationObserver(() => {
      const next = routeHeading();
      if (next === null || next === first || document.activeElement !== document.body) return;
      observer.disconnect();
      focusHeading(next);
    });
    observer.observe(content, { childList: true, subtree: true });
    return () => {
      observer.disconnect();
    };
  }, [pathname]);
}
