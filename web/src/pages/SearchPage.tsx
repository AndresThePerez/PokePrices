// The PokéPrices search page: /search?q=&sort=&page= over GET /v1/cards. The URL is the only
// search state. Each row shows its card's headline close (the first priced variant in card order,
// as the API picks it) in the short-row form, under the list's one source-and-"as of" stamp, and
// links to the card page opened on that variant.
import { useEffect, useId, useRef, type MouseEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Link from '../Link';
import { cardPath, navigate, replaceRoute } from '../router';
import {
  searchCards,
  type ApiFailure,
  type CardSearchPage,
  type CardSummary,
  type SearchSort,
} from '../api/client';
import { PRICE_FOOTNOTE, daysText, priceOrderText, variantLabel } from '../format';
import { unwrap } from '../query';
import { searchKey } from '../queryKeys';
import { cardVariantPath } from '../routes';
import { Button } from '../ui/Button';
import { FOCUS, MUTED, SECONDARY_BUTTON } from '../ui/classes';
import CardThumbnail from '../ui/CardThumbnail';
import { RowPrice } from '../ui/PriceTag';
import { rowPriceIds } from '../ui/rowPriceIds';
import StatusLine from '../ui/StatusLine';
import DegradedNotice from '../ui/DegradedNotice';
import Page from '../ui/Page';
import ProblemNotice from '../ui/ProblemNotice';
import SearchForm from './SearchForm';

/** What a row without a priced variant says in place of a price. */
const NO_PRICE = 'No price in the collected snapshots';

/** The orders, in the order the control offers them. */
const ORDERS: readonly { readonly sort: SearchSort; readonly label: string }[] = [
  { sort: 'relevance', label: 'Best match' },
  { sort: 'price_desc', label: 'Price: high to low' },
  { sort: 'price_asc', label: 'Price: low to high' },
];

/** A page number from the URL; anything that is not a positive integer reads as page 1. */
function readPage(raw: string | null): number {
  return raw !== null && /^[1-9][0-9]{0,8}$/.test(raw) ? Number(raw) : 1;
}

/** An order from the URL; anything that is not one of the three reads as relevance. */
function readSort(raw: string | null): SearchSort {
  return raw === 'price_desc' || raw === 'price_asc' ? raw : 'relevance';
}

/**
 * The in-app URL of a search; page 1 and the relevance order stay implicit, so they share one URL
 * with the plain query.
 */
function searchPath(q: string, page: number, sort: SearchSort): string {
  const params = new URLSearchParams({ q });
  if (sort !== 'relevance') params.set('sort', sort);
  if (page > 1) params.set('page', String(page));
  return `/search?${params.toString()}`;
}

/**
 * The address's query string with its page set to `page` and nothing else touched: every other
 * parameter keeps its place and its bytes. Page 1 stays implicit, as searchPath writes it.
 */
function withPage(search: string, page: number): string {
  let placed = false;
  const parts = search
    .replace(/^\?/, '')
    .split('&')
    .flatMap((part) => {
      const key = part.split('=', 1)[0] ?? '';
      let name = key;
      try {
        name = decodeURIComponent(key.replace(/\+/g, ' '));
      } catch {
        // A key that does not decode is not page: kept as it is.
      }
      if (name !== 'page') return [part];
      if (placed || page <= 1) return [];
      placed = true;
      return [`page=${String(page)}`];
    });
  return parts.length === 0 ? '' : `?${parts.join('&')}`;
}

/** A plain click: the one Link handles in place (a modified click opens a tab instead). */
function plainClick(event: MouseEvent<HTMLAnchorElement>): boolean {
  return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
}

/**
 * What the page shows for an answer whose page lies outside its own pages: the same problem block
 * as for any answer that is not the documented shape, never a "Page X of Y" past its end.
 */
const INCONSISTENT_ANSWER: ApiFailure = {
  ok: false,
  status: 200,
  type: 'about:blank',
  title: 'The PokéPrices API sent an unexpected answer (HTTP 200)',
  requestId: null,
  problem: null,
};

/** An answer that names a page outside its own pages (a result with matches has pages 1 and up). */
function outsideItsPages(meta: CardSearchPage['meta']): boolean {
  return meta.pages >= 1 && (meta.page < 1 || meta.page > meta.pages);
}

/** The API's 422 message on q (the word cap), if that is what the failure is. */
function queryError(failure: ApiFailure | null): string | undefined {
  return failure?.status === 422 ? failure.problem?.errors?.q?.[0] : undefined;
}

function Problem({ failure }: { readonly failure: ApiFailure }) {
  return <ProblemNotice context="Search failed" failure={failure} className="mt-6" />;
}

function Pager({
  q,
  sort,
  page,
  pages,
  onTurn,
}: {
  readonly q: string;
  readonly sort: SearchSort;
  readonly page: number;
  readonly pages: number;
  /** Called on a plain click of either link, before the page changes. */
  readonly onTurn: () => void;
}) {
  if (pages <= 1 && page <= 1) return null;
  const turn = (event: MouseEvent<HTMLAnchorElement>): void => {
    if (plainClick(event)) onTurn();
  };
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
          <Link
            to={searchPath(q, previous, sort)}
            onClick={turn}
            className={`${SECONDARY_BUTTON} whitespace-nowrap`}
          >
            Previous page
          </Link>
        )}
      </div>
      <span className="num text-sm whitespace-nowrap">
        Page {page} of {pages}
      </span>
      <div className="flex justify-end">
        {page < pages && (
          <Link
            to={searchPath(q, page + 1, sort)}
            onClick={turn}
            className={`${SECONDARY_BUTTON} whitespace-nowrap`}
          >
            Next page
          </Link>
        )}
      </div>
    </nav>
  );
}

