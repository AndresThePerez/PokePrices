import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  PALETTE_KEY,
  THEME_KEY,
  readPreferences,
  setPalette,
  setTheme,
  usePreferences,
} from './preferences';

function fakeDarkOs(dark: boolean): { flip: (next: boolean) => void } {
  const listeners = new Set<() => void>();
  const list = {
    matches: dark,
    addEventListener: (_type: string, listener: () => void) => {
      listeners.add(listener);
    },
    removeEventListener: (_type: string, listener: () => void) => {
      listeners.delete(listener);
    },
  };
  vi.stubGlobal('matchMedia', () => list as unknown as MediaQueryList);
  return {
    flip: (next) => {
      list.matches = next;
      for (const listener of [...listeners]) listener();
    },
  };
}

function attribute(name: string): string | null {
  return document.documentElement.getAttribute(name);
}

beforeEach(() => {
  window.localStorage.clear();
  document.documentElement.removeAttribute('data-theme');
  document.documentElement.removeAttribute('data-palette');
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  window.localStorage.clear();
});

describe('preferences, edge cases (D8)', () => {
  it.each([
    'Dark',
    'DARK',
    ' dark',
    'dark ',
    'dark\n',
    'auto',
    '',
    'null',
    '__proto__',
    'constructor',
  ])('reads the stored theme %j as the default, never as a near miss', (value) => {
    window.localStorage.setItem(THEME_KEY, value);
    fakeDarkOs(false);
    expect(readPreferences()).toEqual({
      theme: 'system',
      palette: 'green-red',
      resolvedTheme: 'light',
    });
  });

  it.each(['Blue-Orange', 'blue orange', 'blue-orange ', '', 'toString'])(
    'reads the stored palette %j as the default',
    (value) => {
      window.localStorage.setItem(PALETTE_KEY, value);
      expect(readPreferences().palette).toBe('green-red');
    },
  );

  it('keeps the dark theme when only the palette changes under a dark operating system', () => {
    fakeDarkOs(true);
    const { result } = renderHook(() => usePreferences());
    expect(attribute('data-theme')).toBe('dark');
    act(() => {
      setPalette('blue-orange');
    });
    expect(result.current).toEqual({
      theme: 'system',
      palette: 'blue-orange',
      resolvedTheme: 'dark',
    });
    expect(attribute('data-theme')).toBe('dark');
    expect(attribute('data-palette')).toBe('blue-orange');
  });

  it('stops following the operating system once a theme is chosen, and follows again on system', () => {
    const os = fakeDarkOs(false);
    const { result } = renderHook(() => usePreferences());
    act(() => {
      setTheme('light');
    });
    act(() => {
      os.flip(true);
    });
    expect(attribute('data-theme')).toBe('light');
    act(() => {
      setTheme('system');
    });
    expect(attribute('data-theme')).toBe('dark');
    act(() => {
      os.flip(false);
    });
    expect(attribute('data-theme')).toBe('light');
    expect(result.current.resolvedTheme).toBe('light');
  });

  it('survives a localStorage whose getItem and setItem both throw and still switches the page', () => {
    fakeDarkOs(false);
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('full', 'QuotaExceededError');
    });
    const { result } = renderHook(() => usePreferences());
    expect(result.current.theme).toBe('system');
    act(() => {
      setTheme('dark');
    });
    act(() => {
      setPalette('blue-orange');
    });
    expect(result.current).toEqual({
      theme: 'dark',
      palette: 'blue-orange',
      resolvedTheme: 'dark',
    });
    expect(attribute('data-theme')).toBe('dark');
    expect(attribute('data-palette')).toBe('blue-orange');
  });

  it('reads storage afresh on the next mount after the last reader leaves', () => {
    fakeDarkOs(false);
    const first = renderHook(() => usePreferences());
    act(() => {
      setTheme('dark');
    });
    expect(first.result.current.resolvedTheme).toBe('dark');
    first.unmount();
    window.localStorage.setItem(THEME_KEY, 'light');
    window.localStorage.setItem(PALETTE_KEY, 'blue-orange');
    const second = renderHook(() => usePreferences());
    expect(second.result.current).toEqual({
      theme: 'light',
      palette: 'blue-orange',
      resolvedTheme: 'light',
    });
    expect(attribute('data-theme')).toBe('light');
  });

  it('gives two readers at once the same answer, and removes the system listener after both leave', () => {
    const os = fakeDarkOs(false);
    const a = renderHook(() => usePreferences());
    const b = renderHook(() => usePreferences());
    act(() => {
      os.flip(true);
    });
    expect(a.result.current.resolvedTheme).toBe('dark');
    expect(b.result.current.resolvedTheme).toBe('dark');
    a.unmount();
    act(() => {
      os.flip(false);
    });
    expect(b.result.current.resolvedTheme).toBe('light');
    b.unmount();
    document.documentElement.setAttribute('data-theme', 'sentinel');
    act(() => {
      os.flip(true);
    });
    expect(attribute('data-theme')).toBe('sentinel');
  });
});
