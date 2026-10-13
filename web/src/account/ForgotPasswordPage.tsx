// /password/forgot (D10, D2): POST /forgot-password. The API answers the same message whether or
// not the address has an account; the page shows that message verbatim.
import { useState, type SubmitEvent } from 'react';
import { requestPasswordReset, type ApiFailure } from '../api/client';
import { AccountMain, Field, ProblemBlock, StatusLine } from './Form';
import { PRIMARY_BUTTON, firstErrors } from './fields';

const FIELDS = ['email'] as const;

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const errors = firstErrors(failure);

  async function send(): Promise<void> {
    setPending(true);
    setFailure(null);
    setMessage(null);
    const result = await requestPasswordReset({ email });
    setPending(false);
    if (result.ok) setMessage(result.data.message);
    else setFailure(result);
  }

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (!pending) void send();
  }

  return (
    <AccountMain title="Reset your password">
      {message !== null && <StatusLine>{message}</StatusLine>}
      {failure !== null && (
        <ProblemBlock context="Could not send the reset link" failure={failure} fields={FIELDS} />
      )}
      <form noValidate onSubmit={submit} className="mt-6 space-y-4">
        <Field
          label="Email"
          name="email"
          type="email"
          autoComplete="email"
          value={email}
          onChange={setEmail}
          error={errors.email}
        />
        <button type="submit" disabled={pending} className={PRIMARY_BUTTON}>
          Send reset link
        </button>
      </form>
    </AccountMain>
  );
}
