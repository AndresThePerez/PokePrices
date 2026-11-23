// The one place a public price page (home, search, a card page, movers) learns the session after a
// reload: it asks GET /v1/me through the shared ['me'] query only when the browser already holds
// the app's CSRF cookie (holdsSessionCookie), which only a sign-in or account page, a sign-in or the
// demo sets, and only while the cache knows nothing yet (no ['me'] answer: a reload, a pasted
// link). A visitor without the cookie sends exactly what the page sent before, and browsing prices
// still sets no cookies; an answer the cache already holds, even a stale one, is left to the pages
// that show the session (no polling, no second ask after this tab's own sign-out). Signed in, the
// header shows the portfolio estimate and the card page reads the two lists; a 401 (an expired
// session) is the signed-out state, calmly. The sign-in and account pages ask for the session
// themselves.
// The rule itself (which routes, the cookie) is asksSession in sessionAsk.ts; the card page reads
// useSessionAwaited from there to hold its account controls until the answer comes.
import { useQuery } from '@tanstack/react-query';
import type { ApiFailure, Me } from '../api/client';
import { useRoute } from '../router';
import { ME_KEY, fetchMe } from './session';
import { asksSession } from './sessionAsk';

function Ask() {
  useQuery<Me | null, ApiFailure>({
    queryKey: ME_KEY,
    queryFn: ({ signal }) => fetchMe({ signal }),
    // Only while there is no answer: once one is cached, this observer is inactive.
    enabled: (query) => query.state.data === undefined,
  });
  return null;
}

export default function PublicSession() {
  const { route } = useRoute();
  return asksSession(route.name) ? <Ask /> : null;
}
