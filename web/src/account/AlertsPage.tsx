// /alerts: where a verified account manages its webhooks and, above them, where
// alerts go and the per-card pointer. Signed out, it offers sign-in (and back here) and
// sign-up. The demo and an unverified account read the page and are told, in words, why they cannot
// change it: no form and no button; the demo (useReadOnly) also sees its seeded samples. Requests:
// GET /v1/me, then GET /v1/webhooks once signed in, then GET /v1/demo/samples for the demo only, or
// GET /v1/me/notifications only for an account that may change its settings.
import type { ReactNode } from 'react';
import Link from '../Link';
import { WEBHOOKS_LOADING, useDemoSamples, useNotifications, useWebhooks } from './alerts';
import DemoSamples, { SAMPLES_LINE } from './DemoSamples';
import Notice from '../ui/Notice';
import Page from '../ui/Page';
import StatusLine from '../ui/StatusLine';
import AccountScope from './AccountScope';
import { ProblemBlock } from './Form';
import { TEXT_LINK } from '../ui/classes';
import NotificationSettings, { PerCardAlerts } from './NotificationSettings';
import { useReadOnly, useSession } from './session';
import SignInPrompt from './SignInPrompt';
import Webhooks from './Webhooks';

const DEMO_LINE =
  'The read-only demo cannot set alerts or add webhooks: it has no email address or Discord of ' +
  'its own to send them to.';
const UNVERIFIED_LINE = 'Verify your email address to set alerts or add webhooks.';

function AlertsMain({ children }: { readonly children: ReactNode }) {
  return (
    <Page>
      <h1 className="text-2xl font-semibold">Alerts and webhooks</h1>
      <div className="max-w-xl">{children}</div>
    </Page>
  );
}

function Loading() {
  return <StatusLine gap="mt-6">{WEBHOOKS_LOADING}</StatusLine>;
}

function SignedOut() {
  return <SignInPrompt sentence="Sign in to set up alerts and webhooks." next="/alerts" signUp />;
}

function Unverified() {
  return (
    <Notice tone="info" className="mt-6">
      <p>{UNVERIFIED_LINE}</p>
      <p>
        <Link to="/account" className={TEXT_LINK}>
          Go to your account
        </Link>
      </p>
    </Notice>
  );
}

export default function AlertsPage() {
  const session = useSession();
  const readOnly = useReadOnly();
  const signedIn = session.state === 'signed-in';
  const canWrite = signedIn && session.me.email_verified && !readOnly;
  // Declared in this order so the requests go out in it: webhooks, then the demo's samples (the
  // demo only) or the settings (an account that may change them).
  const webhooks = useWebhooks(signedIn);
  const samples = useDemoSamples(signedIn && readOnly);
  const notifications = useNotifications(canWrite);

  let body;
  if (session.state === 'loading') {
    body = <Loading />;
  } else if (session.state === 'failed') {
    body = <ProblemBlock context="Could not load your account" failure={session.failure} />;
  } else if (
    session.state === 'signed-out' ||
    (webhooks.data === undefined && webhooks.error?.status === 401)
  ) {
    body = <SignedOut />;
  } else {
    const { me } = session;
    // Scoped to the account: another account remounts it, dropping every secret held in state
    // (a webhook's one-time signing secret, the settings draft).
    body = (
      <AccountScope>
        {readOnly ? (
          <>
            <Notice tone="info" className="mt-6">
              <p>{DEMO_LINE}</p>
              <p className="mt-1">{SAMPLES_LINE}</p>
            </Notice>
            <DemoSamples body={samples.data} error={samples.error} />
          </>
        ) : (
          !me.email_verified && <Unverified />
        )}
        {canWrite && (
          <>
            <NotificationSettings
              email={me.email}
              settings={notifications.data}
              error={notifications.error}
            />
            <PerCardAlerts />
          </>
        )}
        <Webhooks list={webhooks.data} error={webhooks.error} canWrite={canWrite} />
      </AccountScope>
    );
  }

  return <AlertsMain>{body}</AlertsMain>;
}
