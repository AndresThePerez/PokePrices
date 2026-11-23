<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use App\Domain\Accounts\DemoAccount;
use App\Domain\Pricing\PricingConfig;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use stdClass;

/**
 * The read-only demo's samples: one alert on a watchlist card, one sample signed webhook and two
 * delivered sample messages about the newest real move of that card (an alert email as the account would have received
 * it, and the price.moved body sent to the webhook). seed() writes them from price_events and nothing else; read()
 * gives them back to the demo with the webhook's signature headers.
 *
 * Nothing is ever sent for them: both deliveries are written delivered (the sweep claims pending rows only), the demo
 * is never eligible for the dispatch (AlertEligibility), DemoReadOnly refuses its test sends, and the retention sweep
 * keeps them. The signing secret is a fixed, published sample (secret(), built from KEY): never a real key, never a
 * value read from the environment, and never any account's stored secret.
 */
final class DemoSamples
{
    /** The 32 bytes of the published sample signing key: the secret is whsec_ and their base64. */
    public const string KEY = 'pokeprices-demo-sample-secret-01';

    /** The demo's alert threshold, in whole percent. */
    public const int ALERT_PCT = 10;

    /** The card the alert goes on when no card of the demo has moved by the threshold. */
    public const string FALLBACK = 'ex5-17:reverse:-:-';

    public const string DESCRIPTION = 'Sample signed webhook';

    /** A reserved name (RFC 2606): nothing is ever sent there. */
    public const string URL = 'https://example.test/pokeprices-demo-sample';

    public const string URL_HINT = 'https://example.test/…';

    /** The fixed message ids of the two samples, so a second seed rewrites the same rows. */
    public const string ALERT_MESSAGE_ID = 'msg_01K7DEM0A1ERTSAMP1E0000000';

    public const string WEBHOOK_MESSAGE_ID = 'msg_01K7DEM0WEBH00KSAMP1E00000';

    /** The receiver's answer recorded on the sample webhook delivery. */
    private const int RECEIVED = 204;

    /** The published sample signing secret. */
    public static function secret(): string
    {
        return WebhookSecret::PREFIX.base64_encode(self::KEY);
    }

    /**
     * Writes the samples for the demo user: the alert on the card of the newest event among $watchlist whose move
     * reached ALERT_PCT (else on FALLBACK), the sample webhook, and the two delivered messages about that event. With
     * no such event there is no message, and $line says so.
     *
     * @param  list<string>  $watchlist  the demo's watchlist keys, as seeded
     * @param  Closure(string): void  $line
     */
    public static function seed(ConnectionInterface $db, int $demoId, array $watchlist, Closure $line): void
    {
        $event = self::event($db, $watchlist);
        $alertKey = $event !== null ? (string) $event->variant_key : (in_array(self::FALLBACK, $watchlist, true) ? self::FALLBACK : null);

        $db->update(
            'UPDATE watchlist_items SET alert_pct = CASE WHEN variant_key = ?::text THEN ?::smallint END
             WHERE user_id = ? AND alert_pct IS DISTINCT FROM (CASE WHEN variant_key = ?::text THEN ?::smallint END)',
            [$alertKey, self::ALERT_PCT, $demoId, $alertKey, self::ALERT_PCT],
        );
        $endpointId = self::endpoint($db, $demoId, $event === null ? null : (string) $event->as_of);
        $db->delete('DELETE FROM deliveries WHERE user_id = ? AND message_id NOT IN (?, ?)', [$demoId, self::ALERT_MESSAGE_ID, self::WEBHOOK_MESSAGE_ID]);

        if ($event === null) {
            $db->delete('DELETE FROM deliveries WHERE user_id = ?', [$demoId]);
            $line('No sample messages: no card on the demo watchlist moved by '.self::ALERT_PCT.'% in the collected closes.');

            return;
        }

        $card = DemoAccount::card((string) $event->card_id);
        self::delivery($db, $demoId, self::ALERT_MESSAGE_ID, 'alert.triggered', 'email', null, $event,
            NotificationText::emailBody(NotificationText::alert($event, $card, self::ALERT_PCT)), null);
        self::delivery($db, $demoId, self::WEBHOOK_MESSAGE_ID, 'price.moved', 'webhook', $endpointId, $event,
            PriceMovedPayload::encode($event, $card), self::RECEIVED);
    }

