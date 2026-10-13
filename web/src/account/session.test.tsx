import { QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, render, renderHook, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { createQueryClient } from '../query';
import {
  jsonResponse,
  problemResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
} from '../test/fixtures';
import { ME_KEY, fetchMe, useArrivalStatus, useSession } from './session';

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

function wrapper() {
  const client = createQueryClient();
  return {
    client,
    Wrapper: ({ children }: { readonly children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    ),
  };
}

describe('fetchMe (D11)', () => {
  it('reads the account, and turns a 401 into null (signed out, not a problem)', async () => {
    stubAccountFetch({
      'GET /v1/me': [jsonResponse(signedInMe), problemResponse(unauthenticatedProblem)],
    });
    expect(await fetchMe()).toEqual(signedInMe.data);
    expect(await fetchMe()).toBeNull();
  });

  it('throws any other failure', async () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(serverErrorProblem('/v1/me')) });
    await expect(fetchMe()).rejects.toMatchObject({ ok: false, status: 500 });
  });
});

describe('useSession', () => {
  it("uses the ['me'] key and goes from loading to signed in", async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    const { client, Wrapper } = wrapper();
    const { result } = renderHook(useSession, { wrapper: Wrapper });
    expect(result.current).toEqual({ state: 'loading' });
    await waitFor(() => {
      expect(result.current).toEqual({ state: 'signed-in', me: signedInMe.data });
    });
    expect(ME_KEY).toEqual(['me']);
    expect(client.getQueryData(ME_KEY)).toEqual(signedInMe.data);
  });

  it('reads a 401 as signed out', async () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    const { Wrapper } = wrapper();
    const { result } = renderHook(useSession, { wrapper: Wrapper });
    await waitFor(() => {
      expect(result.current).toEqual({ state: 'signed-out' });
    });
  });

  it('reports a first failure, and keeps the last good answer when a later poll fails', async () => {
    stubAccountFetch({
      'GET /v1/me': [problemResponse(serverErrorProblem('/v1/me')), jsonResponse(signedInMe)],
    });
    const { client, Wrapper } = wrapper();
    const { result } = renderHook(useSession, { wrapper: Wrapper });
    await waitFor(() => {
      expect(result.current).toMatchObject({ state: 'failed', failure: { status: 500 } });
    });
    await act(async () => {
      await client.refetchQueries({ queryKey: ME_KEY });
    });
    await waitFor(() => {
      expect(result.current).toEqual({ state: 'signed-in', me: signedInMe.data });
    });
    stubAccountFetch({ 'GET /v1/me': problemResponse(serverErrorProblem('/v1/me')) });
    await act(async () => {
      await client.refetchQueries({ queryKey: ME_KEY });
    });
    await waitFor(() => {
      expect(client.getQueryState(ME_KEY)).toMatchObject({ status: 'error', errorUpdateCount: 2 });
    });
    expect(result.current).toEqual({ state: 'signed-in', me: signedInMe.data });
  });
});

describe('useArrivalStatus', () => {
  function Probe() {
    const status = useArrivalStatus();
    return <p>{status ?? 'none'}</p>;
  }

  it('shows the status a navigation carried once, then clears it from the history entry', () => {
    window.history.replaceState(
      { status: 'Password saved. Sign in with the new password.' },
      '',
      '/sign-in',
    );
    render(<Probe />);
    expect(screen.getByText('Password saved. Sign in with the new password.')).toBeTruthy();
    expect(window.history.state).toBeNull();
    expect(window.location.pathname).toBe('/sign-in');
  });

  it('ignores a history entry without a string status', () => {
    window.history.replaceState({ status: 42 }, '', '/sign-in');
    render(<Probe />);
    expect(screen.getByText('none')).toBeTruthy();
  });
});
