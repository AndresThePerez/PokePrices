// A variant's 1M sparkline (D12): an inline 96 x 28 svg, no library, drawn from the same D6
// segments as the chart. A piece into a filled point is dashed; a one-point segment is a dot.
// Number() is used for geometry only (D2); the svg is hidden from assistive technology because
// the price lines beside it already carry every fact.
import type { ReactElement } from 'react';
import type { PricePoint } from '../api/client';
import { segments } from './series';

const WIDTH = 96;
const HEIGHT = 28;
const PAD = 2;
const STROKE = { fill: 'none', stroke: 'currentColor', strokeWidth: '1.5' } as const;

function coordinate(value: number): string {
  return value.toFixed(1);
}

export default function Sparkline({ points }: { readonly points: readonly PricePoint[] }) {
  const count = points.length;
  if (count === 0) return null;
  const values = points.map((point) => Number(point.close));
  const min = Math.min(...values);
  const max = Math.max(...values);
  const inner = WIDTH - 2 * PAD;
  const tall = HEIGHT - 2 * PAD;
  const x = (index: number): number =>
    count === 1 ? WIDTH / 2 : PAD + (index * inner) / (count - 1);
  const y = (value: number): number =>
    max === min ? HEIGHT / 2 : HEIGHT - PAD - ((value - min) * tall) / (max - min);
  const at = (index: number): string =>
    `${coordinate(x(index))},${coordinate(y(values[index] ?? min))}`;

  const shapes: ReactElement[] = [];
  let offset = 0;
  for (const segment of segments(points)) {
    const start = offset;
    offset += segment.length;
    if (segment.length === 1) {
      shapes.push(
        <circle
          key={`c${String(start)}`}
          cx={coordinate(x(start))}
          cy={coordinate(y(values[start] ?? min))}
          r="2"
          {...STROKE}
        />,
      );
      continue;
    }
    let run: number[] = [];
    const flush = (): void => {
      if (run.length >= 2) {
        shapes.push(
          <polyline key={`s${String(run[0])}`} points={run.map(at).join(' ')} {...STROKE} />,
        );
      }
      run = [];
    };
    segment.forEach((point, position) => {
      if (position === 0) return;
      const index = start + position;
      if (point.is_filled) {
        flush();
        shapes.push(
          <polyline
            key={`d${String(index)}`}
            points={`${at(index - 1)} ${at(index)}`}
            strokeDasharray="2 2"
            {...STROKE}
          />,
        );
      } else {
        if (run.length === 0) run.push(index - 1);
        run.push(index);
      }
    });
    flush();
  }

  return (
    <svg
      viewBox={`0 0 ${String(WIDTH)} ${String(HEIGHT)}`}
      width={WIDTH}
      height={HEIGHT}
      aria-hidden="true"
      focusable="false"
      className="shrink-0 text-chart-line"
    >
      {shapes}
    </svg>
  );
}
