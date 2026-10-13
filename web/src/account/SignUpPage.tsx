// /sign-up (D10, D3): invite code, email and password over POST /register. On 201 the account is
// signed in; the page refetches ['me'] and opens /account with its status line.
import { useQueryClient } from '@tanstack/react-query';
import { useState, type SubmitEvent } from 'react';
import { signUp, type ApiFailure } from '../api/client';
import { navigate } from '../router';
import { AccountMain, Field, ProblemBlock } from './Form';
import { MUTED, PRIMARY_BUTTON, firstErrors } from './fields';
import { ME_KEY } from './session';

const FIELDS = ['invite_code', 'email', 'password', 'password_confirmation'] as const;
const CREATED = 'Account created. Check your email for the verification link.';

export default function SignUpPage() {
  const queryClient = useQueryClient();
  const [inviteCode, setInviteCode] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const errors = firstErrors(failure);

  async function send(): Promise<void> {
    setPending(true);
    setFailure(null);
    const result = await signUp({
      invite_code: inviteCode,
      email,
      password,
      password_confirmation: confirmation,
    });
    // Secrets never stay on screen after a submit: the passwords and the invite code are cleared.
    setPassword('');
    setConfirmation('');
    setInviteCode('');
    setPending(false);
    if (!result.ok) {
      setFailure(result);
      return;
    }
    await queryClient.invalidateQueries({ queryKey: ME_KEY, refetchType: 'all' });
    navigate('/account', CREATED);
  }

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (!pending) void send();
  }

  return (
    <AccountMain title="Create an account">
      <p className={`mt-2 ${MUTED}`}>PokéPrices accounts are invite-only.</p>
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
          error={errors.invite_code}
        />
        <Field
          label="Email"
          name="email"
          type="email"
          autoComplete="email"
          value={email}
          onChange={setEmail}
          error={errors.email}
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
        />
        <Field
          label="Confirm password"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          value={confirmation}
          onChange={setConfirmation}
          error={errors.password_confirmation}
        />
        <button type="submit" disabled={pending} className={PRIMARY_BUTTON}>
          Create account
        </button>
      </form>
    </AccountMain>
  );
}
