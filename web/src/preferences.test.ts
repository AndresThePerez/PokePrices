import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import indexHtml from '../index.html?raw';
import {
  PALETTE_KEY,
  THEME_KEY,
  readPreferences,
  setPalette,
  setTheme,
  usePreferences,
} from './preferences';

const DARK_QUERY = '(prefers-color-scheme: dark)';

// Vitest blanks every .css import, ?raw included (its css option is off), so the stylesheet is
// read from disk. tsconfig.app.json carries no Node types, hence the untyped specifier and the
// narrow cast. index.html comes through a ?raw import.
const NODE_FS = 'node:fs';
const { readFileSync } = (await import(/* @vite-ignore */ NODE_FS)) as {
  readonly readFileSync: (path: string, encoding: 'utf8') => string;
};
const testFile = import.meta.url;
const indexCss = readFileSync(new URL('index.css', testFile).pathname, 'utf8');

interface FakeMedia {
  readonly queries: string[];
  readonly listenerCount: () => number;
  readonly flip: (dark: boolean) => void;
}

/** A matchMedia that answers the dark-scheme query and lets the test flip the system setting. */
function fakeMatchMedia(dark: boolean): FakeMedia {
  const listeners = new Set<(event: MediaQueryListEvent) => void>();
  const queries: string[] = [];
  const list = {
    matches: dark,
    media: DARK_QUERY,
    addEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => {
      listeners.add(listener);
    },
    removeEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => {
      listeners.delete(listener);
    },
  };
  vi.stubGlobal('matchMedia', (query: string) => {
    queries.push(query);
    return list as unknown as MediaQueryList;
  });
  return {
    queries,
    listenerCount: () => listeners.size,
    flip: (next: boolean) => {
      list.matches = next;
      for (const listener of listeners) listener({ matches: next } as MediaQueryListEvent);
    },
  };
}

/**
 * Runs the inline script of index.html the way the browser does: as a classic script element
 * (Vitest's jsdom runs scripts). Scripts run in jsdom's own window, not in the test global, so a
 * matchMedia fake is handed over through the shared document object.
 */
function runPrePaint(matchMedia?: unknown): void {
  const script = /<script>([\s\S]*?)<\/script>/.exec(indexHtml)?.[1] ?? '';
  const run = (source: string): void => {
    const element = document.createElement('script');
    element.textContent = source;
    document.head.append(element);
    element.remove();
  };
  if (matchMedia !== undefined) {
    Object.defineProperty(document, 'prePaintMatchMedia', {
      configurable: true,
      value: matchMedia,
    });
    run('window.matchMedia = document.prePaintMatchMedia;');
    onTestFinished(() => {
      run('delete window.matchMedia;');
      Reflect.deleteProperty(document, 'prePaintMatchMedia');
    });
  }
  run(script);
}

function rootAttributes(): readonly [string | null, string | null] {
  const root = document.documentElement;
  return [root.getAttribute('data-theme'), root.getAttribute('data-palette')];
}

beforeEach(() => {
  window.localStorage.clear();
  document.documentElement.removeAttribute('data-theme');
  document.documentElement.removeAttribute('data-palette');
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  window.localStorage.clear();
});

