// The degraded catalog line: shown when an answer's names come from the last good
// PokéSearch copy. A warn Notice as a paragraph with no role; the sentence is one text node.
import Notice from './Notice';

const DEGRADED_LINE = 'Catalog data may be out of date: PokéSearch is unavailable';

export default function DegradedNotice({ className = '' }: { readonly className?: string }) {
  return (
    <Notice tone="warn" as="p" className={className}>
      {DEGRADED_LINE}
    </Notice>
  );
}
