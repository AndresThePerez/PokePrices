// The PokéPrices search page: /search?q=&page= over GET /v1/cards. The URL is the only search state.
import { useEffect, useState } from 'react';
import Link from '../Link';
import { cardPath } from '../router';
import { searchCards, type ApiFailure, type ApiResult, type CardSearchPage } from '../api/client';
import { initialOf } from '../format';
import { FOCUS, MUTED, SECONDARY_BUTTON } from '../ui/classes';
import Notice from '../ui/Notice';
import SearchForm from './SearchForm';

const DEGRADED_LINE = 'Catalog data may be out of date: PokéSearch is unavailable';

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
  return (
    <Notice tone="problem" role="alert" className="mt-6">
      <p className="font-semibold">Search failed: {failure.title}</p>
      {failure.requestId !== null && (
        <p className="mt-1 text-sm">
          Request ID <code className="break-all">{failure.requestId}</code>
        </p>
      )}
    </Notice>
  );
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
  if (card.image_small === '') {
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
      {meta.degraded && (
        <Notice tone="warn" as="p" className="mt-4">
          {DEGRADED_LINE}
        </Notice>
      )}
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

interface Settled {
  readonly key: string;
  readonly result: ApiResult<CardSearchPage>;
}

export default function SearchPage({ query }: { readonly query: URLSearchParams }) {
  const q = (query.get('q') ?? '').trim();
  const page = readPage(query.get('page'));
  const key = `${String(page)} ${q}`;
  const [settled, setSettled] = useState<Settled | null>(null);

  useEffect(() => {
    if (q === '') return;
    const controller = new AbortController();
    searchCards(page > 1 ? { q, page } : { q }, { signal: controller.signal }).then(
      (result) => {
        setSettled({ key, result });
      },
      () => {
        // Aborted: a newer search (or leaving the page) superseded this one.
      },
    );
    return () => {
      controller.abort();
    };
  }, [q, page, key]);

  let body;
  if (q === '') {
    body = <p className={`mt-6 ${MUTED}`}>Type a card name, then press Search.</p>;
  } else if (settled?.key !== key) {
    body = (
      <p role="status" className={`mt-6 text-sm ${MUTED}`}>
        Searching for “{q}”…
      </p>
    );
  } else if (!settled.result.ok) {
    body = <Problem failure={settled.result} />;
  } else {
    body = <Results q={q} body={settled.result.data} />;
  }

  return (
    <main className="mx-auto max-w-3xl p-6">
      <h1 className="text-2xl font-semibold">Search cards</h1>
      <SearchForm key={q} initial={q} />
      {body}
    </main>
  );
}
