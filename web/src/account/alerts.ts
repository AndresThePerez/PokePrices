// Shared pieces of /alerts that are not components: the movement rule's figures, the
// query keys and the date part of an API timestamp. The page reads no clock: every date it shows
// is the date part of a timestamp the API sent.
import { useQuery } from '@tanstack/react-query';
import {
  getDemoSamples,
  getNotifications,
  getWebhooks,
  type ApiFailure,
  type DemoSamplesBody,
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

/** The loading line of /alerts. */
export const WEBHOOKS_LOADING = 'Loading your alerts and webhooks…';

/** The YYYY-MM-DD date part of an RFC 3339 timestamp; the time of day is never shown. */
export function datePart(timestamp: string): string {
  return timestamp.slice(0, 10);
}

/** The account's webhooks (the polling defaults), asked for only once the session is signed in. */
export function useWebhooks(enabled: boolean) {
  return useQuery<WebhooksBody, ApiFailure>({
    queryKey: WEBHOOKS_KEY,
    queryFn: ({ signal }) => unwrap(getWebhooks({ signal })),
    enabled,
  });
}

/** The account's alert settings (the polling defaults), asked for only for an account that may change them. */
export function useNotifications(enabled: boolean) {
  return useQuery<NotificationsBody, ApiFailure>({
    queryKey: NOTIFICATIONS_KEY,
    queryFn: ({ signal }) => unwrap(getNotifications({ signal })),
    enabled,
  });
}

export const DEMO_SAMPLES_KEY = accountKey('demo-samples');

/** The read-only demo's samples (the polling defaults), asked for only by the demo. */
export function useDemoSamples(enabled: boolean) {
  return useQuery<DemoSamplesBody, ApiFailure>({
    queryKey: DEMO_SAMPLES_KEY,
    queryFn: ({ signal }) => unwrap(getDemoSamples({ signal })),
    enabled,
  });
}
