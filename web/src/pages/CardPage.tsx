// The PokéPrices card page: /card/:id over GET /v1/cards/{id}. Every date and version it shows
// comes from the API response (R132), never from the request or render time.
import { useQuery } from '@tanstack/react-query';
import CardActions from '../account/CardActions';
import Link from '../Link';
import {
  getCard,
  getCardPrices,
  type ApiFailure,
  type Card,
  type CardDetail,
  type CardPrices,
  type PricePoint,
} from '../api/client';
import Sparkline from '../chart/Sparkline';
import { PRICE_FOOTNOTE, changeLine, grouped, variantLabel } from '../format';
import { unwrap } from '../query';
import PriceHistory from './PriceHistory';

const DEGRADED_LINE = 'Catalog data may be out of date: PokéSearch is unavailable';
const CATALOG_MISSING_LINE = 'This card is no longer in the PokéSearch catalog';
const CARD_NOT_FOUND = '/problems/card-not-found';
const FOCUS =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 dark:focus-visible:outline-sky-400';
/** Muted text, readable in both themes. */
const MUTED = 'text-slate-600 dark:text-slate-400';
const FAINT = 'text-slate-500 dark:text-slate-400';

/** An RFC 3339 timestamp: date, time, optional fraction, then Z or a +hh:mm / -hh:mm offset. */
const RFC3339 =
  /^(\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01]))T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|([+-])(\d{2}):([0-5]\d))$/;

/**
 * The UTC calendar day of an RFC 3339 timestamp from the API, or null for anything else. Only a
 * strict timestamp is read (a lenient Date.parse turns "12" into a date the API never gave), and
 * a calendar day that does not exist (2026-02-30) is refused rather than rolled over.
 */
function utcDay(timestamp: string | null): string | null {
  const match = timestamp === null ? null : RFC3339.exec(timestamp);
  if (match === null || timestamp === null) return null;
  const ms = Date.parse(timestamp);
  if (Number.isNaN(ms)) return null;
  const sign = match[2] === '-' ? -1 : 1;
  const offsetMinutes =
    match[2] === undefined ? 0 : sign * (Number(match[3]) * 60 + Number(match[4]));
  const writtenDay = new Date(ms + offsetMinutes * 60_000).toISOString().slice(0, 10);
  if (writtenDay !== match[1]) return null;
  return new Date(ms).toISOString().slice(0, 10);
}

/** Which PokéSearch dataset answered: its seed ref and the day it was indexed. */
function catalogLine(catalog: Card['catalog']): string {
  if (catalog.seed_ref === null) return 'catalog version unknown';
  const dataset = `Catalog: PokéSearch dataset ${catalog.seed_ref.slice(0, 7)}`;
  const day = utcDay(catalog.seeded_at);
  return day === null ? dataset : `${dataset}, indexed ${day} (UTC)`;
}

type Variant = Card['variants'][number];
type Source = Variant['sources'][number];

function sourceLine(source: Source): string {
  return `TCGplayer product ${String(source.product_id)} (${source.subtype}) via TCGCSV, mapped from the collected snapshot of ${source.map_snapshot_day}`;
}

/** The host an image URL points at, for the attribution; null when the URL does not parse. */
function imageHost(url: string): string | null {
  try {
    const host = new URL(url).host;
    return host === '' ? null : host;
  } catch {
    return null;
  }
}

type Price = NonNullable<Variant['price']>;

/** A point as the history answer must carry it; anything else draws no sparkline. */
function isPoint(value: unknown): value is PricePoint {
  if (typeof value !== 'object' || value === null) return false;
  const point = value as Partial<Record<keyof PricePoint, unknown>>;
  return (
    typeof point.day === 'string' &&
    typeof point.close === 'string' &&
    Number.isFinite(Number(point.close)) &&
    typeof point.is_filled === 'boolean' &&
    typeof point.series_epoch === 'number'
  );
}

