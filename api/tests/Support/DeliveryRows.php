<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Alerts\Jobs\DeliverDue;
use App\Domain\Alerts\Models\WebhookEndpoint;
use App\Domain\Alerts\RetryPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rows and helpers for the sender and sweep tests (plan P5-i1 T02): endpoints with a chosen URL, pending deliveries
 * inserted straight into their table, the sweep run through the deliver command, and a retry policy pinned to one
 * end of its jitter. Every URL and secret is a fixed, non-secret test value (RFC 2606 hosts, RFC 5737 addresses).
 */
final class DeliveryRows
{
    /** The public address the fake resolver gives hooks.example.com (RFC 5737). */
    public const string PUBLIC_IP = '203.0.113.10';

    public const string HOST = 'hooks.example.com';

    public const string URL = 'https://hooks.example.com/pokeprices/test-0000';

    /** A fixed body, as the dispatcher would have stored it. */
    public const string BODY = '{"type":"price.moved","timestamp":"2026-10-12T20:05:45Z","data":{"event_id":"evt_1","close":"12.30"}}';

    /** Resolves hooks.example.com to the public test address and nothing else. */
    public static function resolver(): FakeHostResolver
    {
        return FakeHostResolver::install([self::HOST => [self::PUBLIC_IP]]);
    }

    /** Pins the retry jitter to its minimum or maximum for the rest of the test. */
    public static function jitter(string $at): void
    {
        app()->instance(RetryPolicy::class, new RetryPolicy(30, 4, 3600, fn (int $min, int $max): int => $at === 'min' ? $min : $max));
    }

    /** A standard endpoint of $user at $url, saved through the model (encrypted url and secret); returns its id. */
    public static function endpoint(User $user, string $url = self::URL, ?string $secret = null): int
    {
        $endpoint = new WebhookEndpoint;
        $endpoint->forceFill([
            'user_id' => $user->id,
            'kind' => 'standard',
            'url' => $url,
            'url_hint' => 'https://hooks.example.com/…',
            'secret' => $secret ?? AlertRows::secret(),
        ])->save();

        return (int) $endpoint->id;
    }

    /**
     * One pending webhook delivery to $endpointId, due at the app clock unless overridden; returns its id.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function delivery(int $endpointId, array $overrides = []): int
    {
        $userId = (int) DB::table('webhook_endpoints')->where('id', $endpointId)->value('user_id');

        return (int) DB::table('deliveries')->insertGetId($overrides + [
            'message_id' => 'msg_'.Str::ulid(),
            'user_id' => $userId,
            'kind' => 'price.moved',
            'channel' => 'webhook',
            'endpoint_id' => $endpointId,
            'event_id' => random_int(1, PHP_INT_MAX),
            'day' => '2026-10-12',
            'body' => self::BODY,
            'next_attempt_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.uP'),
            'created_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /** The delivery row as an object. */
    public static function row(int $id): object
    {
        $row = DB::selectOne(
            "SELECT id, message_id, status, attempts, claims, last_status, last_error, delivered_at,
                to_char(next_attempt_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US') AS next_at
             FROM deliveries WHERE id = ?",
            [$id],
        );
        assert(is_object($row));

        return $row;
    }

    /** Seconds from the app clock to the row's next_attempt_at. */
    public static function dueIn(int $id): float
    {
        $next = CarbonImmutable::parse(self::row($id)->next_at.'Z');

        return CarbonImmutable::now()->diffInSeconds($next, false);
    }

    /**
     * One sweep through pokeprices:alerts:deliver; returns [exit code, the decoded JSON line].
     *
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    public static function sweep(): array
    {
        $code = Artisan::call('pokeprices:alerts:deliver');
        $lines = array_values(array_filter(explode("\n", trim(Artisan::output())), fn (string $l): bool => str_starts_with($l, '{')));
        $json = $lines === [] ? null : json_decode((string) end($lines), true);

        return [$code, is_array($json) ? $json : null];
    }

    /** Runs one DeliverDue job through the (sync) queue, as the scheduler would. */
    public static function job(): void
    {
        DeliverDue::dispatch();
    }
}
