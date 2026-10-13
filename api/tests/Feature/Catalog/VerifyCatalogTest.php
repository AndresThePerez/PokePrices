<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use App\Domain\Catalog\CircuitBreaker;
use App\Domain\Catalog\VerifyCatalog;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Support\CatalogCache;

// The VerifyCatalog job (plan T45, F-P1-031, R129 queue half): every distinct card_variants.card_id is checked
// through PokéSearch's GET /api/v1/cards?ids= in chunks of 100 and catalog_status, catalog_seed_ref and
// catalog_checked_at are written. The batch endpoint is faked from a catalog table; every request is recorded.
// The job runs through the sync queue (phpunit), so its middleware runs exactly as a worker would run it.

const T45_BASE = 'http://192.0.2.10:8081';
const T45_REF = 'cccccccccccccccccccccccccccccccccccccccc';
const T45_OLD_REF = 'dddddddddddddddddddddddddddddddddddddddd';

function t45Meta(string $ref): array
{
    return [
        'version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3,
        'seed' => ['ref' => $ref, 'seeded_at' => '2026-10-11T20:05:45Z'], 'request_id' => 'upstream-meta',
    ];
}

/** card_variants rows for $ids (a normal and, for every third id, also a holo variant), mapped in one build. */
function t45Rows(array $ids, string $status = 'unverified', ?string $seedRef = null, ?string $checkedAt = null): void
{
    $build = DB::table('map_builds')->insertGetId([
        'snapshot_day' => '2026-10-11', 'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11', 'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00', 'finished_at' => '2026-10-12 08:05:00+00', 'counts' => '{}',
    ]);
    $product = 50000 + DB::table('source_product_map')->count();
    foreach ($ids as $n => $id) {
        foreach ($n % 3 === 0 ? ['normal', 'holo'] : ['normal'] as $finish) {
            $key = "{$id}:{$finish}:-:-";
            DB::table('card_variants')->insert([
                'variant_key' => $key, 'card_id' => $id, 'finish' => $finish, 'edition' => '-', 'treatment' => '-',
                'catalog_status' => $status, 'catalog_seed_ref' => $seedRef, 'catalog_checked_at' => $checkedAt,
            ]);
            DB::table('source_product_map')->insert([
                'source' => 'tcgplayer', 'source_group_id' => 604, 'source_product_id' => $product++,
                'source_subtype' => $finish === 'holo' ? 'Holofoil' : 'Normal', 'source_product_name' => 'Card '.$id,
                'source_number' => '1', 'variant_key' => $key, 'method' => 'number+name', 'confidence' => '1.000',
                'name_agreement' => true, 'series_epoch' => 2, 'map_build_id' => $build,
            ]);
        }
    }
}

/** @return array<string, array{catalog_status: string, catalog_seed_ref: string|null, catalog_checked_at: string|null}> */
function t45Statuses(): array
{
    return DB::table('card_variants')->orderBy('variant_key')->get()->mapWithKeys(fn (object $r): array => [
        $r->variant_key => ['catalog_status' => $r->catalog_status, 'catalog_seed_ref' => $r->catalog_seed_ref, 'catalog_checked_at' => $r->catalog_checked_at],
    ])->all();
}

/** @return list<string> sv1-1 … sv1-$n */
function t45Ids(int $n): array
{
    return array_map(fn (int $i): string => "sv1-{$i}", range(1, $n));
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T45_BASE, 'services.pokesearch.api_key' => null]);
    $this->prefix = CatalogCache::isolate();

    // The ids PokéSearch has; $this->failBatch, when set, is [n, status]: batch request n and every later one
    // answer with that status.
    $this->catalog = [];
    $this->failBatch = null;
    $this->batches = [];
    $this->meta = T45_REF;
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/api/meta') {
            return Http::response(t45Meta($this->meta));
        }
        if ($path !== '/api/v1/cards') {
            return Http::response(['error' => 'unrouted'], 599);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $ids = explode(',', (string) $query['ids']);
        $this->batches[] = $ids;
        if ($this->failBatch !== null && count($this->batches) >= $this->failBatch[0]) {
            return Http::response(['error' => 'es down'], $this->failBatch[1]);
        }
        $found = array_values(array_intersect($ids, $this->catalog));

        return Http::response([
            'data' => array_map(fn (string $id): array => ['id' => $id, 'name' => 'Card '.$id], $found),
            'missing' => array_values(array_diff($ids, $this->catalog)),
            'seed' => ['ref' => T45_REF, 'seeded_at' => '2026-10-11T20:05:45Z'],
        ]);
    });
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('flags exactly the ids the batch answer lists as missing, checking each distinct card id once in chunks of 100', function (): void {
    $ids = t45Ids(150);
    t45Rows($ids);
    $missing = ['sv1-7', 'sv1-120', 'sv1-150'];
    $this->catalog = array_values(array_diff($ids, $missing));
    $epochs = DB::table('source_product_map')->orderBy('variant_key')->pluck('series_epoch', 'variant_key')->all();
    $variantCount = DB::table('card_variants')->count();
    Carbon::setTestNow('2026-10-13 09:30:00');

    VerifyCatalog::dispatch();

    $flagged = DB::table('card_variants')->where('catalog_status', 'catalog_missing')->distinct()->orderBy('card_id')->pluck('card_id')->all();
    $sent = array_merge(...$this->batches);
    sort($sent);
    $expectedSent = $ids;
    sort($expectedSent);
    expect(array_map('count', $this->batches))->toBe([100, 50])
        ->and($sent)->toBe($expectedSent)
        ->and($flagged)->toBe(['sv1-120', 'sv1-150', 'sv1-7'])
        ->and(DB::table('card_variants')->where('catalog_status', 'ok')->count())->toBe($variantCount - 4)
        ->and(DB::table('card_variants')->where('catalog_status', 'unverified')->count())->toBe(0)
        ->and(DB::table('card_variants')->distinct()->pluck('catalog_seed_ref')->all())->toBe([T45_REF])
        ->and(DB::table('card_variants')->whereNot('catalog_checked_at', '2026-10-13 09:30:00+00')->count())->toBe(0)
        // Nothing deleted, and no mapping moved to a new epoch.
        ->and(DB::table('card_variants')->count())->toBe($variantCount)
        ->and(DB::table('source_product_map')->orderBy('variant_key')->pluck('series_epoch', 'variant_key')->all())->toBe($epochs)
        ->and(DB::table('source_product_map_history')->count())->toBe(0);
});

