// PokéPrices display preferences: the theme and the up/down palette. Stored in localStorage,
// written to <html data-theme data-palette>, and exposed to React through usePreferences(). The
// inline script in index.html applies the same rules before first paint; keep the two in step.
import { useSyncExternalStore } from 'react';

export const THEME_KEY = 'pokeprices:theme';
export const PALETTE_KEY = 'pokeprices:palette';
export const THEMES = ['system', 'light', 'dark'] as const;
export const PALETTES = ['green-red', 'blue-orange'] as const;

export type ThemeChoice = (typeof THEMES)[number];
export type Palette = (typeof PALETTES)[number];
type ResolvedTheme = 'light' | 'dark';

interface Preferences {
  /** What the visitor chose (system follows the operating system). */
  readonly theme: ThemeChoice;
  readonly palette: Palette;
  /** The theme in use: system resolved through prefers-color-scheme. */
  readonly resolvedTheme: ResolvedTheme;
}

const DARK_QUERY = '(prefers-color-scheme: dark)';

/** A stored value if it is one of the allowed ones, else the fallback (blocked storage included). */
function stored<T extends string>(key: string, allowed: readonly T[], fallback: T): T {
  let raw: string | null;
  try {
    raw = window.localStorage.getItem(key);
  } catch {
    return fallback;
  }
  return allowed.find((value) => value === raw) ?? fallback;
}

function persist(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    // Blocked or full storage: the choice holds for this visit only.
  }
}

/** The operating system's dark-scheme query, or null where matchMedia is missing or fails. */
function darkQuery(): MediaQueryList | null {
  try {
    return window.matchMedia(DARK_QUERY);
  } catch {
    return null;
  }
}

function resolve(theme: ThemeChoice, palette: Palette): Preferences {
  const dark = theme === 'dark' || (theme === 'system' && darkQuery()?.matches === true);
  return { theme, palette, resolvedTheme: dark ? 'dark' : 'light' };
}

/** The preferences as storage and the operating system state them right now. */
export function readPreferences(): Preferences {
  return resolve(stored(THEME_KEY, THEMES, 'system'), stored(PALETTE_KEY, PALETTES, 'green-red'));
}

function applyToDocument(preferences: Preferences): void {
  const root = document.documentElement;
  root.setAttribute('data-theme', preferences.resolvedTheme);
  root.setAttribute('data-palette', preferences.palette);
}

// One shared snapshot while anything reads the preferences; dropped when the last reader goes, so
// the next mount reads storage afresh.
let snapshot: Preferences | null = null;
let systemQuery: MediaQueryList | null = null;
const listeners = new Set<() => void>();

function current(): Preferences {
  snapshot ??= readPreferences();
  return snapshot;
}

function publish(next: Preferences): void {
  const previous = current();
  applyToDocument(next);
  if (
    previous.theme === next.theme &&
    previous.palette === next.palette &&
    previous.resolvedTheme === next.resolvedTheme
  ) {
    return;
  }
  snapshot = next;
  for (const listener of listeners) listener();
}

function onSystemChange(): void {
  const now = current();
  if (now.theme === 'system') publish(resolve('system', now.palette));
}

function subscribe(listener: () => void): () => void {
  if (listeners.size === 0) {
    systemQuery = darkQuery();
    try {
      systemQuery?.addEventListener('change', onSystemChange);
    } catch {
      // A MediaQueryList without addEventListener: the theme still resolves, it just won't follow.
      systemQuery = null;
    }
  }
  listeners.add(listener);
  applyToDocument(current());
  return () => {
    listeners.delete(listener);
    if (listeners.size === 0) {
      systemQuery?.removeEventListener('change', onSystemChange);
      systemQuery = null;
      snapshot = null;
    }
  };
}

/** Chooses a theme: stored, applied to <html>, and passed to every usePreferences() reader. */
export function setTheme(theme: ThemeChoice): void {
  persist(THEME_KEY, theme);
  publish(resolve(theme, current().palette));
}

/** Chooses the up/down palette, the same way. */
export function setPalette(palette: Palette): void {
  persist(PALETTE_KEY, palette);
  publish(resolve(current().theme, palette));
}

/** The current preferences; re-renders on a choice and on a system change while theme is system. */
export function usePreferences(): Preferences {
  return useSyncExternalStore(subscribe, current);
}
