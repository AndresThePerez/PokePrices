// The read-only demo's samples on /alerts, each in its own titled section and labelled as a
// sample: the alert on a watchlist card, the alert email as the account would have received it,
// and the signed webhook delivery with its signature headers, its exact body and the published
// sample secret it verifies with. Nothing is ever sent from the demo. Every date in the page's own
// words is the date part of a timestamp the API sent; the body is shown as its exact bytes (the
// signature is computed over them), the event's own times included.
import { useId, type ReactNode } from 'react';
import type { ApiFailure, DemoSamplesBody } from '../api/client';
import { variantLabel } from '../format';
import Panel from '../ui/Panel';
import StatusLine from '../ui/StatusLine';
import { MUTED } from '../ui/classes';
import { MOVEMENT_ABS, datePart } from './alerts';
import { ProblemBlock } from './Form';

/** The line under the demo sentence of /alerts. */
export const SAMPLES_LINE =
  'The alert, the email and the signed webhook delivery below are samples: nothing is ever sent ' +
  'from the demo.';
const NO_SAMPLES =
  'No sample messages: no card on the demo watchlist moved by its alert threshold in the ' +
  'collected closes.';
const SECRET_LABEL =
  'Sample signing secret, published for the demo only; it belongs to no account.';
const LOADING = "Loading the demo's samples…";

/** A block of exact text: wrapped, monospaced, on the sunken step. */
const CODE_BLOCK =
  'mt-2 block rounded-md border border-line bg-surface-sunken px-3 py-3 font-mono text-sm ' +
  'whitespace-pre-wrap break-all text-ink';

function SampleSection({
  title,
  children,
}: {
  readonly title: string;
  readonly children: ReactNode;
}) {
  const headingId = useId();
  return (
    <Panel as="section" aria-labelledby={headingId} className="mt-6">
      <h2 id={headingId} className="text-lg font-semibold">
        {title}
      </h2>
      {children}
    </Panel>
  );
}

type Samples = DemoSamplesBody['data'];

function AlertSample({ alert }: { readonly alert: NonNullable<Samples['alert']> }) {
  const name = alert.card === null ? `Card ${alert.card_id}` : alert.card.name;
  return (
    <SampleSection title="Sample alert">
      <p className="mt-2 font-semibold">{`${name} · ${variantLabel(alert)}`}</p>
      <p className="mt-1">
        {`An alert at ${String(alert.alert_pct)}% (and at least $${MOVEMENT_ABS}) on the demo's ` +
          `watchlist: an email when this card's daily close moves by that much against the ` +
          `previous close.`}
      </p>
    </SampleSection>
  );
}

function EmailSample({ message }: { readonly message: NonNullable<Samples['message']> }) {
  return (
    <SampleSection title="Sample alert email">
      <p className={`mt-1 text-sm ${MUTED}`}>
        {`Delivered by email on ${datePart(message.delivered_at)}.`}
      </p>
      <p className="mt-3 font-semibold">{message.subject}</p>
      <pre className={CODE_BLOCK}>{message.text}</pre>
    </SampleSection>
  );
}

function WebhookSample({
  webhook,
  secret,
}: {
  readonly webhook: NonNullable<Samples['webhook']>;
  readonly secret: string;
}) {
  const headers = [
    `webhook-id: ${webhook.headers['webhook-id']}`,
    `webhook-timestamp: ${webhook.headers['webhook-timestamp']}`,
    `webhook-signature: ${webhook.headers['webhook-signature']}`,
  ].join('\n');
  return (
    <SampleSection title="Sample signed webhook delivery">
      <p className={`mt-1 text-sm ${MUTED}`}>
        {`Delivered to ${webhook.url_hint} on ${datePart(webhook.delivered_at)}.`}
      </p>
      <h3 className="mt-3 text-base font-semibold">Headers</h3>
      <pre className={CODE_BLOCK}>{headers}</pre>
      <h3 className="mt-3 text-base font-semibold">Body</h3>
      <pre className={CODE_BLOCK} data-sample-payload="">
        {webhook.body}
      </pre>
      <p className="mt-3 text-sm">{SECRET_LABEL}</p>
      <code className={CODE_BLOCK}>{secret}</code>
    </SampleSection>
  );
}

/** The demo's samples, or their loading line, or the problem block of a failed read. */
export default function DemoSamples({
  body,
  error,
}: {
  readonly body: DemoSamplesBody | undefined;
  readonly error: ApiFailure | null;
}) {
  if (body === undefined) {
    if (error !== null) {
      return <ProblemBlock context="Could not load the demo's samples" failure={error} />;
    }
    return <StatusLine gap="mt-6">{LOADING}</StatusLine>;
  }
  const { alert, message, webhook, sample_secret: secret } = body.data;
  return (
    <>
      {alert !== null && <AlertSample alert={alert} />}
      {message !== null && <EmailSample message={message} />}
      {webhook !== null && <WebhookSample webhook={webhook} secret={secret} />}
      {message === null && webhook === null && <p className="mt-6">{NO_SAMPLES}</p>}
    </>
  );
}
