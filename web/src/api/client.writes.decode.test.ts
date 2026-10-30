// The write-answer decoders (Q1-i6 T03, c1 rest): a 2xx write answer whose fields are not the ones
// its caller reads is the unexpected-answer failure, never data; a well-shaped answer passes through
// as is (a caller that reads no field gets its envelope checked). The writes whose answer is empty
// (204 and Fortify's '') stay unchecked.
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
  type ApiResult,
  createWebhook,
  putAlert,
  putHolding,
  putNotifications,
  putWatchlistItem,
  resetPassword,
  testWebhook,
} from './client';
import {
  FAKE_SIGNING_SECRET,
  alertBody,
  clearXsrfCookie,
  discordWebhook,
  jsonResponse,
  notificationsBody,
  rawResponse,
  setXsrfCookie,
  stubAccountFetch,
  webhookCreatedBody,
  webhookOf,
} from '../test/fixtures';

beforeEach(() => {
  setXsrfCookie();
});

afterEach(() => {
  clearXsrfCookie();
});

function UNEXPECTED(status: number) {
  return {
    ok: false,
    status,
    type: 'about:blank',
    title: `The PokéPrices API sent an unexpected answer (HTTP ${String(status)})`,
    requestId: null,
    problem: null,
  };
}

const KEY = 'base1-4:holo:unlimited:-';
const SEGMENT = 'base1-4%3Aholo%3Aunlimited%3A-';

type Call = () => Promise<ApiResult<unknown>>;

interface Write {
  readonly name: string;
  readonly route: string;
  readonly status: number;
  readonly call: Call;
  readonly good: readonly unknown[];
  readonly bad: readonly (readonly [string, unknown])[];
}

const RESET_FORM = {
  token: 'fake-reset-token-0000',
  email: 'collector@example.com',
  password: 'fake-password-0000-ok',
  password_confirmation: 'fake-password-0000-ok',
};

/** Shapes no write answer has: nothing, the wrong top level, data missing or of the wrong kind. */
const ENVELOPE_MISSES: readonly (readonly [string, unknown])[] = [
  ['an empty object', {}],
  ['data null', { data: null }],
  ['data a list', { data: [] }],
  ['data a string', { data: 'ok' }],
  ['a bare array', []],
  ['null', null],
  ['a string', 'ok'],
];

const WRITES: readonly Write[] = [
  {
    name: 'resetPassword',
    route: 'POST /reset-password',
    status: 200,
    call: () => resetPassword(RESET_FORM),
    good: [{ message: 'Your password has been reset.' }],
    bad: [
      ['an empty object', {}],
      ['message a number', { message: 7 }],
      ['message null', { message: null }],
      ['a bare array', []],
      ['null', null],
      ['a string', 'ok'],
    ],
  },
  {
    name: 'putWatchlistItem',
    route: `PUT /v1/watchlist/${SEGMENT}`,
    status: 200,
    call: () => putWatchlistItem(KEY),
    good: [{ data: { variant_key: KEY } }],
    bad: ENVELOPE_MISSES,
  },
  {
    name: 'putHolding',
    route: `PUT /v1/portfolio/${SEGMENT}`,
    status: 200,
    call: () => putHolding(KEY, 3),
    good: [{ data: { variant_key: KEY, quantity: 3 } }],
    bad: [
      ...ENVELOPE_MISSES,
      ['quantity missing', { data: { variant_key: KEY } }],
      ['quantity a string', { data: { variant_key: KEY, quantity: '3' } }],
      ['quantity null', { data: { variant_key: KEY, quantity: null } }],
    ],
  },
  {
    name: 'createWebhook',
    route: 'POST /v1/webhooks',
    status: 201,
    call: () => createWebhook({ kind: 'standard', url: 'https://hooks.example.com/pokeprices' }),
    good: [
      webhookCreatedBody(webhookOf(), FAKE_SIGNING_SECRET),
      webhookCreatedBody(discordWebhook, null),
    ],
    bad: [
      ...ENVELOPE_MISSES,
      ['the secret missing', { data: { ...webhookOf() } }],
      ['the secret a number', { data: { ...webhookOf(), secret: 0 } }],
      ['kind outside the two', { data: { ...webhookOf(), kind: 'slack', secret: null } }],
      ['kind missing', { data: { ...webhookOf(), kind: undefined, secret: null } }],
    ],
  },
  {
    name: 'testWebhook',
    route: 'POST /v1/webhooks/41/test',
    status: 202,
    call: () => testWebhook(41),
    good: [{ data: { message_id: 'msg_01J0000000000000000000000A' } }],
    bad: ENVELOPE_MISSES,
  },
  {
    name: 'putNotifications',
    route: 'PUT /v1/me/notifications',
    status: 200,
    call: () => putNotifications({ channel: 'email', digest: true }),
    good: [notificationsBody({ digest: true })],
    bad: [
      ...ENVELOPE_MISSES,
      ['channel outside the two', { data: { channel: 'sms', digest: true } }],
      ['digest missing', { data: { channel: 'email' } }],
      ['digest a string', { data: { channel: 'email', digest: 'true' } }],
    ],
  },
  {
    name: 'putAlert',
    route: `PUT /v1/alerts/${SEGMENT}`,
    status: 200,
    call: () => putAlert(KEY, 10),
    good: [alertBody(KEY, 10)],
    bad: ENVELOPE_MISSES,
  },
];

for (const write of WRITES) {
  describe(`${write.name} decodes its answer`, () => {
    it.each(write.good.map((body, index) => [index, body] as const))(
      'passes well-shaped answer %i through as is',
      async (_index, body) => {
        stubAccountFetch({ [write.route]: jsonResponse(body, { status: write.status }) });
        expect(await write.call()).toEqual({ ok: true, data: body });
      },
    );

    it.each(write.bad)('refuses %s as the unexpected answer', async (_shape, body) => {
      stubAccountFetch({ [write.route]: jsonResponse(body, { status: write.status }) });
      expect(await write.call()).toEqual(UNEXPECTED(write.status));
    });

    it('refuses an empty 2xx as the unexpected answer', async () => {
      stubAccountFetch({ [write.route]: rawResponse(write.status, null) });
      expect(await write.call()).toEqual(UNEXPECTED(write.status));
    });
  });
}
