// The account form pieces (D10): a labelled field with its hint and error, the problem block and
// the status line. Every input has a visible <label>; an error is tied to its field with
// aria-describedby and aria-invalid; every control is 44 px tall.
import type { HTMLAttributes, ReactNode } from 'react';
import type { ApiFailure } from '../api/client';
import Link from '../Link';
import { Field as UiField, Input } from '../ui/Field';
import Notice from '../ui/Notice';
import Page from '../ui/Page';
import Panel from '../ui/Panel';
import ProblemNotice from '../ui/ProblemNotice';
import { MUTED, QUIET_BUTTON, TEXT_LINK } from './fields';

/** A show/hide toggle for a password field: its constant accessible name and its state. */
export interface Reveal {
  readonly label: string;
  readonly shown: boolean;
  readonly onToggle: () => void;
}

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
  readonly required?: boolean | undefined;
  readonly reveal?: Reveal | undefined;
}

/**
 * One labelled input; the hint and the first 422 message sit under it and describe it. A reveal
 * puts a 44 px quiet "Show" toggle on the input's row (aria-pressed is its state; the input shows
 * its text while pressed).
 */
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
  required,
  reveal,
}: FieldProps) {
  return (
    <UiField label={label} hint={hint} error={error} required={required}>
      {(control) => {
        const input = (
          <Input
            {...control}
            name={name}
            type={reveal?.shown === true ? 'text' : type}
            autoComplete={autoComplete}
            value={value}
            readOnly={readOnly === true}
            inputMode={inputMode}
            onChange={(event) => {
              onChange(event.target.value);
            }}
            className={reveal === undefined ? 'mt-1 w-full' : 'w-full min-w-0 flex-1'}
          />
        );
        if (reveal === undefined) return input;
        return (
          <div className="mt-1 flex items-center gap-2">
            {input}
            <button
              type="button"
              aria-label={reveal.label}
              aria-pressed={reveal.shown}
              onClick={reveal.onToggle}
              className={`${QUIET_BUTTON} shrink-0`}
            >
              Show
            </button>
          </div>
        );
      }}
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
    <ProblemNotice
      context={context}
      failure={failure}
      headline={firstLine(context, failure)}
      className="mt-6"
    >
      {unshown.length > 0 && (
        <ul className="mt-1 list-disc pl-5 text-sm">
          {unshown.map(([field, message]) => (
            <li key={field}>{message}</li>
          ))}
        </ul>
      )}
    </ProblemNotice>
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

/** The centred 420 px panel of the sign-in and sign-up pages (the column width at 390). */
const AUTH_PANEL = 'mx-auto w-full max-w-[420px] max-sm:px-4 sm:py-6';

/**
 * A page of the account area: one main landmark, one h1, a readable column. `auth` (sign-in,
 * sign-up) puts the h1 and the children in one centred panel and `after` below it; `wide` (the
 * Account page's panels) is a 576 px column.
 */
export function AccountMain({
  title,
  width = 'form',
  after,
  children,
}: {
  readonly title: string;
  readonly width?: 'form' | 'auth' | 'wide';
  readonly after?: ReactNode;
  readonly children: ReactNode;
}) {
  if (width === 'auth') {
    return (
      <Page>
        <Panel className={AUTH_PANEL}>
          <h1 className="text-2xl font-semibold">{title}</h1>
          {children}
        </Panel>
        {after}
      </Page>
    );
  }
  return (
    <Page>
      <h1 className="text-2xl font-semibold">{title}</h1>
      <div className={width === 'wide' ? 'max-w-xl' : 'max-w-md'}>{children}</div>
    </Page>
  );
}
