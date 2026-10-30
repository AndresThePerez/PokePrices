// Shared pieces of the account forms (D10) that are not components: the 422 error reader. The
// class names the forms apply live in ui/classes.ts.
import type { ApiFailure } from '../api/client';

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
