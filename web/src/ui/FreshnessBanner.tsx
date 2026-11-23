// The stale-prices banner in the shell: one warn line, over GET /v1/prices/freshness, saying how
// old the newest collected prices are when the answer is out of its freshness SLO. Nothing shows
// while it is within the SLO, while it loads, when the request fails, or before any day was
// collected (no day to name). The age and the day are the API's; the browser reads no clock.
import { useQuery } from '@tanstack/react-query';
import { getPriceFreshness, type ApiFailure, type PriceFreshness } from '../api/client';
import { unwrap } from '../query';
import { freshnessKey } from '../queryKeys';
import Notice from './Notice';

/** The banner's one sentence: the newest day and its age with one decimal ("26.0", never "26"). */
function staleText(day: string, ageHours: number): string {
  return `Prices are out of date: the newest collected prices are as of ${day}, ${ageHours.toFixed(1)} hours old.`;
}

export default function FreshnessBanner() {
  const query = useQuery<PriceFreshness, ApiFailure>({
    queryKey: freshnessKey(),
    queryFn: ({ signal }) => unwrap(getPriceFreshness({ signal })),
  });
  const data = query.data?.data;
  if (data === undefined || data.within_slo || data.day === null || data.age_hours === null) {
    return null;
  }
  return (
    <div className="mx-auto max-w-3xl px-4 pt-4 sm:px-6">
      <Notice tone="warn" as="p" className="text-sm">
        {staleText(data.day, data.age_hours)}
      </Notice>
    </div>
  );
}
