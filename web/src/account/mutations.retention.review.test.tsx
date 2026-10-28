// Adversarial review of Q1-i4 T06 (round 1): after a write starts, settles or fails, the mutation
// cache holds no function that could send it again, the hook stays usable for the next write, and
// two presses in one tick still send one request.
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { createElement, type ReactNode } from 'react';
import { describe, expect, it } from 'vitest';
import type { ApiFailure, ApiResult } from '../api/client';
import { useAccountWrite } from './mutations';

const FAILURE: ApiFailure = {
  ok: false,
  status: 422,
  type: 'about:blank',
  title: 'Unprocessable',
  requestId: null,
  problem: null,
};

function setup() {
  const client = new QueryClient();
  const wrapper = ({ children }: { children: ReactNode }) =>
    createElement(QueryClientProvider, { client }, children);
  const hook = renderHook(() => useAccountWrite<string>(), { wrapper });
  return { client, hook };
}

function holdsFunction(client: QueryClient): boolean {
  return client
    .getMutationCache()
    .getAll()
    .some((mutation) =>
      JSON.stringify(mutation.state.variables, (_key, value: unknown) =>
        typeof value === 'function' ? '#fn' : value,
      ).includes('#fn'),
    );
}

describe('the mutation cache after a write', () => {
  it('holds no function while the request is in flight, after success and after a refused write', async () => {
    const { client, hook } = setup();
    let release: (result: ApiResult<string>) => void = () => undefined;
    const slow = new Promise<ApiResult<string>>((resolve) => {
      release = resolve;
    });
    act(() => {
      hook.result.current.run(() => slow);
    });
    await waitFor(() => {
      expect(hook.result.current.isPending).toBe(true);
    });
    expect(holdsFunction(client)).toBe(false);
    await act(async () => {
      release({ ok: true, data: 'done' });
      await slow;
    });
    await waitFor(() => {
      expect(hook.result.current.isPending).toBe(false);
    });
    expect(hook.result.current.data).toBe('done');
    expect(holdsFunction(client)).toBe(false);
    act(() => {
      hook.result.current.run(() => Promise.resolve(FAILURE));
    });
    await waitFor(() => {
      expect(hook.result.current.error).toEqual(FAILURE);
    });
    expect(holdsFunction(client)).toBe(false);
  });

  it('two presses in one tick send one request, and a request that throws is an error, not a hang', async () => {
    const { client, hook } = setup();
    let sends = 0;
    act(() => {
      hook.result.current.run(() => {
        sends += 1;
        return Promise.resolve({ ok: true, data: 'a' });
      });
      hook.result.current.run(() => {
        sends += 1;
        return Promise.resolve({ ok: true, data: 'b' });
      });
    });
    await waitFor(() => {
      expect(hook.result.current.isPending).toBe(false);
    });
    expect(sends).toBe(1);
    act(() => {
      hook.result.current.run(() => Promise.reject(new Error('network down')));
    });
    await waitFor(() => {
      expect(hook.result.current.isError).toBe(true);
    });
    expect(holdsFunction(client)).toBe(false);
    act(() => {
      hook.result.current.run(() => Promise.resolve({ ok: true, data: 'again' }));
    });
    await waitFor(() => {
      expect(hook.result.current.data).toBe('again');
    });
  });
});
