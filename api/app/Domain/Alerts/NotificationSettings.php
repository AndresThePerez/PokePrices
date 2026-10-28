<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use App\Domain\Accounts\AccountLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Where a person's alerts and digest go: email or their Discord webhook,
 * and whether a daily digest is sent. A person without a notification_settings row has the defaults (email, digest
 * off). Discord can be chosen only while the person has an active (not disabled) Discord webhook; saving and deleting
 * a webhook both run under the per-user lock (App\Domain\Accounts\AccountLock), so a save can never choose a Discord
 * webhook that a concurrent delete removes.
 */
final class NotificationSettings
{
    public const array CHANNELS = ['email', 'discord'];

    public const string CHANNEL = 'Choose email or Discord.';

    public const string DIGEST = 'Choose whether to send the daily digest.';

    public const string DISCORD_FIRST = 'Add a Discord webhook first.';

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * The person's settings and whether they have an active Discord webhook, in one statement.
     *
     * @return array{channel: string, digest: bool, discord_available: bool}
     */
    public function of(int $userId): array
    {
        $row = $this->db->selectOne(<<<'SQL'
            SELECT COALESCE(s.channel, 'email') AS channel,
                   COALESCE(s.digest, false) AS digest,
                   EXISTS (SELECT 1 FROM webhook_endpoints e WHERE e.user_id = u.id AND e.kind = 'discord' AND e.disabled_at IS NULL) AS discord_available
            FROM (SELECT ?::bigint AS id) u
            LEFT JOIN notification_settings s ON s.user_id = u.id
            SQL, [$userId], false);

        return [
            'channel' => $row instanceof stdClass && $row->channel === 'discord' ? 'discord' : 'email',
            'digest' => $row instanceof stdClass && $row->digest === true,
            'discord_available' => $row instanceof stdClass && $row->discord_available === true,
        ];
    }

    /**
     * Saves the person's choice under the per-user lock.
     *
     * @throws ValidationException errors.channel when Discord is chosen without an active Discord webhook
     */
    public function save(int $userId, string $channel, bool $digest, CarbonImmutable $now): void
    {
        $this->db->transaction(function () use ($userId, $channel, $digest, $now): void {
            AccountLock::forUser($this->db, $userId);
            if ($channel === 'discord' && ! $this->of($userId)['discord_available']) {
                throw ValidationException::withMessages(['channel' => [self::DISCORD_FIRST]]);
            }
            $this->db->insert(
                'INSERT INTO notification_settings (user_id, channel, digest, updated_at) VALUES (?, ?, ?, ?::timestamptz)
                 ON CONFLICT (user_id) DO UPDATE SET channel = EXCLUDED.channel, digest = EXCLUDED.digest, updated_at = EXCLUDED.updated_at',
                [$userId, $channel, $digest, $now->utc()->format('Y-m-d H:i:sP')],
            );
        });
    }

    /**
     * Sets the channel back to email when it was discord (D9: the Discord webhook was deleted). Call inside the
     * transaction that deletes the webhook.
     */
    public static function resetDiscord(ConnectionInterface $db, int $userId): void
    {
        $db->update("UPDATE notification_settings SET channel = 'email', updated_at = now() WHERE user_id = ? AND channel = 'discord'", [$userId]);
    }
}