describe('display preferences (D8)', () => {
  it('defaults to system and green-red with empty storage, and resolves system to light without matchMedia', () => {
    expect(typeof window.matchMedia).toBe('undefined');
    expect(readPreferences()).toEqual({
      theme: 'system',
      palette: 'green-red',
      resolvedTheme: 'light',
    });
    const { result } = renderHook(() => usePreferences());
    expect(result.current).toEqual({
      theme: 'system',
      palette: 'green-red',
      resolvedTheme: 'light',
    });
    expect(rootAttributes()).toEqual(['light', 'green-red']);
  });

  it.each([
    ['system', 'green-red', 'light'],
    ['light', 'green-red', 'light'],
    ['dark', 'green-red', 'dark'],
    ['light', 'blue-orange', 'light'],
    ['dark', 'blue-orange', 'dark'],
  ] as const)('reads theme %s and palette %s from storage', (theme, palette, resolved) => {
    window.localStorage.setItem(THEME_KEY, theme);
    window.localStorage.setItem(PALETTE_KEY, palette);
    const { result } = renderHook(() => usePreferences());
    expect(result.current).toEqual({ theme, palette, resolvedTheme: resolved });
    expect(rootAttributes()).toEqual([resolved, palette]);
  });

  it('resolves a stored system theme through matchMedia', () => {
    const media = fakeMatchMedia(true);
    window.localStorage.setItem(THEME_KEY, 'system');
    const { result } = renderHook(() => usePreferences());
    expect(result.current.resolvedTheme).toBe('dark');
    expect(media.queries).toContain(DARK_QUERY);
    expect(rootAttributes()).toEqual(['dark', 'green-red']);
  });

  it.each(['Dark', 'auto', '', ' dark', 'green-red'])(
    'falls back to the defaults for the unknown stored value %j',
    (value) => {
      window.localStorage.setItem(THEME_KEY, value);
      window.localStorage.setItem(PALETTE_KEY, value === 'green-red' ? 'red-green' : value);
      expect(readPreferences()).toEqual({
        theme: 'system',
        palette: 'green-red',
        resolvedTheme: 'light',
      });
    },
  );

  it('falls back to the defaults, without crashing, when localStorage throws on read and on write', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });
    const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('full', 'QuotaExceededError');
    });
    const { result } = renderHook(() => usePreferences());
    expect(result.current).toEqual({
      theme: 'system',
      palette: 'green-red',
      resolvedTheme: 'light',
    });
    expect(() => {
      act(() => {
        setTheme('dark');
        setPalette('blue-orange');
      });
    }).not.toThrow();
    expect(setItem).toHaveBeenCalledWith(THEME_KEY, 'dark');
    // The choice still holds for this visit, even though it could not be stored.
    expect(result.current.resolvedTheme).toBe('dark');
    expect(rootAttributes()).toEqual(['dark', 'blue-orange']);
  });

  it('falls back to light when matchMedia throws', () => {
    vi.stubGlobal('matchMedia', () => {
      throw new TypeError('not supported');
    });
    expect(readPreferences().resolvedTheme).toBe('light');
  });

  it('follows the system change event while the theme is system, and ignores it otherwise', () => {
    const media = fakeMatchMedia(false);
    const { result } = renderHook(() => usePreferences());
    expect(result.current.resolvedTheme).toBe('light');
    act(() => {
      media.flip(true);
    });
    expect(result.current.resolvedTheme).toBe('dark');
    expect(rootAttributes()[0]).toBe('dark');
    act(() => {
      setTheme('light');
    });
    act(() => {
      media.flip(false);
      media.flip(true);
    });
    expect(result.current).toEqual({
      theme: 'light',
      palette: 'green-red',
      resolvedTheme: 'light',
    });
    expect(rootAttributes()[0]).toBe('light');
  });

  it('persists a choice and applies it to the html element', () => {
    const { result } = renderHook(() => usePreferences());
    act(() => {
      setTheme('dark');
    });
    expect(window.localStorage.getItem(THEME_KEY)).toBe('dark');
    expect(result.current.resolvedTheme).toBe('dark');
    act(() => {
      setPalette('blue-orange');
    });
    expect(window.localStorage.getItem(PALETTE_KEY)).toBe('blue-orange');
    expect(result.current.palette).toBe('blue-orange');
    expect(rootAttributes()).toEqual(['dark', 'blue-orange']);
  });

  it('stops listening to the system setting once nothing reads the preferences', () => {
    const media = fakeMatchMedia(false);
    const { unmount } = renderHook(() => usePreferences());
    expect(media.listenerCount()).toBe(1);
    unmount();
    expect(media.listenerCount()).toBe(0);
  });
});

describe('pre-paint script in index.html (D8)', () => {
  it('declares both colour schemes', () => {
    expect(indexHtml).toContain('<meta name="color-scheme" content="light dark" />');
  });

  it('sets the theme in <head>, before the module script, from both storage keys', () => {
    const head = indexHtml.slice(0, indexHtml.indexOf('</head>'));
    const inline = /<script>([\s\S]*?)<\/script>/.exec(head);
    expect(inline).not.toBeNull();
    const script = inline?.[1] ?? '';
    expect(indexHtml.indexOf(script)).toBeLessThan(indexHtml.indexOf('/src/main.tsx'));
    expect(script).toContain("'pokeprices:theme'");
    expect(script).toContain("'pokeprices:palette'");
    expect(script).toContain('(prefers-color-scheme: dark)');
    expect(script).toContain('data-theme');
    expect(script).toContain('data-palette');
    expect(script).toContain('try');
  });

  it.each([
    [null, null, false, 'light', 'green-red'],
    ['dark', 'blue-orange', false, 'dark', 'blue-orange'],
    ['light', null, true, 'light', 'green-red'],
    ['system', null, true, 'dark', 'green-red'],
    ['bogus', 'bogus', false, 'light', 'green-red'],
  ] as const)(
    'applies the same rules as preferences.ts (theme %s, palette %s, system dark %s)',
    (theme, palette, systemDark, expectedTheme, expectedPalette) => {
      if (theme !== null) window.localStorage.setItem(THEME_KEY, theme);
      if (palette !== null) window.localStorage.setItem(PALETTE_KEY, palette);
      if (systemDark) fakeMatchMedia(true);
      // The shipped script text itself runs, the same bytes the browser runs.
      runPrePaint(systemDark ? globalThis.matchMedia : undefined);
      expect(rootAttributes()).toEqual([expectedTheme, expectedPalette]);
      expect(readPreferences()).toEqual({
        theme: theme === 'bogus' || theme === null ? 'system' : theme,
        palette: expectedPalette,
        resolvedTheme: expectedTheme,
      });
    },
  );

  it('survives a throwing localStorage and a missing matchMedia with the defaults', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });
    expect(() => {
      runPrePaint();
    }).not.toThrow();
    expect(rootAttributes()).toEqual(['light', 'green-red']);
  });
});

