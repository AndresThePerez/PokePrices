// The card page's Price history section (D4, D5, D7, D9, D10) over
// GET /v1/cards/{id}/prices?range=. Every day and amount it prints is the API's (R132); the
// browser's clock is never read. The canvas chart (T06) is a lazy chunk inside an error boundary.
// D2 T02: the section is a Panel; its header row holds the Variant and Chart range segments, then
// the data table toggle, the stamp, the History begins note, the plot and the attribution link.
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
import { cardPricesKey } from '../queryKeys';
import { Button } from '../ui/Button';
import { MUTED, TEXT_LINK } from '../ui/classes';
import Notice from '../ui/Notice';
import Panel from '../ui/Panel';

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
    return this.state.failed ? <p className="mt-3">{CHART_FAILED}</p> : this.props.children;
  }
}

/** The link the chart library's licence asks for, 12 px under every drawn chart (D6, F16). */
function Attribution() {
  return (
    <p className="mt-3 text-meta">
      <a href="https://www.tradingview.com/" className={`${TEXT_LINK} ${MUTED}`}>
        {ATTRIBUTION}
      </a>
    </p>
  );
}

function Problem({ failure }: { readonly failure: ApiFailure }) {
  return (
    <Notice tone="problem" role="alert" className="mt-3">
      <p className="font-semibold">Could not load the price history: {failure.title}</p>
      {failure.requestId !== null && (
        <p className="mt-1 text-sm">
          Request ID <code className="break-all">{failure.requestId}</code>
        </p>
      )}
    </Notice>
  );
}

