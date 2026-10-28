<?php

declare(strict_types=1);

use App\Domain\Alerts\Jobs\DeliverDue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AlertRows;

// Plan P5-i1 T02 criterion 6 (D7, review A07): two sessions claiming at once never get the same row. The rows are
// committed on a cloned connection outside the test's RefreshDatabase transaction (a claim race needs row
// visibility), due on 2099-01-01 so no other test's sweep (on the real clock) can ever claim them, and removed in
// a finally block.

it('gives two concurrent claims disjoint committed rows: the second skips what the first holds', function (): void {
    config([
        'database.connections.pgsql_race_a' => config('database.connections.pgsql'),
        'database.connections.pgsql_race_b' => config('database.connections.pgsql'),
    ]);
    $raceA = DB::connection('pgsql_race_a');
    $raceB = DB::connection('pgsql_race_b');
    $email = 'p5-race-'.Str::lower(Str::random(12)).'@p5-race-'.Str::lower(Str::random(6)).'.example.com';
    $at = CarbonImmutable::parse('2099-01-01 00:01:00', 'UTC');
    $userId = null;
    $mine = [];

    try {
        $userId = (int) $raceA->table('users')->insertGetId([
            'email' => $email, 'password' => 'not-a-password-hash', 'email_verified_at' => '2026-10-01 00:00:00+00',
        ]);
        $endpointId = (int) $raceA->table('webhook_endpoints')->insertGetId([
            'user_id' => $userId, 'kind' => 'standard', 'url' => Crypt::encryptString(AlertRows::URL),
            'url_hint' => 'https://hooks.example.com/…', 'secret' => Crypt::encryptString(AlertRows::secret()),
        ]);
        for ($i = 0; $i < 6; $i++) {
            $mine[] = (int) $raceA->table('deliveries')->insertGetId([
                'message_id' => 'msg_'.Str::ulid(), 'user_id' => $userId, 'kind' => 'webhook.test', 'channel' => 'webhook',
                'endpoint_id' => $endpointId, 'body' => '{}', 'next_attempt_at' => '2099-01-01 00:00:00+00',
            ]);
        }

        $raceA->beginTransaction();
        $a = DeliverDue::claim($raceA, $at, 3);

        $raceB->statement("SET lock_timeout = '250ms'");
        $b = DeliverDue::claim($raceB, $at, 6);
        $raceA->commit();

        sort($a);
        sort($b);
        expect($a)->toHaveCount(3)
            ->and($b)->toHaveCount(3)
            ->and(array_intersect($a, $b))->toBe([])
            ->and(array_merge($a, $b))->toEqualCanonicalizing($mine)
            ->and($a)->toBe(array_slice($mine, 0, 3))
            ->and($raceA->table('deliveries')->whereIn('id', $mine)->pluck('claims')->unique()->values()->all())->toBe([1]);
    } finally {
        if ($raceA->transactionLevel() > 0) {
            $raceA->rollBack();
        }
        if ($userId !== null) {
            $raceA->table('users')->where('id', $userId)->delete();
        }
        $left = [
            (int) $raceA->table('users')->where('email', $email)->count(),
            (int) $raceA->table('webhook_endpoints')->where('user_id', $userId ?? 0)->count(),
            (int) $raceA->table('deliveries')->whereIn('id', $mine === [] ? [0] : $mine)->count(),
        ];
        DB::purge('pgsql_race_a');
        DB::purge('pgsql_race_b');
    }

    expect($left)->toBe([0, 0, 0]);
});
