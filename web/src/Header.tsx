// The PokéPrices header on every route: the main links and the two display settings (D8).
import { useId, type ChangeEvent } from 'react';
import Link from './Link';
import {
  PALETTES,
  THEMES,
  setPalette,
  setTheme,
  usePreferences,
  type Palette,
  type ThemeChoice,
} from './preferences';

const FOCUS =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 dark:focus-visible:outline-sky-400';
const SELECT = `min-h-11 rounded-md border border-slate-300 bg-white px-2 text-sm text-slate-900 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 ${FOCUS}`;

const THEME_LABELS: Record<ThemeChoice, string> = {
  system: 'System',
  light: 'Light',
  dark: 'Dark',
};

const PALETTE_LABELS: Record<Palette, string> = {
  'green-red': 'Green and red',
  'blue-orange': 'Blue and orange',
};

function chooseTheme(event: ChangeEvent<HTMLSelectElement>): void {
  const value = event.target.value;
  const theme = THEMES.find((option) => option === value);
  if (theme !== undefined) setTheme(theme);
}

function choosePalette(event: ChangeEvent<HTMLSelectElement>): void {
  const value = event.target.value;
  const palette = PALETTES.find((option) => option === value);
  if (palette !== undefined) setPalette(palette);
}

export default function Header() {
  const { theme, palette } = usePreferences();
  const themeId = useId();
  const paletteId = useId();
  return (
    <header className="border-b border-slate-200 dark:border-slate-700">
      <div className="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-x-6 px-6 py-1">
        <nav aria-label="Main">
          <ul className="flex flex-wrap items-center gap-x-5">
            <li>
              <Link
                to="/"
                className={`inline-flex min-h-11 items-center text-lg font-semibold ${FOCUS}`}
              >
                PokéPrices
              </Link>
            </li>
            <li>
              <Link
                to="/search"
                className={`inline-flex min-h-11 items-center underline-offset-4 hover:underline ${FOCUS}`}
              >
                Search
              </Link>
            </li>
          </ul>
        </nav>
        <div className="flex flex-wrap items-center gap-x-4 text-sm">
          <span className="flex items-center gap-2">
            <label htmlFor={themeId}>Theme</label>
            <select id={themeId} value={theme} onChange={chooseTheme} className={SELECT}>
              {THEMES.map((option) => (
                <option key={option} value={option}>
                  {THEME_LABELS[option]}
                </option>
              ))}
            </select>
          </span>
          <span className="flex items-center gap-2">
            <label htmlFor={paletteId}>Up/down colours</label>
            <select id={paletteId} value={palette} onChange={choosePalette} className={SELECT}>
              {PALETTES.map((option) => (
                <option key={option} value={option}>
                  {PALETTE_LABELS[option]}
                </option>
              ))}
            </select>
          </span>
        </div>
      </div>
    </header>
  );
}
