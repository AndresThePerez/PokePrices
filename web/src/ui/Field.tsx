// The Field primitive (D1): a visible label, the control, then the hint and the error, both tied
// to the control with aria-describedby; an error also sets aria-invalid. Ids are the control's
// useId plus -hint and -error, as the account forms built them.
import { useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes } from 'react';
import { ERROR, HINT, INPUT, LABEL, SELECT } from './classes';

/** What Field hands its control: the id the label points at and the describing attributes. */
export interface ControlProps {
  readonly id: string;
  readonly 'aria-invalid'?: true | undefined;
  readonly 'aria-describedby'?: string | undefined;
}

export interface FieldProps {
  readonly label: string;
  readonly hint?: string | undefined;
  readonly error?: string | undefined;
  readonly children: (control: ControlProps) => ReactNode;
}

export function Field({ label, hint, error, children }: FieldProps) {
  const id = useId();
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const described = [hint === undefined ? null : hintId, error === undefined ? null : errorId]
    .filter((part) => part !== null)
    .join(' ');
  return (
    <div>
      <label htmlFor={id} className={LABEL}>
        {label}
      </label>
      {children({
        id,
        'aria-invalid': error === undefined ? undefined : true,
        'aria-describedby': described === '' ? undefined : described,
      })}
      {hint !== undefined && (
        <p id={hintId} className={`mt-1 ${HINT}`}>
          {hint}
        </p>
      )}
      {error !== undefined && (
        <p id={errorId} className={`mt-1 ${ERROR}`}>
          {error}
        </p>
      )}
    </div>
  );
}

function withExtra(base: string, extra: string | undefined): string {
  return extra === undefined || extra === '' ? base : `${base} ${extra}`;
}

/** A 44 px text input on the raised surface; extra classes (width, margin) come after. */
export function Input({ className, ...rest }: InputHTMLAttributes<HTMLInputElement>) {
  return <input {...rest} className={withExtra(INPUT, className)} />;
}

export interface SelectOption {
  readonly value: string;
  readonly label: string;
}

export interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> {
  readonly options: readonly SelectOption[];
}

/** A 44 px select box whose options are given in order as value and text. */
export function Select({ options, className, ...rest }: SelectProps) {
  return (
    <select {...rest} className={withExtra(SELECT, className)}>
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  );
}