/** The three orders as one pressed-segment group; a choice goes back to page 1 of that order. */
function OrderControl({ q, sort }: { readonly q: string; readonly sort: SearchSort }) {
  return (
    <div role="group" aria-label="Order" className="mt-4 flex flex-wrap gap-2">
      {ORDERS.map((order) => (
        <Button
          key={order.sort}
          variant="segment"
          pressed={order.sort === sort}
          onClick={() => {
            navigate(searchPath(q, 1, order.sort));
          }}
        >
          {order.label}
        </Button>
      ))}
    </div>
  );
}

/** The first and last close days of the priced rows, or null when no row has a price. */
function priceDays(
  rows: readonly CardSummary[],
): { readonly first: string; readonly last: string } | null {
  const days = rows.flatMap((row) => (row.price === null ? [] : [row.price.day])).sort();
  const first = days[0];
  const last = days[days.length - 1];
  return first === undefined || last === undefined ? null : { first, last };
}

/**
 * One result: the card's picture, then its name, set, number and rarity, and the headline price
 * (the variant's name over the price and the short change) or the no-price line, all one link to
 * the card page opened on the priced variant. The row's accessible name is the name lines, the
 * variant, the full "Market price" and change lines and the row's own "as of" line.
 */
function ResultRow({ card, showDay }: { readonly card: CardSummary; readonly showDay: boolean }) {
  const id = useId();
  const { price, variant } = card;
  const priced = price !== null && variant !== null;
  const label = priced ? variantLabel(variant) : '';
  const priceIds = priced ? `${id}-variant ${rowPriceIds(id, true)}` : `${id}-missing`;
  return (
    <li aria-labelledby={`${id}-name ${id}-set ${id}-number ${priceIds}`}>
      <Link
        to={priced ? cardVariantPath(card.id, variant.variant_key) : cardPath(card.id)}
        className={`flex min-h-18 items-center gap-3 px-2 py-2 hover:bg-surface-sunken after:ml-auto after:shrink-0 after:pl-1 after:text-lg after:text-ink-muted after:[content:'›'_/_''] ${FOCUS}`}
      >
        <CardThumbnail src={card.image_small} name={card.name} />
        <span className="grid min-w-0 flex-1 gap-x-4 wrap-anywhere sm:grid-cols-[minmax(0,1fr)_minmax(9rem,14rem)]">
          <span className="min-w-0">
            <span id={`${id}-name`} className="block text-base font-semibold">
              {card.name}
            </span>
            <span id={`${id}-set`} className={`block text-sm ${MUTED}`}>
              {card.set_name}
            </span>
            <span id={`${id}-number`} className={`block text-sm ${MUTED}`}>
              {card.rarity === null ? `No. ${card.number}` : `No. ${card.number} · ${card.rarity}`}
            </span>
          </span>
          <span className="mt-1 block min-w-0 sm:mt-0 sm:text-right">
            {priced ? (
              <>
                <span id={`${id}-variant`} className={`block text-sm ${MUTED}`}>
                  {label.charAt(0).toUpperCase() + label.slice(1)}
                </span>
                <span className="flex flex-col sm:items-end">
                  <RowPrice price={price} id={id} day={price.day} showDay={showDay} />
                </span>
              </>
            ) : (
              <span id={`${id}-missing`} className={`block text-meta ${MUTED}`}>
                {NO_PRICE}
              </span>
            )}
          </span>
        </span>
      </Link>
    </li>
  );
}

