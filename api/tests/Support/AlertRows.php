<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Alerts\Models\WebhookEndpoint;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Rows for the alert dispatch tests (plan P5-i1 T01): ingest runs, price_events and webhook endpoints inserted
 * straight into their tables (test data only: the dispatcher never writes runs or events), the dispatch command's
 * JSON line, and the log records a run writes. Every URL and secret here is a fixed, non-secret test value.
 */
final class AlertRows
{
    /** A fixed, non-secret test URL (RFC 2606). */
    public const string URL = 'https://hooks.example.com/pokeprices/test-0000';

    /** A fixed, non-secret test signing secret, built at run time: whsec_ and 32 zero bytes, base64-encoded. */
    public static function secret(): string
    {
        return 'whsec_'.base64_encode(str_repeat("\0", 32));
    }

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public static array $logs = [];

    /** Starts recording every log record written from now on into $logs. */
    public static function captureLogs(): void
    {
        self::$logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            self::$logs[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });
    }

    /**
     * The recorded records with this message.
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public static function logged(string $message): array
    {
        return array_values(array_filter(self::$logs, fn (array $r): bool => $r['message'] === $message));
    }

    /** Inserts one tcgplayer ingest run for $day and returns its id. */
    public static function run(string $day, string $status = 'ok', string $mode = 'sync', ?string $finishedAt = null): int
    {
        return (int) DB::table('ingest_runs')->insertGetId([
            'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day, 'marker' => $day.' 20:05:44+00', 'mode' => $mode,
            'status' => $status, 'started_at' => $day.' 20:10:00+00', 'finished_at' => $finishedAt ?? $day.' 20:30:00+00',
            'counts' => '{}',
        ]);
    }

    /** Inserts ok sync runs for each day. */
    public static function runs(string ...$days): void
    {
        foreach ($days as $day) {
            self::run($day);
        }
    }

    /**
     * Inserts the variant (when new) and one price.moved event for it on $day; returns the event id.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function event(string $variantKey, string $day, array $overrides = []): int
    {
        AccountRows::variant($variantKey);
        $previous = date('Y-m-d', (int) strtotime($day.' -1 day'));

        return (int) DB::table('price_events')->insertGetId($overrides + [
            'type' => 'price.moved', 'rule' => 'draft-v1', 'variant_key' => $variantKey,
            'card_id' => explode(':', $variantKey)[0], 'source' => 'tcgplayer', 'day' => $day, 'series_epoch' => 1,
            'direction' => 'up', 'close' => '12.30', 'previous_close' => '10.00', 'previous_day' => $previous,
            'change' => '2.30', 'change_pct' => '23.00', 'as_of' => $day.' 20:05:45+00',
            'previous_as_of' => $previous.' 20:05:45+00', 'ingest_run_id' => 1,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public static function user(string $email, array $attributes = []): User
    {
        return Accounts::user(['email' => $email] + $attributes);
    }

    /** A standard (signed) or Discord endpoint of $user, saved through the model; returns its id. */
    public static function endpoint(User $user, string $kind = 'standard', bool $disabled = false): int
    {
        $endpoint = new WebhookEndpoint;
        $endpoint->forceFill([
            'user_id' => $user->id,
            'kind' => $kind,
            'url' => $kind === 'discord' ? 'https://discord.com/api/webhooks/1/test-token-0000' : self::URL,
            'url_hint' => $kind === 'discord' ? 'https://discord.com/…' : 'https://hooks.example.com/…',
            'secret' => $kind === 'standard' ? self::secret() : null,
            'disabled_at' => $disabled ? '2026-10-12 00:00:00+00' : null,
        ])->save();

        return (int) $endpoint->id;
    }

    /**
     * Runs pokeprices:alerts:dispatch and returns [exit code, the decoded JSON line or null, the raw output].
     *
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function dispatch(array $options = []): array
    {
        $code = Artisan::call('pokeprices:alerts:dispatch', $options);
        $output = Artisan::output();
        $lines = array_values(array_filter(explode("\n", trim($output)), fn (string $l): bool => str_starts_with($l, '{')));
        $json = $lines === [] ? null : json_decode((string) end($lines), true);

        return [$code, is_array($json) ? $json : null, $output];
    }

    /**
     * Every price.moved delivery as [event_id, endpoint_id], ordered.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function moved(): array
    {
        $rows = DB::select("SELECT event_id, endpoint_id FROM deliveries WHERE kind = 'price.moved' ORDER BY event_id, endpoint_id");

        return array_map(fn (object $r): array => [(int) $r->event_id, (int) $r->endpoint_id], $rows);
    }

    /**
     * dispatch_days as [source, day, ceiling], ordered by day.
     *
     * @return list<array{0: string, 1: string, 2: int|null}>
     */
    public static function days(): array
    {
        $rows = DB::select('SELECT source, day::text AS day, event_id_ceiling FROM dispatch_days ORDER BY day');

        return array_map(fn (object $r): array => [(string) $r->source, (string) $r->day, $r->event_id_ceiling === null ? null : (int) $r->event_id_ceiling], $rows);
    }

    /** Marks every run superseded, as Replay's wipe does. */
    public static function supersedeAll(): void
    {
        DB::update("UPDATE ingest_runs SET status = 'superseded' WHERE status IN ('ok', 'running')");
    }
}
