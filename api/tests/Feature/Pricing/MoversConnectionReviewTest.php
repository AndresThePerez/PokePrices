<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CatalogCache;

// Reviewer tests for Q1-i4 T04: MoversController takes the newest-day read's connection by injection instead of the
// DB facade, so whatever the container binds as ConnectionInterface is the connection that answers query 1.

beforeEach(function (): void {
    Queue::fake([VerifyCatalog::class]);
    $prefix = CatalogCache::isolate();
    $this->beforeApplicationDestroyed(fn () => CatalogCache::flush($prefix));
    config(['services.pokesearch.url' => 'http://192.0.2.10:8081', 'services.pokesearch.api_key' => null]);
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(['data' => array_map(fn (string $id): array => ['id' => $id, 'name' => "Name of {$id}",
            'number' => '4', 'set_name' => 'Fixture set'], array_filter(explode(',', (string) ($query['ids'] ?? '')))), 'missing' => []]);
    });
});

/** One up-mover of 2026-10-14 in price_movers (and its variant). */
function q1t04mcSeed(): void
{
    DB::table('card_variants')->insert(['variant_key' => 'rev-4:holo:unlimited:-', 'card_id' => 'rev-4', 'finish' => 'holo', 'edition' => 'unlimited']);
    DB::table('price_movers')->insert(['day' => '2026-10-14', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => 1,
        'variant_key' => 'rev-4:holo:unlimited:-', 'card_id' => 'rev-4', 'series_epoch' => 1, 'close' => '12.00', 'previous_close' => '10.00',
        'previous_day' => '2026-10-13', 'change' => '2.00', 'change_pct' => '20.00', 'as_of' => '2026-10-14 20:05:45+00']);
}

it('resolves the injected connection to the default connection', function (): void {
    expect(app(ConnectionInterface::class)->getName())->toBe(DB::connection()->getName());
});

it('reads the newest movers day from the connection the container binds, with one query on it and the answer built from that read', function (): void {
    q1t04mcSeed();
    $spy = Mockery::mock(ConnectionInterface::class);
    $spy->shouldReceive('selectOne')->once()->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'price_movers') && $bindings === ['tcgplayer'])
        ->andReturn((object) ['day' => null, 'as_of' => null]);
    app()->instance(ConnectionInterface::class, $spy);

    $response = $this->getJson('/v1/movers');

    $response->assertOk();
    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.day'))->toBeNull()
        ->and($response->json('meta.as_of'))->toBeNull();
});

it('answers the seeded rows and meta unchanged through the default container connection', function (): void {
    q1t04mcSeed();

    $response = $this->getJson('/v1/movers?direction=up&limit=5');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.variant_key'))->toBe('rev-4:holo:unlimited:-')
        ->and($response->json('meta.day'))->toBe('2026-10-14')
        ->and($response->json('meta.as_of'))->toBe('2026-10-14T20:05:45Z')
        ->and($response->json('meta.limit'))->toBe(5)
        ->and($response->json('meta.direction'))->toBe('up');
});

it('keeps the empty movers answer when price_movers holds nothing', function (): void {
    $response = $this->getJson('/v1/movers');

    $response->assertOk();
    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.day'))->toBeNull()
        ->and($response->json('meta.as_of'))->toBeNull()
        ->and($response->json('meta.degraded'))->toBeFalse();
});
