<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use App\Domain\Accounts\AccountLock;
use App\Domain\Alerts\Models\WebhookEndpoint;
use App\Domain\Alerts\Problems\WebhookDisabled;
use App\Domain\Alerts\Problems\WebhookNotFound;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * A person's webhooks: the URL check with its 422 messages, the per-user limits and
 * the insert. Registration counts then inserts under the per-user lock (App\Domain\Accounts\AccountLock), so two
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

    public function __construct(
        private readonly UrlGuard $guard,
        private readonly ConnectionInterface $db,
    ) {}

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
        if ($result->error !== null) {
            return [$result->error->message(), null];
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
    public function rows(int $userId, ?int $id = null): array
    {
        $rows = $this->db->select(<<<'SQL'
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

    /**
     * Counts, then inserts, under the per-user lock: the new endpoint's id.
     *
     * @throws ValidationException errors.url when $userId already holds the most endpoints of $kind
     */
    public function create(
        int $userId,
        string $kind,
        #[\SensitiveParameter] string $url,
        string $urlHint,
        ?string $description,
        #[\SensitiveParameter] ?string $secret,
        CarbonImmutable $now,
    ): int {
        return $this->db->transaction(function () use ($userId, $kind, $url, $urlHint, $description, $secret, $now): int {
            AccountLock::forUser($this->db, $userId);
            $row = $this->db->selectOne('SELECT count(*) AS n FROM webhook_endpoints WHERE user_id = ? AND kind = ?', [$userId, $kind], false);
            $count = $row instanceof stdClass ? (int) $row->n : 0;
            $limit = $kind === 'discord' ? AlertLimits::discordWebhooks() : AlertLimits::webhooks();
            if ($count >= $limit) {
                throw ValidationException::withMessages(['url' => [$kind === 'discord' ? self::DISCORD_LIMIT : "You can add at most {$limit} signed webhooks."]]);
            }
            $row = $this->db->selectOne(
                'INSERT INTO webhook_endpoints (user_id, kind, url, url_hint, secret, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?::timestamptz) RETURNING id',
                [$userId, $kind, Crypt::encryptString($url), $urlHint, $secret === null ? null : Crypt::encryptString($secret), $description, $now->utc()->format('Y-m-d H:i:sP')],
                false,
            );

            return $row instanceof stdClass ? (int) $row->id : 0;
        });
    }

    /**
     * The user's endpoint with this id, for a test message. The contract export reads the status of each refusal
     * from its throws tag's type argument (it cannot see the status inside a called method), so each tag carries the
     * status the exception answers with.
     *
     * @throws WebhookNotFound<404> when the user has no endpoint with this id
     * @throws WebhookDisabled<409> when the endpoint is turned off
     */
    public function testable(int $userId, int $id): WebhookEndpoint
    {
        $endpoint = WebhookEndpoint::query()->whereKey($id)->where('user_id', $userId)->first();
        if (! $endpoint instanceof WebhookEndpoint) {
            throw new WebhookNotFound;
        }
        if ($endpoint->disabled_at !== null) {
            throw new WebhookDisabled;
        }

        return $endpoint;
    }

    /**
     * Deletes the user's endpoint with this id (nothing when the user has none: another user's id changes nothing),
     * its deliveries with it (cascade), and, when it was the Discord webhook, sets the notification channel back to
     * email in the same transaction, under the per-user lock.
     */
    public function delete(int $userId, int $id): void
    {
        $this->db->transaction(function () use ($userId, $id): void {
            AccountLock::forUser($this->db, $userId);
            $deleted = $this->db->selectOne('DELETE FROM webhook_endpoints WHERE id = ? AND user_id = ? RETURNING kind', [$id, $userId], false);
            if ($deleted instanceof stdClass && $deleted->kind === 'discord') {
                NotificationSettings::resetDiscord($this->db, $userId);
            }
        });
    }
}
