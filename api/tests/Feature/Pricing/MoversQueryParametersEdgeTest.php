<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Http\Problems\ProblemDetails;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CatalogCache;

// Edge cases of the query parameters GET /v1/movers accepts: direction and limit, nothing else. The shapes a query
// string can really carry (an empty value, a bare name, an array, a name PHP reads as a number), the conditional
// read, and the two requests the web sends (the home strip and the board). PokéSearch is faked on an RFC 5737 address.

const MQPX_REFUSED = 'This query parameter is not accepted.';

beforeEach(function (): void {
    Queue::fake([VerifyCatalog::class]);
    $prefix = CatalogCache::isolate();
    $this->beforeApplicationDestroyed(function () use ($prefix): void {
        CatalogCache::flush($prefix);
    });
    config(['services.pokesearch.url' => 'http://192.0.2.10:8081', 'services.pokesearch.api_key' => null]);
    Http::fake(function (Request $request): PromiseInterface {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(['data' => array_map(fn (string $id): array => ['id' => $id, 'name' => "Name of {$id}",
            'number' => '4', 'set_name' => 'Fixture set'], array_filter(explode(',', (string) ($query['ids'] ?? '')))), 'missing' => []]);
    });
    foreach ([['up', 'mqpx-4', '12.00', '2.00', '20.00'], ['down', 'mqpx-5', '8.00', '-2.00', '-20.00']] as [$direction, $card, $close, $change, $pct]) {
        $key = "{$card}:holo:unlimited:-";
        DB::table('card_variants')->insert(['variant_key' => $key, 'card_id' => $card, 'finish' => 'holo', 'edition' => 'unlimited']);
        DB::table('price_movers')->insert(['day' => '2026-10-14', 'source' => 'tcgplayer', 'direction' => $direction, 'rank' => 1,
            'variant_key' => $key, 'card_id' => $card, 'series_epoch' => 1, 'close' => $close, 'previous_close' => '10.00',
            'previous_day' => '2026-10-13', 'change' => $change, 'change_pct' => $pct, 'as_of' => '2026-10-14 20:05:45+00']);
    }
});

it('refuses an unknown parameter with an empty value, with no value, and in array form, naming it each time', function (): void {
    $empty = $this->getJson('/v1/movers?direction=up&foo=');
    $bare = $this->getJson('/v1/movers?foo');
    $nested = $this->getJson('/v1/movers?foo[bar]=1&limit=5');
    $two = $this->getJson('/v1/movers?zeta=1&alpha=2');

    expect([$empty->getStatusCode(), $bare->getStatusCode(), $nested->getStatusCode(), $two->getStatusCode()])->toBe([422, 422, 422, 422])
        ->and($empty->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and($empty->json('errors'))->toBe(['foo' => [MQPX_REFUSED]])
        ->and($bare->json('errors'))->toBe(['foo' => [MQPX_REFUSED]])
        ->and($nested->json('errors'))->toBe(['foo' => [MQPX_REFUSED]])
        ->and($two->json('errors'))->toBe(['zeta' => [MQPX_REFUSED], 'alpha' => [MQPX_REFUSED]]);
});

// These two requests go through the HTTP kernel, not the test client: the contract validator decodes a JSON object
// with numeric keys into a list, so it cannot judge this body.
function mqpxThroughKernel(string $uri): Response
{
    return app(Kernel::class)->handle(HttpRequest::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
}

it('names a refused parameter whose name is a number under errors, as a JSON object', function (): void {
    $response = mqpxThroughKernel('/v1/movers?0=1');
    $beside = mqpxThroughKernel('/v1/movers?direction=down&7=x');

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and((string) $response->getContent())->toContain('"errors":{"0":["'.MQPX_REFUSED.'"]}')
        ->and($beside->getStatusCode())->toBe(422)
        ->and((string) $beside->getContent())->toContain('"errors":{"7":["'.MQPX_REFUSED.'"]}');
});

it('keeps limit at its documented edges and refuses a known parameter in array form or repeated out of range by its own name', function (): void {
    $lowest = $this->getJson('/v1/movers?limit=1');
    $highest = $this->getJson('/v1/movers?direction=down&limit=50');
    $zero = $this->getJson('/v1/movers?limit=0');
    $over = $this->getJson('/v1/movers?limit=51');
    $array = $this->getJson('/v1/movers?limit[]=5');
    $directions = $this->getJson('/v1/movers?direction[]=up');
    $repeated = $this->getJson('/v1/movers?direction=up&direction=down');

    expect([$lowest->getStatusCode(), $highest->getStatusCode(), $repeated->getStatusCode()])->toBe([200, 200, 200])
        ->and($lowest->json('meta.limit'))->toBe(1)
        ->and($highest->json('meta'))->toMatchArray(['direction' => 'down', 'limit' => 50])
        ->and($repeated->json('meta.direction'))->toBe('down')
        ->and([$zero->getStatusCode(), $over->getStatusCode(), $array->getStatusCode(), $directions->getStatusCode()])->toBe([422, 422, 422, 422])
        ->and(array_keys($zero->json('errors')))->toBe(['limit'])
        ->and(array_keys($over->json('errors')))->toBe(['limit'])
        ->and(array_keys($array->json('errors')))->toBe(['limit'])
        ->and(array_keys($directions->json('errors')))->toBe(['direction']);
});

it('answers the home strip and the board as before, revalidates them with 304, and never answers 304 once an unknown parameter is added', function (): void {
    $strip = $this->getJson('/v1/movers?direction=up&limit=5');
    $board = $this->getJson('/v1/movers?direction=down&limit=50');
    $etag = (string) $strip->headers->get('ETag');

    $again = $this->getJson('/v1/movers?direction=up&limit=5', ['If-None-Match' => $etag]);
    $withDay = $this->getJson('/v1/movers?direction=up&limit=5&day=2026-10-14', ['If-None-Match' => $etag]);

    expect([$strip->getStatusCode(), $board->getStatusCode()])->toBe([200, 200])
        ->and(array_column($strip->json('data'), 'variant_key'))->toBe(['mqpx-4:holo:unlimited:-'])
        ->and(array_column($board->json('data'), 'variant_key'))->toBe(['mqpx-5:holo:unlimited:-'])
        ->and($strip->json('meta'))->toMatchArray(['day' => '2026-10-14', 'direction' => 'up', 'limit' => 5])
        ->and($etag)->not->toBe('')
        ->and($again->getStatusCode())->toBe(304)
        ->and($withDay->getStatusCode())->toBe(422)
        ->and($withDay->json('errors'))->toBe(['day' => [MQPX_REFUSED]])
        ->and($withDay->headers->get('ETag'))->toBeNull();
});
