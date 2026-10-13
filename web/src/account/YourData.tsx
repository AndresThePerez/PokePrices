// "Your data" and "Delete account" on /account (D10, T08). The export is a plain link the browser
// downloads (GET /v1/me/export, a top-level navigation with a same-origin Referer so Sanctum treats
// it as first-party; never rel="noreferrer", review F-P4-09), so nothing here fetches it. Deleting
// needs the checkbox first; only then does the password field appear, and the password is dropped
// after every answer and whenever the checkbox is cleared. The demo sees why it cannot delete.
import { useQueryClient } from '@tanstack/react-query';
import { useId, useState, type SubmitEvent } from 'react';
import { deleteAccount, type ApiFailure, type Me } from '../api/client';
import { Field, ProblemBlock } from './Form';
import { FOCUS, TEXT_LINK, firstErrors } from './fields';
import { forgetLists } from './lists';
import { ME_KEY } from './session';

const EXPORT_PATH = '/v1/me/export';
const EXPORT_FILE = 'pokeprices-export.json';

const SECTION = 'mt-8 border-t border-slate-200 pt-6 dark:border-slate-700';

/** The one destructive button on the page: filled red in both themes. */
const DANGER_BUTTON =
  `min-h-11 min-w-11 rounded-md bg-red-700 px-4 py-2 font-semibold text-white ` +
  `disabled:opacity-60 dark:bg-red-300 dark:text-red-950 ${FOCUS}`;

/** The checkbox keeps a 44 px row; browsers draw the box itself square inside it. */
const CHECKBOX = `min-h-11 w-5 shrink-0 accent-red-700 dark:accent-red-300 ${FOCUS}`;

function ExportSection() {
  const headingId = useId();
  return (
    <section aria-labelledby={headingId} className={SECTION}>
      <h2 id={headingId} className="text-xl font-semibold">
        Your data
      </h2>
      <p className="mt-2">
        <a
          href={EXPORT_PATH}
          download={EXPORT_FILE}
          referrerPolicy="same-origin"
          className={TEXT_LINK}
        >
          Download your data (JSON)
        </a>
      </p>
    </section>
  );
}

function DeleteForm({ onDeleted }: { readonly onDeleted: () => void }) {
  const queryClient = useQueryClient();
  const checkboxId = useId();
  const [understood, setUnderstood] = useState(false);
  const [password, setPassword] = useState('');
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const errors = firstErrors(failure);
  const ready = understood && password !== '' && !pending;

  async function remove(value: string): Promise<void> {
    setPending(true);
    setFailure(null);
    const result = await deleteAccount({ password: value });
    // The password is never kept after an answer, whatever it was.
    setPassword('');
    setPending(false);
    if (!result.ok) {
      setFailure(result);
      return;
    }
    onDeleted();
    // Signed out at once, then confirmed by the API; the lists of the deleted account are dropped.
    queryClient.setQueryData(ME_KEY, null);
    forgetLists(queryClient);
    void queryClient.invalidateQueries({ queryKey: ME_KEY });
  }

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (ready) void remove(password);
  }

  return (
    <>
      <p className="mt-2">
        Deleting your account removes your email address, watchlist and portfolio from PokéPrices.
        It cannot be undone.
      </p>
      <form
        noValidate
        onSubmit={submit}
        className="mt-4 space-y-4 border-l-4 border-red-700 pl-4 dark:border-red-400"
      >
        <div className="flex min-h-11 items-center gap-3">
          <input
            id={checkboxId}
            type="checkbox"
            checked={understood}
            onChange={(event) => {
              setUnderstood(event.target.checked);
              if (!event.target.checked) setPassword('');
            }}
            className={CHECKBOX}
          />
          <label htmlFor={checkboxId}>I understand that this cannot be undone</label>
        </div>
        {understood && (
          <Field
            label="Password"
            name="password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={setPassword}
            error={errors.password}
          />
        )}
        <button type="submit" disabled={!ready} className={DANGER_BUTTON}>
          Delete my account
        </button>
      </form>
      {failure !== null && (
        <ProblemBlock
          context="Could not delete the account"
          failure={failure}
          fields={understood ? ['password'] : []}
        />
      )}
    </>
  );
}

function DeleteSection({ me, onDeleted }: { readonly me: Me; readonly onDeleted: () => void }) {
  const headingId = useId();
  return (
    <section aria-labelledby={headingId} className={SECTION}>
      <h2 id={headingId} className="text-xl font-semibold">
        Delete account
      </h2>
      {me.read_only ? (
        <p className="mt-2">The demo account cannot be deleted.</p>
      ) : (
        <DeleteForm onDeleted={onDeleted} />
      )}
    </section>
  );
}

export default function YourData({
  me,
  onDeleted,
}: {
  readonly me: Me;
  readonly onDeleted: () => void;
}) {
  return (
    <>
      <ExportSection />
      <DeleteSection me={me} onDeleted={onDeleted} />
    </>
  );
}
