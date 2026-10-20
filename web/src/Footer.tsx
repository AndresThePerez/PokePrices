// The PokéPrices footer on every route (D1): the display settings strip (D8), Theme and Up/down
// colours, moved out of the header bar. Labels and option strings are unchanged. It reads no
// session and sends no request.
import { useId, type ChangeEvent } from 'react';
import {
  PALETTES,
  THEMES,
  setPalette,
  setTheme,
  usePreferences,
  type Palette,
  type ThemeChoice,
} from './preferences';
import { LABEL } from './ui/classes';
import { Select, type SelectOption } from './ui/Field';

const THEME_LABELS: Record<ThemeChoice, string> = {
  system: 'System',
  light: 'Light',
  dark: 'Dark',
};

const PALETTE_LABELS: Record<Palette, string> = {
  'green-red': 'Green and red',
  'blue-orange': 'Blue and orange',
};

const THEME_OPTIONS: readonly SelectOption[] = THEMES.map((value) => ({
  value,
  label: THEME_LABELS[value],
}));

const PALETTE_OPTIONS: readonly SelectOption[] = PALETTES.map((value) => ({
  value,
  label: PALETTE_LABELS[value],
}));

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

const SETTING = 'flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-2';

export default function Footer() {
  const { theme, palette } = usePreferences();
  const themeId = useId();
  const paletteId = useId();
  return (
    <footer className="mt-10 border-t border-line-subtle bg-surface">
      <div className="mx-auto flex max-w-3xl flex-col gap-4 px-4 py-6 sm:flex-row sm:items-center sm:gap-8 sm:px-6">
        <div className={SETTING}>
          <label htmlFor={themeId} className={LABEL}>
            Theme
          </label>
          <Select id={themeId} value={theme} onChange={chooseTheme} options={THEME_OPTIONS} />
        </div>
        <div className={SETTING}>
          <label htmlFor={paletteId} className={LABEL}>
            Up/down colours
          </label>
          <Select
            id={paletteId}
            value={palette}
            onChange={choosePalette}
            options={PALETTE_OPTIONS}
          />
        </div>
      </div>
    </footer>
  );
}
