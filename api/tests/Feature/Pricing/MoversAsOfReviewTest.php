<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\Movers;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CatalogCache;

// Reviewer edge cases for the stored movers as-of (plan Q1-i3 T04, finding B10; R181(5): meta.as_of within the 2-query
// budget). Rows are placed by hand, so the instants, the zones and the days without a real close are exact.

const Q1T04RM_OLD_QUERY = <<<'SQL'
    SELECT to_char(m.day, 'YYYY-MM-DD') AS day,
           (SELECT to_char(max(d.as_of) AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')
            FROM price_daily d WHERE d.source = ? AND d.day = m.day AND NOT d.is_filled) AS as_of
    FROM (SELECT max(day) AS day FROM price_movers WHERE source = ?) m
    SQL;

beforeEach(function (): void {
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    Queue::fake([VerifyCatalog::class]);
    $catalogPrefix = CatalogCache::isolate();
    $this->beforeApplicationDestroyed(fn () => CatalogCache::flush($catalogPrefix));
    config(['services.pokesearch.url' => 'http://192.0.2.10:8081', 'services.pokesearch.api_key' => null]);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(['data' => array_map(fn (string $id): array => ['id' => $id, 'name' => "Name of {$id}",
            'number' => substr((string) strrchr($id, '-'), 1), 'set_name' => 'Fixture set'], explode(',', (string) ($query['ids'] ?? ''))), 'missing' => []]);
    });
});

/** One variant with real closes on two days and one up-mover on the later day carrying $asOf as its stored marker. */
function q1t04rmSeed(string $closeAsOf, string $moverAsOf): void
{
    DB::table('card_variants')->insert(['variant_key' => 'rev-1:holo:unlimited:-', 'card_id' => 'rev-1', 'finish' => 'holo', 'edition' => 'unlimited']);
    foreach ([['2026-10-13', '10.00', '2026-10-13 20:00:00+00'], ['2026-10-14', '12.00', $closeAsOf]] as [$day, $close, $asOf]) {
        DB::table('price_daily')->insert(['variant_key' => 'rev-1:holo:unlimited:-', 'source' => 'tcgplayer', 'day' => $day, 'series_epoch' => 1,
            'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'n_obs' => 1, 'is_filled' => false, 'filled_from' => null, 'as_of' => $asOf]);
    }
    DB::table('price_movers')->insert(['day' => '2026-10-14', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => 1,
        'variant_key' => 'rev-1:holo:unlimited:-', 'card_id' => 'rev-1', 'series_epoch' => 1, 'close' => '12.00', 'previous_close' => '10.00',
        'previous_day' => '2026-10-13', 'change' => '2.00', 'change_pct' => '20.00', 'as_of' => $moverAsOf]);
}

test('meta.as_of is the stored instant in UTC with a Z, truncated to the second, whatever the session time zone', function (string $zone): void {
    // 22:05:46.987 at +02:00 is 20:05:46.987 UTC.
    q1t04rmSeed('2026-10-14 22:05:46.987+02', '2026-10-14 22:05:46.987+02');
    DB::statement("SET LOCAL TIME ZONE '{$zone}'");
    $old = DB::selectOne(Q1T04RM_OLD_QUERY, ['tcgplayer', 'tcgplayer']);

    $response = $this->getJson('/v1/movers?direction=up');

    $response->assertOk();
    expect($response->json('meta.as_of'))->toBe('2026-10-14T20:05:46Z')
        ->and($response->json('meta.as_of'))->toBe($old->as_of)
        ->and($response->json('meta.day'))->toBe('2026-10-14');
})->with(['UTC', 'America/New_York', 'Asia/Tokyo', 'Pacific/Kiritimati']);

test('GET /v1/movers stays within two statements and none reads price_daily', function (string $direction, int $limit): void {
    q1t04rmSeed('2026-10-14 20:05:46+00', '2026-10-14 20:05:46+00');
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->getJson("/v1/movers?direction={$direction}&limit={$limit}")->assertOk();

    $reads = array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'price_')));
    expect(count($reads))->toBeLessThanOrEqual(2)
        ->and(array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'price_daily'))))->toBe([]);
})->with([['up', 1], ['down', 50], ['up', 20]]);

