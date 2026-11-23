<?php

declare(strict_types=1);

use App\Domain\Pricing\LatestPrices;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

const T05E_BASE = 'http://192.0.2.10:8081';

const T05E_SEED_REF = '0123456789abcdef0123456789abcdef01234567';

/** The catalogue's answer for one card: obvious fakes only. */
function t05eAnswer(string $id): array
{
    return ['data' => [
        'id' => $id, 'name' => 'Charizard', 'supertype' => 'Pokémon', 'subtypes' => ['Stage 2'], 'hp' => 120, 'types' => ['Fire'],
        'rarity' => 'Rare Holo', 'number' => '4', 'artist' => 'Mitsuhiro Arita', 'set_id' => explode('-', $id)[0],
        'set_name' => 'Base', 'set_series' => 'Base', 'set_total' => 102, 'release_date' => '1999-01-09',
        'image_small' => 'https://images.example.com/card.png', 'image_large' => 'https://images.example.com/card_hires.png',
    ], 'seed' => ['ref' => T05E_SEED_REF, 'seeded_at' => '2026-10-11T20:05:45Z']];
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T05E_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T05E_BASE, T05E_SEED_REF);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);

    Http::preventStrayRequests();
    Http::fake([T05E_BASE.'/api/v1/cards/*' => fn ($request) => Http::response(t05eAnswer(rawurldecode(basename($request->url()))))]);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

// Adversarial cases for the lowest listing and the thin-market flag of GET /v1/cards/{id}: the flag against exact
// integer-cent arithmetic for several hundred generated pairs, the observation a close is matched to (several
// observations on one day, microseconds in the marker, a close whose observation is missing), and the listing of a
// variant with no close when its newest observation says nothing. Rows are written directly; every /v1 answer is also
// validated against openapi/api.json by Tests\TestCase.

function t05eRun(): int
{
    $id = DB::table('ingest_runs')->where('source', 'tcgplayer')->orderBy('id')->value('id');
    if (is_numeric($id)) {
        return (int) $id;
    }

    return (int) DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-01',
        'marker' => '2026-10-01 20:05:00+00', 'mode' => 'sync', 'status' => 'ok', 'started_at' => '2026-10-01 20:10:00+00',
        'finished_at' => '2026-10-01 20:11:00+00']);
}

function t05eVariant(string $key): void
{
    [$card, $finish, $edition, $treatment] = explode(':', $key);
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => $finish,
        'edition' => $edition, 'treatment' => $treatment, 'catalog_status' => 'unverified']);
}

/** One observation of $key at the instant $at (UTC), with the given market and low. */
function t05eObservation(string $key, string $at, ?string $market, ?string $low): void
{
    DB::statement(<<<'SQL'
        INSERT INTO price_points (variant_key, source, observed_at, day, series_epoch, source_product_id, source_subtype,
                                  market, low, mid, high, direct_low, ingest_run_id)
        VALUES (?, 'tcgplayer', ?::timestamptz, (?::timestamptz AT TIME ZONE 'UTC')::date, 1, 1, 'Holofoil', ?::numeric, ?::numeric,
                NULL, NULL, NULL, ?)
        SQL, [$key, $at, $at, $market, $low, t05eRun()]);
}

/** The real close of $key on $day, matched to the observation at the instant $asOf. */
function t05eClose(string $key, string $day, string $close, string $asOf): void
{
    DB::statement(<<<'SQL'
        INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
        VALUES (?, 'tcgplayer', ?::date, 1, ?, ?, ?, ?, 1, false, NULL, ?::timestamptz)
        SQL, [$key, $day, $close, $close, $close, $close, $asOf]);
}

/** @return array<string, array<string, mixed>> the variants of GET /v1/cards/$card by key, after rebuilding price_latest */
function t05eCard(object $test, string $card): array
{
    LatestPrices::rebuild(DB::connection());
    $response = $test->getJson('/v1/cards/'.rawurlencode($card));
    $response->assertOk();

    return array_column($response->json('data.variants'), null, 'variant_key');
}

function t05eMoney(int $cents): string
{
    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}

it('flags exactly the pairs where twice the close is under the lowest listing, for several hundred generated pairs', function (): void {
    mt_srand(20261123);
    $pairs = [];
    // Cents at and around half, odd and even lows, zero markets, tiny and large values, then plain random pairs.
    foreach ([1, 2, 3, 4, 5, 99, 100, 101, 199, 200, 201, 99999, 100000, 100001, 999999999999] as $low) {
        foreach ([intdiv($low, 2) - 1, intdiv($low, 2), intdiv($low, 2) + 1, intdiv($low + 1, 2), 0, $low, $low + 1] as $market) {
            // The columns hold at most 9999999999.99.
            $pairs[] = [min(max($market, 0), 999999999999), $low];
        }
    }
    while (count($pairs) < 400) {
        $low = mt_rand(1, 400000);
        $pairs[] = [mt_rand(0, 3) === 0 ? mt_rand(0, $low) : max(0, intdiv($low, 2) + mt_rand(-3, 3)), $low];
    }
    $pairs = array_slice(array_values(array_unique($pairs, SORT_REGULAR)), 0, 400);
    expect(count($pairs))->toBeGreaterThan(300);

    foreach ($pairs as $i => [$market, $low]) {
        $key = sprintf('zz3-1:holo:-:t%03d', $i);
        t05eVariant($key);
        t05eObservation($key, '2026-10-13 20:05:00+00', t05eMoney($market), t05eMoney($low));
        t05eClose($key, '2026-10-13', t05eMoney($market), '2026-10-13 20:05:00+00');
    }

    $variants = t05eCard($this, 'zz3-1');

    expect($variants)->toHaveCount(count($pairs));
    $wrong = [];
    foreach ($pairs as $i => [$market, $low]) {
        $price = $variants[sprintf('zz3-1:holo:-:t%03d', $i)]['price'];
        $want = $low > 0 && 2 * $market < $low;
        if ($price['thin_market'] !== $want || $price['lowest_listing'] !== t05eMoney($low) || $price['close'] !== t05eMoney($market)) {
            $wrong[] = [t05eMoney($market), t05eMoney($low), $price['thin_market'], $want];
        }
    }
    expect($wrong)->toBe([]);
});

