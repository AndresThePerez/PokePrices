// /sign-in (D10): email and password over POST /login, then, when Fortify answers
// {two_factor: true}, the authentication code step (T04) over POST /two-factor-challenge. Signed
// in, the page says who; after a sign-in it refetches ['me'] and opens next (already checked by
// the router) or /account.
import { useQueryClient } from '@tanstack/react-query';
import { useId, useState, type SubmitEvent } from 'react';
import { signIn, twoFactorChallenge, type ApiFailure } from '../api/client';
import Link from '../Link';
import { navigate } from '../router';
import DemoButton from './DemoButton';
import { AccountMain, Field, LoadingAccount, ProblemBlock, StatusLine } from './Form';
import { PRIMARY_BUTTON, TEXT_LINK, firstErrors } from './fields';
import { ME_KEY, endSession, useArrivalStatus, useSession } from './session';

const FIELDS = ['email', 'password'] as const;

type ChallengeField = 'code' | 'recovery_code';

/** The two-factor step: an authentication code, or a recovery code instead. */
function TwoFactorChallenge({ next }: { readonly next: string }) {
  const queryClient = useQueryClient();
  const headingId = useId();
  const [field, setField] = useState<ChallengeField>('code');
  const [value, setValue] = useState('');
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const errors = firstErrors(failure);

  async function send(): Promise<void> {
    setPending(true);
    setFailure(null);
    const form = field === 'code' ? { code: value } : { recovery_code: value };
    // The code is never kept after a submit, whatever the answer.
    setValue('');
    const result = await twoFactorChallenge(form);
    if (!result.ok) {
      setPending(false);
      setFailure(result);
      return;
    }
    endSession(queryClient);
    await queryClient.invalidateQueries({ queryKey: ME_KEY, refetchType: 'all' });
    navigate(next);
  }

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (!pending) void send();
  }

  function toggle(): void {
    setField(field === 'code' ? 'recovery_code' : 'code');
    setValue('');
    setFailure(null);
  }

  return (
    <section aria-labelledby={headingId} className="mt-6">
      <h2 id={headingId} className="text-xl font-semibold">
        Two-factor authentication
      </h2>
      {failure !== null && (
        <ProblemBlock context="Could not sign in" failure={failure} fields={[field]} />
      )}
      <form noValidate onSubmit={submit} className="mt-4 space-y-4">
        {field === 'code' ? (
          <Field
            key="code"
            label="Authentication code"
            name="code"
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            value={value}
            onChange={setValue}
            error={errors.code}
          />
        ) : (
          <Field
            key="recovery_code"
            label="Recovery code"
            name="recovery_code"
            type="text"
            autoComplete="off"
            value={value}
            onChange={setValue}
            error={errors.recovery_code}
          />
        )}
        <button type="submit" disabled={pending} className={PRIMARY_BUTTON}>
          Verify
        </button>
      </form>
      <p className="mt-4">
        <button type="button" onClick={toggle} className={TEXT_LINK}>
          {field === 'code' ? 'Use a recovery code instead' : 'Use an authentication code instead'}
        </button>
      </p>
    </section>
  );
}

function SignInForm({ next }: { readonly next: string }) {
  const queryClient = useQueryClient();
  const [challenge, setChallenge] = useState(false);
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
    if (!result.ok) {
      setPending(false);
      setFailure(result);
      return;
    }
    // two_factor true: the authenticator-app step follows; nothing is signed in yet.
    if (result.data.two_factor) {
      setChallenge(true);
      return;
    }
    endSession(queryClient);
    await queryClient.invalidateQueries({ queryKey: ME_KEY, refetchType: 'all' });
    navigate(next);
  }

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (!pending) void send();
  }

  if (challenge) return <TwoFactorChallenge next={next} />;

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
      <DemoButton />
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
