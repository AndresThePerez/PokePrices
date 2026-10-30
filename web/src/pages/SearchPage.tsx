// The PokéPrices search page: /search?q=&page= over GET /v1/cards. The URL is the only search state.
import { useQuery } from '@tanstack/react-query';
import Link from '../Link';
import { cardPath } from '../router';
import { searchCards, type ApiFailure, type CardSearchPage } from '../api/client';
import { initialOf } from '../format';
import { unwrap } from '../query';
import { searchKey } from '../queryKeys';
import { FOCUS, MUTED, SECONDARY_BUTTON } from '../ui/classes';
import StatusLine from '../ui/StatusLine';
import DegradedNotice from '../ui/DegradedNotice';
import Page from '../ui/Page';
import ProblemNotice from '../ui/ProblemNotice';
import SearchForm from './SearchForm';

/** A page number from the URL; anything that is not a positive integer reads as page 1. */
function readPage(raw: string | null): number {
  return raw !== null && /^[1-9][0-9]{0,8}$/.test(raw) ? Number(raw) : 1;
}

/** The in-app URL of a search; page 1 stays implicit so it shares one URL with the plain query. */
function searchPath(q: string, page: number): string {
  const params = new URLSearchParams({ q });
  if (page > 1) params.set('page', String(page));
  return `/search?${params.toString()}`;
}

function Problem({ failure }: { readonly failure: ApiFailure }) {
  return <ProblemNotice context="Search failed" failure={failure} className="mt-6" />;
}

function Pager({
  q,
  page,
  pages,
}: {
  readonly q: string;
  readonly page: number;
  readonly pages: number;
}) {
  if (pages <= 1 && page <= 1) return null;
  const previous = Math.max(1, Math.min(page - 1, pages));
  // Three slots: Previous left, the page line centred, Next right; a missing link leaves its slot
  // empty so the centre never moves.
  return (
    <nav
      aria-label="Result pages"
      className="mt-6 grid grid-cols-[1fr_auto_1fr] items-center gap-2"
    >
      <div className="flex justify-start">
        {page > 1 && (
          <Link to={searchPath(q, previous)} className={`${SECONDARY_BUTTON} whitespace-nowrap`}>
            Previous page
          </Link>
        )}
      </div>
      <span className="num text-sm whitespace-nowrap">
        Page {page} of {pages}
      </span>
      <div className="flex justify-end">
        {page < pages && (
          <Link to={searchPath(q, page + 1)} className={`${SECONDARY_BUTTON} whitespace-nowrap`}>
            Next page
          </Link>
        )}
      </div>
    </nav>
  );
}

type SearchResult = CardSearchPage['data'][number];

const THUMB = 'aspect-[63/88] w-12 shrink-0 rounded-sm border border-line-subtle bg-surface-sunken';

/**
 * A result's 48 px thumbnail from the search answer's own image_small, or, when the API sent none,
 * a card-back placeholder whose initial CSS draws from data-initial (no DOM text, no request).
 */
function Thumbnail({ card }: { readonly card: SearchResult }) {
  if (card.image_small.trim() === '') {
    return (
      <span
        data-initial={initialOf(card.name)}
        className={`${THUMB} flex items-center justify-center text-base font-semibold ${MUTED} before:content-[attr(data-initial)]`}
      />
    );
  }
  return (
    <img
      src={card.image_small}
      alt=""
      width={63}
      height={88}
      loading="lazy"
      decoding="async"
      className={`${THUMB} h-auto object-cover`}
    />
  );
}

function Results({ q, body }: { readonly q: string; readonly body: CardSearchPage }) {
  const { data, meta } = body;
  let summary = `${String(meta.total)} ${meta.total === 1 ? 'card matches' : 'cards match'} “${q}”`;
  if (data.length === 0) {
    summary =
      meta.page > 1
        ? `Page ${String(meta.page)} has no cards for “${q}”.`
        : `No cards match “${q}”. Check the spelling or try fewer words.`;
  }
  return (
    <>
      {meta.degraded && <DegradedNotice className="mt-4" />}
      <p role="status" className="mt-4 text-base font-semibold text-ink">
        {summary}
      </p>
      {data.length > 0 && (
        <ol aria-label="Search results" className="mt-4 divide-y divide-line-subtle">
          {data.map((card) => (
            <li key={card.id}>
              <Link
                to={cardPath(card.id)}
                className={`flex min-h-18 items-center gap-3 px-2 py-2 hover:bg-surface-sunken after:ml-auto after:shrink-0 after:pl-1 after:text-lg after:text-ink-muted after:[content:'›'_/_''] ${FOCUS}`}
              >
                <Thumbnail card={card} />
                <span className="min-w-0 flex-1 wrap-anywhere">
                  <span className="block text-base font-semibold">{card.name}</span>
                  <span className={`block text-sm ${MUTED}`}>{card.set_name}</span>
                  <span className={`block text-sm ${MUTED}`}>
                    {card.rarity === null
                      ? `No. ${card.number}`
                      : `No. ${card.number} · ${card.rarity}`}
                  </span>
                </span>
              </Link>
            </li>
          ))}
        </ol>
      )}
      <Pager q={q} page={meta.page} pages={meta.pages} />
    </>
  );
}

export default function SearchPage({ query }: { readonly query: URLSearchParams }) {
  const q = (query.get('q') ?? '').trim();
  const page = readPage(query.get('page'));
  // One cache entry per query and page (polling, five minutes while visible): a revisited search
  // answers from the cache, and a failed poll keeps the last good answer on screen.
  const search = useQuery<CardSearchPage, ApiFailure>({
    queryKey: searchKey(q, page),
    queryFn: ({ signal }) => unwrap(searchCards(page > 1 ? { q, page } : { q }, { signal })),
    enabled: q !== '',
  });

  let body;
  if (q === '') {
    body = <p className={`mt-6 ${MUTED}`}>Type a card name, then press Search.</p>;
  } else if (search.data !== undefined) {
    body = <Results q={q} body={search.data} />;
  } else if (search.error !== null) {
    body = <Problem failure={search.error} />;
  } else {
    body = <StatusLine gap="mt-6">Searching for “{q}”…</StatusLine>;
  }

  return (
    <Page>
      <h1 className="text-2xl font-semibold">Search cards</h1>
      <SearchForm key={q} initial={q} />
      {body}
    </Page>
  );
}
