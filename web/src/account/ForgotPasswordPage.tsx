// /password/forgot (D10, D2): POST /forgot-password. The API answers the same message whether or
// not the address has an account; the page shows that message verbatim.
import { useState, type SubmitEvent } from 'react';
import { requestPasswordReset, type MessageAnswer } from '../api/client';
import { AccountMain, Field, ProblemBlock, StatusLine } from './Form';
import { PRIMARY_BUTTON } from '../ui/classes';
import { firstErrors } from './fields';
import { useAccountWrite } from './mutations';

const FIELDS = ['email'] as const;

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const write = useAccountWrite<MessageAnswer>();
  const pending = write.isPending;
  const failure = write.error;
  // The API's message, verbatim, once the request answered.
  const message = write.data?.message ?? null;
  const errors = firstErrors(failure);

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (pending) return;
    const form = { email };
    write.run(() => requestPasswordReset(form));
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
