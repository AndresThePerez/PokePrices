// Adversarial edge cases for the held PUT answer on /alerts (Q0-i1-T03, F1): it dies with the
// account, never shows over a newer read after a signal, and is what the next Save sends.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import {
  clearXsrfCookie,
  holdSessionCookie,
  jsonResponse,
  notificationsBody,
  problemResponse,
  sentBody,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  webhooksBody,
} from '../test/fixtures';
import { SESSION_SIGNAL_KEY } from './session';

const created = vi.hoisted(() => ({ clients: [] as QueryClient[] }));
vi.mock('../query', async (importOriginal) => {
  const actual = await importOriginal<typeof QueryModule>();
  return {
    ...actual,
    createQueryClient: (): QueryClient => {
      const client = actual.createQueryClient();
      created.clients.push(client);
      return client;
    },
  };
});

// Every test here starts signed in: the browser holds the token cookie from the first render.
beforeEach(() => {
  holdSessionCookie();
});

afterEach(() => {
  me = jsonResponse(signedInMe);
  cleanup();
  clearXsrfCookie();
  created.clients.length = 0;
  window.localStorage.clear();
  window.history.replaceState(null, '', '/');
});

const A_EMAIL = signedInMe.data.email;
const B = { ...signedInMe.data, email: 'second@example.com' };

function client(): QueryClient {
  const first = created.clients[0];
  if (first === undefined) throw new Error('App created no QueryClient');
  return first;
}

async function settle(): Promise<void> {
  for (let round = 0; round < 6; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

function digest(): boolean {
  return screen.getByRole<HTMLInputElement>('checkbox', { name: 'Send me a daily digest' }).checked;
}

let me: Response = jsonResponse(signedInMe);

/** A saves digest on; the re-read fails, so the form holds the PUT answer. */
async function holdAnswer(afterRead: Response) {
  const calls = stubAccountFetch({
    'GET /v1/me': () => me,
    'GET /v1/webhooks': jsonResponse(webhooksBody([])),
    'GET /v1/me/notifications': [
      jsonResponse(notificationsBody()),
      problemResponse(serverErrorProblem('/v1/me/notifications')),
      afterRead,
    ],
    'PUT /v1/me/notifications': jsonResponse(notificationsBody({ digest: true })),
  });
  window.history.replaceState(null, '', '/alerts');
  render(<App />);
  await screen.findByText(`Email (${A_EMAIL})`);
  fireEvent.click(screen.getByRole('checkbox', { name: 'Send me a daily digest' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  expect(await screen.findByText('Saved.')).toBeTruthy();
  await settle();
  expect(digest()).toBe(true);
  return calls;
}

describe('the held PUT answer (F1) dies with its account and its cache', () => {
  it('account B, whose first read counts the same as A held, never sees A held digest or "Saved."', async () => {
    await holdAnswer(jsonResponse(notificationsBody({ digest: false })));
    await act(async () => {
      client().setQueryData(['me'], B);
      await Promise.resolve();
    });
    await settle();
    expect(await screen.findByText('Email (second@example.com)')).toBeTruthy();
    expect(digest()).toBe(false);
    expect(screen.queryByText('Saved.')).toBeNull();
  });

  it('after another tab signals and the server now answers account B, B reads its own settings, not A held answer', async () => {
    const calls = await holdAnswer(jsonResponse(notificationsBody({ digest: false })));
    me = jsonResponse({ data: B });
    act(() => {
      window.dispatchEvent(
        new StorageEvent('storage', { key: SESSION_SIGNAL_KEY, newValue: '1700000000000.1' }),
      );
    });
    await settle();
    expect(await screen.findByText('Email (second@example.com)')).toBeTruthy();
    expect(digest()).toBe(false);
    expect(screen.queryByText('Saved.')).toBeNull();
    expect(calls.filter((call) => call.key === 'GET /v1/me/notifications').length).toBe(3);
  });

  it('the next Save sends what the form shows (the held answer), not the stale cache', async () => {
    const calls = await holdAnswer(jsonResponse(notificationsBody({ digest: true })));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await settle();
    const puts = calls.filter((call) => call.key === 'PUT /v1/me/notifications');
    expect(puts.length).toBe(2);
    expect(sentBody(puts[1])).toEqual({ channel: 'email', digest: true });
  });
});
