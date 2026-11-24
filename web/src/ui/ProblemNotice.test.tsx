// Q1-i5-T05: the shared problem block of the public pages (and the shared part of the account
// ProblemBlock) and the shared degraded catalog line. Text, role and the request-id line only;
// no class names (R255).
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { ApiFailure } from '../api/client';
import DegradedNotice from './DegradedNotice';
import ProblemNotice from './ProblemNotice';

afterEach(() => {
  cleanup();
});

const DEGRADED_LINE = 'Catalog data may be out of date: PokéSearch is unavailable';

function failure(requestId: string | null, status = 503): ApiFailure {
  return {
    ok: false,
    status,
    type: 'https://pokeprices.example/problems/upstream-unavailable',
    title: 'Upstream Unavailable',
    requestId,
    problem: null,
  };
}

describe('ProblemNotice (Q1-i5-T05)', () => {
  it('is one alert: "<context>: <title>" on the first line, then the request id line', () => {
    render(
      <ProblemNotice context="Search failed" failure={failure('req-0001')} className="mt-6" />,
    );
    const alert = screen.getByRole('alert');
    const lines = alert.querySelectorAll('p');
    expect(lines).toHaveLength(2);
    expect(lines[0]?.textContent).toBe('Search failed: Upstream Unavailable');
    expect(lines[1]?.textContent).toBe('Request ID req-0001');
    expect(lines[1]?.querySelector('code')?.textContent).toBe('req-0001');
    expect(alert.textContent).toBe('Search failed: Upstream UnavailableRequest ID req-0001');
  });

  it('drops the request id line when the failure has none', () => {
    render(<ProblemNotice context="Could not load the movers" failure={failure(null)} />);
    const alert = screen.getByRole('alert');
    expect(alert.querySelectorAll('p')).toHaveLength(1);
    expect(alert.textContent).toBe('Could not load the movers: Upstream Unavailable');
    expect(alert.querySelector('code')).toBeNull();
  });

  it('adds no "wait a minute" line to a public 429 (HG-45 #3)', () => {
    render(
      <ProblemNotice
        context="Could not load this card"
        failure={{ ...failure('req-0429', 429), title: 'Too Many Requests' }}
      />,
    );
    expect(screen.getByRole('alert').textContent).toBe(
      'Could not load this card: Too Many Requests',
    );
  });

  it('takes a whole first line and extra lines, with no request id line on a 422', () => {
    render(
      <ProblemNotice
        context="Could not sign in"
        headline="Could not sign in: check the fields below."
        failure={failure('req-0422', 422)}
      >
        <ul>
          <li>The token field is required.</li>
        </ul>
      </ProblemNotice>,
    );
    const alert = screen.getByRole('alert');
    expect(alert.querySelector('p')?.textContent).toBe(
      'Could not sign in: check the fields below.',
    );
    expect(alert.querySelector('p')?.childNodes).toHaveLength(1);
    expect(alert.textContent).toBe(
      'Could not sign in: check the fields below.' + 'The token field is required.',
    );
  });

  it('prints a hostile title as text', () => {
    render(
      <ProblemNotice
        context="Search failed"
        failure={{ ...failure(null), title: '<img src=x onerror=alert(1)>' }}
      />,
    );
    const alert = screen.getByRole('alert');
    expect(alert.querySelector('img')).toBeNull();
    expect(alert.textContent).toBe('Search failed: <img src=x onerror=alert(1)>');
  });
});

describe('DegradedNotice (Q1-i5-T05)', () => {
  it('prints the degraded sentence as one text node in a paragraph with no role', () => {
    const { container } = render(<DegradedNotice className="mt-4" />);
    const line = screen.getByText(DEGRADED_LINE);
    expect(line.tagName).toBe('P');
    expect(line.childNodes).toHaveLength(1);
    expect(line.hasAttribute('role')).toBe(false);
    expect(container.textContent).toBe(DEGRADED_LINE);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByRole('status')).toBeNull();
  });
});
