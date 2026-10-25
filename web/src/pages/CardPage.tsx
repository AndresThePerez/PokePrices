// The PokéPrices card page: /card/:id over GET /v1/cards/{id}. Every date and version it shows
// comes from the API response (R132), never from the request or render time.
import { useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
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
import { PRICE_FOOTNOTE, variantLabel } from '../format';
import { MUTED, TEXT_LINK } from '../ui/classes';
import Notice from '../ui/Notice';
import Panel from '../ui/Panel';
import PriceTag, { AsOf, ChangeChip, HeroPrice } from '../ui/PriceTag';
import Skeleton from '../ui/Skeleton';
import { unwrap } from '../query';
import { pageTitle, useDocumentTitle } from '../title';
import PriceHistory from './PriceHistory';

const DEGRADED_LINE = 'Catalog data may be out of date: PokéSearch is unavailable';
const CATALOG_MISSING_LINE = 'This card is no longer in the PokéSearch catalog';
const CARD_NOT_FOUND = '/problems/card-not-found';

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

/** The variant keys the history answer holds an eligible series for (history_begins set). */
function eligibleKeys(history: CardPrices | undefined): ReadonlySet<string> {
  const raw: unknown = history;
  const keys = new Set<string>();
  if (typeof raw !== 'object' || raw === null) return keys;
  const data: unknown = (raw as { data?: unknown }).data;
  if (!Array.isArray(data)) return keys;
  for (const one of data as unknown[]) {
    if (typeof one !== 'object' || one === null) continue;
    const series = one as { variant_key?: unknown; history_begins?: unknown; points?: unknown };
    if (
      typeof series.variant_key === 'string' &&
      typeof series.history_begins === 'string' &&
      Array.isArray(series.points)
    ) {
      keys.add(series.variant_key);
    }
  }
  return keys;
}

type PricedVariant = Variant & { readonly price: Price };

function isPriced(variant: Variant): variant is PricedVariant {
  return variant.price !== null;
}

/**
 * The headline: the chosen variant's figure, change chip and as-of line, led by its label line.
 * It sits outside the Prices region and carries no role, so the region's lines stay unique.
 */
function Hero({ variant }: { readonly variant: PricedVariant }) {
  return (
    <div>
      <p className="text-sm text-ink-muted">{`Market price · ${variantLabel(variant)}`}</p>
      <div className="mt-1 flex flex-col items-start sm:flex-row sm:flex-wrap sm:items-baseline sm:gap-x-4">
        <HeroPrice close={variant.price.close} />
        <ChangeChip price={variant.price} />
      </div>
      <AsOf day={variant.price.day} as="p" className="mt-1" />
    </div>
  );
}

/**
 * The price figure of a priced row takes the room the 96 px sparkline column leaves and wraps
 * inside it ("Market price" over "$X") when it is too long, so the sparkline never drops to a line
 * of its own: it sits at the same y, on the first price line, in every row (F15). PriceTag's
 * wrapper markup stays as it is (A1-3); only its figure span's flex sizing is set from here.
 */
const PRICE_WRAPS = '[&>div:first-of-type>span]:flex-1';
const ROW = 'py-6 first:pt-0 last:pb-0';

/** One variant of the Prices list: its lines, the 96 px sparkline column, the quiet actions. */
function VariantRow({
  id,
  variant,
  points,
}: {
  readonly id: string;
  readonly variant: Variant;
  readonly points: readonly PricePoint[];
}) {
  return (
    <li className={variant.price === null ? ROW : `${ROW} ${PRICE_WRAPS}`}>
      {variant.price === null ? (
        <span className="flex flex-wrap items-baseline gap-x-3 text-base text-ink-muted">
          <span className="font-semibold capitalize">{variantLabel(variant)}</span>
          <span className="text-meta">No price in the collected snapshots</span>
        </span>
      ) : (
        <>
          <span className="block text-base font-semibold capitalize">{variantLabel(variant)}</span>
          <PriceTag
            price={variant.price}
            size="variant"
            beside={
              <div className="flex w-24 shrink-0 justify-end self-start pt-2.5">
                <Sparkline points={points} />
              </div>
            }
          />
        </>
      )}
      <CardActions cardId={id} variant={variant} />
    </li>
  );
}

/**
 * The priced card: the hero, the Price history section and the Prices list, all driven by one
 * variant choice (D2 T01). The page owns the chosen key; the default is the first priced variant
 * with an eligible series, in card order, else the first priced variant (A1-4). The 1M history
 * query is the chart's default one (same key), so the page still asks for it once; it feeds the
 * sparklines and the default choice, and nothing is drawn while it loads or after it fails.
 */
function PricedCard({ card }: { readonly card: Card }) {
  const history = useQuery<CardPrices, ApiFailure>({
    queryKey: ['card-prices', card.id, '1M'],
    queryFn: ({ signal }) => unwrap(getCardPrices(card.id, '1M', { signal })),
  });
  const [chosen, setChosen] = useState<string | null>(null);
  const priced = card.variants.filter(isPriced);
  const eligible = eligibleKeys(history.data);
  const selected =
    priced.find((variant) => variant.variant_key === chosen) ??
    priced.find((variant) => eligible.has(variant.variant_key)) ??
    priced[0];
  // Priced rows first, then the unpriced ones, each group in card order (RULING m).
  const rows = [...priced, ...card.variants.filter((variant) => variant.price === null)];
  return (
    <>
      {selected !== undefined && <Hero variant={selected} />}
      <PriceHistory
        id={card.id}
        chosen={selected?.variant_key ?? null}
        onChoose={setChosen}
        priced={priced.map((variant) => variant.variant_key)}
      />
      <PricesSection>
        <ul className="mt-4 divide-y divide-line-subtle">
          {rows.map((variant) => (
            <VariantRow
              key={variant.variant_key}
              id={card.id}
              variant={variant}
              points={pointsOf(history.data, variant.variant_key)}
            />
          ))}
        </ul>
      </PricesSection>
    </>
  );
}

function PricesSection({ children }: { readonly children: ReactNode }) {
  return (
    <section aria-labelledby="card-prices" className="mt-10">
      <h2 id="card-prices" className="text-lg font-semibold">
        Prices
      </h2>
      {children}
    </section>
  );
}

function initialOf(name: string): string {
  const code = name.trim().codePointAt(0);
  return code === undefined ? '' : String.fromCodePoint(code).toUpperCase();
}

/** The card image in a 6 px mat, or the neutral card-back with the name's initial (CSS only). */
function CardImage({ card }: { readonly card: Card }) {
  const src = card.images.large !== '' ? card.images.large : card.images.small;
  const place = 'col-start-1 row-start-1 self-start sm:sticky sm:top-4';
  if (src === '') {
    return (
      <div
        data-initial={initialOf(card.name)}
        className={`${place} flex aspect-[63/88] w-full items-center justify-center rounded-lg border border-line bg-surface-sunken text-2xl font-semibold text-ink-muted before:content-[attr(data-initial)] sm:text-4xl`}
      />
    );
  }
  return (
    <figure
      className={`${place} rounded-lg border border-line bg-surface-raised p-1 shadow-panel sm:p-2`}
    >
      <img
        src={src}
        alt={`${card.name}, ${card.set.name} No. ${card.number}`}
        width={245}
        height={342}
        className="block aspect-[63/88] h-auto w-full rounded-md bg-surface-sunken object-cover"
      />
    </figure>
  );
}

/**
 * Provenance, tier 2 (F10): the once-a-day line when a price shows, each variant's product lines,
 * the catalog line and the image credit, every string verbatim and in that order.
 */
function Sources({ card }: { readonly card: Card }) {
  const credit = imageHost(card.images.large !== '' ? card.images.large : card.images.small);
  return (
    <Panel as="section" aria-labelledby="card-sources" className="mt-10 text-meta">
      <h2 id="card-sources" className="text-sm font-semibold">
        Sources
      </h2>
      {card.variants.some((variant) => variant.price !== null) && (
        <p className="mt-2">{PRICE_FOOTNOTE}</p>
      )}
      {card.variants.length === 0 ? (
        <p className={`mt-3 ${MUTED}`}>Not yet mapped</p>
      ) : (
        <dl className="mt-3 space-y-2">
          {card.variants.map((variant) => (
            <div
              key={variant.variant_key}
              className="sm:grid sm:grid-cols-[8rem_minmax(0,1fr)] sm:gap-x-4"
            >
              <dt className="font-semibold capitalize">{variantLabel(variant)}</dt>
              {variant.sources.length === 0 ? (
                <dd className={`sm:col-start-2 ${MUTED}`}>Not yet mapped</dd>
              ) : (
                variant.sources.map((source) => (
                  <dd
                    key={`${source.source} ${String(source.product_id)} ${source.subtype}`}
                    className={`sm:col-start-2 ${MUTED}`}
                  >
                    {sourceLine(source)}
                  </dd>
                ))
              )}
            </div>
          ))}
        </dl>
      )}
      <p className={`mt-3 border-t border-line-subtle pt-3 ${MUTED}`}>
        {catalogLine(card.catalog)}
      </p>
      {credit !== null && (
        <p className={`mt-1 ${MUTED}`}>
          {`Card image from ${credit}, linked as the PokéSearch catalog lists it.`}
        </p>
      )}
    </Panel>
  );
}

const SET_PART = "before:mx-2 before:content-['·']";

function Loaded({ body }: { readonly body: CardDetail }) {
  const card = body.data;
  const anyPriced = card.variants.some((variant) => variant.price !== null);
  return (
    <>
      {body.meta.degraded && (
        <Notice tone="warn" as="p" className="mb-6">
          {DEGRADED_LINE}
        </Notice>
      )}
      {/* 390: the image beside the name and set line, the rest full width below. 640 up: a
          sticky 240 px image column and one right column (display: contents unwraps it). */}
      <article className="grid grid-cols-[104px_minmax(0,1fr)] gap-x-3 gap-y-6 sm:grid-cols-[240px_minmax(0,1fr)] sm:gap-x-8">
        <CardImage card={card} />
        <div className="contents sm:col-start-2 sm:row-start-1 sm:block sm:min-w-0">
          <div className="col-start-2 row-start-1 min-w-0 self-center sm:self-auto">
            <h1 className="text-lg font-semibold sm:text-2xl">{card.name}</h1>
            <p className={`mt-1 flex flex-wrap text-sm ${MUTED}`}>
              <span>{card.set.name}</span>
              <span className={SET_PART}>No. {card.number}</span>
              {card.rarity !== null && <span className={SET_PART}>{card.rarity}</span>}
            </p>
          </div>
          <div className="col-span-2 min-w-0 sm:mt-6">
            {anyPriced ? (
              <PricedCard card={card} />
            ) : (
              <PricesSection>
                <p className="mt-2">No prices yet</p>
              </PricesSection>
            )}
            <Sources card={card} />
          </div>
        </div>
      </article>
    </>
  );
}

function NotFound({ failure }: { readonly failure: ApiFailure }) {
  return (
    <>
      <h1 className="text-2xl font-semibold">Card not found</h1>
      {failure.problem?.catalog_missing === true && (
        <p className="mt-2 text-base">{CATALOG_MISSING_LINE}</p>
      )}
      <p className="mt-4 text-base">
        <Link to="/search" className={TEXT_LINK}>
          Search cards
        </Link>
      </p>
    </>
  );
}

function Failed({ id, failure }: { readonly id: string; readonly failure: ApiFailure }) {
  return (
    <>
      <h1 className="text-2xl font-semibold">{`Card ${id}`}</h1>
      <Notice tone="problem" role="alert" className="mt-6">
        <p className="font-semibold">Could not load this card: {failure.title}</p>
        {failure.requestId !== null && (
          <p className="mt-1 text-sm">
            Request ID <code className="break-all">{failure.requestId}</code>
          </p>
        )}
      </Notice>
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

  let title = pageTitle(`Card ${id}`);
  if (query.data !== undefined) title = pageTitle(query.data.data.name);
  else if (query.error !== null && isCardNotFound(query.error)) title = pageTitle('Card not found');
  useDocumentTitle(title);

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
        <h1 className="text-2xl font-semibold">{`Card ${id}`}</h1>
        <p role="status" className="mt-6 text-sm text-ink-muted">
          {`Loading card ${id}…`}
        </p>
        <Skeleton className="mt-6 w-48" />
        <Skeleton className="mt-2 w-64" />
      </>
    );
  }

  // While loading, the page reserves one viewport so the footer starts below the fold and the
  // scrollbar is there from the first frame (CLS); every other state keeps its own height.
  const loading = query.data === undefined && query.error === null;
  return (
    <main className={loading ? 'mx-auto min-h-svh max-w-3xl p-6' : 'mx-auto max-w-3xl p-6'}>
      {body}
    </main>
  );
}
