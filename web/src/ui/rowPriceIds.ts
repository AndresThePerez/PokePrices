// The ids of a RowPrice's screen-reader lines, for the row's aria-labelledby.

/** The ids of a RowPrice's screen-reader lines under `id`, in reading order. */
export function rowPriceIds(id: string, withDay: boolean): string {
  return withDay ? `${id}-price ${id}-change ${id}-as-of` : `${id}-price ${id}-change`;
}
