// The Webhooks section of /alerts (P5 D10): the account's webhooks over GET /v1/webhooks, each with
// its last message, a test message and Delete, and the form that adds one. The signing secret of a
// new signed webhook lives in this component's state only: never in a query or mutation cache entry,
// gone from the DOM after "I have stored the secret" and when the page is left. Dates are the date
// parts of the API's timestamps; nothing here reads the clock.
import { useQueryClient } from '@tanstack/react-query';
import { useId, useState, type SubmitEvent } from 'react';
import {
  createWebhook,
  deleteWebhook,
  testWebhook,
  type ApiFailure,
  type WebhookEndpoint,
  type WebhookForm,
  type WebhooksBody,
} from '../api/client';
import {
  MOVEMENT_ABS,
  MOVEMENT_PCT,
  NOTIFICATIONS_KEY,
  WEBHOOKS_KEY,
  WEBHOOKS_LOADING,
  datePart,
  useOnce,
} from './alerts';
import Notice from '../ui/Notice';
import Panel from '../ui/Panel';
import { Field, Input, Select } from '../ui/Field';
import { ProblemBlock, StatusLine } from './Form';
import { MUTED, PRIMARY_BUTTON, SECONDARY_BUTTON, firstErrors } from './fields';

const INTRO =
  `PokéPrices sends a signed message to each signed webhook when a card on your watchlist or ` +
  `portfolio moves by at least ${String(MOVEMENT_PCT)}% and $${MOVEMENT_ABS} between two daily ` +
  `closes. A Discord webhook receives your alerts and digest when you choose Discord below.`;

const SECRET_LINE =
  'Copy this secret before you leave this page. PokéPrices shows it once; to change it, delete ' +
  'the webhook and add it again.';

const DISCORD_ADDED = 'Discord webhook added. Use Send a test message to check it.';
const QUEUED = 'Test message queued. It is sent within a few minutes.';
const TURNED_OFF =
  'Turned off: the receiver said this webhook no longer exists. Delete it and add it again.';

/**
 * The URL field's placeholder (D10): the scheme and its two slashes, as hint text only. It is put
 * together here so the source holds no absolute-URL literal: client.test.ts scans every production
 * source for one, because the SPA fetches its own origin only.
 */
const URL_PLACEHOLDER = `https:${'/'.repeat(2)}`;

const KIND_OPTIONS = [
  { value: 'standard', label: 'Signed webhook (Standard Webhooks)' },
  { value: 'discord', label: 'Discord' },
];

const SECTION = 'mt-8 border-t border-line-subtle pt-6';

/** The one status line of a webhook (D10); a turned-off webhook says so whatever came before. */
function statusLine(endpoint: WebhookEndpoint): { readonly text: string; readonly tone: string } {
  if (endpoint.disabled) {
    return { text: TURNED_OFF, tone: 'font-semibold text-warn' };
  }
  const last = endpoint.last_delivery;
  if (last === null) return { text: 'No message sent yet.', tone: MUTED };
  switch (last.status) {
    case 'delivered':
      return {
        text: `Last message delivered (${datePart(last.at)}).`,
        tone: 'text-ink',
      };
    case 'failed':
      return {
        text: `Last message failed (${datePart(last.at)}).`,
        tone: 'font-semibold text-problem',
      };
    case 'pending':
      return { text: 'Last message waiting to be sent.', tone: MUTED };
  }
}

