// /account as titled panels: Profile (who is signed in, the verified chip with
// the resend button, and Sign out at the top right; the read-only demo is named by the header's
// strip), the two-factor section, Notifications (a static row link to /alerts),
// Display (the Theme and Up/down colours selects, which the footer omits here), Your data,
// then Delete account set apart. Signed out, it links to sign-in and sign-up; signed out,
// loading and failed keep the Display panel after their content. A fresh mount reads the session
// from GET /v1/me alone, so a signed-in session survives a reload.
import { useId, useState, type ReactNode } from 'react';
import { resendVerification, type Me } from '../api/client';
import { DisplaySettings } from '../Footer';
import Link from '../Link';
import Panel from '../ui/Panel';
import { AccountMain, LoadingAccount, ProblemBlock, StatusLine } from './Form';
import { FOCUS, MUTED, SECONDARY_BUTTON, TEXT_LINK } from './fields';
import { useAccountWrite } from './mutations';
import { useArrivalStatus, useSession } from './session';
import { SignOutButton } from './SignOut';
import TwoFactorSection from './TwoFactor';
import YourData from './YourData';

const HEADING = 'text-lg font-semibold';

/** A 13 px chip with a 2 px radius; the verified one carries a CSS check, never DOM text. */
const CHIP =
  'mt-3 inline-flex items-center gap-1 rounded-sm border px-2 py-0.5 text-meta font-semibold';
const VERIFIED_CHIP = `${CHIP} border-line text-ink before:[content:'✓'_/_'']`;
const UNVERIFIED_CHIP = `${CHIP} border-warn bg-warn-tint text-ink`;

/** The full-width row link to /alerts: 16 px 600, 44 px, a CSS chevron at the right. */
const ROW_LINK =
  `-mx-3 mt-2 flex min-h-11 items-center justify-between gap-3 rounded-md px-3 text-base ` +
  `font-semibold text-ink hover:bg-surface-sunken after:text-lg after:[content:'›'_/_''] ${FOCUS}`;

/** One titled panel of the page: a section named by its h2. */
function AccountPanel({
  title,
  children,
}: {
  readonly title: string;
  readonly children: ReactNode;
}) {
  const headingId = useId();
  return (
    <Panel as="section" aria-labelledby={headingId} className="mt-6">
      <h2 id={headingId} className={HEADING}>
        {title}
      </h2>
      {children}
    </Panel>
  );
}

/** Theme and Up/down colours, reachable on /account in every state. */
function Display() {
  return (
    <AccountPanel title="Display">
      <DisplaySettings layout="panel" />
    </AccountPanel>
  );
}

function Verification({ verified }: { readonly verified: boolean }) {
  const write = useAccountWrite<undefined>();
  const pending = write.isPending;
  const sent = write.isSuccess;
  const failure = write.error;

  if (verified) return <p className={VERIFIED_CHIP}>Email verified</p>;
  return (
    <>
      <p className={UNVERIFIED_CHIP}>Email not verified</p>
      <p className="mt-3">
        <button
          type="button"
          disabled={pending}
          onClick={() => {
            if (!pending) write.run(resendVerification);
          }}
          className={SECONDARY_BUTTON}
        >
          Send the verification email again
        </button>
      </p>
      {sent && <StatusLine>Verification email sent.</StatusLine>}
      {failure !== null && (
        <ProblemBlock context="Could not send the verification email" failure={failure} />
      )}
    </>
  );
}

function Profile({ me }: { readonly me: Me }) {
  const headingId = useId();
  return (
    <Panel as="section" aria-labelledby={headingId} className="mt-6">
      <SignOutButton
        quiet
        heading={
          <h2 id={headingId} className={HEADING}>
            Profile
          </h2>
        }
      />
      <p className="mt-2 text-base font-semibold break-all">{`Signed in as ${me.email}`}</p>
      <Verification verified={me.email_verified} />
    </Panel>
  );
}

function SignedIn({ me, onDeleted }: { readonly me: Me; readonly onDeleted: () => void }) {
  return (
    <>
      <Profile me={me} />
      {/* The two-factor section keeps its own region and h2; the panel only frames it. */}
      <Panel className="mt-6 [&>section]:mt-0 [&>section]:border-t-0 [&>section]:pt-0">
        <TwoFactorSection me={me} />
      </Panel>
      <AccountPanel title="Notifications">
        <Link to="/alerts" className={ROW_LINK}>
          Alerts and webhooks
        </Link>
        <p className={`mt-1 text-sm ${MUTED}`}>Where alerts go, per-card alerts and webhooks.</p>
      </AccountPanel>
      <Display />
      <YourData me={me} onDeleted={onDeleted} />
    </>
  );
}

export default function AccountPage() {
  const session = useSession();
  const status = useArrivalStatus();
  // Set when this visit deleted the account: the line stays above the signed-out view.
  const [deleted, setDeleted] = useState(false);
  let body;
  switch (session.state) {
    case 'loading':
      body = (
        <>
          <LoadingAccount />
          <Display />
        </>
      );
      break;
    case 'failed':
      body = (
        <>
          <ProblemBlock context="Could not load your account" failure={session.failure} />
          <Display />
        </>
      );
      break;
    case 'signed-out':
      body = (
        <>
          <p className="mt-6">You are not signed in.</p>
          <p className="mt-2 flex flex-wrap gap-x-6">
            <Link to="/sign-in" className={TEXT_LINK}>
              Sign in
            </Link>
            <Link to="/sign-up" className={TEXT_LINK}>
              Create an account
            </Link>
          </p>
          <Display />
        </>
      );
      break;
    case 'signed-in':
      body = (
        // Keyed by the account: another account remounts it, dropping every secret held in state
        // (the two-factor QR, setup key and recovery codes, a typed password).
        <SignedIn
          key={session.me.email}
          me={session.me}
          onDeleted={() => {
            setDeleted(true);
          }}
        />
      );
      break;
  }
  return (
    <AccountMain title="Account" width="wide">
      {status !== null && <StatusLine>{status}</StatusLine>}
      {deleted && <StatusLine>Your account has been deleted.</StatusLine>}
      {body}
    </AccountMain>
  );
}
