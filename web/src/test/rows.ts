// Helpers for the list-row tests: what a sighted visitor sees of a row (screen-reader-only text
// left out), the list's visible source-and-"as of" stamps, and each row's accessible name.
import { within } from '@testing-library/react';

/** The text a sighted visitor reads in `element`: everything but the screen-reader-only spans. */
export function visibleText(element: Element): string {
  const copy = element.cloneNode(true) as Element;
  for (const hidden of Array.from(copy.querySelectorAll('.sr-only'))) hidden.remove();
  return copy.textContent.replace(/\s+/g, ' ').trim();
}

/** The element's own text, as Testing Library's getByText reads it. */
function ownText(element: Element): string {
  return Array.from(element.childNodes)
    .filter((node) => node.nodeType === Node.TEXT_NODE)
    .map((node) => node.textContent ?? '')
    .join('');
}

/** The visible "from TCGCSV (daily close)" stamps inside `container`, in document order. */
export function visibleStamps(container: HTMLElement): readonly Element[] {
  return Array.from(container.querySelectorAll('*')).filter(
    (element) =>
      /from TCGCSV \(daily close\)/.test(ownText(element)) &&
      element.closest('.sr-only') === null &&
      element.closest('[aria-hidden="true"]') === null,
  );
}

/** Each row of `list` with its accessible name, as Testing Library's role query computes it. */
export function namedRows(
  list: HTMLElement,
): readonly { readonly row: HTMLElement; readonly name: string }[] {
  const rows: { row: HTMLElement; name: string }[] = [];
  within(list).getAllByRole('listitem', {
    name: (name, element) => {
      rows.push({ row: element as HTMLElement, name });
      return true;
    },
  });
  return rows;
}
