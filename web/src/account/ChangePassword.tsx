// "Change password" on /account: the current password, the new one and its confirmation over
// PUT /user/password. The form checks itself first (nothing empty, 12 to 72 characters, the same
// twice); the API's 422 messages sit under their fields; a success is one status line. The three
// values are dropped after every answer. The read-only demo cannot change its password, so it gets
// no panel at all.
import { useId, useState, type SubmitEvent } from 'react';
import { changePassword } from '../api/client';
import Panel from '../ui/Panel';
import { PRIMARY_BUTTON } from '../ui/classes';
import { Field, ProblemBlock, StatusLine } from './Form';
import { firstErrors, newPasswordFields, required, useFieldChecks } from './fields';
import { useAccountWrite } from './mutations';
import { useReadOnly } from './session';

const FIELDS = ['current_password', 'password', 'password_confirmation'] as const;
const CHANGED = 'Password changed. Your other sessions are signed out.';

function ChangePasswordForm() {
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const write = useAccountWrite<undefined>();
  const checks = useFieldChecks();
  const pending = write.isPending;
  const failure = write.error;
  const errors = { ...firstErrors(failure), ...checks.errors };

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (pending) return;
    const fields = [
      ['current_password', current, required('Enter your current password.')],
      ...newPasswordFields(password, confirmation, 'Enter a new password.'),
    ] as const;
    if (!checks.passes(event.currentTarget, fields)) {
      // The last answer (a success line, a problem) no longer describes the form.
      write.reset();
      return;
    }
    const form = {
      current_password: current,
      password,
      password_confirmation: confirmation,
    };
    write.run(async () => {
      const result = await changePassword(form);
      // No password is kept after an answer, whatever it was.
      setCurrent('');
      setPassword('');
      setConfirmation('');
      return result;
    });
  }

  return (
    <>
      <form noValidate onSubmit={submit} className="mt-4 space-y-4">
        <Field
          label="Current password"
          name="current_password"
          type="password"
          autoComplete="current-password"
          value={current}
          onChange={setCurrent}
          error={errors.current_password}
        />
        <Field
          label="New password"
          name="password"
          type="password"
          autoComplete="new-password"
          value={password}
          onChange={setPassword}
          hint="12 to 72 characters."
          error={errors.password}
        />
        <Field
          label="Confirm new password"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          value={confirmation}
          onChange={setConfirmation}
          error={errors.password_confirmation}
        />
        <button type="submit" disabled={pending} className={PRIMARY_BUTTON}>
          Change password
        </button>
      </form>
      {write.isSuccess && <StatusLine>{CHANGED}</StatusLine>}
      {failure !== null && (
        <ProblemBlock context="Could not change the password" failure={failure} fields={FIELDS} />
      )}
    </>
  );
}

/** The Change password panel; nothing for the read-only demo. */
export default function ChangePassword() {
  const headingId = useId();
  const readOnly = useReadOnly();
  if (readOnly) return null;
  return (
    <Panel as="section" aria-labelledby={headingId} className="mt-6">
      <h2 id={headingId} className="text-lg font-semibold">
        Change password
      </h2>
      <ChangePasswordForm />
    </Panel>
  );
}