/**
 * The CSS with comments removed, split into rules: selector (or at-rule prelude) and body.
 * Attribute values are compared unquoted: [data-theme='dark'] (Prettier's form) is [data-theme=dark].
 */
function rules(css: string): { readonly selector: string; readonly body: string }[] {
  const plain = css.replace(/\/\*[\s\S]*?\*\//g, '');
  const found: { selector: string; body: string }[] = [];
  for (const match of plain.matchAll(/([^{};]+)\{([^{}]*)\}/g)) {
    const selector = (match[1] ?? '')
      .trim()
      .replace(/\s+/g, ' ')
      .replace(/=(['"])([^'"\]]*)\1\]/g, '=$2]');
    found.push({ selector, body: match[2] ?? '' });
  }
  return found;
}

function declarations(body: string): Map<string, string> {
  const map = new Map<string, string>();
  for (const match of body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
    map.set(match[1] ?? '', (match[2] ?? '').trim());
  }
  return map;
}

const LIGHT = {
  '--color-up': '#047857',
  '--color-down': '#b91c1c',
  '--color-flat': '#475569',
  '--color-chart-line': '#0369a1',
  '--color-chart-filled': '#64748b',
  '--color-chart-text': '#475569',
  '--color-chart-grid': '#e2e8f0',
};
const DARK = {
  '--color-up': '#34d399',
  '--color-down': '#f87171',
  '--color-flat': '#94a3b8',
  '--color-chart-line': '#38bdf8',
  '--color-chart-filled': '#64748b',
  '--color-chart-text': '#94a3b8',
  '--color-chart-grid': '#1e293b',
};
const BLUE_ORANGE_LIGHT = { '--color-up': '#1d4ed8', '--color-down': '#c2410c' };
const BLUE_ORANGE_DARK = { '--color-up': '#60a5fa', '--color-down': '#fb923c' };

describe('colour tokens in index.css (D8, review F-P3-02)', () => {
  it('keeps the Tailwind import and adds a dark variant keyed on data-theme, never on prefers-color-scheme', () => {
    expect(indexCss).toContain("@import 'tailwindcss';");
    expect(indexCss).toContain(
      '@custom-variant dark (&:where([data-theme=dark], [data-theme=dark] *));',
    );
    expect(indexCss).not.toContain('prefers-color-scheme');
  });

  it('declares every token once in a single @theme static block at its light green-red value', () => {
    const themeBlocks = rules(indexCss).filter((rule) => rule.selector.startsWith('@theme'));
    expect(themeBlocks.map((rule) => rule.selector)).toEqual(['@theme static']);
    expect(Object.fromEntries(declarations(themeBlocks[0]?.body ?? ''))).toEqual(LIGHT);
  });

  it.each([
    ['[data-theme=dark]', DARK],
    ['[data-palette=blue-orange]', BLUE_ORANGE_LIGHT],
    ['[data-theme=dark][data-palette=blue-orange]', BLUE_ORANGE_DARK],
  ] as const)('declares the %s values with the exact hexes', (selector, expected) => {
    const matching = rules(indexCss).filter((rule) => rule.selector === selector);
    expect(matching).toHaveLength(1);
    expect(Object.fromEntries(declarations(matching[0]?.body ?? ''))).toEqual(expected);
  });

  it('declares no token anywhere else', () => {
    const allowed = new Set([
      '@theme static',
      '[data-theme=dark]',
      '[data-palette=blue-orange]',
      '[data-theme=dark][data-palette=blue-orange]',
    ]);
    for (const rule of rules(indexCss)) {
      if (allowed.has(rule.selector)) continue;
      expect([rule.selector, [...declarations(rule.body).keys()]]).toEqual([rule.selector, []]);
    }
  });
});
