<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// The price block of GET /v1/cards/{id} (plan P2-i1 T05 items 3 to 5, D4, D5, honesty rules). The map is built from
// the P1 fixture day and the synthetic days of tests/Fixtures/tcgcsv-days are ingested with --sync; PokéSearch is
// faked. Every /v1 response below is also validated against openapi/api.json by Tests\TestCase (C3).

const T05C_BASE = 'http://192.0.2.10:8081';

const T05C_SEED_REF = '0123456789abcdef0123456789abcdef01234567';

/** A PokéSearch card answer for $id. */
function t05cAnswer(string $id, string $name = 'Charizard'): array
{
    return ['data' => [
        'id' => $id, 'name' => $name, 'supertype' => 'Pokémon', 'subtypes' => ['Basic'], 'hp' => 60, 'types' => ['Fire'],
        'rarity' => 'Promo', 'number' => '8', 'artist' => 'Ken Sugimori', 'set_id' => explode('-', $id)[0],
        'set_name' => 'Promos', 'set_series' => 'Base', 'set_total' => 53, 'release_date' => '1999-07-01',
        'image_small' => 'https://images.example.com/card.png', 'image_large' => 'https://images.example.com/card_hires.png',
    ], 'seed' => ['ref' => T05C_SEED_REF, 'seeded_at' => '2026-10-11T20:05:45Z']];
}

/** Ingests synthetic $days in order with --sync from $snapshots (default: the fixture tree). */
function t05cIngest(array $days, ?string $snapshots = null): void
{
    foreach ($days as $day) {
        $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $snapshots ?? base_path('tests/Fixtures/tcgcsv-days/snapshots')]);
        if ($code !== 0) {
            throw new RuntimeException("the ingest of {$day} failed: ".Artisan::output());
        }
    }
}