    /**
     * The samples as the demo reads them: the alert, the delivered message and the signed delivery (null when not
     * seeded), and the sample secret. Only $userId's rows are read.
     *
     * @return array{sample_secret: string, alert: array{variant_key: string, card_id: string, finish: string, edition: string, treatment: string, card: array{name: string, set_name: string, number: string}|null, alert_pct: int}|null, message: array{message_id: string, kind: string, channel: string, status: string, delivered_at: string, subject: string, text: string}|null, webhook: array{message_id: string, kind: string, url_hint: string, status: string, delivered_at: string, headers: array{'webhook-id': string, 'webhook-timestamp': string, 'webhook-signature': string}, body: string}|null}
     */
    public static function read(ConnectionInterface $db, int $userId): array
    {
        $alert = $db->selectOne(
            'SELECT w.variant_key, v.card_id, v.finish, v.edition, v.treatment, w.alert_pct
             FROM watchlist_items w JOIN card_variants v ON v.variant_key = w.variant_key
             WHERE w.user_id = ? AND w.alert_pct IS NOT NULL ORDER BY w.variant_key LIMIT 1',
            [$userId],
            false,
        );
        $rows = $db->select(
            "SELECT d.message_id, d.kind, d.channel, d.status, d.body, w.url_hint,
                to_char(d.delivered_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS delivered_at,
                extract(epoch FROM d.delivered_at)::bigint AS delivered_epoch
             FROM deliveries d LEFT JOIN webhook_endpoints w ON w.id = d.endpoint_id AND w.user_id = d.user_id
             WHERE d.user_id = ? AND d.message_id IN (?, ?) AND d.delivered_at IS NOT NULL",
            [$userId, self::ALERT_MESSAGE_ID, self::WEBHOOK_MESSAGE_ID],
            false,
        );

        $message = null;
        $webhook = null;
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }
            if ($row->message_id === self::ALERT_MESSAGE_ID) {
                $message = self::message($row);
            } elseif (is_string($row->url_hint)) {
                $webhook = self::signed($row);
            }
        }