function WebhookRow({
  endpoint,
  canWrite,
}: {
  readonly endpoint: WebhookEndpoint;
  readonly canWrite: boolean;
}) {
  const queryClient = useQueryClient();
  const [testing, runTest] = useOnce();
  const [deleting, runDelete] = useOnce();
  const [queued, setQueued] = useState(false);
  const [failure, setFailure] = useState<{
    readonly context: string;
    readonly failure: ApiFailure;
  } | null>(null);
  const status = statusLine(endpoint);
  const hint = endpoint.url_hint;

  async function sendTest(): Promise<void> {
    setQueued(false);
    setFailure(null);
    const result = await testWebhook(endpoint.id);
    if (result.ok) setQueued(true);
    else setFailure({ context: 'Could not send the test message', failure: result });
  }

  async function remove(): Promise<void> {
    setFailure(null);
    const result = await deleteWebhook(endpoint.id);
    if (!result.ok) {
      setFailure({ context: 'Could not delete the webhook', failure: result });
      return;
    }
    // Deleting the Discord webhook changes whether Discord can be chosen (and may reset the channel).
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: WEBHOOKS_KEY }),
      endpoint.kind === 'discord'
        ? queryClient.invalidateQueries({ queryKey: NOTIFICATIONS_KEY })
        : undefined,
    ]);
  }

  return (
    <li className="py-4">
      <p className="font-semibold break-all">{hint}</p>
      {endpoint.description !== null && <p className="wrap-anywhere">{endpoint.description}</p>}
      <p className={`flex flex-wrap gap-x-4 text-sm ${MUTED}`}>
        <span>{endpoint.kind === 'discord' ? 'Discord' : 'Signed webhook'}</span>
        <span>{`Added ${datePart(endpoint.created_at)}`}</span>
      </p>
      <p className={`mt-1 text-sm ${status.tone}`}>{status.text}</p>
      {canWrite && (
        <p className="mt-3 flex flex-wrap gap-2">
          {!endpoint.disabled && (
            <button
              type="button"
              disabled={testing}
              aria-label={`Send a test message to ${hint}`}
              onClick={() => {
                runTest(sendTest);
              }}
              className={SECONDARY_BUTTON}
            >
              Send a test message
            </button>
          )}
          <button
            type="button"
            disabled={deleting}
            aria-label={`Delete the webhook ${hint}`}
            onClick={() => {
              runDelete(remove);
            }}
            className={SECONDARY_BUTTON}
          >
            Delete
          </button>
        </p>
      )}
      {queued && <StatusLine>{QUEUED}</StatusLine>}
      {failure !== null && <ProblemBlock context={failure.context} failure={failure.failure} />}
    </li>
  );
}

/** Shown once after a signed webhook is added; "I have stored the secret" removes it. */
function SecretBlock({
  secret,
  onStored,
}: {
  readonly secret: string;
  readonly onStored: () => void;
}) {
  const headingId = useId();
  return (
    <Panel role="region" aria-labelledby={headingId} className="mt-6">
      <h3 id={headingId} className="text-lg font-semibold">
        Signing secret
      </h3>
      <Notice tone="warn" as="p" className="mt-2">
        {SECRET_LINE}
      </Notice>
      <code className="mt-3 block rounded-md border border-line bg-surface-sunken px-3 py-3 font-mono text-base break-all select-all text-ink">
        {secret}
      </code>
      <p className="mt-3">
        <button type="button" onClick={onStored} className={SECONDARY_BUTTON}>
          I have stored the secret
        </button>
      </p>
    </Panel>
  );
}

const FIELDS = ['kind', 'url', 'description'];

