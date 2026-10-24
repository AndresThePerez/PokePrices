import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { filledOf, pointOf } from '../test/fixtures';
import Sparkline from './Sparkline';

afterEach(cleanup);

function draw(points: Parameters<typeof Sparkline>[0]['points']): SVGElement | null {
  return render(<Sparkline points={points} />).container.querySelector('svg');
}

function circles(svg: Element): Element[] {
  return Array.from(svg.querySelectorAll('circle'));
}

describe('Sparkline end dot and baseline edge rules (F15)', () => {
  it('puts the end dot on the last REAL point when filled points follow it', () => {
    const svg = draw([
      pointOf('2026-10-08', '10.00'),
      pointOf('2026-10-09', '12.00'),
      pointOf('2026-10-10', '11.00'),
      filledOf('2026-10-11', '11.00', '2026-10-10'),
      filledOf('2026-10-12', '11.00', '2026-10-10'),
    ]);
    const dots = circles(svg as Element);
    expect(dots).toHaveLength(1);
    expect([dots[0]?.getAttribute('cx'), dots[0]?.getAttribute('cy')]).toEqual(['48.0', '14.0']);
    expect(dots[0]?.getAttribute('fill')).toBe('currentColor');
  });

  it('draws no dot at all when every point is filled, and still draws the dashed pieces', () => {
    const svg = draw([
      filledOf('2026-10-10', '1.00', '2026-10-09'),
      filledOf('2026-10-11', '2.00', '2026-10-09'),
      filledOf('2026-10-12', '3.00', '2026-10-09'),
    ]);
    expect(svg).not.toBeNull();
    expect(circles(svg as Element)).toHaveLength(0);
    expect((svg as Element).querySelectorAll('polyline[stroke-dasharray="2 2"]')).toHaveLength(2);
  });

  it('draws a flat series as exactly one dotted baseline plus the end dot, never a solid line', () => {
    const svg = draw([
      pointOf('2026-10-10', '5.00'),
      pointOf('2026-10-11', '5.00'),
      pointOf('2026-10-12', '5.00'),
    ]) as Element;
    const lines = Array.from(svg.querySelectorAll('polyline'));
    expect(lines).toHaveLength(1);
    expect(lines[0]?.getAttribute('stroke-dasharray')).toBe('1 2');
    expect(lines[0]?.getAttribute('points')).toBe('2.0,14.0 94.0,14.0');
    expect(circles(svg)).toHaveLength(1);
  });

  it('keeps the svg hidden, unfocusable, role-free and 96 x 28 in the flat and the moving case', () => {
    const flat = draw([
      pointOf('2026-10-10', '5.00'),
      pointOf('2026-10-11', '5.00'),
      pointOf('2026-10-12', '5.00'),
    ]) as Element;
    cleanup();
    const moving = draw([
      pointOf('2026-10-10', '5.00'),
      pointOf('2026-10-11', '6.00'),
      pointOf('2026-10-12', '5.50'),
    ]) as Element;
    for (const svg of [flat, moving]) {
      expect(svg.getAttribute('aria-hidden')).toBe('true');
      expect(svg.getAttribute('focusable')).toBe('false');
      expect(svg.hasAttribute('role')).toBe(false);
      expect(svg.getAttribute('width')).toBe('96');
      expect(svg.getAttribute('height')).toBe('28');
      expect(svg.querySelector('[aria-hidden], [role], [tabindex], title')).toBeNull();
    }
  });

  it('renders nothing (no svg, no wrapper) for 0, 1 and 2 points, and an svg from 3', () => {
    for (const count of [0, 1, 2]) {
      const days = ['2026-10-10', '2026-10-11'].slice(0, count);
      const { container } = render(
        <Sparkline points={days.map((day, index) => pointOf(day, `${String(index + 1)}.00`))} />,
      );
      expect(container.innerHTML).toBe('');
      cleanup();
    }
    expect(
      draw([
        pointOf('2026-10-10', '1.00'),
        pointOf('2026-10-11', '2.00'),
        pointOf('2026-10-12', '3.00'),
      ]),
    ).not.toBeNull();
  });
});
