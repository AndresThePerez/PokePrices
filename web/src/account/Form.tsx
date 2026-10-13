// The account form pieces (D10): a labelled field with its hint and error, the problem block and
// the status line. Every input has a visible <label>; an error is tied to its field with
// aria-describedby and aria-invalid; every control is 44 px tall.
import { useId, type HTMLAttributes, type ReactNode } from 'react';
import type { ApiFailure } from '../api/client';
import Link from '../Link';
import { FOCUS, MUTED, TEXT_LINK } from './fields';

const INPUT =
  `mt-1 block min-h-11 w-full rounded-md border px-3 py-2 text-base ` +
  `border-slate-400 bg-white text-slate-900 dark:border-slate-500 dark:bg-slate-900 dark:text-slate-100 ` +
  `read-only:bg-slate-100 dark:read-only:bg-slate-800 ` +
  `aria-invalid:border-red-700 dark:aria-invalid:border-red-400 ${FOCUS}`;

export interface FieldProps {
  readonly label: string;
  readonly name: string;
  readonly type: 'email' | 'password' | 'text';
  readonly autoComplete: string;
  readonly value: string;
  readonly onChange: (value: string) => void;
  readonly hint?: string | undefined;
  readonly error?: string | undefined;
  readonly readOnly?: boolean | undefined;
  readonly inputMode?: HTMLAttributes<HTMLInputElement>['inputMode'] | undefined;
}

/** One labelled input; the hint and the first 422 message sit under it and describe it. */
export function Field({
  label,
  name,
  type,
  autoComplete,
  value,
  onChange,
  hint,
  error,
  readOnly,
  inputMode,
}: FieldProps) {
  const id = useId();
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const described = [hint === undefined ? null : hintId, error === undefined ? null : errorId]
    .filter((part) => part !== null)
    .join(' ');
  return (
    <div>
      <label htmlFor={id} className="text-sm font-medium">
        {label}
      </label>
      <input
        id={id}
        name={name}
        type={type}
        autoComplete={autoComplete}
        value={value}
        readOnly={readOnly === true}
        inputMode={inputMode}
        aria-invalid={error === undefined ? undefined : true}
        aria-describedby={described === '' ? undefined : described}
        onChange={(event) => {
          onChange(event.target.value);
        }}
        className={INPUT}
      />
      {hint !== undefined && (
        <p id={hintId} className={`mt-1 text-sm ${MUTED}`}>
          {hint}
        </p>
      )}
      {error !== undefined && (
        <p id={errorId} className="mt-1 text-sm font-medium text-red-700 dark:text-red-300">
          {error}
        </p>
      )}
    </div>
  );
}

const ALERT =
  'mt-6 border-l-4 border-red-700 bg-red-50 px-4 py-3 text-red-950 dark:border-red-400 dark:bg-red-950 dark:text-red-100';

function isAlreadySignedIn(failure: ApiFailure): boolean {
  return failure.status === 409 && failure.type.endsWith('/already-signed-in');
}

function firstLine(context: string, failure: ApiFailure): string {
  if (failure.status === 422) return `${context}: check the fields below.`;
  if (failure.status === 429) return `${context}: Too Many Requests. Wait a minute and try again.`;
  return `${context}: ${failure.title}`;
}

/**
 * The P3 D10 problem block: "<context>: <title>" and the request id; a 422 points at the fields
 * (and lists messages of fields the form does not show); a 429 says to wait; a 409
 * already-signed-in says so and links to the account.
 */
export function ProblemBlock({
  context,
  failure,
  fields = [],
}: {
  readonly context: string;
  readonly failure: ApiFailure;
  readonly fields?: readonly string[];
}) {
  if (isAlreadySignedIn(failure)) {
    return (
      <div role="alert" className={ALERT}>
        <p className="font-semibold">You are already signed in.</p>
        <p className="mt-1">
          <Link to="/account" className={TEXT_LINK}>
            Go to your account
          </Link>
        </p>
      </div>
    );
  }
  const errors = failure.status === 422 ? (failure.problem?.errors ?? {}) : {};
  const unshown = Object.entries(errors)
    .filter(([field]) => !fields.includes(field))
    .map(([field, messages]) => [field, messages[0]] as const)
    .filter((entry): entry is readonly [string, string] => entry[1] !== undefined);
  return (
    <div role="alert" className={ALERT}>
      <p className="font-semibold">{firstLine(context, failure)}</p>
      {unshown.length > 0 && (
        <ul className="mt-1 list-disc pl-5 text-sm">
          {unshown.map(([field, message]) => (
            <li key={field}>{message}</li>
          ))}
        </ul>
      )}
      {failure.requestId !== null && (
        <p className="mt-1 text-sm">
          Request ID <code className="break-all">{failure.requestId}</code>
        </p>
      )}
    </div>
  );
}

/** A status line (P3 D10): announced politely, readable in both themes. */
export function StatusLine({ children }: { readonly children: ReactNode }) {
  return (
    <p
      role="status"
      className="mt-6 border-l-4 border-sky-700 bg-sky-50 px-4 py-3 text-sky-950 dark:border-sky-400 dark:bg-sky-950 dark:text-sky-100"
    >
      {children}
    </p>
  );
}

/** The loading line of the account pages. */
export function LoadingAccount() {
  return (
    <p role="status" className={`mt-6 text-sm ${MUTED}`}>
      Loading your account…
    </p>
  );
}

/** A page of the account area: one main landmark, one h1, a readable column. */
export function AccountMain({
  title,
  children,
}: {
  readonly title: string;
  readonly children: ReactNode;
}) {
  return (
    <main className="mx-auto max-w-3xl p-6">
      <h1 className="text-3xl font-semibold">{title}</h1>
      <div className="max-w-md">{children}</div>
    </main>
  );
}