function Stamp({ to }: { readonly to: string | null }) {
  if (to === null) return null;
  return <p className={`mt-2 text-meta ${MUTED}`}>{`as of ${to} from TCGCSV (daily close)`}</p>;
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
        <tr className="border-b border-line-subtle">
          <th scope="col" className="py-2 pr-4 font-semibold">
            Day
          </th>
          <th scope="col" className="py-2 pr-4 text-right font-semibold">
            Close
          </th>
          <th scope="col" className="py-2 font-semibold">
            Note
          </th>
        </tr>
      </thead>
      <tbody>
        {tableRows(points).map((row) => (
          <tr key={`${series.variant_key} ${row.day}`} className="border-b border-line-subtle">
            <td className="py-2 pr-4 whitespace-nowrap">{row.day}</td>
            <td className="py-2 pr-4 text-right num whitespace-nowrap">{row.close}</td>
            <td className={`py-2 ${MUTED}`}>{row.note}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/**
 * The header row of the panel: the Variant group (when offered) and the Chart range group. Below
 * 640 px the panel's 12 px side padding leaves 316 px at 390; each segment drops to 12 px side
 * padding (1W and 1Y stop at the 44 px floor), so the five ranges take about 254-258 px plus four
 * 6 px gaps, 278-282 px, and stay on one row down to a 360 px viewport (286 px usable).
 */
function Controls({ children }: { readonly children: ReactNode }) {
  return <div className="mt-4 flex flex-wrap gap-x-6 gap-y-2">{children}</div>;
}

/**
 * The chosen series' window: the data table toggle, the stamp, the banner, the chart area with
 * its hidden summary and attribution, and the table.
 */
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
  const banner = series.history_begins !== null && showsBanner(series.history_begins, from) && (
    <p role="note" className={`mt-2 text-sm ${MUTED}`}>
      {bannerText(series.history_begins)}
    </p>
  );
  if (points.length === 0) {
    return (
      <>
        <Stamp to={to} />
        {banner}
        {from !== null && to !== null && (
          <p className="mt-3">{`No daily close for this variant between ${from} and ${to}.`}</p>
        )}
      </>
    );
  }
  return (
    <>
      <button
        type="button"
        aria-expanded={tableOpen}
        aria-controls={tableId}
        onClick={() => {
          setTableOpen((open) => !open);
        }}
        className={`mt-2 text-sm ${TEXT_LINK}`}
      >
        {tableOpen ? 'Hide data table' : 'Show data table'}
      </button>
      <Stamp to={to} />
      {banner}
      <div className="mt-3">
        <p id={summaryId} className="sr-only">
          {summaryText(label, range, points)}
        </p>
        <ChartBoundary>
          <Suspense fallback={<div className="h-[296px] sm:h-[376px]" />}>
            <PriceChart segments={parts} label={label} range={range} describedBy={summaryId} />
            <Attribution />
          </Suspense>
        </ChartBoundary>
      </div>
      {tableOpen && (
        <DataTable id={tableId} label={label} range={range} series={series} points={points} />
      )}
    </>
  );
}

function Loaded({
  body,
  range,
  chosen,
  onChoose,
  priced,
  rangeGroup,
}: {
  readonly body: CardPrices;
  readonly range: PriceRange;
  readonly chosen: string | null;
  readonly onChoose: (variantKey: string) => void;
  readonly priced: readonly string[];
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
  // The segments are the eligible series of priced variants, in card order (A1-4). The chart only
  // ever draws the chosen variant: when it has no eligible series here, the no-close line shows
  // instead (RULING o), never another variant's series.
  const options = priced.flatMap((key) => eligible.filter((one) => one.variant_key === key));
  const selected = chosen === null ? options[0] : options.find((one) => one.variant_key === chosen);
  if (selected === undefined) {
    const line =
      eligible.length === 0 || from === null || to === null
        ? ZERO_ELIGIBLE
        : `No daily close for this variant between ${from} and ${to}.`;
    return (
      <>
        <Controls>{rangeGroup}</Controls>
        <Stamp to={to} />
        <p className="mt-3">{line}</p>
      </>
    );
  }
  return (
    <>
      <Controls>
        {options.length >= 2 && (
          <div role="group" aria-label="Variant" className="flex flex-wrap gap-1.5 sm:gap-2">
            {options.map((one) => (
              <Button
                key={one.variant_key}
                variant="segment"
                pressed={one.variant_key === selected.variant_key}
                onClick={() => {
                  onChoose(one.variant_key);
                }}
                className="capitalize max-sm:px-3"
              >
                {variantLabel(one)}
              </Button>
            ))}
          </div>
        )}
        {rangeGroup}
      </Controls>
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

/**
 * The history of the variant the card page chose (`chosen`, a priced variant key); `onChoose`
 * reports a Variant segment press back to the page, which owns the choice and the hero with it.
 * `priced` lists the priced variant keys in card order.
 */
export default function PriceHistory({
  id,
  chosen,
  onChoose,
  priced,
}: {
  readonly id: string;
  readonly chosen: string | null;
  readonly onChoose: (variantKey: string) => void;
  readonly priced: readonly string[];
}) {
  const [range, setRange] = useState<PriceRange>(DEFAULT_RANGE);
  const query = useQuery<CardPrices, ApiFailure>({
    queryKey: cardPricesKey(id, range),
    queryFn: ({ signal }) => unwrap(getCardPrices(id, range, { signal })),
  });

  const rangeGroup = (
    <div role="group" aria-label="Chart range" className="flex flex-wrap gap-1.5 sm:gap-2">
      {RANGES.map((key) => (
        <Button
          key={key}
          variant="segment"
          pressed={key === range}
          onClick={() => {
            setRange(key);
          }}
          className="tabular-nums max-sm:px-3"
        >
          {key}
        </Button>
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
        onChoose={onChoose}
        priced={priced}
        rangeGroup={rangeGroup}
      />
    );
  } else if (query.error !== null) {
    body = (
      <>
        <Controls>{rangeGroup}</Controls>
        <Problem failure={query.error} />
      </>
    );
  } else {
    // The status line reserves what the loaded body adds below Controls (data table toggle 52,
    // stamp 26, chart 12 + 240/320, attribution 56, less this line's 12 px margin), plus the
    // Variant group's second Controls row (8 + 44) when two or more variants are priced.
    const reserve =
      priced.length >= 2 ? 'min-h-[426px] sm:min-h-[506px]' : 'min-h-[374px] sm:min-h-[454px]';
    body = (
      <>
        <Controls>{rangeGroup}</Controls>
        <p role="status" className={`mt-3 text-sm ${MUTED} ${reserve}`}>
          Loading price history…
        </p>
      </>
    );
  }

  return (
    <Panel as="section" aria-labelledby="card-history" className="mt-6 max-sm:px-3">
      <h2 id="card-history" className="text-lg font-semibold">
        Price history
      </h2>
      {body}
    </Panel>
  );
}
