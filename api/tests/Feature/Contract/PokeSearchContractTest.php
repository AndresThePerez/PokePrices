<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use App\Domain\Catalog\PokeSearchClient;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Tests\Support\CatalogCache;

// C4 (plan T51, review-1 rec 5): PokéSearch v1 answers recorded verbatim from the reseeded dev index live under
// tests/Fixtures/pokesearch/. RECORDINGS.json names, per fixture, the request that recorded it, the HTTP status
// and the PokéSearch JSON Schema it must satisfy; schema/ holds those schemas copied byte for byte from the
// PokéSearch commit SCHEMA_SOURCE.json names. Every fixture is validated with opis/json-schema (2020-12), and the
// client, the catalog repository and its degraded mode replay the fixtures through Http::fake.

pest()->group('contract');

const T51_DIR = __DIR__.'/../../Fixtures/pokesearch';

const T51_BASE = 'http://192.0.2.10:8081';

/** The base URI the vendored schemas load under, so their relative $ref ("card.json") resolves inside schema/. */
const T51_SCHEMA_URI = 'https://pokesearch.example/schema/';

const T51_DRAFT = 'https://json-schema.org/draft/2020-12/schema';

/** The eight fixtures plan T51 names, by stored file name (ex10-%3F is stored under an ASCII-safe name). */
const T51_FIXTURES = [
    'cards/base1-4.json',
    'cards/ex10-question-mark.json',
    'cards/ex10-!.json',
    'batch/mixed-missing.json',
    'sets.json',
    'errors/not_found.json',
    'meta.json',
    'search/charizard-p1.json',
];

/** @return array<mixed> */
function t51Json(string $relative): array
{
    expect(is_file(T51_DIR.'/'.$relative))->toBeTrue("tests/Fixtures/pokesearch/{$relative} is missing");

    return json_decode((string) file_get_contents(T51_DIR.'/'.$relative), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, array{request: string, status: int, schema: string}> */
function t51Recordings(): array
{
    return t51Json('RECORDINGS.json')['fixtures'];
}

function t51Validator(): Validator
{
    $validator = new Validator;
    $validator->resolver()->registerPrefix(T51_SCHEMA_URI, T51_DIR.'/schema/');

    return $validator;
}

/** The opis errors of one JSON document against one vendored schema, formatted; [] when it is valid. */
function t51Errors(Validator $validator, string $raw, string $schema): array
{
    $result = $validator->validate(json_decode($raw, false, 512, JSON_THROW_ON_ERROR), T51_SCHEMA_URI.$schema);

    return $result->isValid() ? [] : (new ErrorFormatter)->formatFlat($result->error());
}

/** A request's path, percent-decoded, and its query, parsed: two requests for the same resource compare equal. */
function t51Resource(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    ksort($query);

    return [rawurldecode((string) parse_url($url, PHP_URL_PATH)), $query];
}

/**
 * Answers every PokéSearch request from the recorded fixture for the same resource, with its recorded status and
 * verbatim body; a request no fixture recorded fails the test. Once $this->upstreamDown is true every request
 * times out instead. Records each answered fixture name in $this->replayed.
 */
function t51Replay(object $test): void
{
    $test->replayed = [];
    $test->upstreamDown = false;
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) use ($test) {
        if ($test->upstreamDown) {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }
        foreach (t51Recordings() as $fixture => $recording) {
            if (t51Resource($request->url()) === t51Resource($recording['request'])) {
                $test->replayed[] = $fixture;

                return Http::response((string) file_get_contents(T51_DIR.'/'.$fixture), $recording['status'], ['Content-Type' => 'application/json']);
            }
        }
        throw new RuntimeException("No recorded PokéSearch fixture answers {$request->url()}");
    });
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T51_BASE, 'services.pokesearch.api_key' => null]);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    $this->prefix = CatalogCache::isolate();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('vendors every PokéSearch schema byte for byte from the p1-api-v1 commit SCHEMA_SOURCE.json names', function (): void {
    $source = t51Json('SCHEMA_SOURCE.json');
    $vendored = array_map('basename', glob(T51_DIR.'/schema/*.json') ?: []);
    sort($vendored);
    $listed = array_keys($source['files']);
    sort($listed);

    $matched = 0;
    foreach ($source['files'] as $name => $sha256) {
        expect(hash_file('sha256', T51_DIR.'/schema/'.$name))->toBe($sha256, "schema/{$name} differs from SCHEMA_SOURCE.json");
        $matched++;
    }

    expect($source['branch'])->toBe('p1-api-v1')
        ->and($source['commit'])->toMatch('/\A[0-9a-f]{40}\z/')
        ->and($source['path'])->toBe('docs/api/schema')
        ->and($vendored)->toBe($listed)
        ->and($matched)->toBeGreaterThanOrEqual(5)
        ->and(array_unique(array_column(t51Recordings(), 'schema')))->each->toBeIn($listed);
});

