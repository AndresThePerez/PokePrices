// The sections added to /alerts, above Webhooks: "Where alerts go" (email or Discord,
// the daily digest, Save) and "Per-card alerts" (a pointer to the watchlist). Only a verified account
// that is not the demo sees them. The form shows the saved settings until a choice is changed; Save
// sends exactly { channel, digest } and, once it succeeds, the saved settings are read again (if that
// read fails, the form shows what the PUT answered until a later read succeeds). No clock is read.
import { useQueryClient } from '@tanstack/react-query';
import { useId, useState, type SubmitEvent } from 'react';
import {
  putNotifications,
  type ApiFailure,
  type NotificationSettings as Settings,
  type NotificationsBody,
} from '../api/client';
import Link from '../Link';
import { MOVEMENT_ABS, MOVEMENT_PCT, NOTIFICATIONS_KEY, WEBHOOKS_LOADING } from './alerts';
import { ProblemBlock, StatusLine } from './Form';
import LoadingLine from '../ui/StatusLine';
import { FOCUS, MUTED, PRIMARY_BUTTON, TEXT_LINK } from '../ui/classes';
import { firstErrors } from './fields';
import { useAccountWrite } from './mutations';

const DIGEST_HINT =
  `The digest lists the cards on your watchlist and portfolio whose daily close moved by at least ` +
  `${String(MOVEMENT_PCT)}% and $${MOVEMENT_ABS}. It is sent once per collected day, after that ` +
  `day's prices are in.`;
const DISCORD_HINT = 'Add a Discord webhook below first.';

const SECTION = 'mt-8 border-t border-line-subtle pt-6';

/** A radio or checkbox row: the whole label is the 44 px target. */
const CHOICE_ROW = 'flex min-h-11 cursor-pointer items-center gap-3';
const CHOICE_INPUT = `min-h-11 w-5 shrink-0 accent-accent ${FOCUS}`;

type Draft = Pick<Settings, 'channel' | 'digest'>;

function joinIds(...ids: readonly (string | undefined)[]): string | undefined {
  const joined = ids.filter((id) => id !== undefined).join(' ');
  return joined === '' ? undefined : joined;
}

