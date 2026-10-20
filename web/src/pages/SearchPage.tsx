// The PokéPrices search page: /search?q=&page= over GET /v1/cards. The URL is the only search state.
import { useEffect, useState, type SubmitEvent } from 'react';
import Link from '../Link';
import { cardPath, navigate } from '../router';
import { searchCards, type ApiFailure, type ApiResult, type CardSearchPage } from '../api/client';
import { Button } from '../ui/Button';
import { FOCUS, MUTED, TEXT_LINK } from '../ui/classes';
import { Input } from '../ui/Field';
import Notice from '../ui/Notice';

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

function SearchForm({ initial }: { readonly initial: string }) {
  const [text, setText] = useState(initial);
  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    navigate(searchPath(text.trim(), 1));
  }
  return (
    <form role="search" onSubmit={submit} className="mt-4 flex flex-wrap items-end gap-3">
      <label className="flex min-w-0 grow flex-col gap-1 text-sm font-semibold">
        Card name
        <Input
          type="search"
          name="q"
          value={text}
          maxLength={200}
          autoComplete="off"
          onChange={(event) => {
            setText(event.target.value);
          }}
          className="font-normal"
        />
      </label>
      <Button variant="primary" type="submit">
        Search
      </Button>
    </form>
  );
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
  return (
    <nav aria-label="Result pages" className="mt-6 flex items-center gap-6 text-sm">
      {page > 1 && (
        <Link to={searchPath(q, previous)} className={TEXT_LINK}>
          Previous page
        </Link>
      )}
      <span>
        Page {page} of {pages}
      </span>
      {page < pages && (
        <Link to={searchPath(q, page + 1)} className={TEXT_LINK}>
          Next page
        </Link>
      )}
    </nav>
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
        <Notice tone="warn" as="p" className="mt-6">
          {DEGRADED_LINE}
        </Notice>
      )}
      <p role="status" className={`mt-6 text-sm ${MUTED}`}>
        {summary}
      </p>
      {data.length > 0 && (
        <ol aria-label="Search results" className="mt-2 divide-y divide-line-subtle">
          {data.map((card) => (
            <li key={card.id}>
              <Link
                to={cardPath(card.id)}
                className={`flex items-center gap-4 py-3 hover:bg-surface-sunken ${FOCUS}`}
              >
                <img
                  src={card.image_small}
                  alt=""
                  width={63}
                  height={88}
                  loading="lazy"
                  className="h-[88px] w-[63px] shrink-0 rounded-sm border border-line bg-surface-sunken object-cover"
                />
                <span className="min-w-0">
                  <span className="block text-lg font-semibold">{card.name}</span>
                  <span className={`mt-1 flex flex-wrap gap-x-4 text-sm ${MUTED}`}>
                    <span>{card.set_name}</span>
                    <span>No. {card.number}</span>
                    {card.rarity !== null && <span>{card.rarity}</span>}
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
