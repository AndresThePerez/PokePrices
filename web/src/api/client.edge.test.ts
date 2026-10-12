import { describe, expect, it } from 'vitest';
import { jsonResponse, searchPage, stubFetch } from '../test/fixtures';
import { getCard, searchCards } from './client';

const realFetch = globalThis.fetch;

describe('client edge cases', () => {
  it('keeps a hostile q inside one q parameter on a /v1/cards URL', async () => {
    const calls = stubFetch(() => jsonResponse(searchPage));
    const hostile = 'ünown & page=99#frag?x=1/../../api/search';
    await searchCards({ q: hostile });
    const url = new URL(calls[0]?.url ?? '', 'http://localhost');
    expect(url.pathname).toBe('/v1/cards');
    expect(url.hash).toBe('');
    expect(url.searchParams.get('q')).toBe(hostile);
    expect([...url.searchParams.keys()]).toEqual(['q']);
  });

  it('never lets a dot-segment id climb out of /v1/cards/', async () => {
    const calls = stubFetch(() => jsonResponse({ data: { id: 'x', name: 'x' }, meta: {} }));
    for (const id of ['.', '..']) {
      await getCard(id);
    }
    for (const call of calls) {
      const url = new URL(call.url, 'http://localhost');
      expect(url.pathname.startsWith('/v1/cards/')).toBe(true);
      expect(url.pathname.length).toBeGreaterThan('/v1/cards/'.length);
    }
  });

  it('does not turn an empty card id into a request for the /v1/cards collection', async () => {
    const calls = stubFetch(() => jsonResponse(searchPage));
    const result = await getCard('');
    const urls = calls.map((call) => new URL(call.url, 'http://localhost').pathname);
    expect(urls).not.toContain('/v1/cards/');
    expect(result.ok).toBe(false);
  });

  it('rethrows when the signal aborts while the response body is still being read', async () => {
    const controller = new AbortController();
    const body = new ReadableStream<Uint8Array>({
      start(stream) {
        controller.signal.addEventListener('abort', () => {
          stream.error(new DOMException('The operation was aborted.', 'AbortError'));
        });
      },
    });
    stubFetch(() => new Response(body, { status: 200 }));
    const pending = searchCards({ q: 'unown' }, { signal: controller.signal });
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller.abort();
    await expect(pending).rejects.toBeDefined();
  });

  it('reports an invalid card id as a typed status-0 failure that is not a network failure', async () => {
    const calls = stubFetch(() => jsonResponse({}));
    for (const id of ['', '.', '..']) {
      const result = await getCard(id);
      expect(result).toMatchObject({ ok: false, status: 0, type: 'about:blank', problem: null });
      if (!result.ok) {
        expect(result.title).toBe('The card id is not valid');
        expect(result.title).not.toMatch(/could not be reached/);
      }
    }
    expect(calls).toHaveLength(0);
  });

  it('still sends ids that merely contain dots or are percent-encoded lookalikes', async () => {
    const calls = stubFetch(() => jsonResponse({ data: { id: 'x', name: 'x' }, meta: {} }));
    for (const id of ['...', 'a.b', '%2E', '%2E%2E']) {
      await getCard(id);
    }
    expect(calls).toHaveLength(4);
    for (const call of calls) {
      const url = new URL(call.url, 'http://localhost');
      expect(url.pathname.startsWith('/v1/cards/')).toBe(true);
      expect(url.pathname.length).toBeGreaterThan('/v1/cards/'.length);
    }
  });

  it('does not rethrow a body-read failure when the signal has not aborted', async () => {
    const controller = new AbortController();
    const body = new ReadableStream<Uint8Array>({
      start(stream) {
        stream.error(new DOMException('stream died', 'AbortError'));
      },
    });
    stubFetch(() => new Response(body, { status: 200 }));
    const result = await searchCards({ q: 'unown' }, { signal: controller.signal });
    expect(result.ok).toBe(false);
  });

  // it.fails passes only when the body throws, which is the "test throws" case.
  it.fails('(sentinel) a test that throws while the stub is installed', () => {
    stubFetch(() => jsonResponse(searchPage));
    expect(globalThis.fetch).not.toBe(realFetch);
    throw new Error('boom');
  });

  it('has put the real fetch back after the previous test threw', () => {
    expect(globalThis.fetch).toBe(realFetch);
  });
});