function SettingsForm({
  email,
  settings,
}: {
  readonly email: string;
  readonly settings: Settings;
}) {
  const queryClient = useQueryClient();
  const id = useId();
  const [draft, setDraft] = useState<Draft | null>(null);
  const [saved, setSaved] = useState(false);
  // What the PUT answered, held while the cache has had no newer read (dataUpdateCount unchanged).
  const [held, setHeld] = useState<{ readonly values: Draft; readonly reads: number } | null>(null);
  const reads = queryClient.getQueryState(NOTIFICATIONS_KEY)?.dataUpdateCount ?? 0;
  const confirmed = held !== null && held.reads === reads ? held.values : null;
  const shown: Draft = draft ?? confirmed ?? { channel: settings.channel, digest: settings.digest };
  const write = useAccountWrite<NotificationsBody>({
    onMutate: () => {
      setSaved(false);
    },
    // Refetched, never written to the cache from the answer: an answer that lands after a session
    // transition finds no query to refresh (endSession removed it) or refreshes the next account's
    // own one (a query still read was reset and asked again with the new session). Only this form
    // holds the answer, and only while the read again fails.
    onSuccess: async (body) => {
      const before = queryClient.getQueryState(NOTIFICATIONS_KEY)?.dataUpdateCount ?? 0;
      await queryClient.invalidateQueries({ queryKey: NOTIFICATIONS_KEY });
      const after = queryClient.getQueryState(NOTIFICATIONS_KEY)?.dataUpdateCount ?? 0;
      const answer = body.data;
      setHeld(
        after === before
          ? { values: { channel: answer.channel, digest: answer.digest }, reads: after }
          : null,
      );
      setDraft(null);
      setSaved(true);
    },
  });
  const pending = write.isPending;
  const failure: ApiFailure | null = write.error;
  const channelError = firstErrors(failure).channel;
  const discordHintId = settings.discord_available ? undefined : `${id}-discord-hint`;
  const channelErrorId = channelError === undefined ? undefined : `${id}-channel-error`;
  const digestHintId = `${id}-digest-hint`;

  function change(next: Draft): void {
    setDraft(next);
    setSaved(false);
  }

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    const form = { channel: shown.channel, digest: shown.digest };
    write.run(() => putNotifications(form));
  }

  return (
    <>
      <form noValidate onSubmit={submit} className="mt-4 space-y-4">
        <fieldset>
          <legend className="font-semibold">Send alerts and the digest by</legend>
          <div className="mt-1">
            <label htmlFor={`${id}-email`} className={CHOICE_ROW}>
              <input
                id={`${id}-email`}
                type="radio"
                name="channel"
                value="email"
                checked={shown.channel === 'email'}
                aria-describedby={channelErrorId}
                onChange={() => {
                  change({ ...shown, channel: 'email' });
                }}
                className={CHOICE_INPUT}
              />
              <span className="wrap-anywhere">{`Email (${email})`}</span>
            </label>
            <label
              htmlFor={`${id}-discord`}
              className={`${CHOICE_ROW} ${settings.discord_available ? '' : 'cursor-not-allowed'}`}
            >
              <input
                id={`${id}-discord`}
                type="radio"
                name="channel"
                value="discord"
                checked={shown.channel === 'discord'}
                disabled={!settings.discord_available}
                aria-describedby={joinIds(discordHintId, channelErrorId)}
                onChange={() => {
                  change({ ...shown, channel: 'discord' });
                }}
                className={CHOICE_INPUT}
              />
              <span className={settings.discord_available ? undefined : MUTED}>Discord</span>
            </label>
            {discordHintId !== undefined && (
              <p id={discordHintId} className={`ml-8 text-sm ${MUTED}`}>
                {DISCORD_HINT}
              </p>
            )}
            {channelErrorId !== undefined && (
              <p id={channelErrorId} className="mt-1 text-sm font-semibold text-problem">
                {channelError}
              </p>
            )}
          </div>
        </fieldset>
        <div>
          <label htmlFor={`${id}-digest`} className={CHOICE_ROW}>
            <input
              id={`${id}-digest`}
              type="checkbox"
              name="digest"
              checked={shown.digest}
              aria-describedby={digestHintId}
              onChange={(event) => {
                change({ ...shown, digest: event.target.checked });
              }}
              className={CHOICE_INPUT}
            />
            <span>Send me a daily digest</span>
          </label>
          <p id={digestHintId} className={`ml-8 text-sm ${MUTED}`}>
            {DIGEST_HINT}
          </p>
        </div>
        <button type="submit" disabled={pending} className={PRIMARY_BUTTON}>
          Save
        </button>
      </form>
      {failure !== null && (
        <ProblemBlock
          context="Could not save your alert settings"
          failure={failure}
          fields={['channel']}
        />
      )}
      {saved && <StatusLine>Saved.</StatusLine>}
    </>
  );
}

/** "Where alerts go": the saved settings as a form, or their loading line or problem block. */
export default function NotificationSettings({
  email,
  settings,
  error,
}: {
  readonly email: string;
  readonly settings: NotificationsBody | undefined;
  readonly error: ApiFailure | null;
}) {
  const headingId = useId();
  let body;
  if (settings !== undefined) {
    body = <SettingsForm email={email} settings={settings.data} />;
  } else if (error !== null) {
    body = <ProblemBlock context="Could not load your alert settings" failure={error} />;
  } else {
    body = <LoadingLine gap="mt-4">{WEBHOOKS_LOADING}</LoadingLine>;
  }
  return (
    <section aria-labelledby={headingId} className={SECTION}>
      <h2 id={headingId} className="text-lg font-semibold">
        Where alerts go
      </h2>
      {body}
    </section>
  );
}

/** "Per-card alerts": thresholds are set on the watchlist rows. */
export function PerCardAlerts() {
  const headingId = useId();
  return (
    <section aria-labelledby={headingId} className={SECTION}>
      <h2 id={headingId} className="text-lg font-semibold">
        Per-card alerts
      </h2>
      <p className="mt-2">Set a threshold on any card in your watchlist.</p>
      <p>
        <Link to="/watchlist" className={TEXT_LINK}>
          Open your watchlist
        </Link>
      </p>
    </section>
  );
}
