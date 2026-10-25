import { cleanup, render } from '@testing-library/react';
import { Component, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { pointOf } from '../test/fixtures';
import PriceChart from './PriceChart';
import { segments } from './series';

// A library whose time scale cannot take a size listener: setData and fitContent succeed, then
// subscribeSizeChange throws. The half-built chart must still be removed.
const seen = vi.hoisted(() => ({ created: 0, removed: 0, unsubscribed: 0 }));

vi.mock('lightweight-charts', () => ({
  ColorType: { Solid: 'solid' },
  LineSeries: { type: 'Line' },
  createChart: () => {
    seen.created += 1;
    return {
      addSeries: () => ({ setData: () => undefined, applyOptions: () => undefined }),
      applyOptions: () => undefined,
      timeScale: () => ({
        fitContent: () => undefined,
        subscribeSizeChange: () => {
          throw new Error('cannot subscribe');
        },
        unsubscribeSizeChange: () => {
          seen.unsubscribed += 1;
        },
      }),
      remove: () => {
        seen.removed += 1;
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

describe('PriceChart when the size listener cannot be attached', () => {
  it('removes the chart it created and reaches the error boundary, with no stray unsubscribe', () => {
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
    expect(seen.created).toBeGreaterThanOrEqual(1);
    expect(seen.removed).toBe(seen.created);
    expect(seen.unsubscribed).toBe(0);
  });
});
