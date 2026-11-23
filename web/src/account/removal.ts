// The one removal a watchlist or portfolio page holds at a time (useRemoval): the row taken away,
// where it was, and the list answer it was taken from, so the row's "Removed." line (RemovedLine)
// shows in its place until the list read again holds the row (Undo, or a put-back from another
// tab), or the visitor removes another row, or leaves the page.
import { useState } from 'react';

/** The row a removal took away, where it was, and the list answer it was taken from. */
export interface Removal<Row> {
  readonly row: Row;
  readonly index: number;
  readonly seen: unknown;
}

/**
 * The list's one removal: `shown` is the rows with the removed one taken out while its line shows;
 * `removal` is null once the list read again (a newer answer than the one the removal saw) holds
 * the row again. `removed(row)` starts a removal and replaces any earlier one.
 */
export function useRemoval<Row extends { readonly variant_key: string }>(
  rows: readonly Row[],
  body: unknown,
) {
  const [removal, setRemoval] = useState<Removal<Row> | null>(null);
  let current = removal;
  if (
    current !== null &&
    body !== current.seen &&
    rows.some((row) => row.variant_key === current?.row.variant_key)
  ) {
    current = null;
    setRemoval(null);
  }
  const gone = current?.row.variant_key;
  const shown = gone === undefined ? rows : rows.filter((row) => row.variant_key !== gone);
  return {
    removal: current,
    shown,
    removed: (row: Row): void => {
      setRemoval({ row, index: Math.max(0, rows.indexOf(row)), seen: body });
    },
  };
}