/** The 1M points of one variant from the history answer; none when the answer is malformed. */
function pointsOf(history: CardPrices | undefined, variantKey: string): readonly PricePoint[] {
  const raw: unknown = history;
  if (typeof raw !== 'object' || raw === null) return [];
  const data: unknown = (raw as { data?: unknown }).data;
  if (!Array.isArray(data)) return [];
  const series: unknown = data.find(
    (one: unknown) =>
      typeof one === 'object' &&
      one !== null &&
      (one as { variant_key?: unknown }).variant_key === variantKey,
  );
  const points: unknown = (series as { points?: unknown } | undefined)?.points;
  if (!Array.isArray(points) || !points.every(isPoint)) return [];
  return points;
}

function VariantPrice({
  price,
  points,
}: {
  readonly price: Price;
  readonly points: readonly PricePoint[];
}) {
  const change = changeLine(price);
  return (
    <>
      <div className="mt-1 flex flex-wrap items-center gap-x-3">
        <span className="block text-xl font-semibold tabular-nums">
          {`Market price $${grouped(price.close)}`}
        </span>
        <Sparkline points={points} />
      </div>
      <span className={`block text-sm tabular-nums ${change.tone}`}>{change.text}</span>
      <span className={`block text-sm ${MUTED}`}>
        {`as of ${price.day} from TCGCSV (daily close)`}
      </span>
    </>
  );
}

/**
 * Each variant's newest daily close, or "No prices yet" while no variant of the card has one.
 * Every value is the API's: no date or money arithmetic happens here.
 */
function Prices({ id, variants }: { readonly id: string; readonly variants: Card['variants'] }) {
  if (!variants.some((variant) => variant.price !== null)) {
    return <p className="mt-2">No prices yet</p>;
  }
  return <PricedVariants id={id} variants={variants} />;
}

/**
 * The priced list with a sparkline beside each priced variant (D12). The 1M history query is the
 * chart's default one (same key), so the page asks for it once; nothing is drawn while it loads
 * or after it fails.
 */
function PricedVariants({
  id,
  variants,
}: {
  readonly id: string;
  readonly variants: Card['variants'];
}) {
  const history = useQuery<CardPrices, ApiFailure>({
    queryKey: ['card-prices', id, '1M'],
    queryFn: ({ signal }) => unwrap(getCardPrices(id, '1M', { signal })),
  });
  return (
    <>
      <ul className="mt-2 space-y-4">
        {variants.map((variant) => (
          <li key={variant.variant_key}>
            <span className="block font-medium capitalize">{variantLabel(variant)}</span>
            {variant.price === null ? (
              <span className={`block text-sm ${MUTED}`}>No price in the collected snapshots</span>
            ) : (
              <VariantPrice
                price={variant.price}
                points={pointsOf(history.data, variant.variant_key)}
              />
            )}
            <CardActions cardId={id} variant={variant} />
          </li>
        ))}
      </ul>
      <p className={`mt-4 text-xs leading-snug ${FAINT}`}>{PRICE_FOOTNOTE}</p>
    </>
  );
}

function CardImage({ card }: { readonly card: Card }) {
  const src = card.images.large !== '' ? card.images.large : card.images.small;
  if (src === '') return null;
  const host = imageHost(src);
  return (
    <figure className="mx-auto w-full max-w-60 sm:mx-0">
      <img
        src={src}
        alt={`${card.name}, ${card.set.name} No. ${card.number}`}
        width={245}
        height={342}
        className="aspect-[63/88] w-full rounded-[14px] border-[10px] border-[#f2c230] bg-slate-100 object-cover shadow-[0_22px_40px_-20px_rgba(15,23,42,0.6)] dark:bg-slate-800"
      />
      {host !== null && (
        <figcaption className={`mt-3 text-xs leading-snug ${FAINT}`}>
          {`Card image from ${host}, linked as the PokéSearch catalog lists it.`}
        </figcaption>
      )}
    </figure>
  );
}

function Mapping({ variants }: { readonly variants: Card['variants'] }) {
  if (variants.length === 0) return <p className="mt-2">Not yet mapped</p>;
  return (
    <ul className="mt-2 space-y-3">
      {variants.map((variant) => (
        <li key={variant.variant_key}>
          <span className="block font-medium capitalize">{variantLabel(variant)}</span>
          {variant.sources.length === 0 ? (
            <span className={`block text-sm ${MUTED}`}>Not yet mapped</span>
          ) : (
            variant.sources.map((source) => (
              <span
                key={`${source.source} ${String(source.product_id)} ${source.subtype}`}
                className={`block text-sm ${MUTED}`}
              >
                {sourceLine(source)}
              </span>
            ))
          )}
        </li>
      ))}
    </ul>
  );
}

