<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Http\Problems\ProblemDetails;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CatalogCache;

// GET /v1/movers takes direction and limit and nothing else: any other query parameter (day included, which the
// route never read) is the 422 problem naming it, the way the card search refuses one. A read that really fails is
// the 500 problem, which the contract documents. Every upstream call is faked on an RFC 5737 address.

const MQP_REFUSED = 'This query parameter is not accepted.';

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

/** One up-mover and one down-mover of 2026-10-14 in price_movers (and their variants). */
function mqpSeed(): void
{
    foreach ([['up', 'mqp-4', '12.00', '2.00', '20.00'], ['down', 'mqp-5', '8.00', '-2.00', '-20.00']] as [$direction, $card, $close, $change, $pct]) {
        $key = "{$card}:holo:unlimited:-";
        DB::table('card_variants')->insert(['variant_key' => $key, 'card_id' => $card, 'finish' => 'holo', 'edition' => 'unlimited']);
        DB::table('price_movers')->insert(['day' => '2026-10-14', 'source' => 'tcgplayer', 'direction' => $direction, 'rank' => 1,
            'variant_key' => $key, 'card_id' => $card, 'series_epoch' => 1, 'close' => $close, 'previous_close' => '10.00',
            'previous_day' => '2026-10-13', 'change' => $change, 'change_pct' => $pct, 'as_of' => '2026-10-14 20:05:45+00']);
    }
}

it('refuses a query parameter the movers route does not take with the 422 problem naming it', function (string $query, array $errors): void {
    mqpSeed();

    $response = $this->getJson('/v1/movers?'.$query);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and($response->json('errors'))->toBe($errors)
        ->and($response->json())->not->toHaveKey('data');
})->with([
    'a day' => ['day=2026-10-13', ['day' => [MQP_REFUSED]]],
    'foo' => ['foo=1', ['foo' => [MQP_REFUSED]]],
    'a day beside a good direction and limit' => ['direction=up&limit=5&day=2026-10-13', ['day' => [MQP_REFUSED]]],
]);

it('answers direction and limit as before, alone and together, and refuses the same query once a day is added', function (): void {
    mqpSeed();

    $none = $this->getJson('/v1/movers');
    $down = $this->getJson('/v1/movers?direction=down');
    $one = $this->getJson('/v1/movers?limit=1');
    $both = $this->getJson('/v1/movers?direction=down&limit=5');
    $withDay = $this->getJson('/v1/movers?direction=down&limit=5&day=2026-10-13');

    expect([$none->getStatusCode(), $down->getStatusCode(), $one->getStatusCode(), $both->getStatusCode()])->toBe([200, 200, 200, 200])
        ->and($none->json('meta'))->toMatchArray(['day' => '2026-10-14', 'direction' => 'up', 'limit' => 20])
        ->and(array_column($none->json('data'), 'variant_key'))->toBe(['mqp-4:holo:unlimited:-'])
        ->and($down->json('meta'))->toMatchArray(['day' => '2026-10-14', 'direction' => 'down', 'limit' => 20])
        ->and(array_column($down->json('data'), 'variant_key'))->toBe(['mqp-5:holo:unlimited:-'])
        ->and($one->json('meta'))->toMatchArray(['direction' => 'up', 'limit' => 1])
        ->and($both->json('meta'))->toMatchArray(['direction' => 'down', 'limit' => 5])
        ->and($both->json('data'))->toBe($down->json('data'))
        ->and($withDay->getStatusCode())->toBe(422)
        ->and(array_keys($withDay->json('errors')))->toBe(['day']);
});

it('refuses an unknown parameter before the read, and answers a read that really fails as the 500 problem', function (): void {
    mqpSeed();
    DB::statement('ALTER TABLE price_movers RENAME TO price_movers_mqp_gone');

    $refused = $this->getJson('/v1/movers?foo=1');
    $broken = $this->getJson('/v1/movers?direction=up&limit=5');

    expect($refused->getStatusCode())->toBe(422)
        ->and($refused->json('errors'))->toBe(['foo' => [MQP_REFUSED]])
        ->and($broken->getStatusCode())->toBe(500)
        ->and($broken->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($broken->json('type'))->toBe(ProblemDetails::BASE.'server-error')
        ->and((string) $broken->getContent())->not->toContain('price_movers');
});

it('documents the refusal and the 500 on the movers operation', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $operation = $spec['paths']['/movers']['get'];

    expect(array_keys($operation['responses']))->toBe([200, 304, 422, 429, 500, 503])
        ->and($operation['responses'][500]['content'])->toBe(['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]])
        ->and(array_column($operation['parameters'], 'name'))->toBe(['direction', 'limit'])
        ->and((string) $operation['description'])->toContain('any other query parameter answers 422');
});
