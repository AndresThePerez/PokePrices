// The card page's Price history section (D4, D5, D7, D9, D10) over
// GET /v1/cards/{id}/prices?range=. Every day and amount it prints is the API's (R132); the
// browser's clock is never read. The canvas chart (T06) is a lazy chunk inside an error boundary.
import { Component, Suspense, lazy, useId, useMemo, useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  getCardPrices,
  type ApiFailure,
  type CardPrices,
  type PriceRange,
  type PriceSeries,
} from '../api/client';
import {
  DEFAULT_RANGE,
  RANGES,
  bannerText,
  eligibleSeries,
  pointsInRange,
  segments,
  showsBanner,
  summaryText,
  tableRows,
} from '../chart/series';
import { variantLabel } from '../format';
import { unwrap } from '../query';

const FOCUS =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 dark:focus-visible:outline-sky-400';
const MUTED = 'text-slate-600 dark:text-slate-400';
const ZERO_ELIGIBLE = 'No daily close is held for this card yet.';
const CHART_FAILED = 'The chart could not be drawn. Show the data table to read every close.';
const ATTRIBUTION = 'Chart by TradingView Lightweight Charts';

/** lightweight-charts is loaded with the chart only, never in the entry chunk (D6). */
const PriceChart = lazy(() => import('../chart/PriceChart'));

/** Keeps a chart that cannot be drawn from taking the card page down (D6). */
class ChartBoundary extends Component<{ readonly children: ReactNode }, { failed: boolean }> {
  override state = { failed: false };

  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true };
  }

  override render(): ReactNode {
    return this.state.failed ? <p className="mt-4">{CHART_FAILED}</p> : this.props.children;
  }
}

/** The link the chart library's licence asks for, under every drawn chart (D6). */
function Attribution() {
  return (
    <p className="mt-1 text-xs">
      <a
        href="https://www.tradingview.com/"
        className={`inline-flex min-h-11 items-center underline underline-offset-4 ${MUTED} ${FOCUS}`}
      >
        {ATTRIBUTION}
      </a>
    </p>
  );
}

/** A pressable button: 44 px tall and wide, the pressed state filled in both themes. */
const TOGGLE =
  `inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border px-3 text-sm font-medium ` +
  `border-slate-300 bg-white text-slate-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 ` +
  `aria-pressed:border-slate-900 aria-pressed:bg-slate-900 aria-pressed:text-white ` +
  `dark:aria-pressed:border-slate-100 dark:aria-pressed:bg-slate-100 dark:aria-pressed:text-slate-900 ${FOCUS}`;

function Problem({ failure }: { readonly failure: ApiFailure }) {
  return (
    <div
      role="alert"
      className="mt-4 border-l-4 border-red-700 bg-red-50 px-4 py-3 text-red-950 dark:border-red-400 dark:bg-red-950 dark:text-red-100"
    >
      <p className="font-semibold">Could not load the price history: {failure.title}</p>
      {failure.requestId !== null && (
        <p className="mt-1 text-sm">
          Request ID <code className="break-all">{failure.requestId}</code>
        </p>
      )}
    </div>
  );
}

function Stamp({ to }: { readonly to: string | null }) {
  if (to === null) return null;
  return <p className={`mt-3 text-sm ${MUTED}`}>{`as of ${to} from TCGCSV (daily close)`}</p>;
}