it('restores a flagged id that reappears in the catalog to ok', function (): void {
    t45Rows(['sv1-1', 'sv1-2'], 'catalog_missing', T45_OLD_REF, '2026-10-01 00:00:00+00');
    $this->catalog = ['sv1-1'];
    Carbon::setTestNow('2026-10-13 09:30:00');

    VerifyCatalog::dispatch();

    expect(t45Statuses())->toBe([
        'sv1-1:holo:-:-' => ['catalog_status' => 'ok', 'catalog_seed_ref' => T45_REF, 'catalog_checked_at' => '2026-10-13 09:30:00+00'],
        'sv1-1:normal:-:-' => ['catalog_status' => 'ok', 'catalog_seed_ref' => T45_REF, 'catalog_checked_at' => '2026-10-13 09:30:00+00'],
        'sv1-2:normal:-:-' => ['catalog_status' => 'catalog_missing', 'catalog_seed_ref' => T45_REF, 'catalog_checked_at' => '2026-10-13 09:30:00+00'],
    ]);
});

it('releases the job on a 5xx through the throttle middleware and leaves every status untouched', function (): void {
    $ids = t45Ids(150);
    t45Rows(array_slice($ids, 0, 75), 'ok', T45_OLD_REF, '2026-10-01 00:00:00+00');
    t45Rows(array_slice($ids, 75));
    $this->catalog = array_slice($ids, 10);
    $this->failBatch = [2, 503];
    $before = t45Statuses();
    $released = [];
    Event::listen(JobProcessed::class, function (JobProcessed $event) use (&$released): void {
        $released[] = $event->job->isReleased();
    });

    VerifyCatalog::dispatch();

    $limiter = CircuitBreaker::keyPrefix().'queue:pokesearch';
    $state = Redis::connection('default')->hgetall($limiter);
    expect($released)->toBe([true])
        // The first batch answered, the second failed twice (one fast retry): nothing was written.
        ->and(array_map('count', $this->batches))->toBe([100, 50, 50])
        ->and(t45Statuses())->toBe($before)
        ->and($limiter)->toStartWith($this->prefix.'breaker:')
        ->and((int) $state['count'])->toBe(1)
        ->and((int) $state['end'] - (int) $state['start'])->toBe(30);
});

it('throttles by pokesearch: after five failed runs the sixth is released without calling PokéSearch', function (): void {
    t45Rows(['sv1-1']);
    $this->failBatch = [1, 500];
    $released = [];
    Event::listen(JobProcessed::class, function (JobProcessed $event) use (&$released): void {
        $released[] = $event->job->isReleased();
    });

    for ($i = 0; $i < 5; $i++) {
        $this->batches = [];
        VerifyCatalog::dispatch();
    }
    $this->batches = [];
    VerifyCatalog::dispatch();

    $state = Redis::connection('default')->hgetall(CircuitBreaker::keyPrefix().'queue:pokesearch');
    expect($released)->toBe([true, true, true, true, true, true])
        ->and($this->batches)->toBe([])
        ->and((int) $state['count'])->toBe(5)
        ->and(DB::table('card_variants')->value('catalog_status'))->toBe('unverified');
});

it('is queued when a read observes a seed ref different from catalog:v1:seed_ref:last, and only then', function (): void {
    Queue::fake([VerifyCatalog::class]);
    $repository = app(CatalogRepository::class);

    // The first read has nothing to compare with; the same ref read again is no change.
    $this->meta = T45_OLD_REF;
    $repository->seedRef();
    $this->travel(301)->seconds();
    $repository->seedRef();
    $beforeChange = Queue::pushed(VerifyCatalog::class)->count();

    $this->meta = T45_REF;
    $this->travel(301)->seconds();
    $changed = $repository->seedRef();
    $this->travel(301)->seconds();
    $repository->seedRef();

    expect($beforeChange)->toBe(0)
        ->and($changed->ref)->toBe(T45_REF)
        ->and(Queue::pushed(VerifyCatalog::class)->count())->toBe(1);
});

it('is queued after each pokeprices:map:build, for the connection the build wrote to', function (): void {
    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
    $build = fn (string $day): int => Artisan::call('pokeprices:map:build', [
        '--day' => $day,
        '--dataset' => base_path('tests/Fixtures/tcgcsv/dataset'),
        '--snapshots' => base_path('tests/Fixtures/tcgcsv/snapshots'),
        '--connection' => 'pgsql',
    ]);

    $refused = $build('2026-02-30');
    Queue::assertNotPushed(VerifyCatalog::class);
    $first = $build('2026-10-11');
    $second = $build('2026-10-11');

    expect($refused)->toBe(1)->and($first)->toBe(0)->and($second)->toBe(0);
    Queue::assertPushed(VerifyCatalog::class, 2);
    Queue::assertPushed(VerifyCatalog::class, fn (VerifyCatalog $job): bool => $job->database === 'pgsql');
});