/** Copies fixture day $from to $root/$to, relabelling marker.txt and manifest.json when the name changes. */
function t05cCopyDay(string $root, string $from, ?string $to = null): void
{
    $to ??= $from;
    $src = base_path('tests/Fixtures/tcgcsv-days/snapshots/'.$from);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    mkdir("{$root}/{$to}", 0775, true);
    foreach ($it as $file) {
        $dest = "{$root}/{$to}/".substr((string) $file->getPathname(), strlen($src) + 1);
        $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
    }
    if ($to !== $from) {
        $marker = str_replace($from, $to, trim((string) file_get_contents("{$root}/{$to}/marker.txt")));
        file_put_contents("{$root}/{$to}/marker.txt", $marker."\n");
        $manifest = json_decode((string) file_get_contents("{$root}/{$to}/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
        $manifest['marker'] = $marker;
        $manifest['day'] = $to;
        file_put_contents("{$root}/{$to}/manifest.json", json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}

/** Sets the marketPrice of ($productId, $subtype) in group $group of a scratch day to the raw JSON token $token. */
function t05cSetMarket(string $root, string $day, int $group, int $productId, string $subtype, string $token): void
{
    $path = "{$root}/{$day}/prices/{$group}.json.zst";
    $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    foreach ($document['results'] as $i => $row) {
        if ($row['productId'] === $productId && $row['subTypeName'] === $subtype) {
            $document['results'][$i]['marketPrice'] = '__T05C_TOKEN__';
        }
    }
    file_put_contents($path, zstd_compress(str_replace('"__T05C_TOKEN__"', $token, json_encode($document, JSON_THROW_ON_ERROR))));
}

/** The variant of the response with $key. */
function t05cVariant(array $body, string $key): array
{
    foreach ($body['data']['variants'] as $variant) {
        if ($variant['variant_key'] === $key) {
            return $variant;
        }
    }
    throw new RuntimeException("no variant {$key} in the response");
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T05C_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T05C_BASE, T05C_SEED_REF);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);

    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
    $code = Artisan::call('pokeprices:map:build', [
        '--day' => '2026-10-11',
        '--dataset' => base_path('tests/Fixtures/tcgcsv/dataset'),
        '--snapshots' => base_path('tests/Fixtures/tcgcsv/snapshots'),
    ]);
    if ($code !== 0) {
        throw new RuntimeException('the fixture map build failed: '.Artisan::output());
    }

    Http::preventStrayRequests();
    Http::fake([T05C_BASE.'/api/v1/cards/*' => fn ($request) => Http::response(t05cAnswer(rawurldecode(basename($request->url()))))]);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

it('serves the exact price block of a variant with two real days', function (): void {
    t05cIngest(['2026-10-10', '2026-10-11']);

    $response = $this->getJson('/v1/cards/basep-8');

    $response->assertOk();
    expect(t05cVariant($response->json(), 'basep-8:holo:-:-')['price'])->toBe([
        'source' => 'tcgplayer',
        'feed' => 'tcgcsv',
        'basis' => 'market',
        'currency' => 'USD',
        'cadence' => 'daily',
        'close' => '28.00',
        'day' => '2026-10-11',
        'as_of' => '2026-10-11T20:05:45Z',
        'previous_close' => '25.00',
        'previous_day' => '2026-10-10',
        'change' => '3.00',
        'change_pct' => '12.00',
        'series_epoch' => 1,
    ])
        ->and(array_keys(t05cVariant($response->json(), 'basep-8:holo:-:-')))->toBe(['variant_key', 'finish', 'edition', 'treatment', 'sources', 'price']);
});

it('serves a signed negative change as decimal strings', function (): void {
    t05cIngest(['2026-10-10', '2026-10-11']);

    $price = t05cVariant($this->getJson('/v1/cards/svp-46')->json(), 'svp-46:holo:-:-')['price'];

    expect(array_intersect_key($price, array_flip(['close', 'previous_close', 'change', 'change_pct'])))
        ->toBe(['close' => '4.70', 'previous_close' => '5.00', 'change' => '-0.30', 'change_pct' => '-6.00']);
});

it('serves a null previous close for a variant with one real day', function (): void {
    t05cIngest(['2026-10-11']);

    $price = t05cVariant($this->getJson('/v1/cards/basep-8')->json(), 'basep-8:holo:-:-')['price'];

    expect($price)->toMatchArray(['close' => '28.00', 'day' => '2026-10-11', 'previous_close' => null, 'previous_day' => null, 'change' => null, 'change_pct' => null]);
});

it('serves a null previous close when the latest day starts a new series_epoch', function (): void {
    t05cIngest(['2026-10-10']);
    DB::table('source_product_map')->where('variant_key', 'basep-8:holo:-:-')->update(['series_epoch' => 2]);
    t05cIngest(['2026-10-11']);

    $price = t05cVariant($this->getJson('/v1/cards/basep-8')->json(), 'basep-8:holo:-:-')['price'];

    expect($price)->toMatchArray(['series_epoch' => 2, 'close' => '28.00', 'previous_close' => null, 'previous_day' => null, 'change' => null, 'change_pct' => null]);
});

it('serves a null previous close across a 15-day gap', function (): void {
    $root = storage_path('framework/testing/pp-t05c-'.bin2hex(random_bytes(6)));
    try {
        t05cCopyDay($root, '2026-10-09');
        t05cCopyDay($root, '2026-10-10', '2026-10-24');
        t05cIngest(['2026-10-09', '2026-10-24'], $root);

        $price = t05cVariant($this->getJson('/v1/cards/base1-4')->json(), 'base1-4:holo:unlimited:-')['price'];
    } finally {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }

    expect($price)->toMatchArray(['close' => '70.10', 'day' => '2026-10-24', 'as_of' => '2026-10-24T20:05:42Z', 'previous_close' => null, 'previous_day' => null, 'change' => null, 'change_pct' => null]);
});

it('serves a null change_pct when the previous close is 0.00', function (): void {
    $root = storage_path('framework/testing/pp-t05c-'.bin2hex(random_bytes(6)));
    try {
        t05cCopyDay($root, '2026-10-10');
        t05cCopyDay($root, '2026-10-11');
        t05cSetMarket($root, '2026-10-10', 1543, 83694, 'Normal', '0');
        t05cIngest(['2026-10-10', '2026-10-11'], $root);

        $price = t05cVariant($this->getJson('/v1/cards/tk1a-1')->json(), 'tk1a-1:normal:-:-')['price'];
    } finally {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }

    expect(array_intersect_key($price, array_flip(['close', 'previous_close', 'previous_day', 'change', 'change_pct'])))
        ->toBe(['close' => '0.07', 'previous_close' => '0.00', 'previous_day' => '2026-10-10', 'change' => '0.07', 'change_pct' => null]);
});

it('serves price null for a variant with only null-market rows beside a priced variant', function (): void {
    t05cIngest(['2026-10-10', '2026-10-11']);

    $body = $this->getJson('/v1/cards/base1-4')->json();

    expect(t05cVariant($body, 'base1-4:holo:unlimited:error-black-dot'))->toHaveKey('price')
        ->and(t05cVariant($body, 'base1-4:holo:unlimited:error-black-dot')['price'])->toBeNull()
        ->and(DB::table('price_points')->where('variant_key', 'base1-4:holo:unlimited:error-black-dot')->whereNull('market')->count())->toBe(2)
        ->and(t05cVariant($body, 'base1-4:holo:unlimited:-')['price']['close'])->toBe('70.81');
});

it('serves price null on every variant before any day is ingested', function (): void {
    $body = $this->getJson('/v1/cards/base1-4')->json();

    expect($body['data']['variants'])->not->toBe([])
        ->and(array_column($body['data']['variants'], 'price'))->toBe(array_fill(0, count($body['data']['variants']), null));
});

it('leaves an unmapped card with variants []', function (): void {
    t05cIngest(['2026-10-10', '2026-10-11']);

    $response = $this->getJson('/v1/cards/base1-95');

    $response->assertOk();
    expect($response->json('data.variants'))->toBe([]);
});

it('changes the ETag when a new day lands and answers the old validator with 200 and the new body', function (): void {
    t05cIngest(['2026-10-10']);
    $first = $this->getJson('/v1/cards/basep-8');
    $etag = (string) $first->headers->get('ETag');
    expect($this->getJson('/v1/cards/basep-8', ['If-None-Match' => $etag])->getStatusCode())->toBe(304);

    t05cIngest(['2026-10-11']);
    $second = $this->getJson('/v1/cards/basep-8', ['If-None-Match' => $etag]);

    expect($first->getStatusCode())->toBe(200)
        ->and(t05cVariant($first->json(), 'basep-8:holo:-:-')['price']['day'])->toBe('2026-10-10')
        ->and($second->getStatusCode())->toBe(200)
        ->and($second->headers->get('ETag'))->not->toBe($etag)
        ->and(t05cVariant($second->json(), 'basep-8:holo:-:-')['price']['day'])->toBe('2026-10-11');
});

it('documents a closed, nullable price block on each variant and an honest operation description', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $variant = $spec['components']['schemas']['CardResource']['properties']['variants']['items'];
    $price = $variant['properties']['price'] ?? [];
    $object = collect($price['anyOf'] ?? $price['oneOf'] ?? [$price])->first(fn (array $s): bool => isset($s['properties']));
    $description = (string) $spec['paths']['/cards/{id}']['get']['description'];

    expect($variant['required'])->toContain('price')
        ->and(json_encode($price))->toContain('null')
        ->and($object['additionalProperties'])->toBeFalse()
        ->and(array_keys($object['properties']))->toBe(['source', 'feed', 'basis', 'currency', 'cadence', 'close', 'day', 'as_of', 'previous_close', 'previous_day', 'change', 'change_pct', 'series_epoch'])
        ->and($object['required'])->toBe(array_keys($object['properties']))
        ->and($description)->not->toContain('There is no price yet.')
        ->and($description)->toContain('TCGplayer market prices via TCGCSV')
        ->and($description)->toContain('one close per day')
        ->and(strtolower($description))->not->toMatch('/real-time|realtime|\blive\b|24h|as of today/');
});
