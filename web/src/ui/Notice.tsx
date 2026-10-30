// The Notice primitive: an info, warn or problem block on the tokens only. A 1 px border in
// the tone at 40 %, a 4 px left rule in the tone, the tone's tint, ink text, 4 px corners, 12/16 px
// padding. Its text is exactly its children: no prefix, icon or symbol. `role` is rendered only
// when passed, so each call site keeps the alert, status or no role it had.
import type { ReactNode } from 'react';

export type NoticeTone = 'info' | 'warn' | 'problem';

/** The tone's border, left rule and tint, as literal class strings (Tailwind scans them). */
const TONE: Readonly<Record<NoticeTone, string>> = {
  info: 'border-info/40 border-l-info bg-info-tint',
  warn: 'border-warn/40 border-l-warn bg-warn-tint',
  problem: 'border-problem/40 border-l-problem bg-problem-tint',
};

export default function Notice({
  tone,
  role,
  as = 'div',
  className = '',
  children,
}: {
  readonly tone: NoticeTone;
  readonly role?: 'alert' | 'status';
  readonly as?: 'div' | 'p';
  readonly className?: string;
  readonly children: ReactNode;
}) {
  const classes =
    `rounded-md border border-l-4 px-4 py-3 text-ink ${TONE[tone]} ${className}`.trim();
  return as === 'p' ? (
    <p role={role} className={classes}>
      {children}
    </p>
  ) : (
    <div role={role} className={classes}>
      {children}
    </div>
  );
}
