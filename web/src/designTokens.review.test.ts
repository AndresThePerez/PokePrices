import { compile } from 'tailwindcss';
import { describe, expect, it } from 'vitest';

// Review tests for D1-i1-T01. They read the shipped index.css (Vitest blanks .css imports) and
// resolve the tokens the way the cascade does, so they guard the VALUES the pages will get in all
// four theme x palette pairs, not the constants of preferences.test.ts.
const NODE_FS = 'node:fs';
const { readFileSync } = (await import(/* @vite-ignore */ NODE_FS)) as {
  readonly readFileSync: (path: string, encoding: 'utf8') => string;
};
const testFile = import.meta.url;
const indexCss = readFileSync(new URL('index.css', testFile).pathname, 'utf8');
const tailwindDir = new URL('../node_modules/tailwindcss/', testFile).pathname;

type Tokens = Record<string, string>;

interface Block {
  readonly selector: string;
  readonly body: string;
}

function blocks(css: string): Block[] {
  const plain = css.replace(/\/\*[\s\S]*?\*\//g, '');
  const found: Block[] = [];
  for (const match of plain.matchAll(/([^{};]+)\{([^{}]*)\}/g)) {
    const selector = (match[1] ?? '')
      .trim()
      .replace(/\s+/g, ' ')
      .replace(/=(['"])([^'"\]]*)\1\]/g, '=$2]');
    found.push({ selector, body: match[2] ?? '' });
  }
  return found;
}

/** Every `--name: value;` of a body, in order, duplicates kept. */
function declared(body: string): [string, string][] {
  return [...body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)].map((m) => [
    m[1] ?? '',
    (m[2] ?? '').trim(),
  ]);
}

function only(selector: string): Block {
  const found = blocks(indexCss).filter((block) => block.selector === selector);
  expect(found).toHaveLength(1);
  return found[0] ?? { selector, body: '' };
}

const THEME = '@theme static';
const DARK = '[data-theme=dark]';
const PALETTE = '[data-palette=blue-orange]';
const BOTH = '[data-theme=dark][data-palette=blue-orange]';

/** The tokens a page sees: :root, then every matching rule in source order (the cascade). */
function resolved(theme: 'light' | 'dark', palette: 'green-red' | 'blue-orange'): Tokens {
  const order = [
    THEME,
    ...(theme === 'dark' ? [DARK] : []),
    ...(palette === 'blue-orange' ? [PALETTE] : []),
    ...(theme === 'dark' && palette === 'blue-orange' ? [BOTH] : []),
  ];
  const tokens: Tokens = {};
  for (const selector of order) {
    for (const [name, value] of declared(only(selector).body)) tokens[name] = value;
  }
  return tokens;
}

function channel(hex: string, at: number): number {
  const value = parseInt(hex.slice(at, at + 2), 16) / 255;
  return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
}

function luminance(hex: string): number {
  expect(hex).toMatch(/^#[0-9a-f]{6}$/);
  return 0.2126 * channel(hex, 1) + 0.7152 * channel(hex, 3) + 0.0722 * channel(hex, 5);
}

function contrast(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return ((hi ?? 0) + 0.05) / ((lo ?? 0) + 0.05);
}

const COMBOS = [
  ['light', 'green-red'],
  ['light', 'blue-orange'],
  ['dark', 'green-red'],
  ['dark', 'blue-orange'],
] as const;

function need(tokens: Tokens, name: string): string {
  const value = tokens[name];
  expect(value, name).toBeDefined();
  return value ?? '';
}

describe('D1-i1-T01 token system as the cascade resolves it', () => {
  it.each(COMBOS)(
    'keeps text, status, accent, focus and chart tokens at their WCAG floor (%s, %s)',
    (theme, palette) => {
      const t = resolved(theme, palette);
      const at = (name: string): string => need(t, `--color-${name}`);
      const surfaces = ['surface', 'surface-raised', 'surface-sunken'];
      const failures: string[] = [];
      const floor = (fg: string, bg: string, min: number): void => {
        const ratio = contrast(at(fg), at(bg));
        if (ratio < min) failures.push(`${fg} on ${bg} ${ratio.toFixed(2)} < ${String(min)}`);
      };
      for (const bg of surfaces) {
        floor('ink', bg, 4.5);
        floor('ink-muted', bg, 4.5);
        floor('flat', bg, 4.5);
        floor('accent', bg, 4.5);
        floor('focus', bg, 3);
      }
      for (const bg of ['surface', 'surface-raised']) {
        floor('line', bg, 3);
        floor('up', bg, 4.5);
        floor('down', bg, 4.5);
        floor('chart-text', bg, 4.5);
        floor('chart-line', bg, 3);
      }
      floor('on-accent', 'accent', 4.5);
      floor('ink', 'accent-tint', 4.5);
      floor('ink-muted', 'accent-tint', 4.5);
      floor('info', 'info-tint', 4.5);
      floor('warn', 'warn-tint', 4.5);
      floor('problem', 'problem-tint', 4.5);
      expect(failures).toEqual([]);
    },
  );

  it.each(COMBOS)(
    'keeps up, down, flat and the UI accent four different colours (%s, %s)',
    (theme, palette) => {
      const t = resolved(theme, palette);
      const set = ['up', 'down', 'flat', 'accent'].map((name) => need(t, `--color-${name}`));
      expect(new Set(set).size).toBe(4);
    },
  );

  it('combines dark and blue-orange as intended: dark surfaces with the dark blue-orange up and down', () => {
    expect(resolved('light', 'green-red')).toMatchObject({
      '--color-up': '#03654a',
      '--color-down': '#a91b1b',
      '--color-surface': '#faf7f2',
    });
    expect(resolved('light', 'blue-orange')).toMatchObject({
      '--color-up': '#1d48c8',
      '--color-down': '#a3370a',
      '--color-surface': '#faf7f2',
    });
    expect(resolved('dark', 'green-red')).toMatchObject({
      '--color-up': '#34d399',
      '--color-down': '#f87171',
      '--color-surface': '#171412',
    });
    expect(resolved('dark', 'blue-orange')).toMatchObject({
      '--color-up': '#60a5fa',
      '--color-down': '#fb923c',
      '--color-surface': '#171412',
      '--color-chart-line': '#e7e0d6',
    });
  });

  it('lets the palette rules override up and down and nothing else', () => {
    for (const selector of [PALETTE, BOTH]) {
      expect(declared(only(selector).body).map(([name]) => name)).toEqual([
        '--color-up',
        '--color-down',
      ]);
    }
  });

  it('restates every light colour token in the dark rule, so no dark page inherits a light value', () => {
    const light = declared(only(THEME).body)
      .map(([name]) => name)
      .filter((name) => name.startsWith('--color-'));
    const dark = new Set(declared(only(DARK).body).map(([name]) => name));
    expect(light.filter((name) => !dark.has(name))).toEqual([]);
    expect(light.length).toBeGreaterThan(20);
  });

  it('never declares the same custom property twice in the theme block or in a dark or palette rule', () => {
    for (const selector of [THEME, DARK, PALETTE, BOTH]) {
      const names = declared(only(selector).body).map(([name]) => name);
      expect(
        names.filter((name, i) => names.indexOf(name) !== i),
        selector,
      ).toEqual([]);
    }
  });

  it('makes the focus colour the accent colour in both themes, and rings focus without a literal colour', () => {
    expect(need(resolved('light', 'green-red'), '--color-focus')).toBe(
      need(resolved('light', 'green-red'), '--color-accent'),
    );
    expect(need(resolved('dark', 'green-red'), '--color-focus')).toBe(
      need(resolved('dark', 'green-red'), '--color-accent'),
    );
    const base = blocks(indexCss).filter(
      (block) => !block.selector.startsWith('@') && !block.selector.startsWith('['),
    );
    const focus = base.filter((block) => block.selector === ':focus-visible');
    expect(focus).toHaveLength(1);
    for (const block of base)
      expect(block.body, block.selector).not.toMatch(/#[0-9a-f]{3,8}\b|rgb\(|hsl\(/i);
  });

  it('draws the chart line in a neutral ink, not a hue, in both themes', () => {
    for (const theme of ['light', 'dark'] as const) {
      const hex = need(resolved(theme, 'green-red'), '--color-chart-line');
      const rgb = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
      expect(Math.max(...rgb) - Math.min(...rgb), theme).toBeLessThanOrEqual(24);
    }
  });

  it('holds the brief type scale (12/13/14/16/20/28/36 px on 16/18/20/22/26/34/40) and radii 2/4/6', () => {
    const t = resolved('light', 'green-red');
    const px = (name: string): number => {
      const value = need(t, name);
      const rem = /^([\d.]+)rem$/.exec(value);
      return rem ? Number(rem[1]) * 16 : Number.NaN;
    };
    const sizes = ['xs', 'meta', 'sm', 'base', 'lg', '2xl', '4xl'];
    expect(sizes.map((s) => px(`--text-${s}`))).toEqual([12, 13, 14, 16, 20, 28, 36]);
    expect(sizes.map((s) => px(`--text-${s}--line-height`))).toEqual([16, 18, 20, 22, 26, 34, 40]);
    expect(['sm', 'md', 'lg'].map((s) => need(t, `--radius-${s}`))).toEqual(['2px', '4px', '6px']);
    // The default text-xl and text-3xl stay untouched for the migration batches to rewrite.
    expect(Object.keys(t)).not.toContain('--text-xl');
    expect(Object.keys(t)).not.toContain('--text-3xl');
  });
});

describe('D1-i1-T01 index.css through the Tailwind v4 compiler', () => {
  async function build(candidates: string[]): Promise<string> {
    const compiler = await compile(indexCss, {
      base: tailwindDir,
      loadStylesheet: (id: string) => {
        const path =
          id === 'tailwindcss'
            ? `${tailwindDir}index.css`
            : `${tailwindDir}${id.replace(/^tailwindcss\//, '')}.css`;
        return Promise.resolve({ path, base: tailwindDir, content: readFileSync(path, 'utf8') });
      },
    });
    return compiler.build(candidates);
  }

  it('compiles the num utility to the mono stack with tabular figures', async () => {
    const css = await build(['num']);
    expect(css).toMatch(
      /font-family:\s*var\(--font-mono\);\s*font-variant-numeric:\s*tabular-nums;/,
    );
  });

  it('compiles the meta text size to the 13 px token, and leaves text-xl and text-3xl on Tailwind defaults', async () => {
    const css = await build(['text-meta', 'text-xl', 'text-3xl', 'rounded-md']);
    expect(css).toMatch(
      /font-size:\s*var\(--text-meta\);\s*line-height:\s*var\(--tw-leading,\s*var\(--text-meta--line-height\)\)/,
    );
    expect(css).toMatch(/--text-meta:\s*0\.8125rem;/);
    expect(css).toMatch(/--text-xl:\s*1\.25rem;/);
    expect(css).toMatch(/--text-3xl:\s*1\.875rem;/);
    expect(css).toMatch(/border-radius:\s*var\(--radius-md\)/);
    expect(css).toMatch(/--radius-md:\s*4px;/);
  });

  it('emits the system sans stack once, replacing Tailwind default, and keeps dark rules outside any layer', async () => {
    const css = await build(['bg-surface']);
    expect(css.match(/--font-sans:/g)).toHaveLength(1);
    expect(css).toMatch(/--font-sans:\s*ui-sans-serif, system-ui, -apple-system, 'Segoe UI'/);
    // Unlayered rules beat the @layer theme :root, so the dark and palette values win.
    const topLevel = css.replace(/@layer [^{;]+\{(?:[^{}]|\{[^{}]*\})*\}/g, '');
    expect(topLevel).toContain("[data-theme='dark'] {");
    expect(topLevel.indexOf("[data-theme='dark'] {")).toBeLessThan(
      topLevel.indexOf("[data-palette='blue-orange'] {"),
    );
    expect(topLevel.indexOf("[data-palette='blue-orange'] {")).toBeLessThan(
      topLevel.indexOf("[data-theme='dark'][data-palette='blue-orange'] {"),
    );
  });
});
