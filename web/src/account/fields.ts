// Shared pieces of the account forms (D10) that are not components: class names and the 422
// error reader. Colours are P3 tokens only, each with its dark counterpart.
import type { ApiFailure } from '../api/client';

export const FOCUS =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 dark:focus-visible:outline-sky-400';

/** Muted text, readable in both themes. */
export const MUTED = 'text-slate-600 dark:text-slate-400';

/** An in-text link with a 44 px target. */
export const TEXT_LINK = `inline-flex min-h-11 items-center underline underline-offset-4 ${FOCUS}`;

/** The one filled button of a form. */
export const PRIMARY_BUTTON =
  `min-h-11 min-w-11 rounded-md bg-slate-900 px-4 py-2 font-semibold text-white ` +
  `disabled:opacity-60 dark:bg-slate-100 dark:text-slate-900 ${FOCUS}`;

/** A quieter outlined button (Sign out, Send the verification email again). */
export const SECONDARY_BUTTON =
  `inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border px-4 text-sm font-medium ` +
  `border-slate-400 bg-white text-slate-900 disabled:opacity-60 ` +
  `dark:border-slate-500 dark:bg-slate-900 dark:text-slate-100 ${FOCUS}`;

/** Field errors of a 422 problem: the first message of each field (D10). */
export function firstErrors(failure: ApiFailure | null): Readonly<Record<string, string>> {
  const errors = failure?.status === 422 ? failure.problem?.errors : undefined;
  const first: Record<string, string> = {};
  if (errors === undefined) return first;
  for (const [field, messages] of Object.entries(errors)) {
    const message = messages[0];
    if (message !== undefined) first[field] = message;
  }
  return first;
}
