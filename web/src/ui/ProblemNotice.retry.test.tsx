// The problem block's "Try again" and its request id line: the button repeats the read that
// failed and the block leaves once that read answers; a failed write has no such button (its form
// is the way to try again); the request id is printed for a server error only.
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { ApiFailure } from '../api/client';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  jsonResponse,
  problemResponse,
  searchPage,
  stubFetch,
  upstreamUnavailableProblem,
} from '../test/fixtures';
import ProblemNotice from './ProblemNotice';

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

function failure(status: number, requestId: string | null = `req-${String(status)}`): ApiFailure {
  return {
    ok: false,
    status,
    type: 'https://pokeprices.example/problems/some-problem',
    title: 'Some Problem',
    requestId,
    problem: null,
  };
}

/** A read that fails with an ApiFailure, as the app's reads do (thrown, not an Error). */
async function refuse(problem: ApiFailure): Promise<never> {
  await Promise.resolve();
  const thrown: unknown = problem;
  throw thrown;
}

function Reader({ read }: { readonly read: () => Promise<string> }) {
  const query = useQuery<string, ApiFailure>({ queryKey: ['reader'], queryFn: read, retry: false });
  if (query.data !== undefined) return <p>{`Read: ${query.data}`}</p>;
  if (query.error !== null) return <ProblemNotice context="Could not read" failure={query.error} />;
  return <p role="status">Reading…</p>;
}

function renderReader(read: () => Promise<string>) {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <Reader read={read} />
    </QueryClientProvider>,
  );
}

describe('Try again on a problem block', () => {
  it('repeats the failed read once and the block leaves when it answers', async () => {
    let asked = 0;
    renderReader(() => {
      asked += 1;
      return asked === 1 ? refuse(failure(503)) : Promise.resolve('ok');
    });
    await settle();
    expect(screen.getByRole('alert').textContent).toBe(
      'Could not read: Some ProblemRequest ID req-503',
    );
    const button = screen.getByRole('button', { name: 'Try again' });
    expect(button.getAttribute('type')).toBe('button');
    fireEvent.click(button);
    await settle();
    expect(asked).toBe(2);
    expect(screen.getByText('Read: ok')).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull();
  });

  it('shows the block again, still with Try again, when the repeated read fails too', async () => {
    let asked = 0;
    renderReader(() => {
      asked += 1;
      return refuse(failure(500, `req-try-${String(asked)}`));
    });
    await settle();
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    await settle();
    expect(asked).toBe(2);
    expect(screen.getByRole('alert').textContent).toBe(
      'Could not read: Some ProblemRequest ID req-try-2',
    );
    expect(screen.getAllByRole('button', { name: 'Try again' })).toHaveLength(1);
  });

  it('has no Try again for a failure no read holds (a failed write) or outside a query client', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ProblemNotice context="Could not save" failure={failure(500)} />
      </QueryClientProvider>,
    );
    expect(screen.getByRole('alert').textContent).toBe(
      'Could not save: Some ProblemRequest ID req-500',
    );
    expect(screen.queryByRole('button')).toBeNull();
    cleanup();
    render(<ProblemNotice context="Could not save" failure={failure(500)} />);
    expect(screen.getByRole('alert').textContent).toBe(
      'Could not save: Some ProblemRequest ID req-500',
    );
    expect(screen.queryByRole('button')).toBeNull();
  });

  it('repeats a failed search on the search page and shows its results', async () => {
    let asked = 0;
    const calls = stubFetch((url) => {
      if (url !== '/v1/cards?q=unown') return new Error(`no request expected: ${url}`);
      asked += 1;
      return asked === 1 ? problemResponse(upstreamUnavailableProblem) : jsonResponse(searchPage);
    });
    window.history.replaceState(null, '', '/search?q=unown');
    render(<App />);
    await settle();
    const alert = screen.getByRole('alert');
    expect(alert.textContent).toBe(
      'Search failed: Upstream UnavailableRequest ID req-t46-upstream-0001',
    );
    const main = screen.getByRole('main');
    for (const word of FORBIDDEN_WORDS) expect(main.textContent).not.toMatch(word);
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    // The button leaves with the block: focus is on the content region, not on the body.
    const content = document.getElementById('content');
    expect(content?.contains(main)).toBe(true);
    expect(document.activeElement).toBe(content);
    await settle();
    expect(document.activeElement).toBe(content);
    expect(calls.map((call) => call.url)).toEqual([
      '/v1/prices/freshness',
      '/v1/cards?q=unown',
      '/v1/cards?q=unown',
    ]);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull();
    expect(screen.getByRole('list', { name: 'Search results' })).toBeTruthy();
  });
});

describe('the request id line of a problem block', () => {
  it.each([500, 502, 503, 599])('is printed for a %i', (status) => {
    render(<ProblemNotice context="Could not read" failure={failure(status)} />);
    expect(screen.getByRole('alert').textContent).toBe(
      `Could not read: Some ProblemRequest ID req-${String(status)}`,
    );
  });

  it('comes after a whole first line and the extra lines of a 500', () => {
    render(
      <ProblemNotice context="Could not save" headline="Could not save it." failure={failure(500)}>
        <ul>
          <li>An extra line.</li>
        </ul>
      </ProblemNotice>,
    );
    const alert = screen.getByRole('alert');
    expect(alert.textContent).toBe('Could not save it.An extra line.Request ID req-500');
    expect(alert.lastElementChild?.querySelector('code')?.textContent).toBe('req-500');
  });

  it.each([404, 422, 429, 403, 499])('is left out for a %i', (status) => {
    render(<ProblemNotice context="Could not read" failure={failure(status)} />);
    const alert = screen.getByRole('alert');
    expect(alert.textContent).toBe('Could not read: Some Problem');
    expect(alert.querySelector('code')).toBeNull();
  });
});
