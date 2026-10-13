// /sign-in (D10): email and password over POST /login. Signed in, the page says who; after a
// sign-in it refetches ['me'] and opens next (already checked by the router) or /account.
import { useQueryClient } from '@tanstack/react-query';
import { useState, type SubmitEvent } from 'react';
import { signIn, type ApiFailure } from '../api/client';
import Link from '../Link';
import { navigate } from '../router';
import { AccountMain, Field, LoadingAccount, ProblemBlock, StatusLine } from './Form';
import { PRIMARY_BUTTON, TEXT_LINK, firstErrors } from './fields';
import { ME_KEY, useArrivalStatus, useSession } from './session';

const FIELDS = ['email', 'password'] as const;

function SignInForm({ next }: { readonly next: string }) {
  const queryClient = useQueryClient();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const errors = firstErrors(failure);

  async function send(): Promise<void> {
    setPending(true);
    setFailure(null);
    const result = await signIn({ email, password });
    // The password is never kept after a submit, whatever the answer.
    setPassword('');
    setPending(false);
    if (!result.ok) {
      setFailure(result);
      return;
    }
    // two_factor true: the authenticator-app step (T04) follows; nothing is signed in yet.
    if (result.data.two_factor) return;
    await queryClient.invalidateQueries({ queryKey: ME_KEY, refetchType: 'all' });
    navigate(next);
  }

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (!pending) void send();
  }

  return (
    <>
      {failure !== null && (
        <ProblemBlock context="Could not sign in" failure={failure} fields={FIELDS} />
      )}
      <form noValidate onSubmit={submit} className="mt-6 space-y-4">
        <Field
          label="Email"
          name="email"
          type="email"
          autoComplete="username"
          value={email}
          onChange={setEmail}
          error={errors.email}
        />
        <Field
          label="Password"
          name="password"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={setPassword}
          error={errors.password}
        />
        <button type="submit" disabled={pending} className={PRIMARY_BUTTON}>
          Sign in
        </button>
      </form>
      <p className="mt-4 flex flex-wrap gap-x-6">
        <Link to="/password/forgot" className={TEXT_LINK}>
          Forgot your password?
        </Link>
        <Link to="/sign-up" className={TEXT_LINK}>
          Create an account
        </Link>
      </p>
    </>
  );
}

export default function SignInPage({ next }: { readonly next: string }) {
  const session = useSession();
  const status = useArrivalStatus();
  let body;
  if (session.state === 'loading') {
    body = <LoadingAccount />;
  } else if (session.state === 'signed-in') {
    body = (
      <>
        <p className="mt-6">You are signed in as {session.me.email}.</p>
        <p className="mt-2">
          <Link to="/account" className={TEXT_LINK}>
            Go to your account
          </Link>
        </p>
      </>
    );
  } else {
    body = <SignInForm next={next} />;
  }
  return (
    <AccountMain title="Sign in">
      {status !== null && <StatusLine>{status}</StatusLine>}
      {body}
    </AccountMain>
  );
}
