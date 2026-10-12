// The PokéPrices card page: /card/:id over GET /v1/cards/{id}. Every date and version it shows
// comes from the API response (R132), never from the request or render time.
import { useEffect, useState } from 'react';
import Link from '../Link';
import {
  getCard,
  type ApiFailure,
  type ApiResult,
  type Card,
  type CardDetail,
} from '../api/client';

const DEGRADED_LINE = 'Catalog data may be out of date: PokéSearch is unavailable';
const CATALOG_MISSING_LINE = 'This card is no longer in the PokéSearch catalog';
const CARD_NOT_FOUND = '/problems/card-not-found';
const FOCUS =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700';

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

/** finish, edition and treatment in words; '-' and '' mean "none" in the variant key. */
function variantLabel(variant: Variant): string {
  const parts = [variant.finish, variant.edition, variant.treatment].filter(
    (part) => part !== '' && part !== '-',
  );
  return parts.length > 0 ? parts.join(', ') : variant.variant_key;
}

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
        className="aspect-[63/88] w-full rounded-[14px] border-[10px] border-[#f2c230] bg-slate-100 object-cover shadow-[0_22px_40px_-20px_rgba(15,23,42,0.6)]"
      />
      {host !== null && (
        <figcaption className="mt-3 text-xs leading-snug text-slate-500">
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
            <span className="block text-sm text-slate-600">Not yet mapped</span>
          ) : (
            variant.sources.map((source) => (
              <span
                key={`${source.source} ${String(source.product_id)} ${source.subtype}`}
                className="block text-sm text-slate-600"
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
        <p className="mb-6 border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-amber-950">
          {DEGRADED_LINE}
        </p>
      )}
      <article className="grid gap-8 sm:grid-cols-[15rem_minmax(0,1fr)]">
        <CardImage card={card} />
        <div className="min-w-0">
          <h1 className="text-3xl font-semibold">{card.name}</h1>
          <p className="mt-2 flex flex-wrap gap-x-4 text-slate-600">
            <span>{card.set.name}</span>
            <span>No. {card.number}</span>
            {card.rarity !== null && <span>{card.rarity}</span>}
          </p>
          <section aria-labelledby="card-prices" className="mt-8">
            <h2 id="card-prices" className="text-lg font-semibold">
              Prices
            </h2>
            <p className="mt-2">No prices yet</p>
          </section>
          <section aria-labelledby="card-products" className="mt-8">
            <h2 id="card-products" className="text-lg font-semibold">
              TCGplayer products
            </h2>
            <Mapping variants={card.variants} />
          </section>
          <p className="mt-8 border-t border-slate-200 pt-4 text-sm text-slate-600">
            {catalogLine(card.catalog)}
          </p>
        </div>
      </article>
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
        <Link to="/search" className={`underline underline-offset-4 ${FOCUS}`}>
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
      <div role="alert" className="mt-6 border-l-4 border-red-700 bg-red-50 px-4 py-3 text-red-950">
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

interface Settled {
  readonly id: string;
  readonly result: ApiResult<CardDetail>;
}

export default function CardPage({ id }: { readonly id: string }) {
  const [settled, setSettled] = useState<Settled | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    getCard(id, { signal: controller.signal }).then(
      (result) => {
        setSettled({ id, result });
      },
      () => {
        // Aborted: another card (or leaving the page) superseded this request.
      },
    );
    return () => {
      controller.abort();
    };
  }, [id]);

  let body;
  if (settled?.id !== id) {
    body = (
      <>
        <h1 className="text-3xl font-semibold">{`Card ${id}`}</h1>
        <p role="status" className="mt-6 text-sm text-slate-600">
          {`Loading card ${id}…`}
        </p>
      </>
    );
  } else if (settled.result.ok) {
    body = <Loaded body={settled.result.data} />;
  } else if (isCardNotFound(settled.result)) {
    body = <NotFound failure={settled.result} />;
  } else {
    body = <Failed id={id} failure={settled.result} />;
  }

  return <main className="mx-auto max-w-3xl p-6">{body}</main>;
}
