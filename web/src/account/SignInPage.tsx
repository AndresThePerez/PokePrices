// /sign-in: email and password over POST /login, then, when Fortify answers
// {two_factor: true}, the authentication code step over POST /two-factor-challenge. Signed
// in, the page says who; after a sign-in it refetches ['me'] and opens next (already checked by
// the router) or /account.
import { useId, useState, type SubmitEvent } from 'react';
import { signIn, twoFactorChallenge, type SignInAnswer } from '../api/client';
import Link from '../Link';
import { DemoPanel } from './DemoButton';
import { AccountMain, Field, LoadingAccount, ProblemBlock, StatusLine } from './Form';
import { PRIMARY_BUTTON, TEXT_LINK } from '../ui/classes';
import { firstErrors } from './fields';
import { useAccountWrite, useSessionTransition } from './mutations';
import { useArrivalStatus, useSession } from './session';

const FIELDS = ['email', 'password'] as const;

type ChallengeField = 'code' | 'recovery_code';

/** The two-factor step: an authentication code, or a recovery code instead. */
function TwoFactorChallenge({ next }: { readonly next: string }) {
  const transition = useSessionTransition({ clearsMe: false });
  const headingId = useId();
  const [field, setField] = useState<ChallengeField>('code');
  const [value, setValue] = useState('');
  const challenge = useAccountWrite<undefined>({ onSuccess: () => transition(next) });
  const pending = challenge.isPending;
  const failure = challenge.error;
  const errors = firstErrors(failure);

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (pending) return;
    const form = field === 'code' ? { code: value } : { recovery_code: value };
    // The code is never kept after a submit, whatever the answer.
    setValue('');
    challenge.run(() => twoFactorChallenge(form));
  }

  function toggle(): void {
    setField(field === 'code' ? 'recovery_code' : 'code');
    setValue('');
    if (challenge.isError) challenge.reset();
  }

  return (
    <section aria-labelledby={headingId} className="mt-6">
      <h2 id={headingId} className="text-lg font-semibold">
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

function SignInForm({
  next,
  challenge,
  onChallenge,
}: {
  readonly next: string;
  readonly challenge: boolean;
  readonly onChallenge: () => void;
}) {
  const transition = useSessionTransition({ clearsMe: false });
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const write = useAccountWrite<SignInAnswer>({
    onSuccess: async (answer) => {
      // two_factor true: the authenticator-app step follows; nothing is signed in yet.
      if (answer.two_factor) onChallenge();
      else await transition(next);
    },
  });
  const pending = write.isPending;
  const failure = write.error;
  const errors = firstErrors(failure);

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (pending) return;
    const form = { email, password };
    write.run(async () => {
      const result = await signIn(form);
      // The password is never kept after a submit, whatever the answer.
      setPassword('');
      return result;
    });
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
          maxLength={255}
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
        <button type="submit" disabled={pending} className={`${PRIMARY_BUTTON} h-12 w-full`}>
          Sign in
        </button>
      </form>
      <p className="mt-4 flex flex-wrap gap-x-6 text-sm">
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
  // The two-factor step lives here so the Just looking? panel can leave with the form.
  const [challenge, setChallenge] = useState(false);
  const showsForm = session.state !== 'loading' && session.state !== 'signed-in';
  if (!showsForm && challenge) setChallenge(false); // the form left: forget its two-factor step
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
    body = (
      <SignInForm
        next={next}
        challenge={challenge}
        onChallenge={() => {
          setChallenge(true);
        }}
      />
    );
  }
  const form = showsForm && !challenge;
  return (
    <AccountMain title="Sign in" width="auth" after={form ? <DemoPanel /> : undefined}>
      {status !== null && <StatusLine>{status}</StatusLine>}
      {body}
    </AccountMain>
  );
}