it('takes the lowest listing of the observation at the close\'s own marker when the day holds several observations', function (): void {
    $key = 'zz3-2:holo:-:-';
    t05eVariant($key);
    // An earlier, the close's and a later observation of one day, each with another low; the close is the 20:05 one.
    t05eObservation($key, '2026-10-13 06:00:00+00', '40.00', '900.00');
    t05eObservation($key, '2026-10-13 20:05:00+00', '40.00', '60.00');
    t05eObservation($key, '2026-10-13 23:30:00+00', null, '7000.00');
    t05eClose($key, '2026-10-13', '40.00', '2026-10-13 20:05:00+00');

    $variant = t05eCard($this, 'zz3-2')[$key];

    expect($variant['price'])->toMatchArray(['close' => '40.00', 'lowest_listing' => '60.00', 'thin_market' => false])
        ->and($variant)->not->toHaveKey('listing');
});

it('matches a marker with microseconds to its own observation and not to a neighbouring one', function (): void {
    $key = 'zz3-3:holo:-:-';
    t05eVariant($key);
    t05eObservation($key, '2026-10-13 20:05:44.123456+00', '10.00', '30.00');
    t05eObservation($key, '2026-10-13 20:05:44.123457+00', '10.00', '12.00');
    t05eClose($key, '2026-10-13', '10.00', '2026-10-13 20:05:44.123456+00');

    $variant = t05eCard($this, 'zz3-3')[$key];

    expect($variant['price'])->toMatchArray(['close' => '10.00', 'lowest_listing' => '30.00', 'thin_market' => true]);
});

it('carries no listing and no flag for a close whose observation row is missing, never an older observation\'s listing', function (): void {
    $key = 'zz3-4:holo:-:-';
    t05eVariant($key);
    // The older observation lists 5000.00; the close points at a marker no observation holds.
    t05eObservation($key, '2026-10-12 20:05:00+00', '100.00', '5000.00');
    t05eClose($key, '2026-10-13', '100.00', '2026-10-13 20:05:00+00');

    $variant = t05eCard($this, 'zz3-4')[$key];

    expect($variant['price'])->toMatchArray(['close' => '100.00', 'lowest_listing' => null, 'thin_market' => false])
        ->and($variant)->not->toHaveKey('listing');
});

it('says nothing about a listing for a variant with no close when its newest observation has no low, even after a listed one', function (): void {
    $keys = ['null' => 'zz3-5:holo:-:-', 'zero' => 'zz3-5:normal:-:-', 'positive' => 'zz3-5:reverse:-:-'];
    foreach ($keys as $key) {
        t05eVariant($key);
    }
    t05eObservation($keys['null'], '2026-10-12 20:05:00+00', null, '800.00');
    t05eObservation($keys['null'], '2026-10-13 20:05:00+00', null, null);
    t05eObservation($keys['zero'], '2026-10-12 20:05:00+00', null, '800.00');
    t05eObservation($keys['zero'], '2026-10-13 20:05:00+00', null, '0.00');
    t05eObservation($keys['positive'], '2026-10-12 20:05:00+00', null, '800.00');
    t05eObservation($keys['positive'], '2026-10-13 20:05:00+00', null, '0.01');

    $variants = t05eCard($this, 'zz3-5');

    expect($variants[$keys['null']])->not->toHaveKey('listing')
        ->and($variants[$keys['null']]['price'])->toBeNull()
        ->and($variants[$keys['zero']])->not->toHaveKey('listing')
        ->and($variants[$keys['zero']]['price'])->toBeNull()
        ->and($variants[$keys['positive']]['price'])->toBeNull()
        ->and($variants[$keys['positive']]['listing'])->toMatchArray(['lowest_listing' => '0.01', 'day' => '2026-10-13', 'as_of' => '2026-10-13T20:05:00Z']);
});

it('keeps the listing of a variant with no close to its own newest observation, whatever another variant of the card lists', function (): void {
    $unlisted = 'zz3-6:holo:-:-';
    $listed = 'zz3-6:normal:-:-';
    t05eVariant($unlisted);
    t05eVariant($listed);
    t05eObservation($unlisted, '2026-10-13 20:05:00+00', null, null);
    t05eObservation($listed, '2026-10-14 20:05:00+00', null, '33.00');

    $variants = t05eCard($this, 'zz3-6');

    expect($variants[$unlisted])->not->toHaveKey('listing')
        ->and($variants[$listed]['listing'])->toMatchArray(['lowest_listing' => '33.00', 'day' => '2026-10-14']);
});