function Results({
  q,
  sort,
  body,
  onTurn,
}: {
  readonly q: string;
  readonly sort: SearchSort;
  readonly body: CardSearchPage;
  readonly onTurn: () => void;
}) {
  const { data, meta } = body;
  let summary = `${String(meta.total)} ${meta.total === 1 ? 'card matches' : 'cards match'} “${q}”`;
  if (data.length === 0) {
    summary =
      meta.page > 1
        ? `Page ${String(meta.page)} has no cards for “${q}”.`
        : `No cards match “${q}”. Check the spelling or try fewer words.`;
  }
  // One stamp for the list: its one day, or the first and last day when the rows differ (each row
  // then prints its own day under its price).
  const days = priceDays(data);
  return (
    <>
      {meta.degraded && <DegradedNotice className="mt-4" />}
      <p role="status" className="mt-4 text-base font-semibold text-ink">
        {summary}
      </p>
      {sort !== 'relevance' && meta.ordered !== null && (
        <p className={`mt-1 text-sm ${MUTED}`}>{priceOrderText(meta.ordered, meta.total)}</p>
      )}
      {days !== null && (
        <p className={`mt-2 text-meta ${MUTED}`}>{daysText(days.first, days.last)}</p>
      )}
      {data.length > 0 && (
        <ol aria-label="Search results" className="mt-4 divide-y divide-line-subtle">
          {data.map((card) => (
            <ResultRow
              key={card.id}
              card={card}
              showDay={days !== null && days.first !== days.last}
            />
          ))}
        </ol>
      )}
      {days !== null && <p className={`mt-4 text-meta ${MUTED}`}>{PRICE_FOOTNOTE}</p>}
      <Pager q={q} sort={sort} page={meta.page} pages={meta.pages} onTurn={onTurn} />
    </>
  );
}

export default function SearchPage({ query }: { readonly query: URLSearchParams }) {
  const q = (query.get('q') ?? '').trim();
  const page = readPage(query.get('page'));
  const sort = readSort(query.get('sort'));
  // One cache entry per query, page and order (polling, five minutes while visible): a revisited
  // search answers from the cache, and a failed poll keeps the last good answer on screen.
  const search = useQuery<CardSearchPage, ApiFailure>({
    queryKey: searchKey(q, page, sort),
    queryFn: ({ signal }) =>
      unwrap(
        searchCards(
          { q, ...(page > 1 ? { page } : {}), ...(sort === 'relevance' ? {} : { sort }) },
          { signal },
        ),
      ),
    enabled: q !== '',
  });
  const wordCap = search.data === undefined ? queryError(search.error) : undefined;
  const client = useQueryClient();
  const answer = search.data;
  // An address past the last page shows the last page the API answered: the address is corrected
  // in place (a replace, so Back never returns to it), only its page changes, and the answer is
  // kept as that page's, so the correction asks for nothing more. An answer whose page lies outside
  // its own pages is the problem block instead, and corrects nothing.
  useEffect(() => {
    if (answer === undefined) return;
    const { meta } = answer;
    if (meta.pages < 1 || outsideItsPages(meta) || page <= meta.pages || meta.page >= page) return;
    client.setQueryData(searchKey(q, meta.page, sort), answer);
    replaceRoute(`${window.location.pathname}${withPage(window.location.search, meta.page)}`);
  }, [answer, client, page, q, sort]);
  // A page change moves keyboard focus to the heading, which stays while the next page loads (the
  // link that was clicked does not).
  const heading = useRef<HTMLHeadingElement>(null);
  const onTurn = (): void => {
    heading.current?.focus();
  };

  let body;
  if (q === '') {
    body = <p className={`mt-6 ${MUTED}`}>Type a card name, then press Search.</p>;
  } else if (search.data !== undefined && outsideItsPages(search.data.meta)) {
    body = <Problem failure={INCONSISTENT_ANSWER} />;
  } else if (search.data !== undefined) {
    body = <Results q={q} sort={sort} body={search.data} onTurn={onTurn} />;
  } else if (wordCap !== undefined) {
    body = null;
  } else if (search.error !== null) {
    body = <Problem failure={search.error} />;
  } else {
    body = <StatusLine gap="mt-6">Searching for “{q}”…</StatusLine>;
  }

  // While searching, the page reserves one viewport (as the card page and the movers board do): a
  // full page of results is taller than that, so the footer starts below the fold and the rows
  // push nothing on screen when they arrive. Every other state keeps its own height.
  const searching = q !== '' && search.data === undefined && search.error === null;
  return (
    <Page reserve={searching}>
      <h1 ref={heading} tabIndex={-1} className="text-2xl font-semibold">
        Search cards
      </h1>
      <SearchForm key={q} initial={q} error={wordCap} />
      {q !== '' && <OrderControl q={q} sort={sort} />}
      {body}
    </Page>
  );
}
