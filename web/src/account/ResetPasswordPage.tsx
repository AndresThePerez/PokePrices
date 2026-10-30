// /password/reset?token=&email=: the reset link lands here. On mount the token and the
// email move into component state and leave the address bar (history.replaceState), so the token
// never stays in the browser history. POST /reset-password, then /sign-in with a status line.
import { useEffect, useState, type SubmitEvent } from 'react';
import { resetPassword } from '../api/client';
import Link from '../Link';
import { navigate } from '../router';
import { AccountMain, Field, ProblemBlock } from './Form';
import { PRIMARY_BUTTON, TEXT_LINK } from '../ui/classes';
import { firstErrors } from './fields';
import { useAccountWrite } from './mutations';

const FIELDS = ['email', 'password', 'password_confirmation'] as const;
const SAVED = 'Password saved. Sign in with the new password.';

interface ResetLink {
  readonly token: string;
  readonly email: string;
}

function ResetForm({ link }: { readonly link: ResetLink }) {
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const write = useAccountWrite({
    onSuccess: () => {
      navigate('/sign-in', SAVED);
    },
  });
  const pending = write.isPending;
  const failure = write.error;
  const errors = firstErrors(failure);

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (pending) return;
    const form = {
      token: link.token,
      email: link.email,
      password,
      password_confirmation: confirmation,
    };
    write.run(async () => {
      const result = await resetPassword(form);
      setPassword('');
      setConfirmation('');
      return result;
    });
  }

  return (
    <>
      {failure !== null && (
        <ProblemBlock context="Could not save the password" failure={failure} fields={FIELDS} />
      )}
      <form noValidate onSubmit={submit} className="mt-6 space-y-4">
        <Field
          label="Email"
          name="email"
          type="email"
          maxLength={255}
          autoComplete="username"
          value={link.email}
          onChange={() => undefined}
          readOnly
          error={errors.email}
        />
        <Field
          label="New password"
          name="password"
          type="password"
          autoComplete="new-password"
          value={password}
          onChange={setPassword}
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
          Save password
        </button>
      </form>
    </>
  );
}

export default function ResetPasswordPage({
  token,
  email,
}: {
  readonly token: string | null;
  readonly email: string | null;
}) {
  // Read once: after the address bar is cleared the route no longer carries them.
  const [link] = useState<ResetLink | null>(() =>
    token === null || token === '' || email === null || email === '' ? null : { token, email },
  );
  useEffect(() => {
    window.history.replaceState(null, '', '/password/reset');
  }, []);
  return (
    <AccountMain title="Choose a new password">
      {link === null ? (
        <>
          <p className="mt-6">This reset link is incomplete. Ask for a new one.</p>
          <p className="mt-2">
            <Link to="/password/forgot" className={TEXT_LINK}>
              Ask for a new link
            </Link>
          </p>
        </>
      ) : (
        <ResetForm link={link} />
      )}
    </AccountMain>
  );
}
