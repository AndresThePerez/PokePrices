<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Everything PokéPrices keeps about a person, as GET /v1/me/export hands it over (plan P4-i1 D6; review F-P4-08):
 * the account (email, when it was verified, whether two-factor is on, when it was created), the watchlist (with each
 * alert threshold) and the portfolio ordered by variant key, and the session records newest first (last activity,
 * address, browser). Version 2 (plan P5-i1 D9) adds where alerts go, the webhooks (url_hint only) and the newest
 * deliveries (at most alerts.export_deliveries_limit, without their body).
 *
 * It never holds a secret: no password hash, remember token, two-factor secret, recovery code, session id, session
 * payload, webhook URL, signing secret or delivery body is ever selected. Times are RFC 3339 in UTC to the second.
 */
final class AccountExport
{
    public const string FORMAT = 'pokeprices-account-export';

    public const int VERSION = 2;

    /** to_char pattern of an RFC 3339 UTC time to the second, for a timestamptz taken AT TIME ZONE 'UTC'. */
    private const string SQL_TIME = 'YYYY-MM-DD"T"HH24:MI:SS"Z"';

    private const string PHP_TIME = 'Y-m-d\TH:i:s\Z';

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @return array{format: string, version: int, account: array{email: string, email_verified_at: string|null, two_factor: bool, created_at: string|null}, watchlist: list<array{variant_key: string, added_at: string, alert_pct: int|null}>, portfolio: list<array{variant_key: string, quantity: int, added_at: string, updated_at: string}>, sessions: list<array{last_active_at: string, ip_address: string|null, user_agent: string|null}>, notifications: array{channel: string, digest: bool}, webhooks: list<array{kind: string, url_hint: string, description: string|null, created_at: string, disabled: bool}>, deliveries: list<array{kind: string, channel: string, day: string|null, status: string, attempts: int, created_at: string, delivered_at: string|null}>}
     */
    public function of(User $user): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'account' => [
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->copy()->utc()->format(self::PHP_TIME),
                'two_factor' => $user->two_factor_confirmed_at !== null,
                'created_at' => $user->created_at?->copy()->utc()->format(self::PHP_TIME),
            ],
            'watchlist' => $this->watchlist($user->id),
            'portfolio' => $this->portfolio($user->id),
            'sessions' => $this->sessions($user->id),
            'notifications' => $this->notifications($user->id),
            'webhooks' => $this->webhooks($user->id),
            'deliveries' => $this->deliveries($user->id),
        ];
    }

    /** @return list<array{variant_key: string, added_at: string, alert_pct: int|null}> */
    private function watchlist(int $userId): array
    {
        $rows = $this->db->select(
            "SELECT variant_key, to_char(created_at AT TIME ZONE 'UTC', '".self::SQL_TIME."') AS added_at, alert_pct
               FROM watchlist_items WHERE user_id = ? ORDER BY variant_key COLLATE \"C\"",
            [$userId],
            false,
        );

        return array_values(array_map(static fn (object $row): array => [
            'variant_key' => self::text($row, 'variant_key'),
            'added_at' => self::text($row, 'added_at'),
            'alert_pct' => is_numeric($row->alert_pct ?? null) ? (int) $row->alert_pct : null,
        ], $rows));
    }

    /** @return array{channel: string, digest: bool} the saved choice, or the defaults (email, no digest) */
    private function notifications(int $userId): array
    {
        $row = $this->db->selectOne('SELECT channel, digest FROM notification_settings WHERE user_id = ?', [$userId], false);

        return [
            'channel' => is_object($row) && ($row->channel ?? null) === 'discord' ? 'discord' : 'email',
            'digest' => is_object($row) && ($row->digest ?? null) === true,
        ];
    }

    /**
     * The webhooks, oldest first: kind, url_hint, description, when added and whether turned off. Never the URL or
     * the signing secret: those columns are not selected.
     *
     * @return list<array{kind: string, url_hint: string, description: string|null, created_at: string, disabled: bool}>
     */
    private function webhooks(int $userId): array
    {
        $rows = $this->db->select(
            "SELECT kind, url_hint, description, to_char(created_at AT TIME ZONE 'UTC', '".self::SQL_TIME."') AS created_at,
                    disabled_at IS NOT NULL AS disabled
               FROM webhook_endpoints WHERE user_id = ? ORDER BY created_at, id",
            [$userId],
            false,
        );

        return array_values(array_map(static fn (object $row): array => [
            'kind' => self::text($row, 'kind'),
            'url_hint' => self::text($row, 'url_hint'),
            'description' => is_string($row->description ?? null) ? $row->description : null,
            'created_at' => self::text($row, 'created_at'),
            'disabled' => ($row->disabled ?? null) === true,
        ], $rows));
    }

    /**
     * The newest deliveries first, at most alerts.export_deliveries_limit (review A10.4). Never the body: it is not
     * selected.
     *
     * @return list<array{kind: string, channel: string, day: string|null, status: string, attempts: int, created_at: string, delivered_at: string|null}>
     */
    private function deliveries(int $userId): array
    {
        $limit = config('alerts.export_deliveries_limit', 500);
        $rows = $this->db->select(
            "SELECT kind, channel, day::text AS day, status, attempts,
                    to_char(created_at AT TIME ZONE 'UTC', '".self::SQL_TIME."') AS created_at,
                    to_char(delivered_at AT TIME ZONE 'UTC', '".self::SQL_TIME."') AS delivered_at
               FROM deliveries WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?",
            [$userId, is_numeric($limit) ? max(0, (int) $limit) : 500],
            false,
        );

        return array_values(array_map(static fn (object $row): array => [
            'kind' => self::text($row, 'kind'),
            'channel' => self::text($row, 'channel'),
            'day' => is_string($row->day ?? null) ? $row->day : null,
            'status' => self::text($row, 'status'),
            'attempts' => is_numeric($row->attempts ?? null) ? (int) $row->attempts : 0,
            'created_at' => self::text($row, 'created_at'),
            'delivered_at' => is_string($row->delivered_at ?? null) ? $row->delivered_at : null,
        ], $rows));
    }

    /** @return list<array{variant_key: string, quantity: int, added_at: string, updated_at: string}> */
    private function portfolio(int $userId): array
    {
        $rows = $this->db->select(
            "SELECT variant_key, quantity,
                    to_char(created_at AT TIME ZONE 'UTC', '".self::SQL_TIME."') AS added_at,
                    to_char(updated_at AT TIME ZONE 'UTC', '".self::SQL_TIME."') AS updated_at
               FROM portfolio_holdings WHERE user_id = ? ORDER BY variant_key COLLATE \"C\"",
            [$userId],
            false,
        );

        return array_values(array_map(static fn (object $row): array => [
            'variant_key' => self::text($row, 'variant_key'),
            'quantity' => is_numeric($row->quantity ?? null) ? (int) $row->quantity : 0,
            'added_at' => self::text($row, 'added_at'),
            'updated_at' => self::text($row, 'updated_at'),
        ], $rows));
    }

    /**
     * The session records of the user, newest activity first. Only the three columns a person may see are selected:
     * never the session id or the payload.
     *
     * @return list<array{last_active_at: string, ip_address: string|null, user_agent: string|null}>
     */
    private function sessions(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT last_activity, ip_address, user_agent FROM sessions WHERE user_id = ? ORDER BY last_activity DESC',
            [$userId],
            false,
        );

        return array_values(array_map(static fn (object $row): array => [
            'last_active_at' => gmdate(self::PHP_TIME, is_numeric($row->last_activity ?? null) ? (int) $row->last_activity : 0),
            'ip_address' => is_string($row->ip_address ?? null) ? $row->ip_address : null,
            'user_agent' => is_string($row->user_agent ?? null) ? $row->user_agent : null,
        ], $rows));
    }

    private static function text(object $row, string $column): string
    {
        $value = $row->{$column} ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