function Loaded({ body }: { readonly body: CardDetail }) {
  const card = body.data;
  return (
    <>
      {body.meta.degraded && (
        <p className="mb-6 border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-amber-950 dark:border-amber-400 dark:bg-amber-950 dark:text-amber-100">
          {DEGRADED_LINE}
        </p>
      )}
      <article className="grid gap-8 sm:grid-cols-[15rem_minmax(0,1fr)]">
        <CardImage card={card} />
        <div className="min-w-0">
          <h1 className="text-3xl font-semibold">{card.name}</h1>
          <p className={`mt-2 flex flex-wrap gap-x-4 ${MUTED}`}>
            <span>{card.set.name}</span>
            <span>No. {card.number}</span>
            {card.rarity !== null && <span>{card.rarity}</span>}
          </p>
          <section aria-labelledby="card-prices" className="mt-8">
            <h2 id="card-prices" className="text-lg font-semibold">
              Prices
            </h2>
            <Prices id={card.id} variants={card.variants} />
          </section>
          <section aria-labelledby="card-products" className="mt-8">
            <h2 id="card-products" className="text-lg font-semibold">
              TCGplayer products
            </h2>
            <Mapping variants={card.variants} />
          </section>
          <p
            className={`mt-8 border-t border-slate-200 pt-4 text-sm dark:border-slate-700 ${MUTED}`}
          >
            {catalogLine(card.catalog)}
          </p>
        </div>
      </article>
      {card.variants.some((variant) => variant.price !== null) && <PriceHistory id={card.id} />}
    </>
  );
}

function NotFound({ failure }: { readonly failure: ApiFailure }) {
  return (
    <>
      <h1 className="text-3xl font-semibold">Card not found</h1>
      {failure.problem?.catalog_missing === true && (
        <p className="mt-2 text-base">{CATALOG_MISSING_LINE}</p>
      )}
      <p className="mt-4 text-base">
        <Link
          to="/search"
          className={`inline-flex min-h-11 items-center underline underline-offset-4 ${FOCUS}`}
        >
          Search cards
        </Link>
      </p>
    </>
  );
}

function Failed({ id, failure }: { readonly id: string; readonly failure: ApiFailure }) {
  return (
    <>
      <h1 className="text-3xl font-semibold">{`Card ${id}`}</h1>
      <div
        role="alert"
        className="mt-6 border-l-4 border-red-700 bg-red-50 px-4 py-3 text-red-950 dark:border-red-400 dark:bg-red-950 dark:text-red-100"
      >
        <p className="font-semibold">Could not load this card: {failure.title}</p>
        {failure.requestId !== null && (
          <p className="mt-1 text-sm">
            Request ID <code className="break-all">{failure.requestId}</code>
          </p>
        )}
      </div>
    </>
  );
}

function isCardNotFound(failure: ApiFailure): boolean {
  return failure.status === 404 && failure.type.endsWith(CARD_NOT_FOUND);
}

export default function CardPage({ id }: { readonly id: string }) {
  // Polled every five minutes while the tab is visible (D9). A failed poll keeps the last good
  // answer on screen: the failure only shows when there is no card to show.
  const query = useQuery<CardDetail, ApiFailure>({
    queryKey: ['card', id],
    queryFn: ({ signal }) => unwrap(getCard(id, { signal })),
  });

  let body;
  if (query.data !== undefined) {
    body = <Loaded body={query.data} />;
  } else if (query.error !== null) {
    body = isCardNotFound(query.error) ? (
      <NotFound failure={query.error} />
    ) : (
      <Failed id={id} failure={query.error} />
    );
  } else {
    body = (
      <>
        <h1 className="text-3xl font-semibold">{`Card ${id}`}</h1>
        <p role="status" className={`mt-6 text-sm ${MUTED}`}>
          {`Loading card ${id}…`}
        </p>
      </>
    );
  }

  return <main className="mx-auto max-w-3xl p-6">{body}</main>;
}