test('Movers::newest returns the newest day with the as_of stored on it, not the older day\'s', function (): void {
    q1t04rmSeed('2026-10-14 20:05:46+00', '2026-10-14 20:05:46+00');
    DB::table('price_movers')->insert(['day' => '2026-10-13', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => 1,
        'variant_key' => 'rev-1:holo:unlimited:-', 'card_id' => 'rev-1', 'series_epoch' => 1, 'close' => '10.00', 'previous_close' => '9.00',
        'previous_day' => '2026-10-12', 'change' => '1.00', 'change_pct' => '11.11', 'as_of' => '2030-01-01 00:00:00+00']);

    expect(Movers::newest(DB::connection(), 'tcgplayer'))->toBe(['day' => '2026-10-14', 'as_of' => '2026-10-14T20:05:46Z'])
        ->and(Movers::newest(DB::connection(), 'nosuch'))->toBe(['day' => null, 'as_of' => null]);
});

test('the migration backfills a stored as_of from real closes only, never from a later filled row', function (): void {
    q1t04rmSeed('2026-10-14 20:05:46+00', '2000-01-01 00:00:00+00');
    // A filled row with a later marker on the same day must not count (the old subquery had NOT is_filled).
    DB::table('card_variants')->insert(['variant_key' => 'rev-2:holo:unlimited:-', 'card_id' => 'rev-2', 'finish' => 'holo', 'edition' => 'unlimited']);
    DB::table('price_daily')->insert(['variant_key' => 'rev-2:holo:unlimited:-', 'source' => 'tcgplayer', 'day' => '2026-10-14', 'series_epoch' => 1,
        'open' => '1.00', 'high' => '1.00', 'low' => '1.00', 'close' => '1.00', 'n_obs' => 0, 'is_filled' => true, 'filled_from' => '2026-10-13',
        'as_of' => '2026-10-14 23:59:59+00']);
    $migration = require database_path('migrations/2026_10_28_000002_add_as_of_to_price_movers.php');

    $migration->down();
    expect(Schema::hasColumn('price_movers', 'as_of'))->toBeFalse();
    $migration->up();

    expect(Movers::newest(DB::connection(), 'tcgplayer'))->toBe(['day' => '2026-10-14', 'as_of' => '2026-10-14T20:05:46Z']);
    $nullable = DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'price_movers' AND column_name = 'as_of'");
    expect($nullable->is_nullable)->toBe('NO');
});

test('the rebuild stores the day marker over all real closes, including a variant that is not a mover', function (): void {
    q1t04rmSeed('2026-10-14 20:05:46+00', '2000-01-01 00:00:00+00');
    // A second variant with no previous close: it can never be a mover, but its close carries the newest marker.
    DB::table('card_variants')->insert(['variant_key' => 'rev-2:holo:unlimited:-', 'card_id' => 'rev-2', 'finish' => 'holo', 'edition' => 'unlimited']);
    DB::table('price_daily')->insert(['variant_key' => 'rev-2:holo:unlimited:-', 'source' => 'tcgplayer', 'day' => '2026-10-14', 'series_epoch' => 1,
        'open' => '3.00', 'high' => '3.00', 'low' => '3.00', 'close' => '3.00', 'n_obs' => 1, 'is_filled' => false, 'filled_from' => null,
        'as_of' => '2026-10-14 21:30:15+00']);

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-14');

    expect(DB::table('price_movers')->count())->toBe(1)
        ->and(Movers::newest(DB::connection(), 'tcgplayer'))->toBe(['day' => '2026-10-14', 'as_of' => '2026-10-14T21:30:15Z']);
});
