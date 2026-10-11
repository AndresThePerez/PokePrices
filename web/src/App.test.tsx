import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from './App';

afterEach(() => {
  cleanup();
  document.body.innerHTML = '';
  // Each test that imports ./main gets a fresh module instance (a failed import stays cached).
  vi.resetModules();
});

describe('App', () => {
  it('names the product with its accent', () => {
    render(<App />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('PokéPrices');
  });

  it('states the daily-close basis and never claims real-time prices', () => {
    const { container } = render(<App />);
    const text = container.textContent.toLowerCase();
    expect(text).toContain('daily close');
    expect(text).not.toContain('real-time');
    expect(text).not.toContain('realtime');
    expect(text).not.toContain('live price');
  });

  it('mounts into #root from main.tsx', async () => {
    document.body.innerHTML = '<div id="root"></div>';
    await act(async () => {
      await import('./main');
    });
    expect(document.querySelector('#root h1')?.textContent).toBe('PokéPrices');
  });

  it('refuses to start without #root', async () => {
    vi.resetModules();
    await expect(import('./main')).rejects.toThrow('PokéPrices: #root element missing from index.html');
  });
});
