<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\CatalogCache;

// Edge cases for the VerifyCatalog job (plan T45, F-P1-031): chunk boundaries at 100 and 101 distinct ids, an
// empty table, ids that need percent-encoding, ids the answer was never asked about, and an answer outside the
// contract part-way through. The job never deletes a row and never writes when any chunk failed.

const T45E_BASE = 'http://192.0.2.10:8081';
const T45E_REF = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

/** One card_variants row per id (two finishes for every id, so the rows outnumber the distinct ids). */
function t45eRows(array $ids): void
{
    foreach ($ids as $id) {
        foreach (['normal', 'holo'] as $finish) {
            DB::table('card_variants')->insert([
                'variant_key' => "{$id}:{$finish}:-:-", 'card_id' => $id, 'finish' => $finish, 'edition' => '-', 'treatment' => '-',
                'catalog_status' => 'unverified', 'catalog_seed_ref' => null, 'catalog_checked_at' => null,
            ]);
        }
    }
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T45E_BASE, 'services.pokesearch.api_key' => null]);
    $this->prefix = CatalogCache::isolate();

    // $this->have: the ids PokéSearch has; $this->extra: ids added to data without being asked for;
    // $this->broken: batch number (1-based) that answers with a body outside the contract, or null.
    $this->have = [];
    $this->extra = [];
    $this->broken = null;
    $this->urls = [];
    $this->batches = [];
    Http::fake(function (ClientRequest $request) {
        $this->urls[] = $request->url();
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $ids = explode(',', (string) $query['ids']);
        $this->batches[] = $ids;
        if ($this->broken === count($this->batches)) {
            return Http::response(['unexpected' => true]);
        }
        $found = array_values(array_unique([...array_intersect($ids, $this->have), ...$this->extra]));

        return Http::response([
            'data' => array_map(fn (string $id): array => ['id' => $id], $found),
            'missing' => array_values(array_diff($ids, $this->have)),
            'seed' => ['ref' => T45E_REF, 'seeded_at' => '2026-10-11T20:05:45Z'],
        ]);
    });
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('sends exactly 100 distinct ids as one batch and 101 as 100 plus 1, counting ids and not rows', function (): void {
    $ids = array_map(fn (int $i): string => "sv2-{$i}", range(1, 100));
    t45eRows($ids);
    $this->have = $ids;

    VerifyCatalog::dispatch();
    $at100 = array_map('count', $this->batches);

    $this->batches = [];
    $extra = 'sv2-101';
    t45eRows([$extra]);
    $this->have[] = $extra;
    VerifyCatalog::dispatch();

    expect($at100)->toBe([100])
        ->and(array_map('count', $this->batches))->toBe([100, 1])
        ->and(DB::table('card_variants')->count())->toBe(202)
        ->and(DB::table('card_variants')->where('catalog_status', 'ok')->count())->toBe(202);
});

it('makes no request and writes nothing when the product map holds no variants', function (): void {
    VerifyCatalog::dispatch();

    Http::assertNothingSent();
    expect(DB::table('card_variants')->count())->toBe(0);
});

it('percent-encodes ? and ! in the batch and still flags those ids, ignoring ids it was never asked about', function (): void {
    t45eRows(['ex10-?', 'sv1-!', 'sv1-1']);
    $this->have = ['sv1-1'];
    $this->extra = ['zz9-999'];

    VerifyCatalog::dispatch();

    $statuses = DB::table('card_variants')->orderBy('card_id')->pluck('catalog_status', 'card_id')->all();
    expect($this->urls)->toHaveCount(1)
        ->and($this->urls[0])->toContain('ids=ex10-%3F%2Csv1-%21%2Csv1-1')
        ->and($this->urls[0])->not->toContain('?ids=ex10-?')
        ->and($statuses)->toBe(['ex10-?' => 'catalog_missing', 'sv1-!' => 'catalog_missing', 'sv1-1' => 'ok'])
        ->and(DB::table('card_variants')->count())->toBe(6)
        ->and(DB::table('card_variants')->where('card_id', 'zz9-999')->count())->toBe(0);
});

it('writes nothing when a later chunk answers outside the contract, and deletes no row', function (): void {
    $ids = array_map(fn (int $i): string => "sv3-{$i}", range(1, 250));
    t45eRows($ids);
    $this->have = array_slice($ids, 0, 200);
    $this->broken = 3;
    $before = DB::table('card_variants')->orderBy('variant_key')->get()->all();

    // The throttle middleware catches the contract failure and releases the job for a later try.
    $released = [];
    Event::listen(JobProcessed::class, function (JobProcessed $event) use (&$released): void {
        $released[] = $event->job->isReleased();
    });
    VerifyCatalog::dispatch();

    expect($released)->toBe([true])
        ->and(array_map('count', $this->batches))->toBe([100, 100, 50])
        ->and(DB::table('card_variants')->orderBy('variant_key')->get()->all())->toEqual($before)
        ->and(DB::table('card_variants')->where('catalog_status', 'unverified')->count())->toBe(500);
});
