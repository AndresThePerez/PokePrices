// The PokéPrices footer (D1): the display settings strip (D8), Theme and Up/down colours, moved
// out of the header bar. Labels and option strings are unchanged. On /account the same settings sit
// in the page's Display panel (R297), so the footer keeps its landmark and top rule but no content
// there and every control name stays unique. It reads no session and sends no request.
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
import { useRoute } from './router';
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

const LAYOUT = {
  // The footer strip: one row from 640 px, each label beside its select.
  strip: {
    row: 'mx-auto flex max-w-3xl flex-col gap-4 px-4 py-6 sm:flex-row sm:items-center sm:gap-8 sm:px-6',
    setting: SETTING,
    select: undefined,
  },
  // The Account page's Display panel: label above a full-width select, side by side from 640 px.
  panel: {
    row: 'mt-4 grid gap-4 sm:grid-cols-2',
    setting: 'flex min-w-0 flex-col gap-1',
    select: 'w-full',
  },
} as const;

/** The Theme and Up/down colours selects with their visible labels. */
export function DisplaySettings({ layout }: { readonly layout: keyof typeof LAYOUT }) {
  const { theme, palette } = usePreferences();
  const themeId = useId();
  const paletteId = useId();
  const classes = LAYOUT[layout];
  return (
    <div className={classes.row}>
      <div className={classes.setting}>
        <label htmlFor={themeId} className={LABEL}>
          Theme
        </label>
        <Select
          id={themeId}
          value={theme}
          onChange={chooseTheme}
          options={THEME_OPTIONS}
          className={classes.select}
        />
      </div>
      <div className={classes.setting}>
        <label htmlFor={paletteId} className={LABEL}>
          Up/down colours
        </label>
        <Select
          id={paletteId}
          value={palette}
          onChange={choosePalette}
          options={PALETTE_OPTIONS}
          className={classes.select}
        />
      </div>
    </div>
  );
}

export default function Footer() {
  usePreferences(); // keeps the snapshot subscribed while the Display panel is unmounted
  const { route } = useRoute();
  return (
    <footer className="mt-10 border-t border-line-subtle bg-surface">
      {route.name !== 'account' && <DisplaySettings layout="strip" />}
    </footer>
  );
}