function DataTable({
  id,
  label,
  range,
  series,
  points,
}: {
  readonly id: string;
  readonly label: string;
  readonly range: PriceRange;
  readonly series: PriceSeries;
  readonly points: PriceSeries['points'];
}) {
  return (
    <table id={id} className="mt-3 w-full text-left text-sm tabular-nums">
      <caption className={`mb-2 text-left ${MUTED}`}>
        {`Daily closes of ${label}, ${range}, from TCGCSV`}
      </caption>
      <thead>
        <tr className="border-b border-slate-300 dark:border-slate-600">
          <th scope="col" className="py-2 pr-4 font-semibold">
            Day
          </th>
          <th scope="col" className="py-2 pr-4 font-semibold">
            Close
          </th>
          <th scope="col" className="py-2 font-semibold">
            Note
          </th>
        </tr>
      </thead>
      <tbody>
        {tableRows(points).map((row) => (
          <tr
            key={`${series.variant_key} ${row.day}`}
            className="border-b border-slate-200 dark:border-slate-700"
          >
            <td className="py-2 pr-4 whitespace-nowrap">{row.day}</td>
            <td className="py-2 pr-4 whitespace-nowrap">{row.close}</td>
            <td className={`py-2 ${MUTED}`}>{row.note}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/** The chosen series' window: banner, the chart area with its hidden summary, and the table. */
function SeriesView({
  series,
  range,
  from,
  to,
}: {
  readonly series: PriceSeries;
  readonly range: PriceRange;
  readonly from: string | null;
  readonly to: string | null;
}) {
  const [tableOpen, setTableOpen] = useState(false);
  const ids = useId();
  const summaryId = `${ids}-summary`;
  const tableId = `${ids}-table`;
  const label = variantLabel(series);
  const points = useMemo(
    () => (from === null || to === null ? [] : pointsInRange(series.points, from, to)),
    [series.points, from, to],
  );
  const parts = useMemo(() => segments(points), [points]);
  return (
    <>
      {series.history_begins !== null && showsBanner(series.history_begins, from) && (
        <p role="note" className={`mt-4 text-sm ${MUTED}`}>
          {bannerText(series.history_begins)}
        </p>
      )}
      {points.length === 0 ? (
        from !== null &&
        to !== null && (
          <p className="mt-4">{`No daily close for this variant between ${from} and ${to}.`}</p>
        )
      ) : (
        <>
          <div className="mt-4">
            <p id={summaryId} className="sr-only">
              {summaryText(label, range, points)}
            </p>
            <ChartBoundary>
              <Suspense fallback={null}>
                <PriceChart segments={parts} label={label} range={range} describedBy={summaryId} />
                <Attribution />
              </Suspense>
            </ChartBoundary>
          </div>
          <button
            type="button"
            aria-expanded={tableOpen}
            aria-controls={tableId}
            onClick={() => {
              setTableOpen((open) => !open);
            }}
            className={`mt-4 inline-flex min-h-11 items-center underline underline-offset-4 ${FOCUS}`}
          >
            {tableOpen ? 'Hide data table' : 'Show data table'}
          </button>
          {tableOpen && (
            <DataTable id={tableId} label={label} range={range} series={series} points={points} />
          )}
        </>
      )}
    </>
  );
}

function Loaded({
  body,
  range,
  chosen,
  onChoose,
  rangeGroup,
}: {
  readonly body: CardPrices;
  readonly range: PriceRange;
  readonly chosen: string | null;
  readonly onChoose: (variantKey: string) => void;
  readonly rangeGroup: ReactNode;
}) {
  // An answer of an unexpected shape shows as "nothing held" rather than taking the page down.
  const raw: unknown = body;
  const record = (raw ?? {}) as { data?: unknown; meta?: unknown };
  const all: unknown = record.data;
  const usable = (one: unknown): one is PriceSeries =>
    typeof one === 'object' &&
    one !== null &&
    Array.isArray((one as { points?: unknown }).points) &&
    ((one as { history_begins?: unknown }).history_begins === null ||
      typeof (one as { history_begins?: unknown }).history_begins === 'string');
  const eligible = Array.isArray(all) ? eligibleSeries(all.filter(usable)) : [];
  const meta = (record.meta ?? undefined) as Partial<CardPrices['meta']> | undefined;
  const from = meta?.from ?? null;
  const to = meta?.to ?? null;
  const selected = eligible.find((one) => one.variant_key === chosen) ?? eligible[0];
  if (selected === undefined) {
    return (
      <>
        {rangeGroup}
        <Stamp to={to} />
        <p className="mt-4">{ZERO_ELIGIBLE}</p>
      </>
    );
  }
  return (
    <>
      {eligible.length >= 2 && (
        <div role="group" aria-label="Variant" className="mt-3 flex flex-wrap gap-2">
          {eligible.map((one) => (
            <button
              key={one.variant_key}
              type="button"
              aria-pressed={one.variant_key === selected.variant_key}
              onClick={() => {
                onChoose(one.variant_key);
              }}
              className={`${TOGGLE} capitalize`}
            >
              {variantLabel(one)}
            </button>
          ))}
        </div>
      )}
      {rangeGroup}
      <Stamp to={to} />
      <SeriesView
        key={`${selected.variant_key} ${range}`}
        series={selected}
        range={range}
        from={from}
        to={to}
      />
    </>
  );
}

export default function PriceHistory({ id }: { readonly id: string }) {
  const [range, setRange] = useState<PriceRange>(DEFAULT_RANGE);
  const [chosen, setChosen] = useState<string | null>(null);
  const query = useQuery<CardPrices, ApiFailure>({
    queryKey: ['card-prices', id, range],
    queryFn: ({ signal }) => unwrap(getCardPrices(id, range, { signal })),
  });

  const rangeGroup = (
    <div role="group" aria-label="Chart range" className="mt-3 flex flex-wrap gap-2">
      {RANGES.map((key) => (
        <button
          key={key}
          type="button"
          aria-pressed={key === range}
          onClick={() => {
            setRange(key);
          }}
          className={`${TOGGLE} tabular-nums`}
        >
          {key}
        </button>
      ))}
    </div>
  );

  let body;
  if (query.data !== undefined) {
    body = (
      <Loaded
        body={query.data}
        range={range}
        chosen={chosen}
        onChoose={setChosen}
        rangeGroup={rangeGroup}
      />
    );
  } else if (query.error !== null) {
    body = (
      <>
        {rangeGroup}
        <Problem failure={query.error} />
      </>
    );
  } else {
    body = (
      <>
        {rangeGroup}
        <p role="status" className={`mt-4 text-sm ${MUTED}`}>
          Loading price history…
        </p>
      </>
    );
  }

  return (
    <section aria-labelledby="card-history" className="mt-10">
      <h2 id="card-history" className="text-lg font-semibold">
        Price history
      </h2>
      {body}
    </section>
  );
}
