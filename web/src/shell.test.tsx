import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from './App';

afterEach(() => {
  cleanup();
});

describe('shell page honesty', () => {
  it('states where prices come from and the date they are as of', () => {
    const { container } = render(<App />);
    const text = container.textContent.toLowerCase();
    expect(text).toContain('source');
    expect(text).toContain('as of');
  });

  it('has exactly one h1 inside a main landmark and no template images or links', () => {
    const { container } = render(<App />);
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('main')).toBeTruthy();
    expect(container.querySelectorAll('img, svg, a, button')).toHaveLength(0);
  });

  it('keeps the accent as the precomposed U+00E9, not e plus a combining mark', () => {
    render(<App />);
    const heading = screen.getByRole('heading', { level: 1 }).textContent;
    expect(heading).toBe('PokéPrices');
    expect(heading).toHaveLength(10);
  });
});
