// The class strings of the design-system primitives, on the tokens of index.css only. The
// account pages import FOCUS, MUTED, TEXT_LINK, PRIMARY_BUTTON and SECONDARY_BUTTON from this file
// directly, so these strings are exactly what Button and Field apply. Every control keeps
// the literal min-h-11 (44 px; the shortControls pin) and no transition or animation class.

/** The keyboard focus ring, in the focus token. */
export const FOCUS =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';

/** Muted text (≥4.5:1 on every surface in both themes). */
export const MUTED = 'text-ink-muted';

/** An in-text link with a 44 px target. */
export const TEXT_LINK = `inline-flex min-h-11 items-center underline underline-offset-4 ${FOCUS}`;

const BUTTON =
  `inline-flex min-h-11 min-w-11 items-center justify-center rounded-md px-4 text-sm font-semibold ` +
  `disabled:opacity-60 ${FOCUS}`;

/** The one filled button of a form: accent fill, on-accent text. */
export const PRIMARY_BUTTON = `${BUTTON} bg-accent text-on-accent`;

/** An outlined button: raised surface, the 1 px line border (≥3:1), ink text. */
export const SECONDARY_BUTTON = `${BUTTON} border border-line bg-surface-raised text-ink`;

/** A borderless button: no fill until hovered. */
export const QUIET_BUTTON = `${BUTTON} bg-transparent text-ink hover:bg-surface-sunken`;

/** A destructive action: problem fill. */
export const DANGER_BUTTON = `${BUTTON} bg-problem text-on-accent`;

/**
 * One segment of a segmented control: a 4 px box with the 1 px line border. Pressed is the accent
 * tint plus a CSS check glyph (no DOM text) and an accent border, so it is never hue alone.
 */
export const SEGMENT_BUTTON =
  `${BUTTON} border border-line bg-surface-raised text-ink ` +
  `aria-pressed:border-accent aria-pressed:bg-accent-tint ` +
  `aria-pressed:before:mr-1 aria-pressed:before:content-['✓']`;

/**
 * A text input or select box, 44 px: raised surface, the 1 px line border, ink text. Read-only sits
 * in the sunken step with the ink-muted border (the line token is under 3:1 on sunken in dark);
 * aria-invalid turns the border to problem.
 */
export const INPUT =
  `block min-h-11 rounded-md border border-line bg-surface-raised px-3 py-2 text-base text-ink ` +
  `read-only:border-ink-muted read-only:bg-surface-sunken aria-invalid:border-problem ${FOCUS}`;

/** A select box: the input box without the read-only state, which a select does not have. */
export const SELECT =
  `block min-h-11 rounded-md border border-line bg-surface-raised px-3 py-2 text-base text-ink ` +
  `aria-invalid:border-problem ${FOCUS}`;

/** A field's label: 14 px, 600. */
export const LABEL = 'text-sm font-semibold text-ink';

/** A field's hint: 13 px, muted. */
export const HINT = `text-meta ${MUTED}`;

/** A field's error: 14 px, 600, problem. */
export const ERROR = 'text-sm font-semibold text-problem';