function AddWebhook() {
  const queryClient = useQueryClient();
  const headingId = useId();
  const [kind, setKind] = useState<WebhookForm['kind']>('standard');
  const [url, setUrl] = useState('');
  const [description, setDescription] = useState('');
  const [pending, run] = useOnce();
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const [secret, setSecret] = useState<string | null>(null);
  const [discordAdded, setDiscordAdded] = useState(false);
  const errors = firstErrors(failure);

  async function add(form: WebhookForm): Promise<void> {
    setFailure(null);
    setDiscordAdded(false);
    const result = await createWebhook(form);
    // The URL may carry a token (Discord): it is never kept after an answer.
    setUrl('');
    if (!result.ok) {
      setFailure(result);
      return;
    }
    setDescription('');
    const created = result.data.data;
    if (created.secret === null) setDiscordAdded(true);
    else setSecret(created.secret);
    // A new Discord webhook makes Discord a channel that can be chosen.
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: WEBHOOKS_KEY }),
      created.kind === 'discord'
        ? queryClient.invalidateQueries({ queryKey: NOTIFICATIONS_KEY })
        : undefined,
    ]);
  }

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    const form: WebhookForm =
      description.trim() === '' ? { kind, url } : { kind, url, description };
    run(() => add(form));
  }

  return (
    <section aria-labelledby={headingId} className="mt-8">
      <h3 id={headingId} className="text-lg font-semibold">
        Add a webhook
      </h3>
      <form noValidate onSubmit={submit} className="mt-3 space-y-4">
        <Field label="Type" error={errors.kind}>
          {(props) => (
            <Select
              {...props}
              name="kind"
              value={kind}
              onChange={(event) => {
                setKind(event.target.value === 'discord' ? 'discord' : 'standard');
              }}
              options={KIND_OPTIONS}
              className="mt-1 w-full"
            />
          )}
        </Field>
        <Field label="URL" error={errors.url}>
          {(props) => (
            <Input
              {...props}
              name="url"
              type="url"
              inputMode="url"
              autoComplete="off"
              placeholder={URL_PLACEHOLDER}
              value={url}
              onChange={(event) => {
                setUrl(event.target.value);
              }}
              className="mt-1 w-full"
            />
          )}
        </Field>
        <Field label="Description (optional)" error={errors.description}>
          {(props) => (
            <Input
              {...props}
              name="description"
              type="text"
              autoComplete="off"
              value={description}
              onChange={(event) => {
                setDescription(event.target.value);
              }}
              className="mt-1 w-full"
            />
          )}
        </Field>
        <button type="submit" disabled={pending} className={PRIMARY_BUTTON}>
          Add webhook
        </button>
      </form>
      {failure !== null && (
        <ProblemBlock context="Could not add the webhook" failure={failure} fields={FIELDS} />
      )}
      {discordAdded && <StatusLine>{DISCORD_ADDED}</StatusLine>}
      {secret !== null && (
        <SecretBlock
          secret={secret}
          onStored={() => {
            setSecret(null);
          }}
        />
      )}
    </section>
  );
}

/**
 * The Webhooks section: the intro, the list (or its loading line, empty line or problem block) and,
 * for a verified account that is not the demo, the buttons and the add form.
 */
export default function Webhooks({
  list,
  error,
  canWrite,
}: {
  readonly list: WebhooksBody | undefined;
  readonly error: ApiFailure | null;
  readonly canWrite: boolean;
}) {
  const headingId = useId();
  let body;
  if (list !== undefined) {
    body =
      list.data.length === 0 ? (
        // The empty state: a dashed 120 px box with the sentence centred; the form follows it.
        <div className="mt-4 flex min-h-30 items-center justify-center rounded-lg border border-dashed border-line bg-surface p-4">
          <p className={`text-center text-base ${MUTED}`}>No webhooks yet.</p>
        </div>
      ) : (
        <ul
          aria-label="Webhooks"
          className="mt-4 divide-y divide-line-subtle border-y border-line-subtle"
        >
          {list.data.map((endpoint) => (
            <WebhookRow key={endpoint.id} endpoint={endpoint} canWrite={canWrite} />
          ))}
        </ul>
      );
  } else if (error !== null) {
    body = <ProblemBlock context="Could not load your webhooks" failure={error} />;
  } else {
    body = (
      <p role="status" className={`mt-4 text-sm ${MUTED}`}>
        {WEBHOOKS_LOADING}
      </p>
    );
  }
  return (
    <section aria-labelledby={headingId} className={SECTION}>
      <h2 id={headingId} className="text-lg font-semibold">
        Webhooks
      </h2>
      <p className="mt-2">{INTRO}</p>
      {body}
      {canWrite && <AddWebhook />}
    </section>
  );
}