it('records exactly the eight fixtures plan T51 names, each from its own request', function (): void {
    $recordings = t51Recordings();
    $names = array_keys($recordings);
    sort($names);
    $expected = T51_FIXTURES;
    sort($expected);

    expect($names)->toBe($expected)
        ->and(array_column($recordings, 'request'))->toBe(array_unique(array_column($recordings, 'request')))
        ->and($recordings['cards/base1-4.json'])->toBe(['request' => '/api/v1/cards/base1-4', 'status' => 200, 'schema' => 'card-response.json'])
        ->and($recordings['cards/ex10-question-mark.json'])->toBe(['request' => '/api/v1/cards/ex10-%3F', 'status' => 200, 'schema' => 'card-response.json'])
        ->and($recordings['cards/ex10-!.json'])->toBe(['request' => '/api/v1/cards/ex10-!', 'status' => 200, 'schema' => 'card-response.json'])
        ->and($recordings['batch/mixed-missing.json'])->toBe(['request' => '/api/v1/cards?ids=base1-4,ex10-%3F,no-such-1', 'status' => 200, 'schema' => 'cards-batch-response.json'])
        ->and($recordings['sets.json'])->toBe(['request' => '/api/v1/sets', 'status' => 200, 'schema' => 'sets-response.json'])
        ->and($recordings['errors/not_found.json'])->toBe(['request' => '/api/v1/cards/no-such-1', 'status' => 404, 'schema' => 'error.json'])
        ->and($recordings['meta.json'])->toBe(['request' => '/api/meta', 'status' => 200, 'schema' => 'meta.json'])
        ->and($recordings['search/charizard-p1.json'])->toBe(['request' => '/api/search?q=charizard&page=1', 'status' => 200, 'schema' => 'search.json']);
});

it('holds the answers of the reseeded index: one seed ref across them, the requested ids, the batch miss', function (): void {
    $meta = t51Json('meta.json');
    $ref = $meta['seed']['ref'];
    $batch = t51Json('batch/mixed-missing.json');
    $search = t51Json('search/charizard-p1.json');

    expect($meta['docs'])->toBe(20635)
        ->and($ref)->toMatch('/\A[0-9a-f]{40}\z/')
        ->and(t51Json('cards/base1-4.json')['data']['id'])->toBe('base1-4')
        ->and(t51Json('cards/base1-4.json')['data']['name'])->toBe('Charizard')
        ->and(t51Json('cards/ex10-question-mark.json')['data']['id'])->toBe('ex10-?')
        ->and(t51Json('cards/ex10-!.json')['data']['id'])->toBe('ex10-!')
        ->and(array_column($batch['data'], 'id'))->toBe(['base1-4', 'ex10-?'])
        ->and($batch['missing'])->toBe(['no-such-1'])
        ->and(t51Json('errors/not_found.json')['error']['code'])->toBe('not_found')
        ->and($search['page'])->toBe(1)
        ->and($search['results'])->not->toBe([])
        ->and(count(t51Json('sets.json')['data']))->toBeGreaterThan(100);
    foreach (['cards/base1-4.json', 'cards/ex10-question-mark.json', 'cards/ex10-!.json', 'batch/mixed-missing.json', 'sets.json'] as $fixture) {
        expect(t51Json($fixture)['seed']['ref'])->toBe($ref, "{$fixture} was recorded from another seed");
    }
});

it('validates every recorded fixture against its vendored schema with opis/json-schema 2020-12', function (): void {
    $validator = t51Validator();

    $validated = 0;
    foreach (t51Recordings() as $fixture => $recording) {
        $schema = t51Json('schema/'.$recording['schema']);
        $errors = t51Errors($validator, (string) file_get_contents(T51_DIR.'/'.$fixture), $recording['schema']);

        expect($schema['$schema'])->toBe(T51_DRAFT)
            ->and($errors)->toBe([], "{$fixture} does not satisfy schema/{$recording['schema']}");
        $validated++;
    }

    expect($validator->parser()->defaultDraftVersion())->toBe('2020-12')
        ->and($validated)->toBeGreaterThanOrEqual(8)
        ->and($validated)->toBe(count(T51_FIXTURES));
});

it('rejects a fixture outside its schema, through the $ref into card.json as well', function (): void {
    $validator = t51Validator();
    $card = t51Json('cards/base1-4.json');
    $extraField = $card;
    $extraField['data']['price'] = 1;
    $noMissing = t51Json('batch/mixed-missing.json');
    unset($noMissing['missing']);
    $badCode = t51Json('errors/not_found.json');
    $badCode['error']['code'] = 'teapot';

    expect(t51Errors($validator, json_encode($extraField, JSON_THROW_ON_ERROR), 'card-response.json'))->not->toBe([])
        ->and(t51Errors($validator, json_encode($noMissing, JSON_THROW_ON_ERROR), 'cards-batch-response.json'))->not->toBe([])
        ->and(t51Errors($validator, json_encode($badCode, JSON_THROW_ON_ERROR), 'error.json'))->not->toBe([])
        ->and(t51Errors($validator, json_encode($card, JSON_THROW_ON_ERROR), 'card-response.json'))->toBe([]);
});

