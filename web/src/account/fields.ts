// Shared pieces of the account forms (D10) that are not components: class names and the 422
// error reader. Colours are design tokens only (D1).
import type { ApiFailure } from '../api/client';

// The class constants live with the primitives that apply them (ui/classes.ts); the account pages
// and their tests keep importing them from here under the same names.
export { FOCUS, MUTED, PRIMARY_BUTTON, SECONDARY_BUTTON, TEXT_LINK } from '../ui/classes';

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
