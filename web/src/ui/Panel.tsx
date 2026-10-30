// The Panel primitive: the raised surface, the 1 px line border, 6 px corners, the soft panel
// shadow and 16/24 px padding. It renders the landmark and heading props it is given and nothing
// else: no role or label of its own.
import type { ReactNode } from 'react';

export default function Panel({
  as = 'div',
  role,
  'aria-labelledby': labelledBy,
  'aria-label': label,
  className = '',
  children,
}: {
  readonly as?: 'section' | 'div';
  readonly role?: 'region';
  readonly 'aria-labelledby'?: string;
  readonly 'aria-label'?: string;
  readonly className?: string;
  readonly children: ReactNode;
}) {
  const props = {
    role,
    'aria-labelledby': labelledBy,
    'aria-label': label,
    className:
      `rounded-lg border border-line bg-surface-raised px-6 py-4 text-ink shadow-panel ${className}`.trim(),
  };
  return as === 'section' ? (
    <section {...props}>{children}</section>
  ) : (
    <div {...props}>{children}</div>
  );
}
