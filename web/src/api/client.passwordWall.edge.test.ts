// Reviewer edge case: the 423 of a route that needs a recently confirmed password now arrives as the
// API's problem document (password-confirmation-required), where it was a plain {"message": …} body.
// The client must hand the screens the same status, with the problem's own type and request id.
import { afterEach, describe, expect, it } from 'vitest';
import { enableTwoFactor, hasProblemType, recoveryCodes } from './client';
import { clearXsrfCookie, problemResponse, setXsrfCookie, stubAccountFetch } from '../test/fixtures';

const TYPE = 'https://pokeprices.andrestheperez.com/problems/password-confirmation-required';

function wall(instance: string, requestId: string) {
  return {
    type: TYPE,
    title: 'Password Confirmation Required',
    status: 423,
    instance,
    request_id: requestId,
  };
}

afterEach(() => {
  clearXsrfCookie();
});

describe('the 423 problem document of the password confirmation wall', () => {
  it('reaches a write and a read as status 423 with the problem type, its request id and no retry', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({
      'POST /user/two-factor-authentication': problemResponse(
        wall('/user/two-factor-authentication', 'req-wall-write-0001'),
      ),
      'GET /user/two-factor-recovery-codes': problemResponse(
        wall('/user/two-factor-recovery-codes', 'req-wall-read-0002'),
      ),
    });

    const write = await enableTwoFactor();
    const read = await recoveryCodes();

    expect(write).toMatchObject({
      ok: false,
      status: 423,
      type: TYPE,
      title: 'Password Confirmation Required',
      requestId: 'req-wall-write-0001',
      problem: wall('/user/two-factor-authentication', 'req-wall-write-0001'),
    });
    expect(read).toMatchObject({ ok: false, status: 423, type: TYPE, requestId: 'req-wall-read-0002' });
    expect(!write.ok && hasProblemType(write, 423, 'password-confirmation-required')).toBe(true);
    expect(!read.ok && hasProblemType(read, 423, 'password-confirmation-required')).toBe(true);
    expect(calls.map((call) => call.key)).toEqual([
      'POST /user/two-factor-authentication',
      'GET /user/two-factor-recovery-codes',
    ]);
  });
});
