// /account (D10): who is signed in (the read-only demo is named by the header's strip), whether
// the email address is verified (with a resend button), the two-factor section (T04), Your data
// and Delete account (T08) and Sign out (shared with the strip, SignOut.tsx), with a static link
// to /alerts after the two-factor section (P5 T04). Signed out, it links to sign-in and sign-up.
// A fresh mount reads the session from GET /v1/me alone, so a signed-in session survives a reload
// (D13).
import { useState } from 'react';
import { resendVerification, type ApiFailure, type Me } from '../api/client';
import Link from '../Link';
import { AccountMain, LoadingAccount, ProblemBlock, StatusLine } from './Form';
import { SECONDARY_BUTTON, TEXT_LINK } from './fields';
import { useArrivalStatus, useSession } from './session';
import { SignOutButton } from './SignOut';
import TwoFactorSection from './TwoFactor';
import YourData from './YourData';

function Verification({ verified }: { readonly verified: boolean }) {
  const [pending, setPending] = useState(false);
  const [sent, setSent] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);

  async function resend(): Promise<void> {
    setPending(true);
    setSent(false);
    setFailure(null);
    const result = await resendVerification();
    setPending(false);
    if (result.ok) setSent(true);
    else setFailure(result);
  }

  if (verified) return <p className="mt-2">Email verified</p>;
  return (
    <>
      <p className="mt-2">Email not verified</p>
      <p className="mt-3">
        <button
          type="button"
          disabled={pending}
          onClick={() => {
            if (!pending) void resend();
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

function SignedIn({ me, onDeleted }: { readonly me: Me; readonly onDeleted: () => void }) {
  return (
    <>
      <p className="mt-6 text-lg font-semibold break-all">{`Signed in as ${me.email}`}</p>
      <Verification verified={me.email_verified} />
      <TwoFactorSection me={me} />
      <p className="mt-8 border-t border-line-subtle pt-6">
        <Link to="/alerts" className={TEXT_LINK}>
          Alerts and webhooks
        </Link>
      </p>
      <YourData me={me} onDeleted={onDeleted} />
      <SignOutButton className="mt-8 border-t border-line-subtle pt-6" />
    </>
  );
}

export default function AccountPage() {
  const session = useSession();
  const status = useArrivalStatus();
  // Set when this visit deleted the account (T08): the line stays above the signed-out view.
  const [deleted, setDeleted] = useState(false);
  let body;
  switch (session.state) {
    case 'loading':
      body = <LoadingAccount />;
      break;
    case 'failed':
      body = <ProblemBlock context="Could not load your account" failure={session.failure} />;
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
    <AccountMain title="Account">
      {status !== null && <StatusLine>{status}</StatusLine>}
      {deleted && <StatusLine>Your account has been deleted.</StatusLine>}
      {body}
    </AccountMain>
  );
}
