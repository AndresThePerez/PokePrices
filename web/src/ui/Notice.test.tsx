import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { ListLoading } from '../account/ListParts';
import { MoversLoading } from '../pages/MoverList';
import { stubFetch } from '../test/fixtures';
import Notice, { type NoticeTone } from './Notice';
import Panel from './Panel';
import Skeleton from './Skeleton';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  window.history.replaceState(null, '', '/');
});

const TONES: readonly NoticeTone[] = ['info', 'warn', 'problem'];
const HOSTILE = '<img src=x onerror=alert(1)><script>alert(2)</script>';

describe('Notice', () => {
  it('renders role=alert when a problem notice is given role alert', () => {
    render(
      <Notice tone="problem" role="alert">
        Search failed: Upstream Unavailable
      </Notice>,
    );
    expect(screen.getByRole('alert').textContent).toBe('Search failed: Upstream Unavailable');
  });

  it('renders role=status when given role status, on every tone', () => {
    for (const tone of TONES) {
      render(
        <Notice tone={tone} role="status">
          Saved.
        </Notice>,
      );
      expect(screen.getByRole('status').getAttribute('role')).toBe('status');
      cleanup();
    }
  });

  it('renders no role attribute when no role is passed', () => {
    for (const tone of TONES) {
      const { container } = render(<Notice tone={tone}>Plain line</Notice>);
      const element = container.firstElementChild;
      if (element === null) throw new Error('notice expected');
      expect(element.hasAttribute('role')).toBe(false);
      expect(container.querySelector('[role]')).toBeNull();
      cleanup();
    }
  });

  it('carries exactly its children as text: no prefix, icon, symbol or aria-hidden part', () => {
    for (const tone of TONES) {
      const { container } = render(
        <Notice tone={tone}>Catalog data may be out of date: PokéSearch is unavailable</Notice>,
      );
      const element = container.firstElementChild;
      if (element === null) throw new Error('notice expected');
      expect(container.childElementCount).toBe(1);
      expect(element.textContent).toBe(
        'Catalog data may be out of date: PokéSearch is unavailable',
      );
      expect(element.querySelector('svg, img, [aria-hidden]')).toBeNull();
      expect(element.hasAttribute('title')).toBe(false);
      expect(element.hasAttribute('aria-hidden')).toBe(false);
      cleanup();
    }
  });

  it('keeps nested children markup as given (Request ID <code>)', () => {
    render(
      <Notice tone="problem" role="alert">
        <p>Could not load this card: Upstream Unavailable</p>
        <p>
          Request ID <code>req-1</code>
        </p>
      </Notice>,
    );
    const alert = screen.getByRole('alert');
    expect(alert.textContent).toBe(
      'Could not load this card: Upstream UnavailableRequest ID req-1',
    );
    expect(alert.querySelector('code')?.textContent).toBe('req-1');
  });

  it('renders as a p when asked, so a one-line notice keeps its paragraph', () => {
    const { container } = render(
      <Notice tone="info" as="p">
        One line.
      </Notice>,
    );
    expect(container.firstElementChild?.tagName).toBe('P');
    expect(container.firstElementChild?.textContent).toBe('One line.');
  });

  it('keeps hostile children as text', () => {
    const { container } = render(
      <Notice tone="warn" role="alert">
        {HOSTILE}
      </Notice>,
    );
    expect(screen.getByRole('alert').textContent).toBe(HOSTILE);
    expect(container.querySelector('img, script')).toBeNull();
  });
});

describe('Panel', () => {
  it('renders the region landmark and heading wiring it is given', () => {
    render(
      <Panel role="region" aria-labelledby="secret-heading">
        <h3 id="secret-heading">Signing secret</h3>
        <p>Body</p>
      </Panel>,
    );
    const region = screen.getByRole('region', { name: 'Signing secret' });
    expect(region.tagName).toBe('DIV');
    expect(region.getAttribute('aria-labelledby')).toBe('secret-heading');
    expect(region.textContent).toBe('Signing secretBody');
  });

  it('renders a section with an aria-label as a named region', () => {
    render(
      <Panel as="section" aria-label="Summary">
        Total
      </Panel>,
    );
    const region = screen.getByRole('region', { name: 'Summary' });
    expect(region.tagName).toBe('SECTION');
  });

  it('adds no role, label or aria-hidden of its own', () => {
    const { container } = render(<Panel>Plain</Panel>);
    const element = container.firstElementChild;
    if (element === null) throw new Error('panel expected');
    expect(element.tagName).toBe('DIV');
    expect(element.getAttributeNames().filter((name) => name !== 'class')).toEqual([]);
    expect(element.textContent).toBe('Plain');
  });
});

/** The element must be a Skeleton bar: aria-hidden, no text, no svg. */
function expectSkeleton(element: Element | null): void {
  if (element === null) throw new Error('skeleton expected');
  expect(element.getAttribute('aria-hidden')).toBe('true');
  expect(element.textContent).toBe('');
  expect(element.querySelector('svg')).toBeNull();
  expect(element.tagName).not.toBe('svg');
}

describe('Skeleton', () => {
  it('is an aria-hidden block with no text and no svg', () => {
    const { container } = render(<Skeleton />);
    expect(container.childElementCount).toBe(1);
    expectSkeleton(container.firstElementChild);
  });

  it('sits after the list loading line, which keeps its status text', () => {
    render(<ListLoading>Loading your watchlist…</ListLoading>);
    const status = screen.getByRole('status');
    expect(status.textContent).toBe('Loading your watchlist…');
    expectSkeleton(status.nextElementSibling);
    expect(status.previousElementSibling).toBeNull();
  });

  it('sits after the movers loading line, which keeps its status text', () => {
    render(<MoversLoading />);
    const status = screen.getByRole('status');
    expect(status.textContent).toBe('Loading movers…');
    expectSkeleton(status.nextElementSibling);
  });

  it('puts two bars after the card loading line, which keeps its status text', () => {
    stubFetch(() => new Error('not answered before the assertions'));
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
    const status = screen.getByRole('status');
    expect(status.textContent).toBe('Loading card ex10-?…');
    const first = status.nextElementSibling;
    expectSkeleton(first);
    expectSkeleton(first?.nextElementSibling ?? null);
    expect(first?.nextElementSibling?.nextElementSibling ?? null).toBeNull();
    expect(screen.getAllByRole('status')).toHaveLength(1);
  });
});
