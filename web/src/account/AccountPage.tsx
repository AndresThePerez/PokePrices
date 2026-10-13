// /account (D10): who is signed in, whether the email address is verified (with a resend
// button), the two-factor section (T04) and Sign out. Signed out, it links to sign-in and sign-up. A fresh mount reads the
// session from GET /v1/me alone, so a signed-in session survives a reload (D13).
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { resendVerification, signOut, type ApiFailure, type Me } from '../api/client';
import Link from '../Link';
import { navigate } from '../router';
import { AccountMain, LoadingAccount, ProblemBlock, StatusLine } from './Form';
import { SECONDARY_BUTTON, TEXT_LINK } from './fields';
import { ME_KEY, useArrivalStatus, useSession } from './session';
import TwoFactorSection from './TwoFactor';

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

function SignOut() {
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);

  async function leave(): Promise<void> {
    setPending(true);
    setFailure(null);
    const result = await signOut();
    setPending(false);
    if (!result.ok) {
      setFailure(result);
      return;
    }
    // Signed out at once (no stale "Signed in as" on the next visit), then confirmed by the API.
    queryClient.setQueryData(ME_KEY, null);
    void queryClient.invalidateQueries({ queryKey: ME_KEY });
    navigate('/');
  }

  return (
    <div className="mt-8 border-t border-slate-200 pt-6 dark:border-slate-700">
      <button
        type="button"
        disabled={pending}
        onClick={() => {
          if (!pending) void leave();
        }}
        className={SECONDARY_BUTTON}
      >
        Sign out
      </button>
      {failure !== null && <ProblemBlock context="Could not sign out" failure={failure} />}
    </div>
  );
}

function SignedIn({ me }: { readonly me: Me }) {
  return (
    <>
      <p className="mt-6 text-lg font-medium break-all">{`Signed in as ${me.email}`}</p>
      <Verification verified={me.email_verified} />
      <TwoFactorSection me={me} />
      <SignOut />
    </>
  );
}

export default function AccountPage() {
  const session = useSession();
  const status = useArrivalStatus();
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
      body = <SignedIn me={session.me} />;
      break;
  }
  return (
    <AccountMain title="Account">
      {status !== null && <StatusLine>{status}</StatusLine>}
      {body}
    </AccountMain>
  );
}
