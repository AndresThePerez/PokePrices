// The account form pieces (D10): a labelled field with its hint and error, the problem block and
// the status line. Every input has a visible <label>; an error is tied to its field with
// aria-describedby and aria-invalid; every control is 44 px tall.
import type { HTMLAttributes, ReactNode } from 'react';
import type { ApiFailure } from '../api/client';
import Link from '../Link';
import { Field as UiField, Input } from '../ui/Field';
import Notice from '../ui/Notice';
import { MUTED, TEXT_LINK } from './fields';

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
  return (
    <UiField label={label} hint={hint} error={error}>
      {(control) => (
        <Input
          {...control}
          name={name}
          type={type}
          autoComplete={autoComplete}
          value={value}
          readOnly={readOnly === true}
          inputMode={inputMode}
          onChange={(event) => {
            onChange(event.target.value);
          }}
          className="mt-1 w-full"
        />
      )}
    </UiField>
  );
}

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
      <Notice tone="problem" role="alert" className="mt-6">
        <p className="font-semibold">You are already signed in.</p>
        <p className="mt-1">
          <Link to="/account" className={TEXT_LINK}>
            Go to your account
          </Link>
        </p>
      </Notice>
    );
  }
  const errors = failure.status === 422 ? (failure.problem?.errors ?? {}) : {};
  const unshown = Object.entries(errors)
    .filter(([field]) => !fields.includes(field))
    .map(([field, messages]) => [field, messages[0]] as const)
    .filter((entry): entry is readonly [string, string] => entry[1] !== undefined);
  return (
    <Notice tone="problem" role="alert" className="mt-6">
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
    </Notice>
  );
}

/** A status line (P3 D10): announced politely, readable in both themes. */
export function StatusLine({ children }: { readonly children: ReactNode }) {
  return (
    <Notice tone="info" role="status" as="p" className="mt-6">
      {children}
    </Notice>
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
