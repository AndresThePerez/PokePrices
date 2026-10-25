// "Your data" and "Delete account" on /account (D10, T08). The export is a plain link the browser
// downloads (GET /v1/me/export, a top-level navigation with a same-origin Referer so Sanctum treats
// it as first-party; never rel="noreferrer", review F-P4-09), so nothing here fetches it. Deleting
// needs the checkbox first; only then does the password field appear, and the password is dropped
// after every answer and whenever the checkbox is cleared. The demo sees why it cannot delete.
// Both are panels of the Account page; Delete account stands apart as the danger zone (D2-i2-T07):
// further down, its border in the problem tone.
import { useQueryClient } from '@tanstack/react-query';
import { useId, useState, type SubmitEvent } from 'react';
import { deleteAccount, type ApiFailure, type Me } from '../api/client';
import { Button } from '../ui/Button';
import Panel from '../ui/Panel';
import { Field, ProblemBlock } from './Form';
import { FOCUS, TEXT_LINK, firstErrors } from './fields';
import { ME_KEY, endSession } from './session';

const EXPORT_PATH = '/v1/me/export';
const EXPORT_FILE = 'pokeprices-export.json';

const HEADING = 'text-lg font-semibold';

/** The checkbox keeps a 44 px row; browsers draw the box itself square inside it. */
const CHECKBOX = `min-h-11 w-5 shrink-0 accent-problem ${FOCUS}`;

function ExportSection() {
  const headingId = useId();
  return (
    <Panel as="section" aria-labelledby={headingId} className="mt-6">
      <h2 id={headingId} className={HEADING}>
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
    </Panel>
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
    // Signed out at once, then confirmed by the API; everything of the deleted account is dropped.
    queryClient.setQueryData(ME_KEY, null);
    endSession(queryClient);
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
      <form noValidate onSubmit={submit} className="mt-4 space-y-4 border-l-4 border-problem pl-4">
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
        <Button variant="danger" type="submit" disabled={!ready}>
          Delete my account
        </Button>
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
    <Panel as="section" aria-labelledby={headingId} className="mt-10 border-problem/40!">
      <h2 id={headingId} className={HEADING}>
        Delete account
      </h2>
      {me.read_only ? (
        <p className="mt-2">The demo account cannot be deleted.</p>
      ) : (
        <DeleteForm onDeleted={onDeleted} />
      )}
    </Panel>
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
