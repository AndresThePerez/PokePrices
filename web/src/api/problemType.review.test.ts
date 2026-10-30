import { describe, expect, it } from 'vitest';
import { isDemoReadOnly } from '../account/lists';
import { hasProblemType, type ApiFailure } from './client';

// tsconfig.app.json carries no Node types, hence the untyped specifier and the narrow cast.
const NODE_FS = 'node:fs';
const { readFileSync } = (await import(/* @vite-ignore */ NODE_FS)) as {
  readonly readFileSync: (path: string, encoding: 'utf8') => string;
};
// The base is a variable: Vite rewrites a literal `new URL(…, import.meta.url)` into an asset URL.
const testFile = import.meta.url;
const api = (path: string): string =>
  readFileSync(new URL(`../../../api/app/${path}`, testFile).pathname, 'utf8');

/** The first quoted value after `needle` in a PHP source: the API's own constant, not a copy. */
function phpValue(source: string, needle: RegExp): string {
  const match = needle.exec(source);
  if (match?.[1] === undefined) throw new Error(`not found: ${String(needle)}`);
  return match[1];
}

const BASE = phpValue(api('Http/Problems/ProblemDetails.php'), /const string BASE = '([^']+)'/);
const CARD_NOT_FOUND = phpValue(
  api('Domain/Catalog/Problems/CardNotFound.php'),
  /function slug\(\)[^{]*\{\s*return '([^']+)'/,
);
const DEMO_READ_ONLY = phpValue(
  api('Http/Problems/DemoReadOnly.php'),
  /function slug\(\)[^{]*\{\s*return '([^']+)'/,
);
const ALREADY_SIGNED_IN = phpValue(
  api('Http/Problems/AlreadySignedIn.php'),
  /function slug\(\)[^{]*\{\s*return '([^']+)'/,
);

function failure(status: number, type: string): ApiFailure {
  return { ok: false, status, type, title: 'Problem', requestId: null, problem: null };
}

describe('hasProblemType against the type strings the API emits', () => {
  it('matches each of the three slugs the web checks, exactly as the API builds BASE + slug', () => {
    expect(BASE.endsWith('/problems/')).toBe(true);
    expect(hasProblemType(failure(404, BASE + CARD_NOT_FOUND), 404, 'card-not-found')).toBe(true);
    expect(hasProblemType(failure(403, BASE + DEMO_READ_ONLY), 403, 'demo-read-only')).toBe(true);
    expect(hasProblemType(failure(409, BASE + ALREADY_SIGNED_IN), 409, 'already-signed-in')).toBe(
      true,
    );
    expect(isDemoReadOnly(failure(403, BASE + DEMO_READ_ONLY))).toBe(true);
  });

  it('keeps the three slugs apart: no API type answers another call site', () => {
    const types = [BASE + CARD_NOT_FOUND, BASE + DEMO_READ_ONLY, BASE + ALREADY_SIGNED_IN];
    const checks: readonly (readonly [number, string])[] = [
      [404, 'card-not-found'],
      [403, 'demo-read-only'],
      [409, 'already-signed-in'],
    ];
    for (const [index, type] of types.entries()) {
      for (const [checkIndex, [status, slug]] of checks.entries()) {
        expect(hasProblemType(failure(status, type), status, slug), `${type} vs ${slug}`).toBe(
          index === checkIndex,
        );
      }
    }
  });

  it.each([
    ['a nested path under the slug', `${BASE}demo-read-only/extra`],
    ['a trailing slash', `${BASE}demo-read-only/`],
    ['a query string', `${BASE}demo-read-only?x=1`],
    ['a fragment', `${BASE}demo-read-only#x`],
    ['another case', `${BASE}Demo-Read-Only`],
    ['a longer word ending in the slug', `${BASE}not-demo-read-only`],
    ['the slug as a bare path with no /problems/ segment', 'https://example.test/demo-read-only'],
    ['a relative reference with no leading slash', 'problems/demo-read-only'],
    ['the about:blank default', 'about:blank'],
    ['an empty type', ''],
  ])('does not take %s for the demo read-only refusal', (_name, type) => {
    expect(hasProblemType(failure(403, type), 403, 'demo-read-only')).toBe(false);
    expect(isDemoReadOnly(failure(403, type))).toBe(false);
  });
});
