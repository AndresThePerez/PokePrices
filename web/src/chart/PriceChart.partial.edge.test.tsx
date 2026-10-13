import { cleanup, render } from '@testing-library/react';
import { Component, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { pointOf } from '../test/fixtures';
import PriceChart from './PriceChart';
import { segments } from './series';

// A library whose setData rejects the data after createChart and addSeries already succeeded
// (the real one throws on a value it cannot plot).
const removed = vi.hoisted(() => ({ count: 0, created: 0 }));

vi.mock('lightweight-charts', () => ({
  ColorType: { Solid: 'solid' },
  LineSeries: { type: 'Line' },
  createChart: () => {
    removed.created += 1;
    return {
      addSeries: () => ({
        setData: () => {
          throw new Error('Value is null');
        },
        applyOptions: () => undefined,
      }),
      applyOptions: () => undefined,
      timeScale: () => ({ fitContent: () => undefined }),
      remove: () => {
        removed.count += 1;
      },
    };
  },
}));

class Catch extends Component<{ readonly children: ReactNode }, { failed: boolean }> {
  override state = { failed: false };
  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true };
  }
  override render(): ReactNode {
    return this.state.failed ? <p>boundary</p> : this.props.children;
  }
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe('PriceChart when drawing fails half way', () => {
  it('removes the chart it had already created, so a failed draw leaks no canvas', () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const { container } = render(
      <Catch>
        <PriceChart
          segments={segments([pointOf('2026-10-01', '10.00'), pointOf('2026-10-02', '11.00')])}
          label="holo"
          range="1M"
          describedBy="s"
        />
      </Catch>,
    );
    expect(container.textContent).toBe('boundary');
    expect(removed.created).toBeGreaterThanOrEqual(1);
    expect(removed.count).toBe(removed.created);
  });
});
