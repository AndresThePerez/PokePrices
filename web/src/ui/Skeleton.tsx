// The Skeleton primitive: a 16 px sunken bar with 2 px corners that stands in for content
// while it loads. It is aria-hidden with no text and no svg; it sits after a role=status loading
// line and never replaces or wraps it.
export default function Skeleton({ className = '' }: { readonly className?: string }) {
  return (
    <div aria-hidden="true" className={`h-4 rounded-sm bg-surface-sunken ${className}`.trim()} />
  );
}