it('replays the fixtures through the PokéSearch client: cards, the encoded ids, the batch, the miss, meta and search', function (): void {
    t51Replay($this);
    $client = new PokeSearchClient;

    $search = $client->search('charizard', 1);
    $recordedSearch = t51Json('search/charizard-p1.json');

    expect($client->card('base1-4'))->toBe(t51Json('cards/base1-4.json'))
        ->and($client->card('ex10-?'))->toBe(t51Json('cards/ex10-question-mark.json'))
        ->and($client->card('ex10-!'))->toBe(t51Json('cards/ex10-!.json'))
        ->and($client->card('no-such-1'))->toBeNull()
        ->and($client->cards(['base1-4', 'ex10-?', 'no-such-1']))->toBe(t51Json('batch/mixed-missing.json'))
        ->and($client->meta())->toBe(t51Json('meta.json'))
        ->and($search['results'])->toBe($recordedSearch['results'])
        ->and($search['total'])->toBe($recordedSearch['total'])
        ->and($search['pages'])->toBe($recordedSearch['pages'])
        ->and($this->replayed)->toBe([
            'search/charizard-p1.json', 'cards/base1-4.json', 'cards/ex10-question-mark.json', 'cards/ex10-!.json',
            'errors/not_found.json', 'batch/mixed-missing.json', 'meta.json',
        ]);
});

it('replays the fixtures through the catalog repository under the recorded seed ref and caches them', function (): void {
    t51Replay($this);
    $repository = app(CatalogRepository::class);

    $first = $repository->card('base1-4');
    $second = $repository->card('base1-4');
    $search = $repository->search('charizard', 1);

    expect($repository->seedRef()->ref)->toBe(t51Json('meta.json')['seed']['ref'])
        ->and($first?->data)->toBe(t51Json('cards/base1-4.json'))
        ->and($first?->cached)->toBeFalse()
        ->and($second?->data)->toBe(t51Json('cards/base1-4.json'))
        ->and($second?->cached)->toBeTrue()
        ->and($repository->card('ex10-?')?->data)->toBe(t51Json('cards/ex10-question-mark.json'))
        ->and($repository->card('no-such-1'))->toBeNull()
        ->and($search->data['results'])->toBe(t51Json('search/charizard-p1.json')['results'])
        ->and(array_count_values($this->replayed)['cards/base1-4.json'])->toBe(1);
});

it('serves the replayed card and search degraded from the last-good copy once PokéSearch is down', function (): void {
    t51Replay($this);
    $repository = app(CatalogRepository::class);
    $fetchedAt = Carbon::now()->startOfSecond();
    $this->travelTo($fetchedAt);
    $repository->card('base1-4');
    $repository->search('charizard', 1);

    $this->upstreamDown = true;
    $this->travel(86400 + 1)->seconds();
    $card = $repository->card('base1-4');
    $search = $repository->search('charizard', 1);

    $staleSince = $fetchedAt->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    expect($card?->degraded)->toBeTrue()
        ->and($card?->data)->toBe(t51Json('cards/base1-4.json'))
        ->and($card?->staleSince)->toBe($staleSince)
        ->and($search->degraded)->toBeTrue()
        ->and($search->data['results'])->toBe(t51Json('search/charizard-p1.json')['results'])
        ->and($search->staleSince)->toBe($staleSince);
});

it('answers GET /v1/cards?q= and /v1/cards/{id} from the replayed fixtures, then degraded, inside openapi/api.json', function (): void {
    t51Replay($this);
    $recordedCard = t51Json('cards/base1-4.json');

    $search = $this->getJson('/v1/cards?q=charizard&page=1');
    $card = $this->getJson('/v1/cards/base1-4');
    $this->upstreamDown = true;
    $this->travel(86400 + 1)->seconds();
    $degradedSearch = $this->getJson('/v1/cards?q=charizard&page=1');
    $degradedCard = $this->getJson('/v1/cards/base1-4');

    expect($search->getStatusCode())->toBe(200)
        ->and(array_column($search->json('data'), 'id'))->toBe(array_column(t51Json('search/charizard-p1.json')['results'], 'id'))
        ->and($card->getStatusCode())->toBe(200)
        ->and($card->json('data.id'))->toBe('base1-4')
        ->and($card->json('data.name'))->toBe($recordedCard['data']['name'])
        ->and($card->json('data.catalog.seed_ref'))->toBe($recordedCard['seed']['ref'])
        ->and($degradedSearch->getStatusCode())->toBe(200)
        ->and($degradedSearch->json('meta.degraded'))->toBeTrue()
        ->and($degradedCard->getStatusCode())->toBe(200)
        ->and($degradedCard->json('meta.degraded'))->toBeTrue()
        ->and($degradedCard->json('data.id'))->toBe('base1-4');
});
