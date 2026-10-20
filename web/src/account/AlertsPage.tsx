// /alerts (P5 D10): where a verified account manages its webhooks (T04) and, above them, where
// alerts go and the per-card pointer (T07). Signed out, it offers sign-in (and back here) and
// sign-up. The demo and an unverified account read the page and are told, in words, why they cannot
// change it: no form and no button. Requests: GET /v1/me, then GET /v1/webhooks once signed in, and
// GET /v1/me/notifications only for an account that may change its settings.
import { Fragment, type ReactNode } from 'react';
import Link from '../Link';
import { WEBHOOKS_LOADING, useNotifications, useWebhooks } from './alerts';
import Notice from '../ui/Notice';
import { ProblemBlock } from './Form';
import { MUTED, TEXT_LINK } from './fields';
import NotificationSettings, { PerCardAlerts } from './NotificationSettings';
import { useSession } from './session';
import Webhooks from './Webhooks';

const DEMO_LINE =
  'The read-only demo cannot set alerts or add webhooks: it has no email address or Discord of ' +
  'its own to send them to.';
const UNVERIFIED_LINE = 'Verify your email address to set alerts or add webhooks.';

function AlertsMain({ children }: { readonly children: ReactNode }) {
  return (
    <main className="mx-auto max-w-3xl p-6">
      <h1 className="text-2xl font-semibold">Alerts and webhooks</h1>
      <div className="max-w-xl">{children}</div>
    </main>
  );
}

function Loading() {
  return (
    <p role="status" className={`mt-6 text-sm ${MUTED}`}>
      {WEBHOOKS_LOADING}
    </p>
  );
}

function SignedOut() {
  return (
    <>
      <p className="mt-6">Sign in to set up alerts and webhooks.</p>
      <p className="mt-2 flex flex-wrap gap-x-6">
        <Link to="/sign-in?next=/alerts" className={TEXT_LINK}>
          Sign in
        </Link>
        <Link to="/sign-up" className={TEXT_LINK}>
          Create an account
        </Link>
      </p>
    </>
  );
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
  const canWrite =
    session.state === 'signed-in' && session.me.email_verified && !session.me.read_only;
  // Declared in this order so the requests go out in it: webhooks, then the settings.
  const webhooks = useWebhooks(session.state === 'signed-in');
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
    // Keyed by the account: another account remounts it, dropping every secret held in state
    // (a webhook's one-time signing secret, the settings draft).
    body = (
      <Fragment key={me.email}>
        {me.read_only ? (
          <Notice tone="info" as="p" className="mt-6">
            {DEMO_LINE}
          </Notice>
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
      </Fragment>
    );
  }

  return <AlertsMain>{body}</AlertsMain>;
}
