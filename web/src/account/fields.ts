// Shared pieces of the account forms that are not components: the 422 error reader and the checks
// a form runs in the browser before it is sent. The class names the forms apply live in
// ui/classes.ts.
import { useCallback, useState } from 'react';
import type { ApiFailure } from '../api/client';

/** One check of a field's value: its message when the value fails, else null. */
export type FieldCheck = (value: string) => string | null;

/** A field as a form checks it: its input's name, its value, then its checks in order. */
export type CheckedField = readonly [name: string, value: string, ...checks: FieldCheck[]];

type FieldErrors = Readonly<Record<string, string>>;

const NO_ERRORS: FieldErrors = {};

/** The new-password rule the API applies on sign-up, reset and change: 12 to 72 characters. */
const PASSWORD_MIN = 12;
const PASSWORD_MAX = 72;

/** Something before an @ and no white space; the API judges the rest of the address. */
const EMAIL_SHAPE = /^[^\s@]+@[^\s@]*$/;

/** Fails an empty value with the field's own message. */
export function required(message: string): FieldCheck {
  return (value) => (value === '' ? message : null);
}

/** Fails a value that does not have an email address's shape. */
export const emailShape: FieldCheck = (value) =>
  EMAIL_SHAPE.test(value) ? null : 'Enter an email address like name@example.com.';

/** Fails a new password shorter than 12 or longer than 72 characters. */
export const passwordLength: FieldCheck = (value) => {
  const length = Array.from(value).length;
  if (length < PASSWORD_MIN) return `Use at least ${String(PASSWORD_MIN)} characters.`;
  if (length > PASSWORD_MAX) return `Use at most ${String(PASSWORD_MAX)} characters.`;
  return null;
};

/** Fails a confirmation that differs from the password it repeats. */
export function sameAs(password: string): FieldCheck {
  return (value) => (value === password ? null : 'The two passwords do not match.');
}

/** An email field's checks: not empty, then the shape. */
export const EMAIL_CHECKS: readonly FieldCheck[] = [
  required('Enter your email address.'),
  emailShape,
];

/** The check of a field that takes the account's current password to confirm an action. */
export const PASSWORD_GIVEN: FieldCheck = required('Enter your password.');

/** The check of an authentication-code field. */
export const CODE_GIVEN: FieldCheck = required('Enter the authentication code.');

/**
 * The `password` and `password_confirmation` fields of a form that sets a new password: not
 * empty (`empty` is the password field's message), 12 to 72 characters, and the same twice.
 */
export function newPasswordFields(
  password: string,
  confirmation: string,
  empty: string,
): readonly CheckedField[] {
  return [
    ['password', password, required(empty), passwordLength],
    [
      'password_confirmation',
      confirmation,
      required('Enter the password again.'),
      sameAs(password),
    ],
  ];
}

/** The first failing message of each field, in the order the form gives its fields. */
export function checkFields(fields: readonly CheckedField[]): FieldErrors {
  const found: Record<string, string> = {};
  for (const [name, value, ...checks] of fields) {
    for (const check of checks) {
      const message = check(value);
      if (message === null) continue;
      found[name] = message;
      break;
    }
  }
  return found;
}

/**
 * The browser checks of one form. `passes(form, fields)` runs them in a submit handler: every
 * failing field gets its message in `errors` (the form shows each under its field, where a 422
 * message would sit), focus goes to the first failing field, and false tells the form to send
 * nothing. A pass empties `errors`; `clear()` empties them when the form's fields change.
 */
export function useFieldChecks() {
  const [errors, setErrors] = useState<FieldErrors>(NO_ERRORS);
  const passes = useCallback((form: HTMLFormElement, fields: readonly CheckedField[]): boolean => {
    const found = checkFields(fields);
    const first = Object.keys(found)[0];
    setErrors(first === undefined ? NO_ERRORS : found);
    if (first === undefined) return true;
    const input = form.elements.namedItem(first);
    if (input instanceof HTMLElement) input.focus();
    return false;
  }, []);
  const clear = useCallback(() => {
    setErrors(NO_ERRORS);
  }, []);
  return { errors, passes, clear };
}

/** Field errors of a 422 problem: the first message of each field. */
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
