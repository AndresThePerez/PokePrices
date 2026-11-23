// "Removed." with Undo, in the place of a row the visitor removed from the watchlist or the
// portfolio. One line at a time per list: it stays until the visitor leaves the page or removes
// another row (useRemoval), and goes by itself once the list read again holds the row (Undo, or a
// put-back from another tab). Focus never falls to the page body: the line's Undo takes it when the
// line appears, keeps it through a failed Undo (it is aria-disabled while it runs, never disabled,
// so the browser does not drop focus), and when the line goes while holding it, focus moves to the
// row that takes its place (the restored row after Undo) or, with none, to the page heading.
import { useLayoutEffect, useRef } from 'react';
import type { ApiFailure } from '../api/client';
import { SECONDARY_BUTTON } from '../ui/classes';
import { ProblemBlock } from './Form';

/** After the line has gone: the row at its place, else the page heading. */
function focusAfter(list: Element | null, index: number, heading: HTMLElement | null): void {
  const item = list?.isConnected === true ? list.children.item(index) : null;
  const target = item?.querySelector<HTMLElement>('a, button, input') ?? heading;
  target?.focus();
}

export default function RemovedLine({
  index,
  undoLabel,
  pending,
  failure,
  onUndo,
}: {
  readonly index: number;
  readonly undoLabel: string;
  readonly pending: boolean;
  readonly failure: ApiFailure | null;
  readonly onUndo: () => void;
}) {
  const lineRef = useRef<HTMLLIElement>(null);

  // Focus the Undo when the line appears; hand focus on when the line goes while holding it. The
  // line is keyed by its row, so its index never changes while it is mounted.
  useLayoutEffect(() => {
    const line = lineRef.current;
    line?.querySelector('button')?.focus();
    return () => {
      if (line === null || !line.contains(document.activeElement)) return;
      const list = line.parentElement;
      const heading = line.closest('main')?.querySelector('h1') ?? null;
      queueMicrotask(() => {
        focusAfter(list, index, heading);
      });
    };
  }, [index]);

  return (
    <li ref={lineRef} className="py-3">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <p className="text-base">
          {failure === null ? 'Removed.' : 'Removed. Undo did not go through.'}
        </p>
        <button
          type="button"
          aria-label={undoLabel}
          aria-disabled={pending ? true : undefined}
          onClick={() => {
            if (!pending) onUndo();
          }}
          className={SECONDARY_BUTTON}
        >
          Undo
        </button>
      </div>
      {failure !== null && <ProblemBlock context="Could not put it back" failure={failure} />}
    </li>
  );
}
