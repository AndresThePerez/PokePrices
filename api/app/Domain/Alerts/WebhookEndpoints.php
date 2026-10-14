<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use App\Domain\Accounts\AccountLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use stdClass;

/**
 * A person's webhooks (plan P5-i1 D1, D6, D9; T03): the URL check with its 422 messages, the per-user limits and
 * the insert. Registration counts then inserts under the per-user lock (App\Domain\Accounts\AccountLock, P4), so two
 * concurrent registrations can never pass a limit. url and secret are written encrypted exactly as the model's
 * encrypted casts write them (APP_KEY); url_hint keeps only the scheme, host and a non-default port.
 */
final class WebhookEndpoints
{
    public const string HTTPS = 'Use an https URL.';

    public const string PRIVATE = 'PokéPrices does not send to private, local or reserved network addresses.';

    public const string UNRESOLVABLE = 'This host name does not resolve.';

    public const string DISCORD_SHAPE = 'A Discord webhook URL looks like https://discord.com/api/webhooks/<id>/<token>.';

    public const string TOO_LONG = 'The URL is too long.';

    public const string KIND = 'Choose a signed webhook or Discord.';

    public const string DISCORD_LIMIT = 'You can add one Discord webhook.';

    public const array KINDS = ['standard', 'discord'];

    public function __construct(private readonly UrlGuard $guard) {}

    /**
     * Checks $url for an endpoint of $kind: [null, the url_hint] when it may be registered, else [the errors.url
     * message, null]. A Discord URL must have D6's Discord shape first; every URL then passes the address guard.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function check(string $kind, #[\SensitiveParameter] string $url): array
    {
        if (strlen($url) > UrlGuard::MAX_BYTES) {
            return [self::TOO_LONG, null];
        }
        if ($kind === 'discord' && ! DiscordMessage::isWebhookUrl($url)) {
            return [self::DISCORD_SHAPE, null];
        }
        $result = $this->guard->check($url);
        if (! $result->allowed()) {
            return [match ($result->error) {
                'too_long' => self::TOO_LONG,
                'blocked_address' => self::PRIVATE,
                'unresolvable' => self::UNRESOLVABLE,
                default => self::HTTPS,
            }, null];
        }
        $scheme = strtolower((string) strstr($url, '://', true));
        $port = ($scheme === 'https' && $result->port === 443) || ($scheme === 'http' && $result->port === 80) ? '' : ':'.$result->port;

        return [null, "{$scheme}://{$result->host}{$port}/…"];
    }

    /**
     * The user's endpoints ordered by id (or the one with $id), as the API shows them: never url or secret.
     * last_delivery is the endpoint's newest delivery by id: its status and when (delivered_at, else created_at).
     *
     * @return list<array{id: int, kind: string, url_hint: string, description: string|null, created_at: string, disabled: bool, last_delivery: array{status: string, at: string}|null}>
     */
    public static function rows(ConnectionInterface $db, int $userId, ?int $id = null): array
    {
        $rows = $db->select(<<<'SQL'
            SELECT w.id, w.kind, w.url_hint, w.description,
                to_char(w.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                (w.disabled_at IS NOT NULL) AS disabled, d.status AS last_status,
                to_char(coalesce(d.delivered_at, d.created_at) AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS last_at
            FROM webhook_endpoints w
            LEFT JOIN LATERAL (
                SELECT status, delivered_at, created_at FROM deliveries WHERE endpoint_id = w.id ORDER BY id DESC LIMIT 1
            ) d ON true
            WHERE w.user_id = ? AND (?::bigint IS NULL OR w.id = ?::bigint)
            ORDER BY w.id
            SQL, [$userId, $id, $id], false);

        $out = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }
            $out[] = [
                'id' => (int) $row->id,
                'kind' => (string) $row->kind,
                'url_hint' => (string) $row->url_hint,
                'description' => is_string($row->description) ? $row->description : null,
                'created_at' => (string) $row->created_at,
                'disabled' => (bool) $row->disabled,
                'last_delivery' => is_string($row->last_status) ? ['status' => $row->last_status, 'at' => (string) $row->last_at] : null,
            ];
        }

        return $out;
    }

    /** The errors.url message when $userId already holds the most endpoints of $kind, else null. */
    public static function limitError(ConnectionInterface $db, int $userId, string $kind): ?string
    {
        $row = $db->selectOne('SELECT count(*) AS n FROM webhook_endpoints WHERE user_id = ? AND kind = ?', [$userId, $kind], false);
        $count = $row instanceof stdClass ? (int) $row->n : 0;
        $limit = $kind === 'discord' ? (int) config('alerts.webhooks.discord_limit', 1) : (int) config('alerts.webhooks.limit', 5);
        if ($count < $limit) {
            return null;
        }

        return $kind === 'discord' ? self::DISCORD_LIMIT : "You can add at most {$limit} signed webhooks.";
    }

    /**
     * Counts, then inserts, under the per-user lock: the new endpoint's id, or the errors.url limit message.
     */
    public static function create(
        ConnectionInterface $db,
        int $userId,
        string $kind,
        #[\SensitiveParameter] string $url,
        string $urlHint,
        ?string $description,
        #[\SensitiveParameter] ?string $secret,
        CarbonImmutable $now,
    ): int|string {
        return $db->transaction(function () use ($db, $userId, $kind, $url, $urlHint, $description, $secret, $now): int|string {
            AccountLock::forUser($db, $userId);
            $error = self::limitError($db, $userId, $kind);
            if ($error !== null) {
                return $error;
            }
            $row = $db->selectOne(
                'INSERT INTO webhook_endpoints (user_id, kind, url, url_hint, secret, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?::timestamptz) RETURNING id',
                [$userId, $kind, Crypt::encryptString($url), $urlHint, $secret === null ? null : Crypt::encryptString($secret), $description, $now->utc()->format('Y-m-d H:i:sP')],
                false,
            );

            return $row instanceof stdClass ? (int) $row->id : 0;
        });
    }
}
