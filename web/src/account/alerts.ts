// Shared pieces of /alerts (P5 D10) that are not components: the movement rule's figures, the
// query keys and the date part of an API timestamp. The page reads no clock: every date it shows
// is the date part of a timestamp the API sent.
import { useQuery } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import {
  getNotifications,
  getWebhooks,
  type ApiFailure,
  type NotificationsBody,
  type WebhooksBody,
} from '../api/client';
import { unwrap } from '../query';
import { accountKey } from './session';

/** The movement rule (draft-v1, ADR 0001): a close moves by at least this percentage … */
export const MOVEMENT_PCT = 5;
/** … and at least this many dollars (the API's decimal string, printed as it is). */
export const MOVEMENT_ABS = '1.00';

export const WEBHOOKS_KEY = accountKey('webhooks');
export const NOTIFICATIONS_KEY = accountKey('notifications');

/** The loading line of /alerts (D10). */
export const WEBHOOKS_LOADING = 'Loading your alerts and webhooks…';

/** The YYYY-MM-DD date part of an RFC 3339 timestamp; the time of day is never shown. */
export function datePart(timestamp: string): string {
  return timestamp.slice(0, 10);
}

/** The account's webhooks (P3 D9 defaults), asked for only once the session is signed in. */
export function useWebhooks(enabled: boolean) {
  return useQuery<WebhooksBody, ApiFailure>({
    queryKey: WEBHOOKS_KEY,
    queryFn: ({ signal }) => unwrap(getWebhooks({ signal })),
    enabled,
  });
}

/** The account's alert settings (P3 D9 defaults), asked for only for an account that may change them. */
export function useNotifications(enabled: boolean) {
  return useQuery<NotificationsBody, ApiFailure>({
    queryKey: NOTIFICATIONS_KEY,
    queryFn: ({ signal }) => unwrap(getNotifications({ signal })),
    enabled,
  });
}

/** A button that runs its action once per click: a second click while it runs does nothing. */
export function useOnce(): readonly [boolean, (action: () => Promise<void>) => void] {
  const running = useRef(false);
  const [pending, setPending] = useState(false);
  function run(action: () => Promise<void>): void {
    if (running.current) return;
    running.current = true;
    setPending(true);
    void action().finally(() => {
      running.current = false;
      setPending(false);
    });
  }
  return [pending, run];
}