        return [
            'sample_secret' => self::secret(),
            'alert' => $alert instanceof stdClass ? [
                'variant_key' => (string) $alert->variant_key,
                'card_id' => (string) $alert->card_id,
                'finish' => (string) $alert->finish,
                'edition' => (string) $alert->edition,
                'treatment' => (string) $alert->treatment,
                'card' => DemoAccount::card((string) $alert->card_id),
                'alert_pct' => (int) $alert->alert_pct,
            ] : null,
            'message' => $message,
            'webhook' => $webhook,
        ];
    }

    /** @param  list<string>  $watchlist */
    private static function event(ConnectionInterface $db, array $watchlist): ?stdClass
    {
        if ($watchlist === []) {
            return null;
        }
        $marks = implode(', ', array_fill(0, count($watchlist), '?'));
        $row = $db->selectOne(
            'SELECT '.PriceMovedPayload::COLUMNS." FROM price_events e
             WHERE e.source = ? AND e.variant_key IN ({$marks}) AND abs(e.change_pct) >= ?
             ORDER BY e.day DESC, e.id DESC LIMIT 1",
            [PricingConfig::source(), ...$watchlist, self::ALERT_PCT],
            false,
        );

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * The demo's one sample webhook, kept when it already exists (its encrypted url and secret are not rewritten, so
     * a second seed leaves the row as it was); any other webhook of the demo is deleted.
     */
    private static function endpoint(ConnectionInterface $db, int $demoId, ?string $asOf): int
    {
        $kept = $db->selectOne(
            "SELECT id FROM webhook_endpoints WHERE user_id = ? AND kind = 'standard' AND url_hint = ? AND description = ? ORDER BY id LIMIT 1",
            [$demoId, self::URL_HINT, self::DESCRIPTION],
            false,
        );
        $id = $kept instanceof stdClass ? (int) $kept->id : null;
        $db->delete('DELETE FROM webhook_endpoints WHERE user_id = ? AND id IS DISTINCT FROM ?::bigint', [$demoId, $id]);
        if ($id !== null) {
            $db->update('UPDATE webhook_endpoints SET disabled_at = NULL WHERE id = ? AND disabled_at IS NOT NULL', [$id]);

            return $id;
        }

        $row = $db->selectOne(
            "INSERT INTO webhook_endpoints (user_id, kind, url, url_hint, secret, description, created_at)
             VALUES (?, 'standard', ?, ?, ?, ?, coalesce(?::timestamptz, now())) RETURNING id",
            [$demoId, Crypt::encryptString(self::URL), self::URL_HINT, Crypt::encryptString(self::secret()), self::DESCRIPTION, $asOf],
            false,
        );

        return $row instanceof stdClass ? (int) $row->id : 0;
    }

    /** Writes one delivered sample, dated by the event's own as_of; a second seed rewrites the same row. */
    private static function delivery(
        ConnectionInterface $db,
        int $demoId,
        string $messageId,
        string $kind,
        string $channel,
        ?int $endpointId,
        stdClass $event,
        string $body,
        ?int $lastStatus,
    ): void {
        $at = (string) $event->as_of;
        $db->statement(
            "INSERT INTO deliveries (message_id, user_id, kind, channel, endpoint_id, event_id, day, body, status, attempts,
                claims, next_attempt_at, last_status, last_error, delivered_at, created_at)
             VALUES (?, ?, ?, ?, ?::bigint, ?, ?::date, ?, 'delivered', 1, 1, ?::timestamptz, ?::smallint, NULL, ?::timestamptz, ?::timestamptz)
             ON CONFLICT (message_id) DO UPDATE SET kind = EXCLUDED.kind, channel = EXCLUDED.channel,
                endpoint_id = EXCLUDED.endpoint_id, event_id = EXCLUDED.event_id, day = EXCLUDED.day, body = EXCLUDED.body,
                status = EXCLUDED.status, attempts = EXCLUDED.attempts, claims = EXCLUDED.claims,
                next_attempt_at = EXCLUDED.next_attempt_at, last_status = EXCLUDED.last_status, last_error = NULL,
                delivered_at = EXCLUDED.delivered_at, created_at = EXCLUDED.created_at
             WHERE deliveries.user_id = EXCLUDED.user_id",
            [$messageId, $demoId, $kind, $channel, $endpointId, (int) $event->id, (string) $event->day, $body, $at, $lastStatus, $at, $at],
        );
    }

    /** @return array{message_id: string, kind: string, channel: string, status: string, delivered_at: string, subject: string, text: string} */
    private static function message(stdClass $row): array
    {
        $body = json_decode((string) $row->body, true);

        return [
            'message_id' => (string) $row->message_id,
            'kind' => (string) $row->kind,
            'channel' => (string) $row->channel,
            'status' => (string) $row->status,
            'delivered_at' => (string) $row->delivered_at,
            'subject' => is_array($body) && is_string($body['subject'] ?? null) ? $body['subject'] : '',
            'text' => is_array($body) && is_string($body['text'] ?? null) ? $body['text'] : '',
        ];
    }

    /** @return array{message_id: string, kind: string, url_hint: string, status: string, delivered_at: string, headers: array{'webhook-id': string, 'webhook-timestamp': string, 'webhook-signature': string}, body: string} */
    private static function signed(stdClass $row): array
    {
        $body = (string) $row->body;

        return [
            'message_id' => (string) $row->message_id,
            'kind' => (string) $row->kind,
            'url_hint' => (string) $row->url_hint,
            'status' => (string) $row->status,
            'delivered_at' => (string) $row->delivered_at,
            'headers' => WebhookSigner::headers(self::secret(), (string) $row->message_id, (int) $row->delivered_epoch, $body),
            'body' => $body,
        ];
    }
}
