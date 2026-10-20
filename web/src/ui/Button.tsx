// The Button primitive (D1): five variants on the design tokens, every one a 44 px target. A
// segment is a toggle: aria-pressed carries its state and the check glyph is CSS, not text.
import type { ButtonHTMLAttributes, InputHTMLAttributes } from 'react';
import {
  DANGER_BUTTON,
  PRIMARY_BUTTON,
  QUIET_BUTTON,
  SECONDARY_BUTTON,
  SEGMENT_BUTTON,
} from './classes';

export type ButtonVariant = 'primary' | 'secondary' | 'quiet' | 'danger' | 'segment';

const VARIANT: Readonly<Record<ButtonVariant, string>> = {
  primary: PRIMARY_BUTTON,
  secondary: SECONDARY_BUTTON,
  quiet: QUIET_BUTTON,
  danger: DANGER_BUTTON,
  segment: SEGMENT_BUTTON,
};

/** The class string a variant applies, with the caller's extra classes after it. */
function buttonClass(variant: ButtonVariant, extra?: string): string {
  return extra === undefined || extra === '' ? VARIANT[variant] : `${VARIANT[variant]} ${extra}`;
}

export interface ButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'aria-pressed'> {
  readonly variant: ButtonVariant;
  readonly type?: 'button' | 'submit' | 'reset' | undefined;
  /** A segment's state, as aria-pressed; ignored by the other variants. */
  readonly pressed?: boolean | undefined;
}

/** A real <button>, of type button unless told otherwise. */
export function Button({ variant, type = 'button', pressed, className, ...rest }: ButtonProps) {
  return (
    <button
      {...rest}
      type={type}
      aria-pressed={variant === 'segment' ? pressed === true : undefined}
      className={buttonClass(variant, className)}
    />
  );
}

export interface ButtonInputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  readonly variant: ButtonVariant;
  /** The button's words, shown as the input's value. */
  readonly value: string;
}

/**
 * A button that is an <input type="button"> (R221): its words are its value, not text in the
 * surrounding element, so rows keep their exact text. Name it with aria-label.
 */
export function ButtonInput({ variant, className, ...rest }: ButtonInputProps) {
  return <input {...rest} type="button" className={buttonClass(variant, className)} />;
}
