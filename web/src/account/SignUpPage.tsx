// /sign-up: the invite-only sentence with the demo button beside it, then invite code, email and
// password over POST /register. On 201 the account is signed in; the page refetches ['me'] and
// opens /account with its status line. Opening the page asks for nothing: the demo button asks
// only when pressed.
import { useState, type SubmitEvent } from 'react';
import { signUp } from '../api/client';
import Link from '../Link';
import Notice from '../ui/Notice';
import DemoButton from './DemoButton';
import { AccountMain, Field, ProblemBlock } from './Form';
import { PRIMARY_BUTTON, TEXT_LINK } from '../ui/classes';
import { firstErrors } from './fields';
import { useAccountWrite, useSessionTransition } from './mutations';

const FIELDS = ['invite_code', 'email', 'password', 'password_confirmation'] as const;
const CREATED = 'Account created. Check your email for the verification link.';
/** The owner's sentence: sign-up is by invitation, and the demo is the way in without one. */
const INVITE_ONLY = 'Accounts are invite-only for the moment. Try the read-only demo.';

export default function SignUpPage() {
  const transition = useSessionTransition({ clearsMe: false });
  const [inviteCode, setInviteCode] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmation, setShowConfirmation] = useState(false);
  const write = useAccountWrite<undefined>({
    onSuccess: () => transition('/account', { status: CREATED }),
  });
  const pending = write.isPending;
  const failure = write.error;
  const errors = firstErrors(failure);

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (pending) return;
    // Both passwords are hidden again on every submit, whatever the answer.
    setShowPassword(false);
    setShowConfirmation(false);
    const form = {
      invite_code: inviteCode,
      email,
      password,
      password_confirmation: confirmation,
    };
    write.run(async () => {
      const result = await signUp(form);
      // Secrets never stay on screen after a submit: the passwords and the invite code are cleared.
      setPassword('');
      setConfirmation('');
      setInviteCode('');
      if (!result.ok) {
        setShowPassword(false);
        setShowConfirmation(false);
      }
      return result;
    });
  }

  return (
    <AccountMain title="Create an account" width="auth">
      <Notice tone="info" className="mt-4">
        <p>{INVITE_ONLY}</p>
        <DemoButton placement="block" />
      </Notice>
      {failure !== null && (
        <ProblemBlock context="Could not create the account" failure={failure} fields={FIELDS} />
      )}
      <form noValidate onSubmit={submit} className="mt-6 space-y-4">
        <Field
          label="Invite code"
          name="invite_code"
          type="text"
          autoComplete="off"
          value={inviteCode}
          onChange={setInviteCode}
          hint="The code from your invitation."
          error={errors.invite_code}
          required
        />
        <Field
          label="Email"
          name="email"
          type="email"
          maxLength={255}
          autoComplete="email"
          value={email}
          onChange={setEmail}
          error={errors.email}
          required
        />
        <Field
          label="Password"
          name="password"
          type="password"
          autoComplete="new-password"
          value={password}
          onChange={setPassword}
          hint="At least 12 characters."
          error={errors.password}
          required
          reveal={{
            label: 'Show password',
            shown: showPassword,
            onToggle: () => {
              setShowPassword(!showPassword);
            },
          }}
        />
        <Field
          label="Confirm password"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          value={confirmation}
          onChange={setConfirmation}
          error={errors.password_confirmation}
          required
          reveal={{
            label: 'Show password confirmation',
            shown: showConfirmation,
            onToggle: () => {
              setShowConfirmation(!showConfirmation);
            },
          }}
        />
        <button type="submit" disabled={pending} className={`${PRIMARY_BUTTON} h-12 w-full`}>
          Create account
        </button>
      </form>
      <p className="mt-4 text-sm">
        Already have an account?{' '}
        <Link to="/sign-in" className={TEXT_LINK}>
          Sign in
        </Link>
      </p>
    </AccountMain>
  );
}
