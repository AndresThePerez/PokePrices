// Q1-i5-T05: the page shell. One main landmark around the children; `reserve` only adds to the
// plain shell (the loading frame's one-viewport reservation) and the main node survives the
// toggle. States are compared with each other; no class is named (R255).
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import Page from './Page';

afterEach(() => {
  cleanup();
});

function tokens(value: string): string[] {
  return value.split(/\s+/).filter((token) => token !== '');
}

describe('Page (Q1-i5-T05)', () => {
  it('is one main landmark holding its children', () => {
    render(
      <Page>
        <h1>Biggest moves</h1>
      </Page>,
    );
    const main = screen.getByRole('main');
    expect(screen.getAllByRole('main')).toHaveLength(1);
    expect(main.textContent).toBe('Biggest moves');
    expect(main.querySelector('h1')).not.toBeNull();
  });

  it('only adds to the plain shell while reserving, and keeps the same main node and children', () => {
    const { rerender } = render(
      <Page reserve>
        <h1>Card 1</h1>
      </Page>,
    );
    const main = screen.getByRole('main');
    const heading = screen.getByRole('heading', { level: 1 });
    const reserved = main.className;
    rerender(
      <Page>
        <h1>Card 1</h1>
      </Page>,
    );
    const plain = screen.getByRole('main').className;
    expect(screen.getByRole('main')).toBe(main);
    expect(screen.getByRole('heading', { level: 1 })).toBe(heading);
    expect(tokens(plain).length).toBeGreaterThan(0);
    expect(tokens(reserved)).toEqual(expect.arrayContaining(tokens(plain)));
    expect(reserved).not.toBe(plain);
    rerender(
      <Page reserve={false}>
        <h1>Card 1</h1>
      </Page>,
    );
    expect(screen.getByRole('main').className).toBe(plain);
  });
});
